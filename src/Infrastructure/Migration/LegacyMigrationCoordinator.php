<?php
namespace Certloom\Infrastructure\Migration;

use Certloom\Application\Migration\LegacyMigrationCommitService;
use Certloom\Application\Migration\LegacyMigrationPreflightService;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Migration\LegacySourceFingerprint;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable coordinator for the in-place 1.5 registry -> Series/Version migration.
 *
 * Official preflight performs two complete source reads with the same semantic
 * fingerprint contract used by diagnostics. Commit slices acquire the shared
 * maintenance boundary so legacy writes cannot race the source check/batch read
 * and target writes inside one slice.
 */
final class LegacyMigrationCoordinator {
	const OWNER_OPTION = 'certloom_legacy_migration_maintenance_owner';
	const LOCK_TTL     = 300;

	private $source;
	private $preflight;
	private $commit;
	private $state;
	private $maintenance;
	private $fingerprint;

	public function __construct(
		?LegacyCertificateRepository $source = null,
		?LegacyMigrationPreflightService $preflight = null,
		?LegacyMigrationCommitService $commit = null,
		?LegacyMigrationState $state = null,
		?DomainMaintenanceLock $maintenance = null,
		?LegacySourceFingerprint $fingerprint = null
	) {
		$this->source      = $source ?: new LegacyCertificateRepository();
		$this->preflight   = $preflight ?: new LegacyMigrationPreflightService();
		$this->commit      = $commit ?: new LegacyMigrationCommitService( new LegacyMigrationWriter() );
		$this->state       = $state ?: new LegacyMigrationState();
		$this->maintenance = $maintenance ?: new DomainMaintenanceLock();
		$this->fingerprint = $fingerprint ?: new LegacySourceFingerprint();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function preflight_batch( $limit = 250, $today = null ) {
		$today  = $today ?: current_time( 'Y-m-d' );
		$state  = $this->state->get();
		$status = $this->state->status();
		if ( LegacyMigrationState::NOT_STARTED === $status || LegacyMigrationState::BLOCKED === $status ) {
			$snapshot = $this->source->snapshot();
			if ( is_wp_error( $snapshot ) ) { return $snapshot; }
			$started = $this->state->begin_preflight( $snapshot, $today );
			if ( is_wp_error( $started ) ) { return $started; }
			$state  = $this->state->get();
			$status = $this->state->status();
		}

		if ( LegacyMigrationState::PREFLIGHTING !== $status && LegacyMigrationState::PREFLIGHT_VERIFYING !== $status ) {
			return $state;
		}

		$current = $this->source->snapshot();
		if ( is_wp_error( $current ) ) { return $current; }
		if ( ! $this->same_source_boundary( $state, $current ) ) {
			$this->state->block( 'source_changed_during_preflight' );
			return new \WP_Error( 'certloom_legacy_source_changed', __( 'The legacy certificate registry changed during migration preflight. Start preflight again from a fresh snapshot.', 'certloom' ) );
		}

		if ( LegacyMigrationState::PREFLIGHTING === $status ) {
			return $this->preflight_map_batch( $state, $limit, $today );
		}
		return $this->preflight_verify_batch( $state, $limit );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function preflight_map_batch( array $state, $limit, $today ) {
		$cursor = absint( $state['preflight_cursor'] ?? 0 );
		$max_id = absint( $state['source_max_id'] ?? 0 );
		$rows = $this->source->read_batch( $cursor, $max_id, $limit );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			$started = $this->state->begin_preflight_verification();
			return is_wp_error( $started ) ? $started : $this->state->get();
		}

		$fingerprint = $this->fingerprint->advance( (string) ( $state['preflight_fingerprint'] ?? $this->fingerprint->seed() ), $rows );
		if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
		$summary = $this->preflight->preflight( $rows, (string) ( $state['preflight_date'] ?? $today ) );
		$last = absint( end( $rows )['id'] ?? $cursor );
		$saved = $this->state->record_preflight_batch( $last, $summary, $fingerprint );
		if ( is_wp_error( $saved ) ) { return $saved; }

		$state = $this->state->get();
		if ( $last >= $max_id || absint( $state['preflight_total'] ?? 0 ) >= absint( $state['source_count'] ?? 0 ) ) {
			$started = $this->state->begin_preflight_verification();
			if ( is_wp_error( $started ) ) { return $started; }
		}
		return $this->state->get();
	}

	/** @return array<string,mixed>|\WP_Error */
	private function preflight_verify_batch( array $state, $limit ) {
		$cursor = absint( $state['verify_cursor'] ?? 0 );
		$max_id = absint( $state['source_max_id'] ?? 0 );
		$rows = $this->source->read_batch( $cursor, $max_id, $limit );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			$finished = $this->state->finish_preflight_verification();
			return is_wp_error( $finished ) ? $finished : $this->state->get();
		}

		$fingerprint = $this->fingerprint->advance( (string) ( $state['verify_fingerprint'] ?? $this->fingerprint->seed() ), $rows );
		if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
		$last = absint( end( $rows )['id'] ?? $cursor );
		$saved = $this->state->record_preflight_verification_batch( $last, count( $rows ), $fingerprint );
		if ( is_wp_error( $saved ) ) { return $saved; }
		$state = $this->state->get();
		if ( $last >= $max_id || absint( $state['verify_total'] ?? 0 ) >= absint( $state['source_count'] ?? 0 ) ) {
			$finished = $this->state->finish_preflight_verification();
			if ( is_wp_error( $finished ) ) { return $finished; }
		}
		return $this->state->get();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function commit_batch( $limit = 100 ) {
		$cleanup = $this->recover_previous_cleanup();
		if ( is_wp_error( $cleanup ) ) { return $cleanup; }

		$status = $this->state->status();
		if ( LegacyMigrationState::PREFLIGHT_READY !== $status && LegacyMigrationState::MIGRATING !== $status ) {
			return new \WP_Error( 'certloom_legacy_migration_not_ready', __( 'Legacy migration is not ready to commit.', 'certloom' ) );
		}

		$owner = $this->new_owner_token();
		if ( is_wp_error( $owner ) ) { return $owner; }
		$locked = $this->maintenance->acquire( $owner, 'legacy_migration_commit', self::LOCK_TTL );
		if ( is_wp_error( $locked ) ) { return $locked; }

		$result = DomainMaintenanceLock::run_internal( function () use ( $limit ) {
			return $this->commit_batch_locked( $limit );
		} );

		$released = $this->maintenance->release( $owner );
		if ( is_wp_error( $released ) ) {
			return new \WP_Error(
				'certloom_legacy_migration_cleanup_failed',
				sprintf(
					/* translators: 1: migration result, 2: maintenance cleanup error. */
					__( 'Certloom migration could not finish its maintenance cleanup. Migration result: %1$s Cleanup error: %2$s Credential writes remain paused until cleanup is retried or the lease expires.', 'certloom' ),
					is_wp_error( $result ) ? $result->get_error_message() : __( 'migration slice completed', 'certloom' ),
					$released->get_error_message()
				)
			);
		}
		$cleared = $this->clear_owner_token( $owner );
		if ( is_wp_error( $cleared ) ) { return $cleared; }
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function commit_batch_locked( $limit ) {
		$state  = $this->state->get();
		$status = $this->state->status();
		if ( LegacyMigrationState::PREFLIGHT_READY === $status ) {
			$current = $this->source->snapshot();
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! $this->same_source_boundary( $state, $current ) ) {
				$this->state->block( 'source_changed_after_preflight' );
				return new \WP_Error( 'certloom_legacy_source_changed', __( 'The legacy certificate registry changed after preflight. A fresh preflight is required before migration.', 'certloom' ) );
			}
			$begun = $this->state->begin_commit();
			if ( is_wp_error( $begun ) ) { return $begun; }
			$state = $this->state->get();
		} elseif ( LegacyMigrationState::MIGRATING !== $status ) {
			return new \WP_Error( 'certloom_legacy_migration_not_ready', __( 'Legacy migration is not ready to commit.', 'certloom' ) );
		}

		$current = $this->source->snapshot();
		if ( is_wp_error( $current ) ) { return $current; }
		if ( ! $this->same_source_boundary( $state, $current ) ) {
			$this->state->block( 'source_changed_during_commit' );
			return new \WP_Error( 'certloom_legacy_source_changed', __( 'The legacy certificate registry changed while migration was running. Migration has been blocked before public cutover.', 'certloom' ) );
		}

		$cursor = absint( $state['cursor'] ?? 0 );
		$max_id = absint( $state['source_max_id'] ?? 0 );
		$rows = $this->source->read_batch( $cursor, $max_id, $limit );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			$done = $this->state->mark_complete();
			return is_wp_error( $done ) ? $done : $this->state->get();
		}

		$result = $this->commit->commit( $rows );
		$migrated = absint( $state['migrated'] ?? 0 ) + absint( $result['migrated'] ?? 0 );
		$skipped  = absint( $state['skipped'] ?? 0 ) + absint( $result['skipped'] ?? 0 );
		$failed   = absint( $state['failed'] ?? 0 ) + absint( $result['failed'] ?? 0 );
		$saved = $this->state->record_progress( absint( $result['last_legacy_id'] ?? $cursor ), $migrated, $skipped, $failed );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( $failed > 0 ) {
			$this->state->block( 'migration_write_failed' );
			return new \WP_Error( 'certloom_legacy_migration_write_failed', __( 'Legacy migration stopped because at least one row could not be written safely.', 'certloom' ) );
		}
		return $this->state->get();
	}

