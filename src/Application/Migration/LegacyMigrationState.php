<?php
namespace Certloom\Application\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Durable migration/cutover state for the in-place 1.5 registry migration.
 *
 * The state is intentionally separate from the legacy database schema marker.
 * Public Series verification is never enabled merely because tables exist.
 */
final class LegacyMigrationState {
	const OPTION = 'certloom_legacy_domain_migration_state';

	const NOT_STARTED          = 'not_started';
	const PREFLIGHTING         = 'preflighting';
	const PREFLIGHT_VERIFYING  = 'preflight_verifying';
	const PREFLIGHT_READY      = 'preflight_ready';
	const MIGRATING            = 'migrating';
	const COMPLETE             = 'complete';
	const BLOCKED              = 'blocked';

	/** @return array<string,mixed> */
	public function get() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** @return string */
	public function status() {
		$state = $this->get();
		$status = isset( $state['status'] ) ? (string) $state['status'] : self::NOT_STARTED;
		return in_array( $status, self::statuses(), true ) ? $status : self::BLOCKED;
	}

	/** @return bool */
	public function is_complete() {
		return self::COMPLETE === $this->status();
	}

	/**
	 * Confirms the completed migration still describes the current legacy source.
	 * A post-migration legacy edit/import/restore changes the generation marker and
	 * therefore blocks authority cutover until a fresh preflight/commit is run.
	 *
	 * @param array<string,mixed> $snapshot Current legacy source snapshot.
	 * @return bool
	 */
	public function source_snapshot_matches( array $snapshot ) {
		$state = $this->get();
		if ( self::COMPLETE !== (string) ( $state['status'] ?? '' ) ) {
			return false;
		}
		return absint( $state['source_count'] ?? -1 ) === absint( $snapshot['count'] ?? -2 )
			&& absint( $state['source_max_id'] ?? -1 ) === absint( $snapshot['max_id'] ?? -2 )
			&& '' !== (string) ( $state['source_generation'] ?? '' )
			&& hash_equals( (string) $state['source_generation'], (string) ( $snapshot['generation'] ?? '' ) );
	}

	/** Starts a resumable two-pass preflight against an immutable legacy source boundary. @return true|\WP_Error */
	public function begin_preflight( array $snapshot, $today ) {
		$fingerprint = ( new LegacySourceFingerprint() )->seed();
		$value = array(
			'status'                 => self::PREFLIGHTING,
			'source_count'           => absint( $snapshot['count'] ?? 0 ),
			'source_max_id'          => absint( $snapshot['max_id'] ?? 0 ),
			'source_generation'      => (string) ( $snapshot['generation'] ?? '' ),
			'preflight_date'         => (string) $today,
			'preflight_cursor'       => 0,
			'preflight_total'        => 0,
			'preflight_ready'        => 0,
			'preflight_errors'       => 0,
			'preflight_fingerprint'  => $fingerprint,
			'verify_cursor'          => 0,
			'verify_total'           => 0,
			'verify_fingerprint'     => $fingerprint,
			'source_fingerprint'     => '',
			'status_counts'          => array( 'valid' => 0, 'suspended' => 0, 'revoked' => 0, 'expired' => 0 ),
			'error_details'          => array(),
			'cursor'                 => 0,
			'migrated'               => 0,
			'skipped'                => 0,
			'failed'                 => 0,
			'updated_at'             => time(),
		);
		return $this->save( $value );
	}

