<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Job\JobQueue;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\DestinationDeliveryRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Converts a successful canonical Certloom issuance into optional Credly work.
 *
 * It never changes credential truth and never blocks issuance. A Program must
 * be explicitly mapped and the site must have an encrypted issuer connection.
 */
final class CredlyPublishingListener {
	/** @var JobQueue */ private $queue;
	/** @var CredentialRepository */ private $credentials;
	/** @var DestinationDeliveryRepository */ private $deliveries;
	/** @var CredlyMappingStore */ private $mappings;
	/** @var CredlyConnectionRepository */ private $connection;

	public function __construct( JobQueue $queue, ?CredentialRepository $credentials = null, ?DestinationDeliveryRepository $deliveries = null, ?CredlyMappingStore $mappings = null, ?CredlyConnectionRepository $connection = null ) {
		$this->queue = $queue;
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->deliveries = $deliveries ?: new DestinationDeliveryRepository();
		$this->mappings = $mappings ?: new CredlyMappingStore();
		$this->connection = $connection ?: new CredlyConnectionRepository();
	}

	public function register() { add_action( 'certloom_credential_issued', array( $this, 'on_issued' ), 20, 5 ); }

	/** @return void */
	public function on_issued( $version_id, $series_id, $actor_user_id, $source_type, $source_ref ) {
		$version = $this->credentials->find_version( absint( $version_id ) );
		if ( is_wp_error( $version ) || ! is_array( $version ) || 'issued' !== (string) ( $version['issuance_state'] ?? '' ) || absint( $version['series_id'] ?? 0 ) !== absint( $series_id ) ) { return; }
		$snapshot = is_array( $version['snapshot'] ?? null ) ? $version['snapshot'] : array();
		$program_id = absint( $snapshot['program']['id'] ?? 0 );
		if ( ! $program_id ) { return; }
		$mapping = $this->mappings->find( $program_id );
		if ( ! is_array( $mapping ) || empty( $mapping['active'] ) ) { return; }
		$status = $this->connection->status();
		if ( empty( $status['configured'] ) ) { return; }
		$email = sanitize_email( (string) ( $snapshot['recipient']['email'] ?? '' ) );
		if ( '' === $email ) {
			do_action( 'certloom_destination_publish_skipped', 'credly', 'recipient_email_missing', absint( $version_id ) );
			return;
		}
		$mapping_sha = $this->mappings->fingerprint( $mapping );
		if ( is_wp_error( $mapping_sha ) ) { return; }
		$config_sha = (string) ( $status['configuration_sha256'] ?? '' );
		$dedupe = hash( 'sha256', 'credly|' . absint( $version_id ) . '|' . strtolower( (string) $status['organization_id'] ) . '|' . (string) $mapping['badge_template_id'] );
		$created = $this->deliveries->create_delivery( array(
			'destination' => 'credly',
			'series_id' => absint( $series_id ),
			'version_id' => absint( $version_id ),
			'dedupe_key' => $dedupe,
			'mapping_sha256' => $mapping_sha,
			'configuration_sha256' => $config_sha,
			'recipient_sha256' => hash( 'sha256', strtolower( $email ) ),
			'evidence' => array(
				'organization_id' => (string) $status['organization_id'],
				'environment' => (string) $status['environment'],
				'badge_template_id' => (string) $mapping['badge_template_id'],
				'source_type' => sanitize_key( (string) $source_type ),
				'source_ref_sha256' => '' !== (string) $source_ref ? hash( 'sha256', (string) $source_ref ) : '',
				'queued_by' => absint( $actor_user_id ),
			),
		) );
		if ( is_wp_error( $created ) || is_array( $created ) ) { return; }
		$job = $this->queue->enqueue( JobTypes::CREDLY_PUBLISH, absint( $actor_user_id ), array( 'delivery_key' => $created ) );
		if ( is_wp_error( $job ) ) {
			$this->deliveries->mark_failed( $created, 'schedule_failed', array( 'schedule_error' => $job->get_error_code() ), false );
			return;
		}
		$attached = $this->deliveries->attach_job( $created, $job );
		if ( is_wp_error( $attached ) ) {
			/* The job may still be queued, so leave an explicit signal for diagnostics. */
			do_action( 'certloom_destination_publish_attach_failed', 'credly', $created, $job, $attached );
		}
	}
}
