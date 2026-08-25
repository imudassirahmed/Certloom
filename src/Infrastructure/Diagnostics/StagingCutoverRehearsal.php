<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Migration\LegacyRegistryWriteGuard;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Migration\DomainCutoverCoordinator;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;
use Certloom\Infrastructure\Verification\PublicVerificationGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staging/local rehearsal of the final legacy -> Credential Series authority switch.
 *
 * The rehearsal deliberately uses the real production cutover coordinator and
 * public-verification gate, but injects a staging-only readiness policy so the
 * three production feature constants can remain false in the shipped plugin.
 * It then rolls public verification and authority back to legacy and requires
 * the complete future-domain graph to fingerprint identically before/after.
 */
final class StagingCutoverRehearsal {
	const RESULT_OPTION  = 'certloom_staging_cutover_rehearsal';
	const LOCK_OPTION    = 'certloom_staging_cutover_rehearsal_lock';
	const RESULT_SCHEMA  = 'certloom-staging-cutover-rehearsal';
	const RESULT_VERSION = 2;
	const MAX_AGE        = 7 * DAY_IN_SECONDS;
	const LOCK_TTL       = 2 * MINUTE_IN_SECONDS;
	const MAX_SECONDS    = 4.0;
	const BATCH_SIZE     = 200;