	/** @return true|\WP_Error */
	private function recover_previous_cleanup() {
		$stored = strtolower( trim( (string) get_option( self::OWNER_OPTION, '' ) ) );
		if ( '' === $stored ) { return true; }
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $stored ) ) {
			if ( $this->maintenance->active() ) {
				return new \WP_Error( 'certloom_legacy_migration_owner_corrupt', __( 'Certloom found an invalid migration cleanup owner while maintenance is active. Wait for maintenance recovery before retrying migration.', 'certloom' ) );
			}
			return $this->clear_owner_token( $stored );
		}
		$released = $this->maintenance->release( $stored );
		if ( is_wp_error( $released ) ) { return $released; }
		return $this->clear_owner_token( $stored );
	}

	/** @return string|\WP_Error */
	private function new_owner_token() {
		try {
			$owner = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_legacy_migration_owner_random_failed', __( 'Certloom could not generate a safe migration maintenance owner.', 'certloom' ) );
		}
		$saved = update_option( self::OWNER_OPTION, $owner, false );
		return $saved || hash_equals( $owner, (string) get_option( self::OWNER_OPTION, '' ) )
			? $owner
			: new \WP_Error( 'certloom_legacy_migration_owner_save_failed', __( 'Certloom could not save its migration maintenance owner, so migration was not started.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private function clear_owner_token( $expected = '' ) {
		$current = strtolower( trim( (string) get_option( self::OWNER_OPTION, '' ) ) );
		if ( '' !== $expected && preg_match( '/^[a-f0-9]{32}$/', (string) $expected ) && '' !== $current && ! hash_equals( strtolower( (string) $expected ), $current ) ) {
			return new \WP_Error( 'certloom_legacy_migration_owner_mismatch', __( 'Certloom refused to clear a migration cleanup owner created by another process.', 'certloom' ) );
		}
		$deleted = delete_option( self::OWNER_OPTION );
		return $deleted || false === get_option( self::OWNER_OPTION, false )
			? true
			: new \WP_Error( 'certloom_legacy_migration_owner_clear_failed', __( 'Certloom could not clear its migration cleanup owner. Retry cleanup before continuing migration.', 'certloom' ) );
	}

	/** @return bool */
	private function same_source_boundary( array $state, array $snapshot ) {
		if ( ! isset( $state['source_count'], $state['source_max_id'], $state['source_generation'], $snapshot['count'], $snapshot['max_id'], $snapshot['generation'] ) ) {
			return false;
		}
		return absint( $state['source_count'] ) === absint( $snapshot['count'] )
			&& absint( $state['source_max_id'] ) === absint( $snapshot['max_id'] )
			&& (string) $state['source_generation'] === (string) $snapshot['generation'];
	}
}