	/** @return true|\WP_Error */
	public function record_preflight_batch( $cursor, array $summary, $fingerprint = null, $error_limit = 100 ) {
		$value = $this->get();
		if ( self::PREFLIGHTING !== (string) ( $value['status'] ?? '' ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_not_running', __( 'Legacy migration preflight is not running.', 'certloom' ) );
		}
		if ( null !== $fingerprint ) {
			$fingerprint = strtolower( trim( (string) $fingerprint ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
				return new \WP_Error( 'certloom_legacy_preflight_fingerprint_invalid', __( 'Certloom refused invalid official migration-preflight fingerprint state.', 'certloom' ) );
			}
			$value['preflight_fingerprint'] = $fingerprint;
		}
		$value['preflight_cursor'] = absint( $cursor );
		$value['preflight_total'] = absint( $value['preflight_total'] ?? 0 ) + absint( $summary['total'] ?? 0 );
		$value['preflight_ready'] = absint( $value['preflight_ready'] ?? 0 ) + absint( $summary['ready'] ?? 0 );
		$value['preflight_errors'] = absint( $value['preflight_errors'] ?? 0 ) + absint( $summary['errors'] ?? 0 );
		$counts = isset( $value['status_counts'] ) && is_array( $value['status_counts'] ) ? $value['status_counts'] : array();
		foreach ( array( 'valid', 'suspended', 'revoked', 'expired' ) as $code ) {
			$counts[ $code ] = absint( $counts[ $code ] ?? 0 ) + absint( $summary['status_counts'][ $code ] ?? 0 );
		}
		$value['status_counts'] = $counts;
		$details = isset( $value['error_details'] ) && is_array( $value['error_details'] ) ? $value['error_details'] : array();
		foreach ( (array) ( $summary['error_details'] ?? array() ) as $detail ) {
			if ( count( $details ) >= max( 1, (int) $error_limit ) ) { break; }
			$details[] = $detail;
		}
		$value['error_details'] = $details;
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** Moves a successful first preflight pass into the mandatory fingerprint-verification pass. @return true|\WP_Error */
	public function begin_preflight_verification() {
		$value = $this->get();
		if ( self::PREFLIGHTING !== (string) ( $value['status'] ?? '' ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_not_running', __( 'Legacy migration preflight is not running.', 'certloom' ) );
		}
		$complete_count = absint( $value['preflight_total'] ?? 0 ) === absint( $value['source_count'] ?? -1 );
		$no_errors = 0 === absint( $value['preflight_errors'] ?? 0 );
		$at_boundary = absint( $value['preflight_cursor'] ?? 0 ) >= absint( $value['source_max_id'] ?? 0 ) || 0 === absint( $value['source_count'] ?? 0 );
		if ( ! $complete_count || ! $no_errors || ! $at_boundary || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $value['preflight_fingerprint'] ?? '' ) ) ) {
			$value['status'] = self::BLOCKED;
			$value['reason_code'] = $no_errors ? 'source_boundary_changed' : 'preflight_errors';
			$value['updated_at'] = time();
			return $this->save( $value );
		}
		$value['status'] = self::PREFLIGHT_VERIFYING;
		$value['verify_cursor'] = 0;
		$value['verify_total'] = 0;
		$value['verify_fingerprint'] = ( new LegacySourceFingerprint() )->seed();
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** @return true|\WP_Error */
	public function record_preflight_verification_batch( $cursor, $row_count, $fingerprint ) {
		$value = $this->get();
		if ( self::PREFLIGHT_VERIFYING !== (string) ( $value['status'] ?? '' ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_verify_not_running', __( 'Legacy migration preflight verification is not running.', 'certloom' ) );
		}
		$fingerprint = strtolower( trim( (string) $fingerprint ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_verify_fingerprint_invalid', __( 'Certloom refused invalid official preflight verification fingerprint state.', 'certloom' ) );
		}
		$value['verify_cursor'] = absint( $cursor );
		$value['verify_total'] = absint( $value['verify_total'] ?? 0 ) + absint( $row_count );
		$value['verify_fingerprint'] = $fingerprint;
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** Finalizes official preflight only after both complete source reads fingerprint identically. @return true|\WP_Error */
	public function finish_preflight_verification() {
		$value = $this->get();
		if ( self::PREFLIGHT_VERIFYING !== (string) ( $value['status'] ?? '' ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_verify_not_running', __( 'Legacy migration preflight verification is not running.', 'certloom' ) );
		}
		$complete = absint( $value['verify_total'] ?? 0 ) === absint( $value['source_count'] ?? -1 )
			&& ( absint( $value['verify_cursor'] ?? 0 ) >= absint( $value['source_max_id'] ?? 0 ) || 0 === absint( $value['source_count'] ?? 0 ) );
		$map = strtolower( (string) ( $value['preflight_fingerprint'] ?? '' ) );
		$verify = strtolower( (string) ( $value['verify_fingerprint'] ?? '' ) );
		if ( ! $complete || ! preg_match( '/^[a-f0-9]{64}$/', $map ) || ! preg_match( '/^[a-f0-9]{64}$/', $verify ) || ! hash_equals( $map, $verify ) ) {
			$value['status'] = self::BLOCKED;
			$value['reason_code'] = $complete ? 'semantic_fingerprint_changed' : 'verification_count_mismatch';
			$value['updated_at'] = time();
			return $this->save( $value );
		}
		$value['status'] = self::PREFLIGHT_READY;
		$value['source_fingerprint'] = $map;
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** Backward-compatible alias: first pass now proceeds to a required second verification pass. @return true|\WP_Error */
	public function finish_preflight() {
		return $this->begin_preflight_verification();
	}

	/** @return true|\WP_Error */
	public function begin_commit() {
		$value = $this->get();
		if ( self::PREFLIGHT_READY !== (string) ( $value['status'] ?? '' ) && self::MIGRATING !== (string) ( $value['status'] ?? '' ) ) {
			return new \WP_Error( 'certloom_legacy_migration_not_ready', __( 'Legacy migration has not passed both complete preflight source reads.', 'certloom' ) );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $value['source_fingerprint'] ?? '' ) ) ) {
			return new \WP_Error( 'certloom_legacy_migration_fingerprint_missing', __( 'Legacy migration cannot begin without a verified source fingerprint.', 'certloom' ) );
		}
		$value['status'] = self::MIGRATING;
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** @return true|\WP_Error */
	public function record_progress( $cursor, $migrated, $skipped, $failed ) {
		$value = $this->get();
		if ( self::MIGRATING !== (string) ( $value['status'] ?? '' ) ) {
			return new \WP_Error( 'certloom_legacy_migration_not_running', __( 'Legacy migration progress cannot be saved because the migration is not running.', 'certloom' ) );
		}
		$value['cursor'] = absint( $cursor );
		$value['migrated'] = absint( $migrated );
		$value['skipped'] = absint( $skipped );
		$value['failed'] = absint( $failed );
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** @return true|\WP_Error */
	public function mark_complete() {
		$value = $this->get();
		if ( self::MIGRATING !== (string) ( $value['status'] ?? '' ) || ! empty( $value['failed'] ) ) {
			return new \WP_Error( 'certloom_legacy_migration_incomplete', __( 'Legacy migration cannot be completed while failures remain.', 'certloom' ) );
		}
		if ( absint( $value['migrated'] ?? 0 ) + absint( $value['skipped'] ?? 0 ) !== absint( $value['source_count'] ?? -1 ) ) {
			return new \WP_Error( 'certloom_legacy_migration_count_mismatch', __( 'Legacy migration cannot be completed because its target coverage does not match the frozen source boundary.', 'certloom' ) );
		}
		$value['status'] = self::COMPLETE;
		$value['completed_at'] = time();
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** @return true|\WP_Error */
	public function block( $reason_code ) {
		$value = $this->get();
		$value['status'] = self::BLOCKED;
		$value['reason_code'] = sanitize_key( (string) $reason_code );
		$value['updated_at'] = time();
		return $this->save( $value );
	}

	/** @return array<int,string> */
	private static function statuses() {
		return array( self::NOT_STARTED, self::PREFLIGHTING, self::PREFLIGHT_VERIFYING, self::PREFLIGHT_READY, self::MIGRATING, self::COMPLETE, self::BLOCKED );
	}

	/** @return true|\WP_Error */
	private function save( array $value ) {
		$saved = update_option( self::OPTION, $value, false );
		return $saved || $value === get_option( self::OPTION, array() )
			? true
			: new \WP_Error( 'certloom_legacy_migration_state_save_failed', __( 'Certloom could not save legacy migration progress.', 'certloom' ) );
	}
}