	/** @return bool */
	public static function environment_allowed() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'production';
		return in_array( $environment, array( 'local', 'development', 'staging' ), true );
	}

	/** @return bool */
	public static function explicitly_enabled() {
		return defined( 'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL' ) && true === CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL;
	}

	/** @return bool */
	public static function available() {
		return self::environment_allowed() && self::explicitly_enabled();
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function run_slice() {
		$allowed = self::assert_environment();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$owner = self::acquire_execution_lock();
		if ( is_wp_error( $owner ) ) { return $owner; }
		try {
			$result = self::execute_slice();
		} catch ( \Throwable $error ) {
			$cleanup = self::recover_rehearsal_authority();
			$message = __( 'The staging cutover rehearsal encountered an unexpected error and stopped safely.', 'certloom' );
			if ( is_wp_error( $cleanup ) ) {
				$message .= ' ' . $cleanup->get_error_message();
			}
			$unexpected = new \WP_Error( 'certloom_staging_cutover_unexpected', $message );
			$state = self::get_raw_result();
			$result = is_array( $state ) ? self::fail_state_from_error( $state, $unexpected ) : $unexpected;
		}
		$released = self::release_execution_lock( $owner );
		if ( is_wp_error( $released ) ) {
			$cleanup = new \WP_Error( 'certloom_staging_cutover_execution_cleanup_failed', __( 'Certloom could not release the staging cutover-rehearsal execution lock safely after the slice.', 'certloom' ) );
			if ( is_array( $result ) ) { return self::fail_state_from_error( $result, $cleanup ); }
			return $cleanup;
		}
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function execute_slice() {
		$state = self::get_raw_result();
		if ( is_array( $state ) && 'running' === sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$recovered = self::recover_rehearsal_authority();
			if ( is_wp_error( $recovered ) ) { return self::fail_state_from_error( $state, $recovered ); }
		}
		if ( ! is_array( $state ) || 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$state = self::start_state();
			if ( is_wp_error( $state ) ) { return $state; }
		}
		$common = self::assert_common_preconditions( $state );
		if ( is_wp_error( $common ) ) { return self::fail_state_from_error( $state, $common ); }

		$started = microtime( true );
		while ( ( microtime( true ) - $started ) < self::MAX_SECONDS ) {
			$phase = sanitize_key( (string) ( $state['phase'] ?? 'baseline_fingerprint' ) );
			if ( 'baseline_fingerprint' === $phase ) {
				$step = self::advance_fingerprint( $state, 'baseline' );
			} elseif ( 'cutover_cycle' === $phase ) {
				$step = self::advance_cutover_cycle( $state );
			} elseif ( 'final_fingerprint' === $phase ) {
				$step = self::advance_fingerprint( $state, 'final' );
			} elseif ( 'complete' === $phase ) {
				return $state;
			} else {
				return self::fail_state( $state, 'invalid_phase', __( 'Certloom found invalid staging cutover-rehearsal state.', 'certloom' ) );
			}
			if ( is_wp_error( $step ) ) { return self::fail_state_from_error( $state, $step ); }
			$state = $step;
			if ( in_array( sanitize_key( (string) ( $state['status'] ?? '' ) ), array( 'passed', 'failed' ), true ) ) { return $state; }
		}
		return $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function start_state() {
		$initial = self::assert_initial_preconditions();
		if ( is_wp_error( $initial ) ) { return $initial; }
		$runtime_payload = RuntimePayloadEvidence::current();
		if ( ! is_array( $runtime_payload ) ) { return new \WP_Error( 'certloom_staging_cutover_runtime_payload_unavailable', __( 'Certloom could not fingerprint the exact installed plugin payload for staging cutover evidence.', 'certloom' ) ); }
		$restore = StagingRestoreRehearsal::get_last_result();
		$source = ( new LegacyCertificateRepository() )->snapshot();
		if ( ! is_array( $restore ) || is_wp_error( $source ) ) {
			return is_wp_error( $source ) ? $source : new \WP_Error( 'certloom_staging_cutover_restore_evidence_missing', __( 'A fresh passing staging restore rehearsal is required before cutover rehearsal.', 'certloom' ) );
		}
		$state = array(
			'schema'                 => self::RESULT_SCHEMA,
			'version'                => self::RESULT_VERSION,
			'plugin_version'         => defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '',
			'runtime_payload'        => $runtime_payload,
			'environment'            => sanitize_key( (string) wp_get_environment_type() ),
			'status'                 => 'running',
			'phase'                  => 'baseline_fingerprint',
			'reason_code'            => '',
			'started_at'             => time(),
			'updated_at'             => time(),
			'checked_at'             => 0,
			'source_count'           => absint( $source['count'] ?? 0 ),
			'source_max_id'          => absint( $source['max_id'] ?? 0 ),
			'source_generation'      => (string) ( $source['generation'] ?? '' ),
			'restore_checked_at'     => absint( $restore['checked_at'] ?? 0 ),
			'baseline_hash'          => '',
			'baseline_config_hash'   => '',
			'baseline_records'       => 0,
			'baseline_counts'        => array(),
			'receipt_verified'       => false,
			'legacy_guard_blocked'   => false,
			'public_gate_verified'   => false,
			'rollback_verified'      => false,
			'final_verified'         => false,
			'fingerprint_state'      => null,
			'error_message'          => '',
		);
		if ( '' === $state['plugin_version'] || '' === $state['source_generation'] ) {
			return new \WP_Error( 'certloom_staging_cutover_evidence_invalid', __( 'Certloom could not bind the cutover rehearsal to a complete source/version boundary.', 'certloom' ) );
		}
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_fingerprint( array $state, $label ) {
		$fingerprint = new DomainStateFingerprint();
		$current = isset( $state['fingerprint_state'] ) && is_array( $state['fingerprint_state'] ) ? $state['fingerprint_state'] : null;
		if ( ! is_array( $current ) ) {
			$owner = self::random_token( 16 );
			if ( is_wp_error( $owner ) ) { return $owner; }
			$current = $fingerprint->start( $owner );
			if ( is_wp_error( $current ) ) { return $current; }
		}
		$current = $fingerprint->continue_fingerprint( $current, self::BATCH_SIZE );
		if ( is_wp_error( $current ) ) { return $current; }
		$state['fingerprint_state'] = $current;
		if ( empty( $current['complete'] ) ) { return self::persist( $state ); }
		$hash = (string) ( $current['hash'] ?? '' );
		$config = (string) ( $current['configuration_hash'] ?? '' );
		$records = absint( $current['records'] ?? 0 );
		$counts = isset( $current['entity_counts'] ) && is_array( $current['entity_counts'] ) ? $current['entity_counts'] : array();
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $config ) || $records < 1 ) {
			return new \WP_Error( 'certloom_staging_cutover_fingerprint_invalid', __( 'The staging cutover rehearsal could not produce a complete populated domain fingerprint.', 'certloom' ) );
		}
		if ( 'baseline' === $label ) {
			$state['baseline_hash'] = $hash;
			$state['baseline_config_hash'] = $config;
			$state['baseline_records'] = $records;
			$state['baseline_counts'] = $counts;
			$state['phase'] = 'cutover_cycle';
		} else {
			if ( ! hash_equals( (string) $state['baseline_hash'], $hash ) || ! hash_equals( (string) $state['baseline_config_hash'], $config ) || absint( $state['baseline_records'] ) !== $records || (array) $state['baseline_counts'] !== $counts ) {
				return new \WP_Error( 'certloom_staging_cutover_graph_changed', __( 'The future credential graph changed during the authority cutover rehearsal.', 'certloom' ) );
			}
			$state['final_verified'] = true;
			$state['phase'] = 'complete';
			$state['status'] = 'passed';
			$state['checked_at'] = time();
		}
		$state['fingerprint_state'] = null;
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_cutover_cycle( array $state ) {
		if ( ( new DomainMaintenanceLock() )->active() ) {
			return new \WP_Error( 'certloom_staging_cutover_maintenance_active', __( 'Another protected credential-domain maintenance operation is active before the cutover cycle.', 'certloom' ) );
		}
		$source = ( new LegacyCertificateRepository() )->snapshot();
		if ( is_wp_error( $source ) || ! self::same_source_boundary( $state, $source ) ) {
			return is_wp_error( $source ) ? $source : new \WP_Error( 'certloom_staging_cutover_source_changed', __( 'The legacy registry changed before the cutover rehearsal could run.', 'certloom' ) );
		}

		$cutover = new DomainCutoverState();
		$gate = new PublicVerificationGate();
		$coordinator = self::rehearsal_coordinator();
		$error = null;
		try {
			$promoted = $coordinator->promote();
			if ( is_wp_error( $promoted ) ) { $error = $promoted; }
			if ( ! $error && ( ! $cutover->is_domain_authoritative() || ! $cutover->receipt_matches_completed_migration( new LegacyMigrationState() ) ) ) {
				$error = new \WP_Error( 'certloom_staging_cutover_receipt_invalid', __( 'The real cutover coordinator did not leave a committed receipt matching the completed migration.', 'certloom' ) );
			}
			if ( ! $error && ( new DomainMaintenanceLock() )->active() ) {
				$error = new \WP_Error( 'certloom_staging_cutover_maintenance_stuck', __( 'The real cutover coordinator left the shared maintenance boundary active unexpectedly.', 'certloom' ) );
			}
			if ( ! $error && false !== get_option( DomainCutoverCoordinator::OWNER_OPTION, false ) ) {
				$error = new \WP_Error( 'certloom_staging_cutover_owner_stuck', __( 'The real cutover coordinator left its recovery owner after a successful authority switch.', 'certloom' ) );
			}
			if ( ! $error ) {
				$blocked = ( new LegacyRegistryWriteGuard() )->assert_allowed();
				if ( ! is_wp_error( $blocked ) || 'certloom_legacy_registry_read_only' !== $blocked->get_error_code() ) {
					$error = new \WP_Error( 'certloom_staging_cutover_legacy_guard_failed', __( 'The legacy registry did not become read-only after the rehearsal authority switch.', 'certloom' ) );
				} else {
					$state['legacy_guard_blocked'] = true;
				}
			}
			if ( ! $error ) {
				$enabled = $gate->enable();
				if ( is_wp_error( $enabled ) || true !== $enabled || ! $gate->is_ready() ) {
					$error = is_wp_error( $enabled ) ? $enabled : new \WP_Error( 'certloom_staging_cutover_public_gate_failed', __( 'Series public verification did not become ready after the official cutover receipt was committed.', 'certloom' ) );
				} else {
					$state['public_gate_verified'] = true;
				}
			}
			if ( ! $error ) { $state['receipt_verified'] = true; }
		} catch ( \Throwable $exception ) {
			$error = new \WP_Error( 'certloom_staging_cutover_cycle_unexpected', __( 'The authority cutover cycle encountered an unexpected error.', 'certloom' ) );
		}

		$cleanup = self::restore_legacy_authority();
		if ( is_wp_error( $cleanup ) ) {
			return new \WP_Error(
				'certloom_staging_cutover_cleanup_failed',
				sprintf(
					/* translators: 1: cutover-cycle result, 2: cleanup error. */
					__( 'The staging cutover cycle could not restore its legacy/public authority baseline. Cutover result: %1$s Cleanup error: %2$s', 'certloom' ),
					$error ? $error->get_error_message() : __( 'cutover checks completed', 'certloom' ),
					$cleanup->get_error_message()
				)
			);
		}
		if ( $error ) { return $error; }
		$state['rollback_verified'] = true;
		$state['phase'] = 'final_fingerprint';
		return self::persist( $state );
	}

	/** @return true|\WP_Error */
	private static function restore_legacy_authority() {
		$gate = new PublicVerificationGate();
		if ( ! $gate->disable() && false !== get_option( PublicVerificationGate::OPTION, false ) ) {
			return new \WP_Error( 'certloom_staging_cutover_public_gate_cleanup_failed', __( 'Certloom could not disable the staging Series-verification marker during cutover cleanup.', 'certloom' ) );
		}

		/* If a previous promote committed but could not release maintenance, retry its owner-safe cleanup first. */
		if ( false !== get_option( DomainCutoverCoordinator::OWNER_OPTION, false ) ) {
			$recovered = self::rehearsal_coordinator()->promote();
			if ( is_wp_error( $recovered ) ) { return $recovered; }
		}

		$cutover = new DomainCutoverState();
		if ( ! $cutover->is_clean_legacy_state() ) {
			$rolled_back = $cutover->keep_legacy_authoritative();
			if ( is_wp_error( $rolled_back ) ) { return $rolled_back; }
		}
		if ( ! $cutover->is_clean_legacy_state() || ( new DomainMaintenanceLock() )->active() || false !== get_option( DomainCutoverCoordinator::OWNER_OPTION, false ) || $gate->is_ready() ) {
			return new \WP_Error( 'certloom_staging_cutover_cleanup_unconfirmed', __( 'Certloom could not confirm that staging cutover cleanup returned every authority/maintenance marker to the legacy baseline.', 'certloom' ) );
		}
		$allowed = ( new LegacyRegistryWriteGuard() )->assert_allowed();
		return is_wp_error( $allowed ) ? $allowed : true;
	}

	/** @return true|\WP_Error */
	private static function recover_rehearsal_authority() {
		$cutover = new DomainCutoverState();
		$gate_on = false !== get_option( PublicVerificationGate::OPTION, false );
		$cutover_state = ! $cutover->is_clean_legacy_state() || false !== get_option( DomainCutoverCoordinator::OWNER_OPTION, false );
		return ( $gate_on || $cutover_state ) ? self::restore_legacy_authority() : true;
	}

	/** @return DomainCutoverCoordinator */
	private static function rehearsal_coordinator() {
		return new DomainCutoverCoordinator( null, null, null, null, null, static function () { return true; } );
	}

	/** @return true|\WP_Error */
	private static function assert_initial_preconditions() {
		$allowed = self::assert_environment();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( ! StagingRestoreRehearsal::is_current_passed() ) {
			return new \WP_Error( 'certloom_staging_cutover_restore_proof_required', __( 'Run a fresh passing staging populated backup/restore rehearsal before starting cutover rehearsal.', 'certloom' ) );
		}
		return self::assert_common_preconditions( array() );
	}

	/** @return true|\WP_Error */
	private static function assert_common_preconditions( array $state ) {
		$allowed = self::assert_environment();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( ! empty( $state ) ) {
			$current_version = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
			$started_version = (string) ( $state['plugin_version'] ?? '' );
			if ( '' === $current_version || '' === $started_version || ! hash_equals( $started_version, $current_version ) ) {
				return new \WP_Error( 'certloom_staging_cutover_version_changed', __( 'Certloom changed version after the staging cutover rehearsal started. Reclone/restore staging and run the rehearsal with one fixed Certloom version.', 'certloom' ) );
			}
			if ( ! RuntimePayloadEvidence::matches_current( $state['runtime_payload'] ?? null ) ) {
				return new \WP_Error( 'certloom_staging_cutover_runtime_payload_changed', __( 'Certloom production bytes changed after the staging cutover rehearsal started. Reclone/restore staging and rerun the rehearsal with one exact plugin payload.', 'certloom' ) );
			}
		}
		if ( ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY ) || ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) || ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY ) ) {
			return new \WP_Error( 'certloom_staging_cutover_feature_gate_active', __( 'The staging cutover rehearsal is available only while all production future-domain feature constants remain disabled.', 'certloom' ) );
		}
		if ( LegacyMigrationState::COMPLETE !== ( new LegacyMigrationState() )->status() ) {
			return new \WP_Error( 'certloom_staging_cutover_migration_incomplete', __( 'The staging cutover rehearsal requires a completed official populated migration.', 'certloom' ) );
		}
		$cutover = new DomainCutoverState();
		if ( ! $cutover->is_clean_legacy_state() || false !== get_option( PublicVerificationGate::OPTION, false ) || false !== get_option( DomainCutoverCoordinator::OWNER_OPTION, false ) ) {
			return new \WP_Error( 'certloom_staging_cutover_baseline_not_legacy', __( 'The staging cutover rehearsal requires clean legacy authority with no public-Series or cutover-recovery marker. Resolve/reclone staging before continuing.', 'certloom' ) );
		}
		if ( ! empty( $state ) ) {
			$current = ( new LegacyCertificateRepository() )->snapshot();
			if ( is_wp_error( $current ) || ! self::same_source_boundary( $state, $current ) ) {
				return is_wp_error( $current ) ? $current : new \WP_Error( 'certloom_staging_cutover_source_changed', __( 'The legacy source changed after the staging cutover rehearsal started.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_environment() {
		if ( ! self::environment_allowed() ) { return new \WP_Error( 'certloom_staging_cutover_environment', __( 'The final cutover rehearsal is available only when WordPress environment type is local, development, or staging.', 'certloom' ) ); }
		if ( ! self::explicitly_enabled() ) { return new \WP_Error( 'certloom_staging_cutover_opt_in', __( 'The staging cutover rehearsal requires the explicit CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL opt-in constant.', 'certloom' ) ); }
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get_last_result() {
		$result = self::get_raw_result();
		if ( ! is_array( $result ) || self::RESULT_SCHEMA !== (string) ( $result['schema'] ?? '' ) || self::RESULT_VERSION !== (int) ( $result['version'] ?? 0 ) ) { return null; }
		if ( ! is_array( RuntimePayloadEvidence::normalize( $result['runtime_payload'] ?? null ) ) ) { return null; }
		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		$phase = sanitize_key( (string) ( $result['phase'] ?? '' ) );
		if ( ! in_array( $status, array( 'running', 'passed', 'failed' ), true ) || ! in_array( $phase, array( 'baseline_fingerprint', 'cutover_cycle', 'final_fingerprint', 'complete' ), true ) ) { return null; }
		if ( '' === (string) ( $result['plugin_version'] ?? '' ) || ! in_array( sanitize_key( (string) ( $result['environment'] ?? '' ) ), array( 'local', 'development', 'staging' ), true ) || ! absint( $result['started_at'] ?? 0 ) || ! absint( $result['updated_at'] ?? 0 ) || '' === (string) ( $result['source_generation'] ?? '' ) ) { return null; }
		if ( 'passed' === $status ) {
			if ( 'complete' !== $phase || ! absint( $result['checked_at'] ?? 0 ) || empty( $result['receipt_verified'] ) || empty( $result['legacy_guard_blocked'] ) || empty( $result['public_gate_verified'] ) || empty( $result['rollback_verified'] ) || empty( $result['final_verified'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['baseline_hash'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['baseline_config_hash'] ?? '' ) ) || absint( $result['baseline_records'] ?? 0 ) < 1 || ! is_array( $result['baseline_counts'] ?? null ) ) { return null; }
		}
		return $result;
	}

	/** @return string passed|failed|running|stale|not_run|unavailable */
	public static function evidence_state() {
		if ( ! self::available() ) { return 'unavailable'; }
		$result = self::get_last_result();
		if ( ! is_array( $result ) ) { return 'not_run'; }
		$status = sanitize_key( (string) $result['status'] );
		if ( 'running' === $status ) { return 'running'; }
		if ( 'failed' === $status ) { return 'failed'; }
		$current_version = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
		$checked_at = absint( $result['checked_at'] ?? 0 );
		$current = ( new LegacyCertificateRepository() )->snapshot();
		$cutover = new DomainCutoverState();
		$gates_off = ! ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY ) && ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) && ! ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY );
		if ( '' === $current_version || ! hash_equals( $current_version, (string) $result['plugin_version'] ) || ! RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ?? null ) || ! $checked_at || $checked_at > time() + 300 || time() - $checked_at > self::MAX_AGE || ! is_array( $current ) || ! self::same_source_boundary( $result, $current ) || LegacyMigrationState::COMPLETE !== ( new LegacyMigrationState() )->status() || ! StagingRestoreRehearsal::is_current_passed() || ! $gates_off || ! $cutover->is_clean_legacy_state() || false !== get_option( PublicVerificationGate::OPTION, false ) || false !== get_option( DomainCutoverCoordinator::OWNER_OPTION, false ) || ( new DomainMaintenanceLock() )->active() ) { return 'stale'; }
		return 'passed';
	}

	public static function is_current_passed() { return 'passed' === self::evidence_state(); }

	/** Privacy-safe: excludes credential values, IDs, URLs, table names and owner tokens. */
	public static function get_text_report( $result = null ) {
		$result = is_array( $result ) ? $result : self::get_last_result();
		if ( ! is_array( $result ) ) { return __( 'Certloom staging cutover rehearsal: not run.', 'certloom' ); }
		$lines = array(
			sprintf( 'Certloom staging cutover rehearsal: %s', strtoupper( sanitize_key( (string) ( $result['status'] ?? 'unknown' ) ) ) ),
			sprintf( 'Phase: %s', strtoupper( sanitize_key( (string) ( $result['phase'] ?? 'unknown' ) ) ) ),
			sprintf( 'Certloom version: %s', sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) ) ),
			sprintf( 'Certloom payload SHA-256: %s', sanitize_text_field( (string) ( $result['runtime_payload']['sha256'] ?? '' ) ) ),
			sprintf( 'WordPress environment type: %s', sanitize_key( (string) ( $result['environment'] ?? '' ) ) ),
			sprintf( 'Frozen legacy rows: %d', absint( $result['source_count'] ?? 0 ) ),
			sprintf( 'Future-domain records fingerprinted: %d', absint( $result['baseline_records'] ?? 0 ) ),
			sprintf( 'Committed receipt matched migration: %s', ! empty( $result['receipt_verified'] ) ? 'PASS' : 'NOT YET' ),
			sprintf( 'Legacy registry became read-only: %s', ! empty( $result['legacy_guard_blocked'] ) ? 'PASS' : 'NOT YET' ),
			sprintf( 'Series public verification gate became ready: %s', ! empty( $result['public_gate_verified'] ) ? 'PASS' : 'NOT YET' ),
			sprintf( 'Legacy/public authority restored after rehearsal: %s', ! empty( $result['rollback_verified'] ) ? 'PASS' : 'NOT YET' ),
			sprintf( 'Future-domain graph unchanged after cycle: %s', ! empty( $result['final_verified'] ) ? 'PASS' : 'NOT YET' ),
			'Production readiness constants changed: NO',
			'Final credential authority after rehearsal: LEGACY',
		);
		if ( ! empty( $result['checked_at'] ) ) { $lines[] = sprintf( 'Completed UTC: %s', gmdate( 'Y-m-d H:i:s', absint( $result['checked_at'] ) ) ); }
		if ( ! empty( $result['reason_code'] ) ) { $lines[] = sprintf( 'Result reason: %s', sanitize_key( (string) $result['reason_code'] ) ); }
		return implode( "\n", $lines );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function fail_state_from_error( array $state, \WP_Error $error ) { return self::fail_state( $state, $error->get_error_code(), $error->get_error_message() ); }

	/** @return array<string,mixed>|\WP_Error */
	private static function fail_state( array $state, $reason, $message = '' ) {
		$state['status'] = 'failed';
		$state['reason_code'] = sanitize_key( (string) $reason );
		$state['error_message'] = sanitize_text_field( (string) $message );
		$state['checked_at'] = time();
		$state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function persist( array $state ) {
		$state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return bool */
	private static function same_source_boundary( array $state, array $snapshot ) {
		return absint( $state['source_count'] ?? -1 ) === absint( $snapshot['count'] ?? -2 )
			&& absint( $state['source_max_id'] ?? -1 ) === absint( $snapshot['max_id'] ?? -2 )
			&& '' !== (string) ( $state['source_generation'] ?? '' )
			&& hash_equals( (string) $state['source_generation'], (string) ( $snapshot['generation'] ?? '' ) );
	}

	/** @return string|\WP_Error */
	private static function random_token( $bytes ) {
		try { return bin2hex( random_bytes( absint( $bytes ) ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_staging_cutover_random_failed', __( 'Certloom could not generate safe staging cutover-rehearsal ownership tokens.', 'certloom' ) ); }
	}

	/** @return array<string,mixed>|null */
	private static function get_raw_result() { $value = get_option( self::RESULT_OPTION, null ); return is_array( $value ) ? $value : null; }

	/** @return true|\WP_Error */
	private static function save_result( array $state ) {
		$saved = update_option( self::RESULT_OPTION, $state, false );
		return $saved || $state === get_option( self::RESULT_OPTION, null ) ? true : new \WP_Error( 'certloom_staging_cutover_result_save_failed', __( 'Certloom could not save staging cutover-rehearsal progress exactly.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private static function acquire_execution_lock() {
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) > time() ) { return new \WP_Error( 'certloom_staging_cutover_busy', __( 'Another staging cutover-rehearsal slice is already running.', 'certloom' ) ); }
		if ( null !== $existing && false !== $existing ) {
			$deleted = delete_option( self::LOCK_OPTION );
			if ( ! $deleted && false !== get_option( self::LOCK_OPTION, false ) ) { return new \WP_Error( 'certloom_staging_cutover_stale_lock', __( 'Certloom could not clear an expired staging cutover-rehearsal execution lock.', 'certloom' ) ); }
		}
		$owner = self::random_token( 24 );
		if ( is_wp_error( $owner ) ) { return $owner; }
		$value = array( 'token_hash' => hash( 'sha256', $owner ), 'expires_at' => time() + self::LOCK_TTL );
		if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) { return new \WP_Error( 'certloom_staging_cutover_lock_failed', __( 'Certloom could not acquire the staging cutover-rehearsal execution lock.', 'certloom' ) ); }
		return $owner;
	}

	/** @return true|\WP_Error */
	private static function release_execution_lock( $owner ) {
		$current = get_option( self::LOCK_OPTION, null );
		$expected = hash( 'sha256', (string) $owner );
		if ( ! is_array( $current ) || empty( $current['token_hash'] ) || ! hash_equals( (string) $current['token_hash'], $expected ) ) { return new \WP_Error( 'certloom_staging_cutover_lock_owner_mismatch', __( 'Certloom refused to release a staging cutover-rehearsal lock owned by another process.', 'certloom' ) ); }
		$deleted = delete_option( self::LOCK_OPTION );
		return $deleted || false === get_option( self::LOCK_OPTION, false ) ? true : new \WP_Error( 'certloom_staging_cutover_lock_release_failed', __( 'Certloom could not release the staging cutover-rehearsal execution lock safely.', 'certloom' ) );
	}
}
