<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Integration\CredlyClient;
use Certloom\Infrastructure\Integration\CredlyConnectionRepository;
use Certloom\Infrastructure\Integration\CredlyMappingStore;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\DestinationDeliveryRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One external Credly side-effect slice. Interrupted calls are never auto-replayed. */
final class CredlyPublishJobHandler implements JobHandlerInterface {
	/** @var DestinationDeliveryRepository */ private $deliveries;
	/** @var CredentialRepository */ private $credentials;
	/** @var CredlyMappingStore */ private $mappings;
	/** @var CredlyConnectionRepository */ private $connection;
	/** @var CredlyClient */ private $client;
	public function __construct( ?DestinationDeliveryRepository $deliveries = null, ?CredentialRepository $credentials = null, ?CredlyMappingStore $mappings = null, ?CredlyConnectionRepository $connection = null, ?CredlyClient $client = null ) {
		$this->deliveries = $deliveries ?: new DestinationDeliveryRepository();
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->mappings = $mappings ?: new CredlyMappingStore();
		$this->connection = $connection ?: new CredlyConnectionRepository();
		$this->client = $client ?: new CredlyClient();
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) {
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$key = (string) ( $payload['delivery_key'] ?? '' );
		$delivery = $this->deliveries->find_delivery( $key );
		if ( is_wp_error( $delivery ) ) { return $delivery; }
		if ( ! is_array( $delivery ) || 'credly' !== (string) ( $delivery['destination'] ?? '' ) ) { return new \WP_Error( 'certloom_credly_delivery_missing', __( 'The Credly publishing record is unavailable.', 'certloom' ) ); }
		$status = (string) ( $delivery['status'] ?? '' );
		if ( DestinationDeliveryRepository::STATUS_SUCCEEDED === $status ) { return JobSliceResult::succeeded( array( 'delivery_key' => $key, 'status' => 'succeeded', 'idempotent_terminal' => true ), 1 ); }
		if ( DestinationDeliveryRepository::STATUS_UNCERTAIN === $status ) { return JobSliceResult::failed( array( 'code' => 'certloom_credly_publish_uncertain', 'message' => __( 'Credly may have received this publication, but Certloom could not confirm the result. Review the Credly account before trying again.', 'certloom' ) ), array( 'delivery_key' => $key, 'status' => 'uncertain' ), 0 ); }

		$version = $this->credentials->find_version( absint( $delivery['version_id'] ?? 0 ) );
		if ( is_wp_error( $version ) ) { return $version; }
		if ( ! is_array( $version ) || 'issued' !== (string) ( $version['issuance_state'] ?? '' ) ) { return new \WP_Error( 'certloom_credly_version_invalid', __( 'Credly publishing could not find the issued credential record for this publication.', 'certloom' ) ); }
		$snapshot = is_array( $version['snapshot'] ?? null ) ? $version['snapshot'] : array();
		$program_id = absint( $snapshot['program']['id'] ?? 0 );
		$mapping = $this->mappings->find( $program_id );
		if ( ! is_array( $mapping ) || empty( $mapping['active'] ) ) { return $this->definite_failure( $key, 'mapping_missing', __( 'The Credly Program mapping was removed before publishing. Nothing was sent.', 'certloom' ) ); }
		$mapping_sha = $this->mappings->fingerprint( $mapping );
		if ( is_wp_error( $mapping_sha ) ) { return $mapping_sha; }
		if ( ! hash_equals( (string) $delivery['mapping_sha256'], (string) $mapping_sha ) ) { return $this->definite_failure( $key, 'mapping_changed', __( 'The Credly Program mapping changed after this publish was queued. Review the new mapping before publishing again.', 'certloom' ) ); }
		$connection = $this->connection->connection();
		if ( is_wp_error( $connection ) ) { return $this->definite_failure( $key, 'connection_missing', $connection->get_error_message() ); }
		if ( ! hash_equals( (string) $delivery['configuration_sha256'], (string) ( $connection['configuration_sha256'] ?? '' ) ) ) { return $this->definite_failure( $key, 'destination_changed', __( 'The Credly organization or environment changed after this publish was queued. Nothing was sent.', 'certloom' ) ); }

		$recipient = is_array( $snapshot['recipient'] ?? null ) ? $snapshot['recipient'] : array();
		$credential = is_array( $snapshot['credential'] ?? null ) ? $snapshot['credential'] : array();
		$email = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
		if ( '' === $email || ! hash_equals( (string) $delivery['recipient_sha256'], hash( 'sha256', strtolower( $email ) ) ) ) { return $this->definite_failure( $key, 'recipient_changed', __( 'The immutable recipient email no longer matches this Credly publish review. Nothing was sent.', 'certloom' ) ); }
		$issued_at = substr( (string) ( $credential['issued_at'] ?? '' ), 0, 10 );
		$expires_at = ! empty( $credential['expires_at'] ) ? substr( (string) $credential['expires_at'], 0, 10 ) : '';
		$names = $this->recipient_names( $recipient );
		$api_payload = array(
			'badge_template_id' => (string) $mapping['badge_template_id'],
			'recipient_email' => $email,
			'issued_at' => $issued_at,
			'expires_at' => $expires_at,
			'issued_to_first_name' => $names['first'],
			'issued_to_last_name' => $names['last'],
			'issuer_earner_id' => (string) ( $recipient['external_id'] ?? '' ),
			'suppress_badge_notification_email' => ! empty( $mapping['suppress_badge_notification_email'] ),
		);
		try { $attempt = bin2hex( random_bytes( 16 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_credly_attempt_key_failed', __( 'Certloom could not create a secure Credly publishing attempt identifier.', 'certloom' ) ); }
		$sending = $this->deliveries->mark_sending( $key, $attempt );
		if ( is_wp_error( $sending ) ) { return $sending; }
		$result = $this->client->issue_badge( $connection, $api_payload );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$uncertain = is_array( $data ) && ! empty( $data['uncertain'] );
			$recorded = $this->deliveries->mark_failed( $key, $result->get_error_code(), array( 'http_status' => is_array( $data ) ? absint( $data['http_status'] ?? 0 ) : 0, 'attempt_token_sha256' => hash( 'sha256', $attempt ) ), $uncertain );
			if ( is_wp_error( $recorded ) ) { return $recorded; }
			return JobSliceResult::failed( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), array( 'delivery_key' => $key, 'status' => $uncertain ? 'uncertain' : 'failed' ), 1 );
		}
		$recorded = $this->deliveries->mark_succeeded( $key, (string) $result['external_reference'], array( 'http_status' => absint( $result['http_status'] ?? 0 ), 'external_state' => sanitize_key( (string) ( $result['state'] ?? '' ) ), 'attempt_token_sha256' => hash( 'sha256', $attempt ) ) );
		if ( is_wp_error( $recorded ) ) { return $recorded; }
		return JobSliceResult::succeeded( array( 'delivery_key' => $key, 'status' => 'succeeded', 'external_reference' => (string) $result['external_reference'] ), 1 );
	}

	/** @return JobSliceResult|\WP_Error */
	private function definite_failure( $key, $code, $message ) {
		$recorded = $this->deliveries->mark_failed( $key, sanitize_key( $code ), array(), false );
		return is_wp_error( $recorded ) ? $recorded : JobSliceResult::failed( array( 'code' => 'certloom_credly_' . sanitize_key( $code ), 'message' => (string) $message ), array( 'delivery_key' => $key, 'status' => 'failed' ), 0 );
	}

	/** @return array{first:string,last:string} */
	private function recipient_names( array $recipient ) {
		$first = sanitize_text_field( (string) ( $recipient['first_name'] ?? '' ) );
		$last = sanitize_text_field( (string) ( $recipient['last_name'] ?? '' ) );
		if ( '' !== $first || '' !== $last ) { return array( 'first' => $first, 'last' => $last ); }
		$display = trim( sanitize_text_field( (string) ( $recipient['display_name'] ?? '' ) ) );
		if ( '' === $display ) { return array( 'first' => '', 'last' => '' ); }
		$parts = preg_split( '/\s+/u', $display );
		if ( ! is_array( $parts ) || ! $parts ) { return array( 'first' => $display, 'last' => '' ); }
		$first = (string) array_shift( $parts );
		return array( 'first' => $first, 'last' => implode( ' ', $parts ) );
	}
}
