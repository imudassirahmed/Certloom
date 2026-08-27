<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\CredentialVerificationService;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyCertificateMapper;
use Certloom\Application\Migration\LegacyMigrationPreflightService;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Migration\LegacySourceFingerprint;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Migration\LegacyMigrationCoordinator;
use Certloom\Infrastructure\Migration\LegacyMigrationWriter;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit staging/local rehearsal of the real populated legacy migration path.
 *
 * This is intentionally unavailable on production and additionally requires an
 * explicit wp-config opt-in constant. It uses the production migration
 * coordinator/writer, leaves migrated future-domain rows in place for later
 * staging restore/cutover rehearsals, but never changes public/write authority.
 */
final class StagingMigrationRehearsal {
	const RESULT_OPTION  = 'certloom_staging_migration_rehearsal';
	const LOCK_OPTION    = 'certloom_staging_migration_rehearsal_lock';
	const RESULT_SCHEMA  = 'certloom-staging-migration-rehearsal';
	const RESULT_VERSION = 4;
	const MAX_AGE        = 7 * DAY_IN_SECONDS;
	const LOCK_TTL       = 2 * MINUTE_IN_SECONDS;
	const MAX_SECONDS    = 4.0;
	const ERROR_LIMIT    = 25;
	const PREFLIGHT_BATCH = 250;
	const COMMIT_BATCH    = 100;
	const VERIFY_BATCH    = 200;

