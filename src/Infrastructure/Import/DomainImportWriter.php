<?php
namespace Certloom\Infrastructure\Import;

use Certloom\Application\Contract\DomainImportTargetInterface;
use Certloom\Domain\Credential\States;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

/** Atomic new-domain import writer. Existing-number corrections create a new immutable Version rather than rewriting history. */
final class DomainImportWriter implements DomainImportTargetInterface {
	private $recipients;
	private $credentials;
	private $events;
	private $transaction;

	public function __construct( RecipientRepository $recipients = null, CredentialRepository $credentials = null, EventRepository $events = null, Transaction $transaction = null ) {
		$this->recipients = $recipients ?: new RecipientRepository();
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->events = $events ?: new EventRepository();
		$this->transaction = $transaction ?: new Transaction();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function import( $row_number, array $plan, $duplicate_policy = 'skip' ) {
		$row_number = absint( $row_number );
		if ( ! $row_number ) { return new \WP_Error( 'certloom_import_row_invalid', __( 'A valid import row number is required.', 'certloom' ) ); }
		return $this->transaction->run( function () use ( $row_number, $plan, $duplicate_policy ) {
			$key = (string) ( $plan['version']['source_idempotency_key'] ?? '' );
			$existing_source = $this->credentials->find_version_by_source_idempotency( 'certloom_csv', $key );
			if ( is_wp_error( $existing_source ) ) { return $existing_source; }
			if ( is_array( $existing_source ) ) {
				$original_outcome = 1 === absint( $existing_source['version_number'] ?? 0 ) ? 'created' : 'corrected';
				return array(
					'outcome' => $original_outcome,
					'replayed' => true,
					'series_id' => absint( $existing_source['series_id'] ?? 0 ),
					'version_id' => absint( $existing_source['id'] ?? 0 ),
				);
			}

			$display_number = (string) ( $plan['version']['display_number'] ?? '' );
			$existing_number = $this->credentials->find_series_by_display_number( $display_number );
			if ( is_wp_error( $existing_number ) ) { return $existing_number; }
			if ( is_array( $existing_number ) ) {
				if ( 'correct' !== $duplicate_policy ) {
					return array( 'outcome' => 'duplicate', 'series_id' => absint( $existing_number['series_id'] ?? 0 ) );
				}
				$series_id = absint( $existing_number['series_id'] ?? 0 );
				$series = $this->credentials->find_series_for_update( $series_id );
				if ( is_wp_error( $series ) ) { return $series; }
				if ( ! is_array( $series ) ) { return new \WP_Error( 'certloom_import_correction_series_missing', __( 'The credential selected for correction no longer exists.', 'certloom' ) ); }

				$recipient_id = absint( $series['recipient_id'] ?? 0 );
				if ( ! $recipient_id ) { return new \WP_Error( 'certloom_import_correction_recipient_missing', __( 'The credential selected for correction has no valid recipient.', 'certloom' ) ); }
				$recipient_patch = isset( $plan['recipient_patch'] ) && is_array( $plan['recipient_patch'] )
					? $plan['recipient_patch']
					: array( 'display_name' => (string) ( $plan['recipient']['display_name'] ?? '' ) );
				$patched = $this->recipients->update( $recipient_id, $recipient_patch );
				if ( is_wp_error( $patched ) ) { return $patched; }

				$current_version_id = absint( $series['current_version_id'] ?? 0 );
				$current_version = $current_version_id ? $this->credentials->find_version_for_update( $current_version_id ) : null;
				if ( $current_version_id && is_wp_error( $current_version ) ) { return $current_version; }
				if ( $current_version_id && ! is_array( $current_version ) ) {
					return new \WP_Error( 'certloom_import_correction_version_missing', __( 'The credential selected for correction has no readable current version.', 'certloom' ) );
				}

				$version = $this->correction_version( (array) $current_version, $plan );
				unset( $version['version_number'] ); // Repository assigns the next immutable Version number under the Series.
				$version_id = $this->credentials->create_version( $series_id, $version );
				if ( is_wp_error( $version_id ) ) { return $version_id; }
				$current = $this->credentials->set_current_version( $series_id, $version_id );
				if ( is_wp_error( $current ) ) { return $current; }
				$current_admin = (string) ( $series['administrative_state'] ?? States::ADMIN_ACTIVE );
				$series_patch = isset( $plan['series_patch'] ) && is_array( $plan['series_patch'] ) ? $plan['series_patch'] : array();
				$desired_admin = array_key_exists( 'administrative_state', $series_patch )
					? (string) $series_patch['administrative_state']
					: $current_admin;
				if ( $current_admin !== $desired_admin ) {
					$changed = $this->credentials->set_administrative_state( $series_id, $current_admin, $desired_admin );
					if ( is_wp_error( $changed ) ) { return $changed; }
				}
				$event = $this->events->append( array(
					'series_id' => $series_id, 'version_id' => $version_id, 'event_type' => 'credential.corrected',
					'old_state' => array( 'current_version_id' => absint( $series['current_version_id'] ?? 0 ), 'administrative_state' => $current_admin ),
					'new_state' => array( 'current_version_id' => $version_id, 'administrative_state' => $desired_admin ),
					'reason_code' => 'csv_import_correction',
					'meta' => array( 'import_row' => $row_number, 'source_ref' => (string) ( $version['source_ref'] ?? '' ) ),
				) );
				if ( is_wp_error( $event ) ) { return $event; }
				return array( 'outcome' => 'corrected', 'recipient_id' => $recipient_id, 'series_id' => $series_id, 'version_id' => (int) $version_id );
			}

			$recipient_id = $this->recipients->create( $plan['recipient'] );
			if ( is_wp_error( $recipient_id ) ) { return $recipient_id; }
			$series_data = $plan['series'];
			$series_data['recipient_id'] = $recipient_id;
			$series_id = $this->credentials->create_series( $series_data );
			if ( is_wp_error( $series_id ) ) { return $series_id; }
			$version = $plan['version'];
			$version['version_number'] = 1;
			$version_id = $this->credentials->create_version( $series_id, $version );
			if ( is_wp_error( $version_id ) ) { return $version_id; }
			$current = $this->credentials->set_current_version( $series_id, $version_id );
			if ( is_wp_error( $current ) ) { return $current; }
			$admin = (string) ( $plan['series']['administrative_state'] ?? States::ADMIN_ACTIVE );
			if ( States::ADMIN_ACTIVE !== $admin ) {
				$changed = $this->credentials->set_administrative_state( $series_id, States::ADMIN_ACTIVE, $admin );
				if ( is_wp_error( $changed ) ) { return $changed; }
			}
			$event = $this->events->append( array(
				'series_id' => $series_id,
				'version_id' => $version_id,
				'event_type' => 'credential.imported',
				'new_state' => array( 'issuance_state' => States::ISSUANCE_ISSUED, 'administrative_state' => $admin ),
				'reason_code' => 'csv_import',
				'meta' => array( 'import_row' => $row_number, 'source_ref' => (string) ( $version['source_ref'] ?? '' ) ),
			) );
			if ( is_wp_error( $event ) ) { return $event; }
			return array( 'outcome' => 'created', 'recipient_id' => (int) $recipient_id, 'series_id' => (int) $series_id, 'version_id' => (int) $version_id );
		} );
	}

	/**
	 * Builds an immutable correction Version without interpreting omitted CSV
	 * columns as destructive clears. The imported row owns only fields it
	 * explicitly provided; all other issuance/date facts are inherited from the
	 * previous immutable Version.
	 *
	 * @return array<string,mixed>
	 */
	private function correction_version( array $current, array $plan ) {
		$incoming = isset( $plan['version'] ) && is_array( $plan['version'] ) ? $plan['version'] : array();
		$provided = isset( $plan['provided_fields'] ) && is_array( $plan['provided_fields'] ) ? $plan['provided_fields'] : array();

		$version = $incoming;
		$version['previous_version_id'] = ! empty( $current['id'] ) ? absint( $current['id'] ) : null;
		if ( empty( $provided['issue_date'] ) ) {
			$version['issued_at'] = $current['issued_at'] ?? null;
		}
		if ( empty( $provided['expiry_date'] ) ) {
			$version['expires_at'] = $current['expires_at'] ?? null;
		}
		if ( empty( $provided['status'] ) ) {
			$version['legacy_expired'] = ! empty( $current['legacy_expired'] );
		}
		$version['valid_from'] = $current['valid_from'] ?? ( $incoming['valid_from'] ?? null );
		$version['grade'] = $current['grade'] ?? ( $incoming['grade'] ?? null );

		$current_snapshot = isset( $current['snapshot'] ) && is_array( $current['snapshot'] ) ? $current['snapshot'] : array();
		$incoming_snapshot = isset( $incoming['snapshot'] ) && is_array( $incoming['snapshot'] ) ? $incoming['snapshot'] : array();
		if ( empty( $provided['custom_fields'] ) && array_key_exists( 'custom_fields', $current_snapshot ) ) {
			$incoming_snapshot['custom_fields'] = $current_snapshot['custom_fields'];
		}
		$version['snapshot'] = array_merge( $current_snapshot, $incoming_snapshot );

		return $version;
	}

}
