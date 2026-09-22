<?php
namespace Certloom\Infrastructure\Migration;

use Certloom\Application\Contract\LegacyMigrationTargetInterface;
use Certloom\Domain\Credential\States;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Writes one legacy certificate into the new domain atomically and idempotently. */
final class LegacyMigrationWriter implements LegacyMigrationTargetInterface {
	private $recipients;
	private $credentials;
	private $events;
	private $transaction;

	public function __construct( ?RecipientRepository $recipients = null, ?CredentialRepository $credentials = null, ?EventRepository $events = null, ?Transaction $transaction = null ) {
		$this->recipients = $recipients ?: new RecipientRepository();
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->events = $events ?: new EventRepository();
		$this->transaction = $transaction ?: new Transaction();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function migrate( $legacy_id, array $plan ) {
		$legacy_id = absint( $legacy_id );
		if ( ! $legacy_id ) {
			return new \WP_Error( 'certloom_legacy_id_required', __( 'A valid legacy certificate ID is required.', 'certloom' ) );
		}
		return $this->transaction->run( function () use ( $legacy_id, $plan ) {
			$key = (string) ( $plan['version']['source_idempotency_key'] ?? '' );
			$existing = $this->credentials->find_version_by_source_idempotency( 'certloom_legacy', $key );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			if ( is_array( $existing ) ) {
				return array( 'outcome' => 'skipped', 'series_id' => absint( $existing['series_id'] ?? 0 ), 'version_id' => absint( $existing['id'] ?? 0 ) );
			}

			$recipient_id = $this->recipients->create( $plan['recipient'] );
			if ( is_wp_error( $recipient_id ) ) { return $recipient_id; }

			$series_data = $plan['series'];
			$series_data['recipient_id'] = $recipient_id;
			$series_id = $this->credentials->create_series( $series_data );
			if ( is_wp_error( $series_id ) ) { return $series_id; }

			$version_data = $plan['version'];
			$version_data['version_number'] = 1;
			$version_id = $this->credentials->create_version( $series_id, $version_data );
			if ( is_wp_error( $version_id ) ) { return $version_id; }

			$current = $this->credentials->set_current_version( $series_id, $version_id );
			if ( is_wp_error( $current ) ) { return $current; }

			$admin_state = (string) ( $plan['series']['administrative_state'] ?? States::ADMIN_ACTIVE );
			if ( States::ADMIN_ACTIVE !== $admin_state ) {
				$state = $this->credentials->set_administrative_state( $series_id, States::ADMIN_ACTIVE, $admin_state );
				if ( is_wp_error( $state ) ) { return $state; }
			}

			$event_id = $this->events->append( array(
				'series_id' => $series_id,
				'version_id' => $version_id,
				'event_type' => 'credential.migrated',
				'new_state' => array(
					'issuance_state' => (string) ( $plan['version']['issuance_state'] ?? States::ISSUANCE_ISSUED ),
					'administrative_state' => $admin_state,
				),
				'reason_code' => 'legacy_1_5_migration',
				'meta' => array( 'legacy_certificate_id' => $legacy_id ),
			) );
			if ( is_wp_error( $event_id ) ) { return $event_id; }

			return array( 'outcome' => 'migrated', 'series_id' => (int) $series_id, 'version_id' => (int) $version_id, 'recipient_id' => (int) $recipient_id );
		} );
	}
}
