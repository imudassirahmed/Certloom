<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationPreflightService;
use Certloom\Application\Migration\LegacySourceFingerprint;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable, read-only preflight of the actual populated Certloom 1.5 registry.
 *
 * The proof never writes to Credential Series tables and never starts official
 * migration/cutover state. Pass one maps every row through the production
 * migration preflight. Pass two rereads the frozen source and recomputes a
 * privacy-safe semantic fingerprint so concurrent/untracked source changes are
 * less likely to be mistaken for stable acceptance evidence.
 */
final class LegacyMigrationPreflightProof {
	const RESULT_OPTION  = 'certloom_legacy_migration_preflight_proof';
	const LOCK_OPTION    = 'certloom_legacy_migration_preflight_proof_lock';
	const RESULT_SCHEMA  = 'certloom-legacy-migration-preflight-proof';
	const RESULT_VERSION = 4;
	const MAX_AGE         = 7 * DAY_IN_SECONDS;
	const LOCK_TTL        = 2 * MINUTE_IN_SECONDS;
	const BATCH_SIZE      = 250;
	const MAX_ROWS_RUN    = 2000;
	const MAX_SECONDS_RUN = 4.0;
	const ERROR_LIMIT     = 25;

	/** @return array<string,mixed>|\WP_Error */
	public static function run_slice() {
		$preconditions = self::preconditions();
		if ( is_wp_error( $preconditions ) ) {
			return $preconditions;
		}
		$owner = self::acquire_lock();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$result = self::execute_slice();
		$released = self::release_lock( $owner );
		if ( is_wp_error( $released ) ) {
			return $released;
		}
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function execute_slice() {
		$state = self::get_raw_result();
		if ( ! is_array( $state ) || 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
			$state = self::start_state();
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$saved = self::save_result( $state );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( ! RuntimePayloadEvidence::matches_current( $state['runtime_payload'] ?? null ) ) {
			return self::fail_state( $state, 'runtime_payload_changed' );
		}
		if ( ! RuntimeEnvironmentEvidence::matches_current( $state['runtime_environment'] ?? null ) ) {
			return self::fail_state( $state, 'runtime_environment_changed' );
		}

		$source = new LegacyCertificateRepository();
		$current = $source->snapshot();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! self::same_source_boundary( $state, $current ) ) {
			return self::fail_state( $state, 'source_changed' );
		}

		$preflight = new LegacyMigrationPreflightService( null, null, self::ERROR_LIMIT );
		$started = microtime( true );
		$rows_this_run = 0;
		while ( $rows_this_run < self::MAX_ROWS_RUN && ( microtime( true ) - $started ) < self::MAX_SECONDS_RUN ) {
			$phase = sanitize_key( (string) ( $state['phase'] ?? 'map' ) );
			if ( 'map' === $phase ) {
				$cursor = absint( $state['cursor'] ?? 0 );
				$max_id = absint( $state['source_max_id'] ?? 0 );
				$rows = $source->read_batch( $cursor, $max_id, min( self::BATCH_SIZE, self::MAX_ROWS_RUN - $rows_this_run ) );
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				if ( empty( $rows ) ) {
					if ( ! self::map_complete( $state ) ) {
						return self::fail_state( $state, 'source_count_mismatch' );
					}
					if ( 0 !== absint( $state['errors'] ?? 0 ) ) {
						return self::fail_state( $state, 'preflight_errors' );
					}
					$state['phase'] = 'verify';
					$state['verify_cursor'] = 0;
					$state['verify_total'] = 0;
					$state['verify_fingerprint'] = ( new LegacySourceFingerprint() )->seed();
					$state['updated_at'] = time();
					$saved = self::save_result( $state );
					if ( is_wp_error( $saved ) ) { return $saved; }
					continue;
				}

				$fingerprint = ( new LegacySourceFingerprint() )->advance( (string) $state['map_fingerprint'], $rows );
				if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
				$summary = $preflight->preflight( $rows, (string) $state['preflight_date'] );
				$state = self::merge_summary( $state, $summary, $rows );
				$state['map_fingerprint'] = $fingerprint;
				$rows_this_run += count( $rows );
				$saved = self::save_result( $state );
				if ( is_wp_error( $saved ) ) { return $saved; }
				continue;
			}

			if ( 'verify' === $phase ) {
				$cursor = absint( $state['verify_cursor'] ?? 0 );
				$max_id = absint( $state['source_max_id'] ?? 0 );
				$rows = $source->read_batch( $cursor, $max_id, min( self::BATCH_SIZE, self::MAX_ROWS_RUN - $rows_this_run ) );
				if ( is_wp_error( $rows ) ) { return $rows; }
				if ( empty( $rows ) ) {
					if ( ! self::verify_complete( $state ) ) {
						return self::fail_state( $state, 'verification_count_mismatch' );
					}
					if ( ! hash_equals( (string) $state['map_fingerprint'], (string) $state['verify_fingerprint'] ) ) {
						return self::fail_state( $state, 'semantic_fingerprint_changed' );
					}
					$current = $source->snapshot();
					if ( is_wp_error( $current ) ) { return $current; }
					if ( ! self::same_source_boundary( $state, $current ) ) {
						return self::fail_state( $state, 'source_changed' );
					}
					$state['phase'] = 'complete';
					$state['status'] = 'passed';
					$state['reason_code'] = '';
					$state['checked_at'] = time();
					$state['updated_at'] = time();
					$saved = self::save_result( $state );
					return is_wp_error( $saved ) ? $saved : $state;
				}

				$fingerprint = ( new LegacySourceFingerprint() )->advance( (string) $state['verify_fingerprint'], $rows );
				if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
				$last = end( $rows );
				$state['verify_cursor'] = absint( is_array( $last ) ? ( $last['id'] ?? $state['verify_cursor'] ) : $state['verify_cursor'] );
				$state['verify_total'] = absint( $state['verify_total'] ?? 0 ) + count( $rows );
				$state['verify_fingerprint'] = $fingerprint;
				$state['updated_at'] = time();
				$rows_this_run += count( $rows );
				$saved = self::save_result( $state );
				if ( is_wp_error( $saved ) ) { return $saved; }
				continue;
			}

			return self::fail_state( $state, 'invalid_phase' );
		}

		$current = $source->snapshot();
		if ( is_wp_error( $current ) ) { return $current; }
		if ( ! self::same_source_boundary( $state, $current ) ) {
			return self::fail_state( $state, 'source_changed' );
		}
		return $state;
	}

	/** @return array<string,mixed>|null */
	public static function get_last_result() {
		$result = self::get_raw_result();
		if ( ! is_array( $result ) || self::RESULT_SCHEMA !== (string) ( $result['schema'] ?? '' ) || self::RESULT_VERSION !== (int) ( $result['version'] ?? 0 ) ) {
			return null;
		}
		if ( ! is_array( RuntimePayloadEvidence::normalize( $result['runtime_payload'] ?? null ) ) || ! is_array( RuntimeEnvironmentEvidence::normalize( $result['runtime_environment'] ?? null ) ) ) { return null; }
		$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
		$phase = sanitize_key( (string) ( $result['phase'] ?? '' ) );
		if ( ! in_array( $status, array( 'running', 'passed', 'failed' ), true ) || ! in_array( $phase, array( 'map', 'verify', 'complete' ), true ) ) {
			return null;
		}
		if ( '' === sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) ) || ! absint( $result['started_at'] ?? 0 ) || ! absint( $result['updated_at'] ?? 0 ) ) {
			return null;
		}
		foreach ( array( 'source_count', 'source_max_id', 'cursor', 'total', 'ready', 'errors', 'verify_cursor', 'verify_total' ) as $numeric ) {
			if ( ! array_key_exists( $numeric, $result ) || absint( $result[ $numeric ] ) < 0 ) { return null; }
		}
		if ( '' === (string) ( $result['source_generation'] ?? '' ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['map_fingerprint'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $result['verify_fingerprint'] ?? '' ) ) ) {
			return null;
		}
		if ( absint( $result['ready'] ) + absint( $result['errors'] ) !== absint( $result['total'] ) || absint( $result['total'] ) > absint( $result['source_count'] ) || absint( $result['verify_total'] ) > absint( $result['source_count'] ) ) {
			return null;
		}
		$status_total = 0;
		foreach ( array( 'valid', 'suspended', 'revoked', 'expired' ) as $code ) {
			$status_total += absint( $result['status_counts'][ $code ] ?? 0 );
		}
		if ( $status_total !== absint( $result['ready'] ) ) { return null; }
		if ( 'passed' === $status ) {
			if ( 'complete' !== $phase || 0 !== absint( $result['errors'] ) || absint( $result['total'] ) !== absint( $result['source_count'] ) || absint( $result['verify_total'] ) !== absint( $result['source_count'] ) || ! hash_equals( (string) $result['map_fingerprint'], (string) $result['verify_fingerprint'] ) || ! absint( $result['checked_at'] ?? 0 ) ) {
				return null;
			}
		}
		return $result;
	}

	/** @return string passed|failed|running|stale|not_run */
	public static function evidence_state() {
		$result = self::get_last_result();
		if ( ! is_array( $result ) ) { return 'not_run'; }
		$status = sanitize_key( (string) $result['status'] );
		$current = ( new LegacyCertificateRepository() )->snapshot();
		if ( ! is_array( $current ) || ! self::same_source_boundary( $result, $current ) ) { return 'stale'; }
		if ( 'running' === $status ) { return 'running'; }
		if ( 'failed' === $status ) { return 'failed'; }
		$current_version = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
		$checked_at = absint( $result['checked_at'] ?? 0 );
		if ( '' === $current_version || ! hash_equals( $current_version, (string) $result['plugin_version'] ) || ! RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ?? null ) || ! RuntimeEnvironmentEvidence::matches_current( $result['runtime_environment'] ?? null ) || ! $checked_at || $checked_at > time() + 300 || time() - $checked_at > self::MAX_AGE ) {
			return 'stale';
		}
		return 'passed';
	}

	public static function is_current_passed() { return 'passed' === self::evidence_state(); }

	/** Privacy-safe report: counts/status only, never holder/certificate/custom-field values. */
	public static function get_text_report( $result = null ) {
		$result = is_array( $result ) ? $result : self::get_last_result();
		if ( ! is_array( $result ) ) { return __( 'Certloom populated legacy migration preflight: not run.', 'certloom' ); }
		$lines = array(
			sprintf( 'Certloom populated legacy migration preflight: %s', strtoupper( sanitize_key( (string) ( $result['status'] ?? 'unknown' ) ) ) ),
			sprintf( 'Phase: %s', strtoupper( sanitize_key( (string) ( $result['phase'] ?? 'unknown' ) ) ) ),
			sprintf( 'Certloom version: %s', sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) ) ),
			sprintf( 'Certloom payload SHA-256: %s', sanitize_text_field( (string) ( $result['runtime_payload']['sha256'] ?? '' ) ) ),
			sprintf( 'Runtime environment SHA-256: %s', RuntimeEnvironmentEvidence::sha256( $result['runtime_environment'] ?? null ) ),
			sprintf( 'Rows in frozen source boundary: %d', absint( $result['source_count'] ?? 0 ) ),
			sprintf( 'Rows mapped: %d', absint( $result['total'] ?? 0 ) ),
			sprintf( 'Rows ready: %d', absint( $result['ready'] ?? 0 ) ),
			sprintf( 'Rows requiring review: %d', absint( $result['errors'] ?? 0 ) ),
			sprintf( 'Rows fingerprint-verified: %d', absint( $result['verify_total'] ?? 0 ) ),
			sprintf( 'Mapped status counts: valid=%d, suspended=%d, revoked=%d, expired=%d', absint( $result['status_counts']['valid'] ?? 0 ), absint( $result['status_counts']['suspended'] ?? 0 ), absint( $result['status_counts']['revoked'] ?? 0 ), absint( $result['status_counts']['expired'] ?? 0 ) ),
		);
		if ( ! empty( $result['checked_at'] ) ) { $lines[] = sprintf( 'Completed UTC: %s', gmdate( 'Y-m-d H:i:s', absint( $result['checked_at'] ) ) ); }
		if ( ! empty( $result['reason_code'] ) ) { $lines[] = sprintf( 'Result reason: %s', sanitize_key( (string) $result['reason_code'] ) ); }
		return implode( "\n", $lines );
	}

	/** @return true|\WP_Error */
	private static function preconditions() {
		if ( ! DomainAcceptanceProof::is_current_passed() ) {
			return new \WP_Error( 'certloom_legacy_preflight_domain_proof_required', __( 'Run a fresh passing gated Credential Series acceptance proof before scanning the populated legacy registry.', 'certloom' ) );
		}
		if ( ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY ) || ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) || ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_feature_gate_active', __( 'The populated migration preflight is only available before next-domain development features are enabled.', 'certloom' ) );
		}
		$migration = new LegacyMigrationState();
		if ( LegacyMigrationState::NOT_STARTED !== $migration->status() ) { return new \WP_Error( 'certloom_legacy_preflight_migration_started', __( 'The populated migration preflight requires the official legacy migration to be completely not started.', 'certloom' ) ); }
		$cutover = new DomainCutoverState();
		if ( ! $cutover->is_clean_legacy_state() ) { return new \WP_Error( 'certloom_legacy_preflight_cutover_active', __( 'The populated migration preflight is not available after credential authority has been cut over.', 'certloom' ) ); }
		if ( false !== get_option( 'certloom_domain_restore_journal', false ) || false !== get_option( 'certloom_domain_restore_build', false ) ) { return new \WP_Error( 'certloom_legacy_preflight_restore_state', __( 'The populated migration preflight will not run while domain restore/recovery state exists.', 'certloom' ) ); }
		$empty = self::assert_domain_empty();
		return is_wp_error( $empty ) ? $empty : true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function start_state() {
		$runtime_payload = RuntimePayloadEvidence::current();
		if ( ! is_array( $runtime_payload ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_runtime_payload_unavailable', __( 'Certloom could not fingerprint the exact installed plugin payload for migration-preflight evidence.', 'certloom' ) );
		}
		$runtime_environment = RuntimeEnvironmentEvidence::current();
		if ( ! is_array( $runtime_environment ) ) {
			return new \WP_Error( 'certloom_legacy_preflight_runtime_environment_unavailable', __( 'Certloom could not identify the exact WordPress/PHP/database environment for migration-preflight evidence.', 'certloom' ) );
		}
		$snapshot = ( new LegacyCertificateRepository() )->snapshot();
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		return array(
			'schema' => self::RESULT_SCHEMA, 'version' => self::RESULT_VERSION,
			'plugin_version' => defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '',
			'runtime_payload' => $runtime_payload,
			'runtime_environment' => $runtime_environment,
			'status' => 'running', 'phase' => 'map', 'reason_code' => '',
			'started_at' => time(), 'updated_at' => time(), 'checked_at' => 0,
			'preflight_date' => current_time( 'Y-m-d' ),
			'source_count' => absint( $snapshot['count'] ?? 0 ), 'source_max_id' => absint( $snapshot['max_id'] ?? 0 ), 'source_generation' => (string) ( $snapshot['generation'] ?? '' ),
			'cursor' => 0, 'total' => 0, 'ready' => 0, 'errors' => 0,
			'status_counts' => array( 'valid' => 0, 'suspended' => 0, 'revoked' => 0, 'expired' => 0 ),
			'error_examples' => array(),
			'map_fingerprint' => ( new LegacySourceFingerprint() )->seed(), 'verify_cursor' => 0, 'verify_total' => 0, 'verify_fingerprint' => ( new LegacySourceFingerprint() )->seed(),
		);
	}

	/** @return array<string,mixed> */
	private static function merge_summary( array $state, array $summary, array $rows ) {
		$state['total'] = absint( $state['total'] ?? 0 ) + absint( $summary['total'] ?? 0 );
		$state['ready'] = absint( $state['ready'] ?? 0 ) + absint( $summary['ready'] ?? 0 );
		$state['errors'] = absint( $state['errors'] ?? 0 ) + absint( $summary['errors'] ?? 0 );
		foreach ( array( 'valid', 'suspended', 'revoked', 'expired' ) as $code ) { $state['status_counts'][ $code ] = absint( $state['status_counts'][ $code ] ?? 0 ) + absint( $summary['status_counts'][ $code ] ?? 0 ); }
		$last = end( $rows );
		$state['cursor'] = absint( is_array( $last ) ? ( $last['id'] ?? $state['cursor'] ) : $state['cursor'] );
		$examples = isset( $state['error_examples'] ) && is_array( $state['error_examples'] ) ? $state['error_examples'] : array();
		foreach ( (array) ( $summary['error_details'] ?? array() ) as $detail ) {
			if ( count( $examples ) >= self::ERROR_LIMIT || ! is_array( $detail ) ) { break; }
			$examples[] = array( 'legacy_id' => absint( $detail['legacy_id'] ?? 0 ), 'message' => sanitize_text_field( (string) ( $detail['message'] ?? __( 'Migration mapping requires review.', 'certloom' ) ) ) );
		}
		$state['error_examples'] = $examples;
		$state['updated_at'] = time();
		return $state;
	}

	private static function map_complete( array $state ) {
		$count = absint( $state['source_count'] ?? 0 );
		return 0 === $count || ( absint( $state['total'] ?? 0 ) === $count && absint( $state['cursor'] ?? 0 ) >= absint( $state['source_max_id'] ?? 0 ) );
	}

	private static function verify_complete( array $state ) {
		$count = absint( $state['source_count'] ?? 0 );
		return 0 === $count || ( absint( $state['verify_total'] ?? 0 ) === $count && absint( $state['verify_cursor'] ?? 0 ) >= absint( $state['source_max_id'] ?? 0 ) );
	}

	private static function same_source_boundary( array $state, array $snapshot ) {
		return absint( $state['source_count'] ?? -1 ) === absint( $snapshot['count'] ?? -2 ) && absint( $state['source_max_id'] ?? -1 ) === absint( $snapshot['max_id'] ?? -2 ) && '' !== (string) ( $state['source_generation'] ?? '' ) && hash_equals( (string) $state['source_generation'], (string) ( $snapshot['generation'] ?? '' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function fail_state( array $state, $reason ) {
		$state['status'] = 'failed'; $state['reason_code'] = sanitize_key( (string) $reason ); $state['checked_at'] = time(); $state['updated_at'] = time();
		$saved = self::save_result( $state );
		return is_wp_error( $saved ) ? $saved : $state;
	}

	/** @return true|\WP_Error */
	private static function assert_domain_empty() {
		global $wpdb;
		$tables = array( 'recipients', 'issuers', 'programs', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'email_deliveries', 'jobs', 'import_rows', 'restore_map' );
		foreach ( $tables as $logical ) {
			$table = SchemaManager::table( $logical ); $wpdb->last_error = '';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_legacy_preflight_domain_inspect_failed', __( 'Certloom could not inspect the gated-domain tables before migration preflight.', 'certloom' ) ); }
			if ( (string) $exists !== (string) $table ) { continue; }
			$wpdb->last_error = ''; $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( '' !== (string) $wpdb->last_error || null === $count || 0 !== (int) $count ) { return new \WP_Error( 'certloom_legacy_preflight_domain_not_empty', __( 'The populated migration preflight requires the future Credential Series tables to be empty.', 'certloom' ) ); }
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	private static function get_raw_result() { $result = get_option( self::RESULT_OPTION, null ); return is_array( $result ) ? $result : null; }

	/** @return true|\WP_Error */
	private static function save_result( array $state ) {
		$saved = update_option( self::RESULT_OPTION, $state, false );
		return $saved || $state === get_option( self::RESULT_OPTION, null ) ? true : new \WP_Error( 'certloom_legacy_preflight_result_save_failed', __( 'Certloom could not save populated migration preflight progress.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private static function acquire_lock() {
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) > time() ) { return new \WP_Error( 'certloom_legacy_preflight_busy', __( 'Another populated migration preflight slice is already running.', 'certloom' ) ); }
		if ( null !== $existing && false !== $existing ) {
			$deleted = delete_option( self::LOCK_OPTION );
			if ( ! $deleted && false !== get_option( self::LOCK_OPTION, false ) ) { return new \WP_Error( 'certloom_legacy_preflight_stale_lock', __( 'Certloom could not clear an expired migration-preflight execution lock.', 'certloom' ) ); }
		}
		try { $owner = bin2hex( random_bytes( 24 ) ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_legacy_preflight_random_failed', __( 'Certloom could not prepare a secure migration-preflight owner token.', 'certloom' ) ); }
		$value = array( 'token_hash' => hash( 'sha256', $owner ), 'expires_at' => time() + self::LOCK_TTL );
		if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) { return new \WP_Error( 'certloom_legacy_preflight_lock_failed', __( 'Certloom could not acquire the migration-preflight execution lock.', 'certloom' ) ); }
		return $owner;
	}

	/** @return true|\WP_Error */
	private static function release_lock( $owner ) {
		$current = get_option( self::LOCK_OPTION, null ); $expected = hash( 'sha256', (string) $owner );
		if ( ! is_array( $current ) || empty( $current['token_hash'] ) || ! hash_equals( (string) $current['token_hash'], $expected ) ) { return new \WP_Error( 'certloom_legacy_preflight_lock_owner_mismatch', __( 'Certloom refused to release a migration-preflight lock owned by another process.', 'certloom' ) ); }
		$deleted = delete_option( self::LOCK_OPTION );
		return $deleted || false === get_option( self::LOCK_OPTION, false ) ? true : new \WP_Error( 'certloom_legacy_preflight_lock_release_failed', __( 'Certloom could not release the migration-preflight execution lock safely.', 'certloom' ) );
	}
}