	/** @return bool */
	public static function environment_allowed() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'production';
		return in_array( $environment, array( 'local', 'development', 'staging' ), true );
	}

	/** @return bool */
	public static function explicitly_enabled() {
		return defined( 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL' ) && true === CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL;
	}

	/** @return bool */
	public static function available() {
		return self::environment_allowed() && self::explicitly_enabled();
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function run_slice() {
		$available = self::assert_environment();
		if ( is_wp_error( $available ) ) { return $available; }
		$owner = self::acquire_execution_lock();
		if ( is_wp_error( $owner ) ) { return $owner; }
		try {
			$result = self::execute_slice();
		} catch ( \Throwable $error ) {
			$unexpected = new \WP_Error( 'certloom_staging_rehearsal_unexpected', __( 'The staging migration rehearsal encountered an unexpected error and stopped safely.', 'certloom' ) );
			$state = self::get_raw_result();
			$result = is_array( $state ) ? self::fail_state_from_error( $state, $unexpected ) : $unexpected;
		}
		$released = self::release_execution_lock( $owner );
		if ( is_wp_error( $released ) ) {
			$cleanup = new \WP_Error( 'certloom_staging_rehearsal_execution_cleanup_failed', __( 'Certloom could not release the staging rehearsal execution lock safely after the slice.', 'certloom' ) );
			if ( is_array( $result ) ) { return self::fail_state_from_error( $result, $cleanup ); }
			return $cleanup;
		}
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function execute_slice() {
		$state = self::get_raw_result();
		if ( ! is_array( $state ) || 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$started = self::start_state();
			if ( is_wp_error( $started ) ) { return $started; }
			$state = $started;
		}

		$common = self::assert_common_preconditions( $state );
		if ( is_wp_error( $common ) ) { return self::fail_state_from_error( $state, $common ); }

		$started_at = microtime( true );
		while ( ( microtime( true ) - $started_at ) < self::MAX_SECONDS ) {
			$phase = sanitize_key( (string) ( $state['phase'] ?? 'official_preflight' ) );
			if ( 'official_preflight' === $phase ) {
				$step = self::advance_official_preflight( $state );
			} elseif ( 'commit' === $phase ) {
				$step = self::advance_commit( $state );
			} elseif ( 'verify' === $phase ) {
				$step = self::advance_verification( $state );
			} elseif ( 'idempotency' === $phase ) {
				$step = self::advance_idempotency( $state );
			} elseif ( 'complete' === $phase ) {
				return $state;
			} else {
				return self::fail_state( $state, 'invalid_phase', __( 'Certloom found invalid staging migration-rehearsal state.', 'certloom' ) );
			}
			if ( is_wp_error( $step ) ) { return self::fail_state_from_error( $state, $step ); }
			$state = $step;
			if ( 'passed' === sanitize_key( (string) ( $state['status'] ?? '' ) ) || 'failed' === sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
				return $state;
			}
		}
		return $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function start_state() {
		$preconditions = self::assert_initial_preconditions();
		if ( is_wp_error( $preconditions ) ) { return $preconditions; }
		$runtime_payload = RuntimePayloadEvidence::current();
		if ( ! is_array( $runtime_payload ) ) { return new \WP_Error( 'certloom_staging_rehearsal_runtime_payload_unavailable', __( 'Certloom could not fingerprint the exact installed plugin payload for staging migration evidence.', 'certloom' ) ); }
		$source = new LegacyCertificateRepository();
		$snapshot = $source->snapshot();
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$runtime_environment = RuntimeEnvironmentEvidence::current();
		if ( ! is_array( $runtime_environment ) ) { return new \WP_Error( 'certloom_staging_rehearsal_runtime_environment_unavailable', __( 'Certloom could not identify the exact WordPress/PHP/database environment for this staging rehearsal evidence.', 'certloom' ) ); }
		$preflight = LegacyMigrationPreflightProof::get_last_result();
		if ( ! is_array( $preflight ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_preflight_missing', __( 'Run a fresh passing populated migration preflight before starting the staging commit rehearsal.', 'certloom' ) );
		}
		$environment = sanitize_key( (string) wp_get_environment_type() );
		$state = array(
			'schema'                    => self::RESULT_SCHEMA,
			'version'                   => self::RESULT_VERSION,
			'plugin_version'            => defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '',
			'runtime_payload'           => $runtime_payload,
			'runtime_environment'       => $runtime_environment,
			'environment'               => $environment,
			'status'                    => 'running',
			'phase'                     => 'official_preflight',
			'reason_code'               => '',
			'started_at'                => time(),
			'updated_at'                => time(),
			'checked_at'                => 0,
			'source_count'              => absint( $snapshot['count'] ?? 0 ),
			'source_max_id'             => absint( $snapshot['max_id'] ?? 0 ),
			'source_generation'         => (string) ( $snapshot['generation'] ?? '' ),
			'source_fingerprint'        => (string) ( $preflight['map_fingerprint'] ?? '' ),
			'preflight_date'            => (string) ( $preflight['preflight_date'] ?? current_time( 'Y-m-d' ) ),
			'official_preflight_total'  => 0,
			'official_verify_total'     => 0,
			'migrated'                  => 0,
			'skipped'                   => 0,
			'verify_cursor'             => 0,
			'verified'                  => 0,
			'verify_source_fingerprint' => ( new LegacySourceFingerprint() )->seed(),
			'target_generation'         => '',
			'idempotency_cursor'        => 0,
			'idempotency_checked'       => 0,
			'errors'                    => 0,
			'error_examples'            => array(),
		);
		if ( '' === $state['source_generation'] || ! preg_match( '/^[a-f0-9]{64}$/', $state['source_fingerprint'] ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_source_evidence_invalid', __( 'The populated migration-preflight evidence is incomplete. Run the preflight again before rehearsal.', 'certloom' ) );
		}
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_official_preflight( array $state ) {
		$coordinator = new LegacyMigrationCoordinator();
		$result = $coordinator->preflight_batch( self::PREFLIGHT_BATCH, (string) $state['preflight_date'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$migration = new LegacyMigrationState();
		$migration_state = $migration->get();
		$status = $migration->status();
		$state['official_preflight_total'] = absint( $migration_state['preflight_total'] ?? 0 );
		$state['official_verify_total'] = absint( $migration_state['verify_total'] ?? 0 );
		if ( LegacyMigrationState::BLOCKED === $status ) {
			return new \WP_Error( 'certloom_staging_rehearsal_official_preflight_blocked', __( 'The production migration coordinator blocked during its staging preflight. Review the migration state before continuing.', 'certloom' ) );
		}
		if ( LegacyMigrationState::PREFLIGHT_READY === $status ) {
			$fingerprint = (string) ( $migration_state['source_fingerprint'] ?? '' );
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) || ! hash_equals( (string) $state['source_fingerprint'], $fingerprint ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_preflight_fingerprint_mismatch', __( 'The production migration coordinator did not reproduce the populated preflight semantic fingerprint.', 'certloom' ) );
			}
			$state['phase'] = 'commit';
		}
		$state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_commit( array $state ) {
		$migration = new LegacyMigrationState();
		if ( LegacyMigrationState::COMPLETE !== $migration->status() ) {
			$result = ( new LegacyMigrationCoordinator() )->commit_batch( self::COMMIT_BATCH );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		$migration_state = $migration->get();
		$status = $migration->status();
		$state['migrated'] = absint( $migration_state['migrated'] ?? 0 );
		$state['skipped']  = absint( $migration_state['skipped'] ?? 0 );
		if ( LegacyMigrationState::BLOCKED === $status ) {
			return new \WP_Error( 'certloom_staging_rehearsal_migration_blocked', __( 'The real migration writer blocked during the staging rehearsal. No authority was switched.', 'certloom' ) );
		}
		if ( LegacyMigrationState::COMPLETE === $status ) {
			if ( absint( $state['migrated'] ) + absint( $state['skipped'] ) !== absint( $state['source_count'] ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_migration_count_mismatch', __( 'The completed staging migration does not cover the full frozen legacy source.', 'certloom' ) );
			}
			$current = ( new LegacyCertificateRepository() )->snapshot();
			if ( is_wp_error( $current ) || ! self::same_source_boundary( $state, $current ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_source_changed', __( 'The legacy registry changed during the staging migration rehearsal.', 'certloom' ) );
			}
			$state['target_generation'] = ( new DomainGeneration() )->current();
			if ( '' === $state['target_generation'] ) {
				return new \WP_Error( 'certloom_staging_rehearsal_target_generation_missing', __( 'Certloom could not bind staging verification to the migrated target generation.', 'certloom' ) );
			}
			$state['phase'] = 'verify';
			$state['verify_cursor'] = 0;
			$state['verified'] = 0;
			$state['verify_source_fingerprint'] = ( new LegacySourceFingerprint() )->seed();
		}
		$state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_verification( array $state ) {
		$owner = self::maintenance_owner();
		if ( is_wp_error( $owner ) ) { return $owner; }
		$maintenance = new DomainMaintenanceLock();
		$locked = $maintenance->acquire( $owner, 'staging_migration_verify', self::LOCK_TTL );
		if ( is_wp_error( $locked ) ) { return $locked; }
		$result = self::verify_while_locked( $state );
		$released = $maintenance->release( $owner );
		if ( is_wp_error( $released ) ) { return $released; }
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function verify_while_locked( array $state ) {
		$current = ( new LegacyCertificateRepository() )->snapshot();
		if ( is_wp_error( $current ) || ! self::same_source_boundary( $state, $current ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_source_changed', __( 'The legacy registry changed before staging migration parity could be completed.', 'certloom' ) );
		}
		if ( ! hash_equals( (string) $state['target_generation'], ( new DomainGeneration() )->current() ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_target_changed', __( 'The migrated Credential Series target changed during staging parity verification.', 'certloom' ) );
		}

		$source = new LegacyCertificateRepository();
		$cursor = absint( $state['verify_cursor'] ?? 0 );
		$rows = $source->read_batch( $cursor, absint( $state['source_max_id'] ?? 0 ), self::VERIFY_BATCH );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			if ( absint( $state['verified'] ?? 0 ) !== absint( $state['source_count'] ?? -1 ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_verify_count_mismatch', __( 'Staging migration parity did not verify every frozen legacy row.', 'certloom' ) );
			}
			if ( ! hash_equals( (string) $state['source_fingerprint'], (string) $state['verify_source_fingerprint'] ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_verify_fingerprint_mismatch', __( 'The live legacy source fingerprint changed while staging migration parity was being verified.', 'certloom' ) );
			}
			$coverage = self::assert_target_coverage( absint( $state['source_count'] ) );
			if ( is_wp_error( $coverage ) ) { return $coverage; }
			$state['phase'] = 'idempotency';
			$state['idempotency_cursor'] = 0;
			$state['idempotency_checked'] = 0;
			$state['updated_at'] = time();
			$saved = self::save_result( $state );
			return is_wp_error( $saved ) ? $saved : $state;
		}

		$fingerprint = ( new LegacySourceFingerprint() )->advance( (string) $state['verify_source_fingerprint'], $rows );
		if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
		foreach ( $rows as $row ) {
			$validated = self::validate_migrated_row( $row, (string) $state['preflight_date'] );
			if ( is_wp_error( $validated ) ) {
				return self::row_error( $state, $row, $validated );
			}
		}
		$last = end( $rows );
		$state['verify_cursor'] = absint( is_array( $last ) ? ( $last['id'] ?? $cursor ) : $cursor );
		$state['verified'] = absint( $state['verified'] ?? 0 ) + count( $rows );
		$state['verify_source_fingerprint'] = $fingerprint;
		$state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function advance_idempotency( array $state ) {
		$owner = self::maintenance_owner();
		if ( is_wp_error( $owner ) ) { return $owner; }
		$maintenance = new DomainMaintenanceLock();
		$locked = $maintenance->acquire( $owner, 'staging_migration_idempotency', self::LOCK_TTL );
		if ( is_wp_error( $locked ) ) { return $locked; }
		$result = DomainMaintenanceLock::run_internal( function () use ( $state ) { return self::idempotency_while_locked( $state ); } );
		$released = $maintenance->release( $owner );
		if ( is_wp_error( $released ) ) { return $released; }
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function idempotency_while_locked( array $state ) {
		$current = ( new LegacyCertificateRepository() )->snapshot();
		if ( is_wp_error( $current ) || ! self::same_source_boundary( $state, $current ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_source_changed', __( 'The legacy source changed before idempotency replay completed.', 'certloom' ) );
		}
		if ( ! hash_equals( (string) $state['target_generation'], ( new DomainGeneration() )->current() ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_target_changed', __( 'The migrated target changed before idempotency replay completed.', 'certloom' ) );
		}
		$cursor = absint( $state['idempotency_cursor'] ?? 0 );
		$rows = ( new LegacyCertificateRepository() )->read_batch( $cursor, absint( $state['source_max_id'] ?? 0 ), self::COMMIT_BATCH );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			if ( absint( $state['idempotency_checked'] ?? 0 ) !== absint( $state['source_count'] ?? -1 ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_idempotency_count_mismatch', __( 'Staging migration idempotency replay did not cover every frozen legacy row.', 'certloom' ) );
			}
			$coverage = self::assert_target_coverage( absint( $state['source_count'] ) );
			if ( is_wp_error( $coverage ) ) { return $coverage; }
			$state['phase'] = 'complete';
			$state['status'] = 'passed';
			$state['reason_code'] = '';
			$state['checked_at'] = time();
			$state['updated_at'] = time();
			$saved = self::save_result( $state );
			return is_wp_error( $saved ) ? $saved : $state;
		}
		$mapper = new LegacyCertificateMapper();
		$writer = new LegacyMigrationWriter();
		foreach ( $rows as $row ) {
			try { $plan = $mapper->map( $row ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_staging_rehearsal_idempotency_map_failed', $error->getMessage() ); }
			$written = $writer->migrate( absint( $row['id'] ?? 0 ), $plan );
			if ( is_wp_error( $written ) || 'skipped' !== (string) ( is_array( $written ) ? ( $written['outcome'] ?? '' ) : '' ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_idempotency_failed', __( 'A second pass through the real migration writer was not idempotently skipped.', 'certloom' ) );
			}
		}
		$last = end( $rows );
		$state['idempotency_cursor'] = absint( is_array( $last ) ? ( $last['id'] ?? $cursor ) : $cursor );
		$state['idempotency_checked'] = absint( $state['idempotency_checked'] ?? 0 ) + count( $rows );
		$state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return true|\WP_Error */
	private static function validate_migrated_row( array $row, $today ) {
		$mapper = new LegacyCertificateMapper();
		try { $plan = $mapper->map( $row ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_staging_rehearsal_map_failed', $error->getMessage() ); }
		$credentials = new CredentialRepository();
		$key = (string) ( $plan['version']['source_idempotency_key'] ?? '' );
		$version = $credentials->find_version_by_source_idempotency( 'certloom_legacy', $key );
		if ( is_wp_error( $version ) ) { return $version; }
		if ( ! is_array( $version ) ) { return new \WP_Error( 'certloom_staging_rehearsal_version_missing', __( 'A migrated credential Version is missing for a legacy row.', 'certloom' ) ); }
		$series = $credentials->find_series( absint( $version['series_id'] ?? 0 ) );
		if ( is_wp_error( $series ) ) { return $series; }
		if ( ! is_array( $series ) ) { return new \WP_Error( 'certloom_staging_rehearsal_series_missing', __( 'A migrated Credential Series is missing for a legacy row.', 'certloom' ) ); }
		$recipient = ( new RecipientRepository() )->find( absint( $series['recipient_id'] ?? 0 ) );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) ) { return new \WP_Error( 'certloom_staging_rehearsal_recipient_missing', __( 'A migrated recipient is missing for a legacy row.', 'certloom' ) ); }

		$expected_version = $plan['version'];
		$checks = array(
			(string) ( $recipient['display_name'] ?? '' ) === (string) $plan['recipient']['display_name'],
			(string) ( $series['administrative_state'] ?? '' ) === (string) $plan['series']['administrative_state'],
			(string) ( $series['compliance_state'] ?? '' ) === (string) $plan['series']['compliance_state'],
			(string) ( $series['visibility'] ?? '' ) === (string) $plan['series']['visibility'],
			absint( $series['current_version_id'] ?? 0 ) === absint( $version['id'] ?? 0 ),
			(string) ( $version['display_number'] ?? '' ) === (string) $expected_version['display_number'],
			(string) ( $version['issuance_state'] ?? '' ) === (string) $expected_version['issuance_state'],
			(bool) ( $version['legacy_expired'] ?? false ) === (bool) $expected_version['legacy_expired'],
			(string) ( $version['valid_from'] ?? '' ) === (string) ( $expected_version['valid_from'] ?? '' ),
			(string) ( $version['issued_at'] ?? '' ) === (string) ( $expected_version['issued_at'] ?? '' ),
			(string) ( $version['expires_at'] ?? '' ) === (string) ( $expected_version['expires_at'] ?? '' ),
			(string) ( $version['source_type'] ?? '' ) === 'certloom_legacy',
			(string) ( $version['source_ref'] ?? '' ) === (string) absint( $row['id'] ?? 0 ),
			(string) ( $version['source_idempotency_key'] ?? '' ) === $key,
			(array) ( $version['snapshot'] ?? array() ) === (array) $expected_version['snapshot'],
		);
		if ( in_array( false, $checks, true ) ) { return new \WP_Error( 'certloom_staging_rehearsal_row_parity_failed', __( 'A migrated credential does not match its legacy migration plan.', 'certloom' ) ); }

		$preflight = ( new LegacyMigrationPreflightService() )->preflight( array( $row ), (string) $today );
		if ( 1 !== absint( $preflight['ready'] ?? 0 ) || 0 !== absint( $preflight['errors'] ?? 0 ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_status_expected_failed', __( 'Certloom could not derive the expected migrated public status for a legacy row.', 'certloom' ) );
		}
		$expected_status = '';
		foreach ( array( 'valid', 'suspended', 'revoked', 'expired' ) as $status_code ) {
			if ( 1 === absint( $preflight['status_counts'][ $status_code ] ?? 0 ) ) { $expected_status = $status_code; break; }
		}
		$verification = ( new CredentialVerificationService( $credentials ) )->verify_public_id( (string) ( $series['public_id'] ?? '' ), (string) $today );
		if ( is_wp_error( $verification ) || ! $verification->found() || ! $verification->status() || $expected_status !== $verification->status()->code() ) {
			return new \WP_Error( 'certloom_staging_rehearsal_status_parity_failed', __( 'The migrated credential public status does not match the legacy public verdict.', 'certloom' ) );
		}
		$events = ( new EventRepository() )->list_for_series( absint( $series['id'] ?? 0 ), 20 );
		if ( is_wp_error( $events ) ) { return $events; }
		$found_event = false;
		foreach ( $events as $event ) {
			if ( 'credential.migrated' === (string) ( $event['event_type'] ?? '' ) && absint( $event['meta']['legacy_certificate_id'] ?? 0 ) === absint( $row['id'] ?? 0 ) ) { $found_event = true; break; }
		}
		return $found_event ? true : new \WP_Error( 'certloom_staging_rehearsal_event_missing', __( 'The migrated credential is missing its append-only migration event.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private static function assert_target_coverage( $expected ) {
		global $wpdb;
		$expected = absint( $expected );
		$queries = array(
			'versions' => array( SchemaManager::table( 'credential_versions' ), ' WHERE source_type = %s', array( 'certloom_legacy' ), $expected ),
			'series'   => array( SchemaManager::table( 'credential_series' ), '', array(), $expected ),
			'recipients' => array( SchemaManager::table( 'recipients' ), '', array(), $expected ),
			'numbers'  => array( SchemaManager::table( 'credential_numbers' ), '', array(), $expected ),
			'events'   => array( SchemaManager::table( 'credential_events' ), ' WHERE event_type = %s', array( 'credential.migrated' ), $expected ),
		);
		foreach ( $queries as $name => $spec ) {
			$table = $spec[0]; $where = $spec[1]; $args = $spec[2]; $wanted = $spec[3];
			$wpdb->last_error = '';
			$sql_args = array_merge( array( $table ), $args );
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, $sql_args ) );
			if ( '' !== (string) $wpdb->last_error || null === $count || (int) $count !== (int) $wanted ) {
				return new \WP_Error( 'certloom_staging_rehearsal_target_coverage_failed', sprintf( /* translators: %s: internal entity category. */ __( 'The staging migration target has unexpected %s coverage.', 'certloom' ), sanitize_key( $name ) ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_initial_preconditions() {
		$environment = self::assert_environment();
		if ( is_wp_error( $environment ) ) { return $environment; }
		if ( ! \Ecapla_CertVerify_Database_Proof::is_current_passed() || ! DomainAcceptanceProof::is_current_passed() || ! LegacyMigrationPreflightProof::is_current_passed() ) {
			return new \WP_Error( 'certloom_staging_rehearsal_proofs_required', __( 'Run fresh passing database, gated-domain, and populated migration-preflight proofs before staging migration rehearsal.', 'certloom' ) );
		}
		if ( LegacyMigrationState::NOT_STARTED !== ( new LegacyMigrationState() )->status() ) {
			return new \WP_Error( 'certloom_staging_rehearsal_migration_state_not_clean', __( 'The staging migration rehearsal requires official migration state to be completely not started. Reclone or restore the staging database before retrying.', 'certloom' ) );
		}
		$empty = self::assert_domain_empty();
		if ( is_wp_error( $empty ) ) { return $empty; }
		return self::assert_common_preconditions( array() );
	}

	/** @return true|\WP_Error */
	private static function assert_common_preconditions( array $state ) {
		$environment = self::assert_environment();
		if ( is_wp_error( $environment ) ) { return $environment; }
		if ( ! empty( $state ) ) {
			$current_version = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
			$started_version = (string) ( $state['plugin_version'] ?? '' );
			if ( '' === $current_version || '' === $started_version || ! hash_equals( $started_version, $current_version ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_version_changed', __( 'Certloom changed version after the staging migration rehearsal started. Reclone or restore staging and run the rehearsal again with one fixed Certloom version.', 'certloom' ) );
			}
			if ( ! RuntimePayloadEvidence::matches_current( $state['runtime_payload'] ?? null ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_runtime_payload_changed', __( 'Certloom production bytes changed after the staging migration rehearsal started. Reclone or restore staging and rerun the rehearsal with one exact plugin payload.', 'certloom' ) );
			}
			if ( ! RuntimeEnvironmentEvidence::matches_current( $state['runtime_environment'] ?? null ) ) {
				return new \WP_Error( 'certloom_staging_rehearsal_runtime_environment_changed', __( 'The WordPress/PHP/database environment changed after this rehearsal started. Reclone or restore staging and rerun the rehearsal on one fixed runtime environment.', 'certloom' ) );
			}
		}
		if ( ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY ) || ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) || ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY ) ) {
			return new \WP_Error( 'certloom_staging_rehearsal_feature_gate_active', __( 'Staging migration rehearsal is available only before future-domain feature gates are enabled.', 'certloom' ) );
		}
		$cutover = new DomainCutoverState();
		if ( ! $cutover->is_clean_legacy_state() ) { return new \WP_Error( 'certloom_staging_rehearsal_cutover_active', __( 'Staging migration rehearsal is unavailable after credential authority cutover.', 'certloom' ) ); }
		if ( false !== get_option( 'certloom_domain_restore_journal', false ) || false !== get_option( 'certloom_domain_restore_build', false ) ) { return new \WP_Error( 'certloom_staging_rehearsal_restore_state', __( 'Staging migration rehearsal will not run while domain restore/recovery state exists.', 'certloom' ) ); }
		$health = SchemaManager::verify_health_now( false );
		if ( is_wp_error( $health ) || true !== $health ) { return is_wp_error( $health ) ? $health : new \WP_Error( 'certloom_staging_rehearsal_schema_unhealthy', __( 'The future Credential Series schema is not healthy enough for staging migration rehearsal.', 'certloom' ) ); }
		if ( ! empty( $state ) ) {
			$current = ( new LegacyCertificateRepository() )->snapshot();
			if ( is_wp_error( $current ) || ! self::same_source_boundary( $state, $current ) ) { return new \WP_Error( 'certloom_staging_rehearsal_source_changed', __( 'The legacy registry changed after the staging migration rehearsal started.', 'certloom' ) ); }
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_environment() {
		if ( ! self::environment_allowed() ) { return new \WP_Error( 'certloom_staging_rehearsal_environment', __( 'The populated migration commit rehearsal is available only when WordPress environment type is local, development, or staging.', 'certloom' ) ); }
		if ( ! self::explicitly_enabled() ) { return new \WP_Error( 'certloom_staging_rehearsal_opt_in', __( 'The staging migration rehearsal requires the explicit CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL opt-in constant.', 'certloom' ) ); }
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_domain_empty() {
		global $wpdb;
		foreach ( array( 'recipients', 'issuers', 'programs', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'email_deliveries', 'jobs', 'import_rows', 'restore_map' ) as $logical ) {
			$table = SchemaManager::table( $logical );
			$wpdb->last_error = '';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_staging_rehearsal_domain_inspect_failed', __( 'Certloom could not inspect future-domain tables before staging migration rehearsal.', 'certloom' ) ); }
			if ( (string) $exists !== (string) $table ) { continue; }
			$wpdb->last_error = '';
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( '' !== (string) $wpdb->last_error || null === $count || 0 !== (int) $count ) { return new \WP_Error( 'certloom_staging_rehearsal_domain_not_empty', __( 'Staging migration rehearsal requires empty future-domain tables. Reclone or restore the staging database before starting.', 'certloom' ) ); }
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get_last_result() {
		$result = self::get_raw_result();
		if ( ! is_array( $result ) || self::RESULT_SCHEMA !== (string) ( $result['schema'] ?? '' ) || self::RESULT_VERSION !== (int) ( $result['version'] ?? 0 ) ) { return null; }
		if ( ! is_array( RuntimePayloadEvidence::normalize( $result['runtime_payload'] ?? null ) ) || ! is_array( RuntimeEnvironmentEvidence::normalize( $result['runtime_environment'] ?? null ) ) ) { return null; }
		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		$phase = sanitize_key( (string) ( $result['phase'] ?? '' ) );
		if ( ! in_array( $status, array( 'running', 'passed', 'failed' ), true ) || ! in_array( $phase, array( 'official_preflight', 'commit', 'verify', 'idempotency', 'complete' ), true ) ) { return null; }
		if ( '' === (string) ( $result['plugin_version'] ?? '' ) || ! in_array( sanitize_key( (string) ( $result['environment'] ?? '' ) ), array( 'local', 'development', 'staging' ), true ) || ! absint( $result['started_at'] ?? 0 ) || ! absint( $result['updated_at'] ?? 0 ) ) { return null; }
		if ( '' === (string) ( $result['source_generation'] ?? '' ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['source_fingerprint'] ?? '' ) ) ) { return null; }
		foreach ( array( 'source_count', 'source_max_id', 'official_preflight_total', 'official_verify_total', 'migrated', 'skipped', 'verify_cursor', 'verified', 'idempotency_cursor', 'idempotency_checked', 'errors' ) as $numeric ) {
			if ( ! array_key_exists( $numeric, $result ) || absint( $result[ $numeric ] ) < 0 ) { return null; }
		}
		if ( 'passed' === $status ) {
			if ( 'complete' !== $phase || ! absint( $result['checked_at'] ?? 0 ) || absint( $result['verified'] ) !== absint( $result['source_count'] ) || absint( $result['idempotency_checked'] ) !== absint( $result['source_count'] ) || absint( $result['errors'] ) !== 0 || '' === (string) ( $result['target_generation'] ?? '' ) ) { return null; }
		}
		return $result;
	}

	/** @return string passed|failed|running|stale|not_run|unavailable */
	public static function evidence_state() {
		if ( ! self::available() ) { return 'unavailable'; }
		$result = self::get_last_result();
		if ( ! is_array( $result ) ) { return 'not_run'; }
		$status = sanitize_key( (string) $result['status'] );
		$current = ( new LegacyCertificateRepository() )->snapshot();
		if ( ! is_array( $current ) || ! self::same_source_boundary( $result, $current ) ) { return 'stale'; }
		if ( 'running' === $status ) { return 'running'; }
		if ( 'failed' === $status ) { return 'failed'; }
		$current_version = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
		$checked_at = absint( $result['checked_at'] ?? 0 );
		if ( '' === $current_version || ! hash_equals( $current_version, (string) $result['plugin_version'] ) || ! RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ?? null ) || ! RuntimeEnvironmentEvidence::matches_current( $result['runtime_environment'] ?? null ) || ! $checked_at || $checked_at > time() + 300 || time() - $checked_at > self::MAX_AGE || ! hash_equals( (string) $result['target_generation'], ( new DomainGeneration() )->current() ) || LegacyMigrationState::COMPLETE !== ( new LegacyMigrationState() )->status() ) { return 'stale'; }
		return 'passed';
	}

	public static function is_current_passed() { return 'passed' === self::evidence_state(); }

	/** Privacy-safe report; no certificate numbers, holder names, paths or table names. */
	public static function get_text_report( $result = null ) {
		$result = is_array( $result ) ? $result : self::get_last_result();
		if ( ! is_array( $result ) ) { return __( 'Certloom staging migration rehearsal: not run.', 'certloom' ); }
		$lines = array(
			sprintf( 'Certloom staging migration rehearsal: %s', strtoupper( sanitize_key( (string) ( $result['status'] ?? 'unknown' ) ) ) ),
			sprintf( 'Phase: %s', strtoupper( sanitize_key( (string) ( $result['phase'] ?? 'unknown' ) ) ) ),
			sprintf( 'Certloom version: %s', sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) ) ),
			sprintf( 'Certloom payload SHA-256: %s', sanitize_text_field( (string) ( $result['runtime_payload']['sha256'] ?? '' ) ) ),
			sprintf( 'Runtime environment SHA-256: %s', RuntimeEnvironmentEvidence::sha256( $result['runtime_environment'] ?? null ) ),
			sprintf( 'WordPress environment type: %s', sanitize_key( (string) ( $result['environment'] ?? '' ) ) ),
			sprintf( 'Frozen legacy rows: %d', absint( $result['source_count'] ?? 0 ) ),
			sprintf( 'Official preflight mapped: %d', absint( $result['official_preflight_total'] ?? 0 ) ),
			sprintf( 'Official preflight fingerprint-verified: %d', absint( $result['official_verify_total'] ?? 0 ) ),
			sprintf( 'Migration writes: migrated=%d, skipped=%d', absint( $result['migrated'] ?? 0 ), absint( $result['skipped'] ?? 0 ) ),
			sprintf( 'Target parity rows verified: %d', absint( $result['verified'] ?? 0 ) ),
			sprintf( 'Idempotency replay rows checked: %d', absint( $result['idempotency_checked'] ?? 0 ) ),
			sprintf( 'Rows requiring review: %d', absint( $result['errors'] ?? 0 ) ),
			'Credential authority changed: NO',
		);
		if ( ! empty( $result['checked_at'] ) ) { $lines[] = sprintf( 'Completed UTC: %s', gmdate( 'Y-m-d H:i:s', absint( $result['checked_at'] ) ) ); }
		if ( ! empty( $result['reason_code'] ) ) { $lines[] = sprintf( 'Result reason: %s', sanitize_key( (string) $result['reason_code'] ) ); }
		return implode( "\n", $lines );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function row_error( array $state, array $row, \WP_Error $error ) {
		$state['errors'] = absint( $state['errors'] ?? 0 ) + 1;
		$examples = isset( $state['error_examples'] ) && is_array( $state['error_examples'] ) ? $state['error_examples'] : array();
		if ( count( $examples ) < self::ERROR_LIMIT ) { $examples[] = array( 'legacy_id' => absint( $row['id'] ?? 0 ), 'message' => $error->get_error_message() ); }
		$state['error_examples'] = $examples;
		return self::fail_state( $state, 'row_parity_failed', $error->get_error_message() );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function fail_state_from_error( array $state, \WP_Error $error ) {
		return self::fail_state( $state, $error->get_error_code(), $error->get_error_message() );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function fail_state( array $state, $reason, $message = '' ) {
		$state['status'] = 'failed';
		$state['reason_code'] = sanitize_key( (string) $reason );
		$state['checked_at'] = time();
		$state['updated_at'] = time();
		if ( '' !== trim( (string) $message ) ) {
			$examples = isset( $state['error_examples'] ) && is_array( $state['error_examples'] ) ? $state['error_examples'] : array();
			if ( count( $examples ) < self::ERROR_LIMIT ) { $examples[] = array( 'legacy_id' => 0, 'message' => sanitize_text_field( (string) $message ) ); }
			$state['error_examples'] = $examples;
		}
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
	private static function maintenance_owner() {
		try { return bin2hex( random_bytes( 16 ) ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_staging_rehearsal_random_failed', __( 'Certloom could not create a safe staging rehearsal maintenance owner.', 'certloom' ) ); }
	}

	/** @return array<string,mixed>|null */
	private static function get_raw_result() { $result = get_option( self::RESULT_OPTION, null ); return is_array( $result ) ? $result : null; }

	/** @return true|\WP_Error */
	private static function save_result( array $state ) {
		$saved = update_option( self::RESULT_OPTION, $state, false );
		return $saved || $state === get_option( self::RESULT_OPTION, null ) ? true : new \WP_Error( 'certloom_staging_rehearsal_result_save_failed', __( 'Certloom could not save staging migration-rehearsal progress.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private static function acquire_execution_lock() {
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) > time() ) { return new \WP_Error( 'certloom_staging_rehearsal_busy', __( 'Another staging migration-rehearsal slice is already running.', 'certloom' ) ); }
		if ( null !== $existing && false !== $existing ) {
			$deleted = delete_option( self::LOCK_OPTION );
			if ( ! $deleted && false !== get_option( self::LOCK_OPTION, false ) ) { return new \WP_Error( 'certloom_staging_rehearsal_stale_lock', __( 'Certloom could not clear an expired staging rehearsal execution lock.', 'certloom' ) ); }
		}
		try { $owner = bin2hex( random_bytes( 24 ) ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_staging_rehearsal_random_failed', __( 'Certloom could not prepare a safe staging rehearsal execution owner.', 'certloom' ) ); }
		$value = array( 'token_hash' => hash( 'sha256', $owner ), 'expires_at' => time() + self::LOCK_TTL );
		if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) { return new \WP_Error( 'certloom_staging_rehearsal_lock_failed', __( 'Certloom could not acquire the staging rehearsal execution lock.', 'certloom' ) ); }
		return $owner;
	}

	/** @return true|\WP_Error */
	private static function release_execution_lock( $owner ) {
		$current = get_option( self::LOCK_OPTION, null );
		$expected = hash( 'sha256', (string) $owner );
		if ( ! is_array( $current ) || empty( $current['token_hash'] ) || ! hash_equals( (string) $current['token_hash'], $expected ) ) { return new \WP_Error( 'certloom_staging_rehearsal_lock_owner_mismatch', __( 'Certloom refused to release a staging rehearsal lock owned by another process.', 'certloom' ) ); }
		$deleted = delete_option( self::LOCK_OPTION );
		return $deleted || false === get_option( self::LOCK_OPTION, false ) ? true : new \WP_Error( 'certloom_staging_rehearsal_lock_release_failed', __( 'Certloom could not release the staging rehearsal execution lock safely.', 'certloom' ) );
	}
}
