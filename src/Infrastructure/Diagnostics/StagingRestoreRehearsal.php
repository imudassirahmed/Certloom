<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\Backup\DomainBackupBuilder;
use Certloom\Application\Backup\DomainBackupReader;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Restore\DomainRestoreStagingService;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;
use Certloom\Infrastructure\Restore\DomainRestoreMergeService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staging/local rehearsal of complete-domain backup, Replace, Merge and rollback.
 *
 * This intentionally operates on the populated future-domain graph created by the
 * staging migration rehearsal. It never changes credential authority. Every
 * successful restore path must reproduce the same deterministic domain-state
 * fingerprint, while the forced post-swap configuration fault must exercise the
 * production rollback path and also return to the same graph.
 */
final class StagingRestoreRehearsal {
	const RESULT_OPTION  = 'certloom_staging_restore_rehearsal';
	const LOCK_OPTION    = 'certloom_staging_restore_rehearsal_lock';
	const RESULT_SCHEMA  = 'certloom-staging-restore-rehearsal';
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
		return defined( 'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL' ) && true === CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL;
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
			$unexpected = new \WP_Error( 'certloom_staging_restore_unexpected', __( 'The staging restore rehearsal encountered an unexpected error and stopped safely.', 'certloom' ) );
			$state = self::get_raw_result();
			$result = is_array( $state ) ? self::fail_state_from_error( $state, $unexpected ) : $unexpected;
		}
		$released = self::release_execution_lock( $owner );
		if ( is_wp_error( $released ) ) {
			$cleanup = new \WP_Error( 'certloom_staging_restore_execution_cleanup_failed', __( 'Certloom could not release the staging rehearsal execution lock safely after the slice.', 'certloom' ) );
			if ( is_array( $result ) ) { return self::fail_state_from_error( $result, $cleanup ); }
			return $cleanup;
		}
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function execute_slice() {
		$state = self::get_raw_result();
		if ( ! is_array( $state ) || 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$state = self::start_state();
			if ( is_wp_error( $state ) ) { return $state; }
		}
		$common = self::assert_common_preconditions( $state );
		if ( is_wp_error( $common ) ) { return self::fail_state_from_error( $state, $common ); }

		$started = microtime( true );
		while ( ( microtime( true ) - $started ) < self::MAX_SECONDS ) {
			$phase = sanitize_key( (string) ( $state['phase'] ?? 'baseline_fingerprint' ) );
			switch ( $phase ) {
				case 'baseline_fingerprint': $step = self::advance_fingerprint( $state, 'baseline' ); break;
				case 'backup':               $step = self::advance_backup( $state ); break;
				case 'replace_stage':        $step = self::advance_stage( $state, 'replace' ); break;
				case 'replace_commit':       $step = self::advance_replace_commit( $state ); break;
				case 'replace_fingerprint':  $step = self::advance_fingerprint( $state, 'replace' ); break;
				case 'merge_stage':          $step = self::advance_stage( $state, 'merge' ); break;
				case 'merge_build':          $step = self::advance_merge_build( $state ); break;
				case 'merge_commit':         $step = self::advance_merge_commit( $state ); break;
				case 'merge_fingerprint':    $step = self::advance_fingerprint( $state, 'merge' ); break;
				case 'rollback_stage':       $step = self::advance_stage( $state, 'rollback' ); break;
				case 'rollback_fault_commit':$step = self::advance_rollback_fault_commit( $state ); break;
				case 'rollback_fingerprint': $step = self::advance_fingerprint( $state, 'rollback' ); break;
				case 'cleanup':              $step = self::advance_cleanup( $state ); break;
				case 'complete':             return $state;
				default: return self::fail_state( $state, 'invalid_phase', __( 'Certloom found invalid staging restore-rehearsal state.', 'certloom' ) );
			}
			if ( is_wp_error( $step ) ) { return self::fail_state_from_error( $state, $step ); }
			$state = $step;
			if ( 'passed' === sanitize_key( (string) ( $state['status'] ?? '' ) ) || 'failed' === sanitize_key( (string) ( $state['status'] ?? '' ) ) ) { return $state; }
		}
		return $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function start_state() {
		$initial = self::assert_initial_preconditions();
		if ( is_wp_error( $initial ) ) { return $initial; }
		$runtime_payload = RuntimePayloadEvidence::current();
		if ( ! is_array( $runtime_payload ) ) { return new \WP_Error( 'certloom_staging_restore_runtime_payload_unavailable', __( 'Certloom could not fingerprint the exact installed plugin payload for staging restore evidence.', 'certloom' ) ); }
		$migration = StagingMigrationRehearsal::get_last_result();
		$source = ( new LegacyCertificateRepository() )->snapshot();
		if ( ! is_array( $migration ) || is_wp_error( $source ) ) {
			return is_wp_error( $source ) ? $source : new \WP_Error( 'certloom_staging_restore_migration_evidence_missing', __( 'A fresh passing staging migration rehearsal is required before the restore rehearsal.', 'certloom' ) );
		}
		$source_count = absint( $migration['source_count'] ?? 0 );
		if ( $source_count < 1 ) {
			return new \WP_Error( 'certloom_staging_restore_population_required', __( 'The staging restore rehearsal requires a populated migrated credential graph. Rehearse on a staging copy that contains at least one legacy certificate.', 'certloom' ) );
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
			'source_count'           => $source_count,
			'source_max_id'          => absint( $source['max_id'] ?? 0 ),
			'source_generation'      => (string) ( $source['generation'] ?? '' ),
			'migration_checked_at'   => absint( $migration['checked_at'] ?? 0 ),
			'baseline_hash'          => '',
			'baseline_records'       => 0,
			'baseline_config_hash'   => '',
			'baseline_counts'        => array(),
			'backup_records'         => 0,
			'backup_counts'          => array(),
			'backup_chain'           => '',
			'replace_verified'       => false,
			'merge_verified'         => false,
			'rollback_verified'      => false,
			'merge_counts'           => array( 'inserted' => 0, 'existing' => 0, 'skipped' => 0 ),
			'fingerprint_state'      => null,
			'backup_state'           => null,
			'backup_path'            => '',
			'replace_state'          => null,
			'merge_restore_state'    => null,
			'merge_state'            => null,
			'rollback_state'         => null,
			'error_message'          => '',
		);
		if ( '' === $state['plugin_version'] || '' === $state['source_generation'] ) {
			return new \WP_Error( 'certloom_staging_restore_evidence_invalid', __( 'Certloom could not bind the restore rehearsal to a complete source/version boundary.', 'certloom' ) );
		}
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_fingerprint( array $state, $label ) {
		$key = (string) $label;
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

		$hash = strtolower( (string) ( $current['hash'] ?? '' ) );
		$records = absint( $current['records'] ?? 0 );
		$config_hash = strtolower( (string) ( $current['configuration_hash'] ?? '' ) );
		$counts = (array) ( $current['entity_counts'] ?? array() );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $config_hash ) ) {
			return new \WP_Error( 'certloom_staging_restore_fingerprint_invalid', __( 'Certloom produced invalid domain-state fingerprint evidence.', 'certloom' ) );
		}
		if ( 'baseline' === $key ) {
			if ( $records < 1 ) { return new \WP_Error( 'certloom_staging_restore_population_required', __( 'The staging restore rehearsal requires a populated future credential graph.', 'certloom' ) ); }
			$state['baseline_hash'] = $hash;
			$state['baseline_records'] = $records;
			$state['baseline_config_hash'] = $config_hash;
			$state['baseline_counts'] = $counts;
			$state['phase'] = 'backup';
		} else {
			if ( ! hash_equals( (string) $state['baseline_hash'], $hash ) || absint( $state['baseline_records'] ) !== $records || ! hash_equals( (string) $state['baseline_config_hash'], $config_hash ) || (array) $state['baseline_counts'] !== $counts ) {
				return new \WP_Error( 'certloom_staging_restore_parity_failed', sprintf( __( 'The %s restore rehearsal did not reproduce the complete baseline credential graph and configuration.', 'certloom' ), sanitize_key( $key ) ) );
			}
			if ( 'replace' === $key ) { $state['replace_verified'] = true; $state['phase'] = 'merge_stage'; }
			elseif ( 'merge' === $key ) { $state['merge_verified'] = true; $state['phase'] = 'rollback_stage'; }
			elseif ( 'rollback' === $key ) { $state['rollback_verified'] = true; $state['phase'] = 'cleanup'; }
			else { return new \WP_Error( 'certloom_staging_restore_fingerprint_phase_invalid', __( 'Certloom found an invalid restore fingerprint phase.', 'certloom' ) ); }
		}
		$state['fingerprint_state'] = null;
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_backup( array $state ) {
		$builder = new DomainBackupBuilder();
		$current = isset( $state['backup_state'] ) && is_array( $state['backup_state'] ) ? $state['backup_state'] : null;
		if ( ! is_array( $current ) ) {
			$job = self::random_token( 16 );
			if ( is_wp_error( $job ) ) { return $job; }
			$current = $builder->start( $job, (string) $state['plugin_version'] );
			if ( is_wp_error( $current ) ) { return $current; }
		}
		$current = $builder->continue_build( $current, self::BATCH_SIZE );
		if ( is_wp_error( $current ) ) { return $current; }
		$state['backup_state'] = $current;
		if ( empty( $current['complete'] ) ) { return self::persist( $state ); }
		$analysis = ( new DomainBackupReader() )->analyze( (string) $current['path'] );
		if ( is_wp_error( $analysis ) ) { return $analysis; }
		if ( absint( $analysis['records'] ?? 0 ) !== absint( $state['baseline_records'] ) ) {
			return new \WP_Error( 'certloom_staging_restore_backup_count_mismatch', __( 'The generated backup does not cover the complete baseline credential graph.', 'certloom' ) );
		}
		$counts = array_fill_keys( DomainBackupRepository::entity_types(), 0 );
		foreach ( $counts as $entity => $_ ) { $counts[ $entity ] = absint( $analysis['counts'][ $entity ] ?? 0 ); }
		if ( $counts !== (array) $state['baseline_counts'] ) {
			return new \WP_Error( 'certloom_staging_restore_backup_entity_mismatch', __( 'The generated backup entity counts do not match the baseline graph.', 'certloom' ) );
		}
		$state['backup_path'] = (string) $current['path'];
		$state['backup_records'] = absint( $analysis['records'] );
		$state['backup_counts'] = $counts;
		$state['backup_chain'] = strtolower( (string) ( $analysis['chain_sha256'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $state['backup_chain'] ) ) { return new \WP_Error( 'certloom_staging_restore_backup_chain_invalid', __( 'The generated backup integrity chain is invalid.', 'certloom' ) ); }
		$state['backup_state'] = null;
		$state['phase'] = 'replace_stage';
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_stage( array $state, $label ) {
		$property = (string) $label . '_state';
		$current = isset( $state[ $property ] ) && is_array( $state[ $property ] ) ? $state[ $property ] : null;
		$service = new DomainRestoreStagingService();
		if ( ! is_array( $current ) ) {
			$owner = self::random_token( 16 );
			if ( is_wp_error( $owner ) ) { return $owner; }
			$current = $service->start( (string) $state['backup_path'], $owner );
			if ( is_wp_error( $current ) ) { return $current; }
		}
		$current = $service->continue_stage( $current, self::BATCH_SIZE );
		if ( is_wp_error( $current ) ) { return $current; }
		$state[ $property ] = $current;
		if ( ! empty( $current['complete'] ) && 'ready' === (string) ( $current['phase'] ?? '' ) ) {
			if ( 'replace' === $label ) { $state['phase'] = 'replace_commit'; }
			elseif ( 'merge' === $label ) { $state['merge_restore_state'] = $current; $state['phase'] = 'merge_build'; }
			elseif ( 'rollback' === $label ) { $state['phase'] = 'rollback_fault_commit'; }
		}
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_replace_commit( array $state ) {
		$service = new DomainRestoreStagingService();
		$result = $service->commit_replace( (array) $state['replace_state'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$owner = (string) ( $state['replace_state']['owner_token'] ?? '' );
		$receipt = $service->completion_receipt( $owner );
		$valid = self::assert_replace_receipt( $receipt, $state );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$state['replace_state'] = null;
		$state['fingerprint_state'] = null;
		$state['phase'] = 'replace_fingerprint';
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_merge_build( array $state ) {
		$service = new DomainRestoreMergeService();
		$current = isset( $state['merge_state'] ) && is_array( $state['merge_state'] ) ? $state['merge_state'] : null;
		if ( ! is_array( $current ) ) {
			$current = $service->start( (array) $state['merge_restore_state'] );
			if ( is_wp_error( $current ) ) { return $current; }
		}
		$current = $service->continue_merge( $current, self::BATCH_SIZE );
		if ( is_wp_error( $current ) ) { return $current; }
		$state['merge_state'] = $current;
		if ( ! empty( $current['complete'] ) && 'ready' === (string) ( $current['phase'] ?? '' ) ) { $state['phase'] = 'merge_commit'; }
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_merge_commit( array $state ) {
		$service = new DomainRestoreMergeService();
		$result = $service->commit( (array) $state['merge_state'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$owner = (string) ( $state['merge_restore_state']['owner_token'] ?? '' );
		$receipt = ( new DomainRestoreStagingService() )->completion_receipt( $owner );
		if ( ! is_array( $receipt ) || 'merge' !== (string) ( $receipt['mode'] ?? '' ) || absint( $receipt['records'] ?? 0 ) !== absint( $state['backup_records'] ) ) {
			return new \WP_Error( 'certloom_staging_restore_merge_receipt_invalid', __( 'The committed Merge restore is missing its exact durable completion receipt.', 'certloom' ) );
		}
		$counts = isset( $receipt['counts'] ) && is_array( $receipt['counts'] ) ? $receipt['counts'] : array();
		foreach ( array( 'inserted', 'existing', 'skipped' ) as $key ) { if ( ! array_key_exists( $key, $counts ) ) { return new \WP_Error( 'certloom_staging_restore_merge_receipt_invalid', __( 'The committed Merge restore receipt is missing action counts.', 'certloom' ) ); } }
		if ( 0 !== absint( $counts['inserted'] ) || array_sum( array_map( 'absint', $counts ) ) !== absint( $state['backup_records'] ) ) {
			return new \WP_Error( 'certloom_staging_restore_merge_inserted_duplicate', __( 'Merging an identical backup unexpectedly inserted new credential records.', 'certloom' ) );
		}
		$state['merge_counts'] = array( 'inserted' => absint( $counts['inserted'] ), 'existing' => absint( $counts['existing'] ), 'skipped' => absint( $counts['skipped'] ) );
		$state['merge_restore_state'] = null;
		$state['merge_state'] = null;
		$state['fingerprint_state'] = null;
		$state['phase'] = 'merge_fingerprint';
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_rollback_fault_commit( array $state ) {
		$restore_state = (array) $state['rollback_state'];
		$owner = (string) ( $restore_state['owner_token'] ?? '' );
		if ( '' === $owner ) { return new \WP_Error( 'certloom_staging_restore_rollback_owner_missing', __( 'The forced rollback rehearsal is missing its restore owner.', 'certloom' ) ); }
		$armed = true;
		$callback = static function ( $value ) use ( &$armed ) {
			if ( $armed ) { $armed = false; return array( '__certloom_rehearsal_fault__' => true ); }
			return $value;
		};
		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) ) {
			return new \WP_Error( 'certloom_staging_restore_filter_api_missing', __( 'WordPress option-filter APIs are unavailable for the staging rollback rehearsal.', 'certloom' ) );
		}
		add_filter( 'pre_update_option_certverify_fields', $callback, PHP_INT_MAX, 3 );
		try {
			$result = ( new DomainRestoreStagingService() )->commit_replace( $restore_state );
		} finally {
			remove_filter( 'pre_update_option_certverify_fields', $callback, PHP_INT_MAX );
		}
		if ( ! is_wp_error( $result ) || 'certloom_domain_restore_configuration_failed' !== $result->get_error_code() ) {
			return new \WP_Error( 'certloom_staging_restore_rollback_fault_not_detected', __( 'The staging restore rehearsal did not detect and roll back the forced post-swap configuration mismatch exactly as expected.', 'certloom' ) );
		}
		$receipt = ( new DomainRestoreStagingService() )->completion_receipt( $owner );
		if ( is_wp_error( $receipt ) ) { return $receipt; }
		if ( null !== $receipt ) { return new \WP_Error( 'certloom_staging_restore_rollback_receipt_unexpected', __( 'A failed-and-rolled-back restore unexpectedly left a completion receipt.', 'certloom' ) ); }
		$state['rollback_state'] = null;
		$state['fingerprint_state'] = null;
		$state['phase'] = 'rollback_fingerprint';
		return self::persist( $state );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_cleanup( array $state ) {
		$path = (string) ( $state['backup_path'] ?? '' );
		if ( '' !== $path ) {
			$deleted = ( new PrivateJobFileStore() )->delete( $path );
			if ( is_wp_error( $deleted ) || false === $deleted ) {
				return is_wp_error( $deleted ) ? $deleted : new \WP_Error( 'certloom_staging_restore_backup_cleanup_failed', __( 'The rehearsal completed its data checks but could not remove the private rehearsal backup file.', 'certloom' ) );
			}
		}
		$state['backup_path'] = '';
		$state['phase'] = 'complete';
		$state['status'] = 'passed';
		$state['checked_at'] = time();
		$state['updated_at'] = time();
		return self::persist( $state );
	}

	/** @return true|\WP_Error */
	private static function assert_replace_receipt( $receipt, array $state ) {
		if ( ! is_array( $receipt ) || 'replace' !== (string) ( $receipt['mode'] ?? '' ) || absint( $receipt['records'] ?? 0 ) !== absint( $state['backup_records'] ) ) {
			return new \WP_Error( 'certloom_staging_restore_replace_receipt_invalid', __( 'The committed Replace restore is missing its exact durable completion receipt.', 'certloom' ) );
		}
		$counts = isset( $receipt['counts'] ) && is_array( $receipt['counts'] ) ? $receipt['counts'] : array();
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			if ( absint( $counts[ $entity ] ?? -1 ) !== absint( $state['backup_counts'][ $entity ] ?? -2 ) ) {
				return new \WP_Error( 'certloom_staging_restore_replace_receipt_invalid', __( 'The Replace restore completion receipt does not match the generated backup entity counts.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_initial_preconditions() {
		$allowed = self::assert_environment();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( ! StagingMigrationRehearsal::is_current_passed() ) {
			return new \WP_Error( 'certloom_staging_restore_migration_proof_required', __( 'Run a fresh passing populated staging migration rehearsal before starting the populated restore rehearsal.', 'certloom' ) );
		}
		if ( LegacyMigrationState::COMPLETE !== ( new LegacyMigrationState() )->status() ) {
			return new \WP_Error( 'certloom_staging_restore_migration_incomplete', __( 'Official legacy migration must be complete on this staging copy before restore rehearsal.', 'certloom' ) );
		}
		if ( false !== get_option( 'certloom_domain_restore_journal', false ) || false !== get_option( 'certloom_domain_restore_build', false ) ) {
			return new \WP_Error( 'certloom_staging_restore_existing_restore_state', __( 'Existing domain restore/recovery state must be resolved before starting the staging restore rehearsal.', 'certloom' ) );
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
				return new \WP_Error( 'certloom_staging_restore_version_changed', __( 'Certloom changed version after the staging restore rehearsal started. Reclone or restore staging and rerun the rehearsal with one fixed Certloom version.', 'certloom' ) );
			}
			if ( ! RuntimePayloadEvidence::matches_current( $state['runtime_payload'] ?? null ) ) {
				return new \WP_Error( 'certloom_staging_restore_runtime_payload_changed', __( 'Certloom production bytes changed after the staging restore rehearsal started. Reclone or restore staging and rerun the rehearsal with one exact plugin payload.', 'certloom' ) );
			}
		}
		if ( ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY ) || ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) || ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY ) ) {
			return new \WP_Error( 'certloom_staging_restore_feature_gate_active', __( 'The staging restore rehearsal is available only before future-domain feature gates are enabled.', 'certloom' ) );
		}
		$cutover = new DomainCutoverState();
		if ( ! $cutover->is_clean_legacy_state() ) {
			return new \WP_Error( 'certloom_staging_restore_cutover_active', __( 'The staging restore rehearsal is unavailable after credential authority cutover.', 'certloom' ) );
		}
		if ( LegacyMigrationState::COMPLETE !== ( new LegacyMigrationState() )->status() ) {
			return new \WP_Error( 'certloom_staging_restore_migration_incomplete', __( 'The staging restore rehearsal requires a completed official migration.', 'certloom' ) );
		}
		$health = SchemaManager::verify_health_now( false );
		if ( is_wp_error( $health ) || true !== $health ) {
			return is_wp_error( $health ) ? $health : new \WP_Error( 'certloom_staging_restore_schema_unhealthy', __( 'The future credential-domain schema is not healthy enough for restore rehearsal.', 'certloom' ) );
		}
		if ( ! empty( $state ) ) {
			$current = ( new LegacyCertificateRepository() )->snapshot();
			if ( is_wp_error( $current ) || absint( $state['source_count'] ?? -1 ) !== absint( $current['count'] ?? -2 ) || absint( $state['source_max_id'] ?? -1 ) !== absint( $current['max_id'] ?? -2 ) || '' === (string) ( $state['source_generation'] ?? '' ) || ! hash_equals( (string) $state['source_generation'], (string) ( $current['generation'] ?? '' ) ) ) {
				return new \WP_Error( 'certloom_staging_restore_legacy_source_changed', __( 'The legacy source changed after the populated restore rehearsal started. Reclone or restore staging before trying again.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_environment() {
		if ( ! self::environment_allowed() ) { return new \WP_Error( 'certloom_staging_restore_environment', __( 'The populated restore rehearsal is available only when WordPress environment type is local, development, or staging.', 'certloom' ) ); }
		if ( ! self::explicitly_enabled() ) { return new \WP_Error( 'certloom_staging_restore_opt_in', __( 'The staging restore rehearsal requires the explicit CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL opt-in constant.', 'certloom' ) ); }
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get_last_result() {
		$result = self::get_raw_result();
		if ( ! is_array( $result ) || self::RESULT_SCHEMA !== (string) ( $result['schema'] ?? '' ) || self::RESULT_VERSION !== (int) ( $result['version'] ?? 0 ) ) { return null; }
		if ( ! is_array( RuntimePayloadEvidence::normalize( $result['runtime_payload'] ?? null ) ) ) { return null; }
		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		$phase = sanitize_key( (string) ( $result['phase'] ?? '' ) );
		$phases = array( 'baseline_fingerprint', 'backup', 'replace_stage', 'replace_commit', 'replace_fingerprint', 'merge_stage', 'merge_build', 'merge_commit', 'merge_fingerprint', 'rollback_stage', 'rollback_fault_commit', 'rollback_fingerprint', 'cleanup', 'complete' );
		if ( ! in_array( $status, array( 'running', 'passed', 'failed' ), true ) || ! in_array( $phase, $phases, true ) ) { return null; }
		if ( '' === (string) ( $result['plugin_version'] ?? '' ) || ! in_array( sanitize_key( (string) ( $result['environment'] ?? '' ) ), array( 'local', 'development', 'staging' ), true ) || ! absint( $result['started_at'] ?? 0 ) || ! absint( $result['updated_at'] ?? 0 ) || absint( $result['source_count'] ?? 0 ) < 1 || '' === (string) ( $result['source_generation'] ?? '' ) ) { return null; }
		if ( 'passed' === $status ) {
			$entities = DomainBackupRepository::entity_types();
			$baseline_counts = isset( $result['baseline_counts'] ) && is_array( $result['baseline_counts'] ) ? $result['baseline_counts'] : array();
			$backup_counts = isset( $result['backup_counts'] ) && is_array( $result['backup_counts'] ) ? $result['backup_counts'] : array();
			$merge_counts = isset( $result['merge_counts'] ) && is_array( $result['merge_counts'] ) ? $result['merge_counts'] : array();
			$baseline_records = absint( $result['baseline_records'] ?? 0 );
			$backup_records = absint( $result['backup_records'] ?? 0 );
			if ( 'complete' !== $phase || ! absint( $result['checked_at'] ?? 0 ) || absint( $result['checked_at'] ?? 0 ) < absint( $result['started_at'] ?? 0 ) || empty( $result['replace_verified'] ) || empty( $result['merge_verified'] ) || empty( $result['rollback_verified'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['baseline_hash'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['baseline_config_hash'] ?? '' ) ) || $baseline_records < 1 || $backup_records !== $baseline_records || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['backup_chain'] ?? '' ) ) || array_keys( $baseline_counts ) !== $entities || $backup_counts !== $baseline_counts || array_sum( array_map( 'absint', $baseline_counts ) ) !== $baseline_records || array_keys( $merge_counts ) !== array( 'inserted', 'existing', 'skipped' ) || 0 !== absint( $merge_counts['inserted'] ?? -1 ) || array_sum( array_map( 'absint', $merge_counts ) ) !== $backup_records || '' !== (string) ( $result['backup_path'] ?? '' ) ) { return null; }
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
		if ( '' === $current_version || ! hash_equals( $current_version, (string) $result['plugin_version'] ) || ! RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ?? null ) || ! $checked_at || $checked_at > time() + 300 || time() - $checked_at > self::MAX_AGE || ! is_array( $current ) || absint( $result['source_count'] ) !== absint( $current['count'] ?? -1 ) || absint( $result['source_max_id'] ) !== absint( $current['max_id'] ?? -1 ) || ! hash_equals( (string) $result['source_generation'], (string) ( $current['generation'] ?? '' ) ) || LegacyMigrationState::COMPLETE !== ( new LegacyMigrationState() )->status() || ! $gates_off || ! $cutover->is_clean_legacy_state() ) { return 'stale'; }
		return 'passed';
	}

	public static function is_current_passed() { return 'passed' === self::evidence_state(); }

	/** Privacy-safe: excludes backup paths, owner tokens, certificate data and table names. */
	public static function get_text_report( $result = null ) {
		$result = is_array( $result ) ? $result : self::get_last_result();
		if ( ! is_array( $result ) ) { return __( 'Certloom staging restore rehearsal: not run.', 'certloom' ); }
		$merge = isset( $result['merge_counts'] ) && is_array( $result['merge_counts'] ) ? $result['merge_counts'] : array();
		$lines = array(
			sprintf( 'Certloom staging restore rehearsal: %s', strtoupper( sanitize_key( (string) ( $result['status'] ?? 'unknown' ) ) ) ),
			sprintf( 'Phase: %s', strtoupper( sanitize_key( (string) ( $result['phase'] ?? 'unknown' ) ) ) ),
			sprintf( 'Certloom version: %s', sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) ) ),
			sprintf( 'Certloom payload SHA-256: %s', sanitize_text_field( (string) ( $result['runtime_payload']['sha256'] ?? '' ) ) ),
			sprintf( 'WordPress environment type: %s', sanitize_key( (string) ( $result['environment'] ?? '' ) ) ),
			sprintf( 'Populated migrated legacy rows: %d', absint( $result['source_count'] ?? 0 ) ),
			sprintf( 'Baseline future-domain records: %d', absint( $result['baseline_records'] ?? 0 ) ),
			sprintf( 'Generated backup records: %d', absint( $result['backup_records'] ?? 0 ) ),
			sprintf( 'Replace graph parity: %s', ! empty( $result['replace_verified'] ) ? 'PASS' : 'NOT YET' ),
			sprintf( 'Merge graph parity: %s', ! empty( $result['merge_verified'] ) ? 'PASS' : 'NOT YET' ),
			sprintf( 'Merge actions: inserted=%d, existing=%d, skipped=%d', absint( $merge['inserted'] ?? 0 ), absint( $merge['existing'] ?? 0 ), absint( $merge['skipped'] ?? 0 ) ),
			sprintf( 'Forced rollback parity: %s', ! empty( $result['rollback_verified'] ) ? 'PASS' : 'NOT YET' ),
			'Credential authority changed: NO',
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

	/** @return string|\WP_Error */
	private static function random_token( $bytes ) {
		try { return bin2hex( random_bytes( absint( $bytes ) ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_staging_restore_random_failed', __( 'Certloom could not generate safe staging restore-rehearsal ownership tokens.', 'certloom' ) ); }
	}

	/** @return array<string,mixed>|null */
	private static function get_raw_result() { $value = get_option( self::RESULT_OPTION, null ); return is_array( $value ) ? $value : null; }

	/** @return true|\WP_Error */
	private static function save_result( array $state ) {
		$saved = update_option( self::RESULT_OPTION, $state, false );
		return $saved || $state === get_option( self::RESULT_OPTION, null ) ? true : new \WP_Error( 'certloom_staging_restore_result_save_failed', __( 'Certloom could not save staging restore-rehearsal progress exactly.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private static function acquire_execution_lock() {
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) > time() ) { return new \WP_Error( 'certloom_staging_restore_busy', __( 'Another staging restore-rehearsal slice is already running.', 'certloom' ) ); }
		if ( null !== $existing && false !== $existing ) {
			$deleted = delete_option( self::LOCK_OPTION );
			if ( ! $deleted && false !== get_option( self::LOCK_OPTION, false ) ) { return new \WP_Error( 'certloom_staging_restore_stale_lock', __( 'Certloom could not clear an expired staging restore-rehearsal execution lock.', 'certloom' ) ); }
		}
		$owner = self::random_token( 24 );
		if ( is_wp_error( $owner ) ) { return $owner; }
		$value = array( 'token_hash' => hash( 'sha256', $owner ), 'expires_at' => time() + self::LOCK_TTL );
		if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) { return new \WP_Error( 'certloom_staging_restore_lock_failed', __( 'Certloom could not acquire the staging restore-rehearsal execution lock.', 'certloom' ) ); }
		return $owner;
	}

	/** @return true|\WP_Error */
	private static function release_execution_lock( $owner ) {
		$current = get_option( self::LOCK_OPTION, null );
		$expected = hash( 'sha256', (string) $owner );
		if ( ! is_array( $current ) || empty( $current['token_hash'] ) || ! hash_equals( (string) $current['token_hash'], $expected ) ) { return new \WP_Error( 'certloom_staging_restore_lock_owner_mismatch', __( 'Certloom refused to release a staging restore-rehearsal lock owned by another process.', 'certloom' ) ); }
		$deleted = delete_option( self::LOCK_OPTION );
		return $deleted || false === get_option( self::LOCK_OPTION, false ) ? true : new \WP_Error( 'certloom_staging_restore_lock_release_failed', __( 'Certloom could not release the staging restore-rehearsal execution lock safely.', 'certloom' ) );
	}
}
