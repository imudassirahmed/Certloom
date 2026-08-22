<?php
namespace Certloom\Infrastructure\Migration;

use Certloom\Application\Migration\LegacyMigrationCommitService;
use Certloom\Application\Migration\LegacyMigrationPreflightService;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable coordinator for the in-place 1.5 registry -> Series/Version migration.
 *
 * It snapshots the existing legacy data-generation marker before preflight and
 * refuses commit if the source registry changes before migration completes.
 * Each target row is still written in its own short transaction by the writer.
 */
final class LegacyMigrationCoordinator {
	private $source;
	private $preflight;
	private $commit;
	private $state;

	public function __construct( LegacyCertificateRepository $source = null, LegacyMigrationPreflightService $preflight = null, LegacyMigrationCommitService $commit = null, LegacyMigrationState $state = null ) {
		$this->source = $source ?: new LegacyCertificateRepository();
		$this->preflight = $preflight ?: new LegacyMigrationPreflightService();
		$this->commit = $commit ?: new LegacyMigrationCommitService( new LegacyMigrationWriter() );
		$this->state = $state ?: new LegacyMigrationState();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function preflight_batch( $limit = 250, $today = null ) {
		$today = $today ?: current_time( 'Y-m-d' );
		$state = $this->state->get();
		$status = $this->state->status();
		if ( LegacyMigrationState::NOT_STARTED === $status || LegacyMigrationState::BLOCKED === $status ) {
			$snapshot = $this->source->snapshot();
			if ( is_wp_error( $snapshot ) ) { return $snapshot; }
			$started = $this->state->begin_preflight( $snapshot, $today );
			if ( is_wp_error( $started ) ) { return $started; }
			$state = $this->state->get();
		} elseif ( LegacyMigrationState::PREFLIGHTING !== $status ) {
			return $state;
		}

		$current = $this->source->snapshot();
		if ( is_wp_error( $current ) ) { return $current; }
		if ( ! $this->same_source_boundary( $state, $current ) ) {
			$this->state->block( 'source_changed_during_preflight' );
			return new \WP_Error( 'certloom_legacy_source_changed', __( 'The legacy certificate registry changed during migration preflight. Start preflight again from a fresh snapshot.', 'certloom' ) );
		}

		$cursor = absint( $state['preflight_cursor'] ?? 0 );
		$max_id = absint( $state['source_max_id'] ?? 0 );
		$rows = $this->source->read_batch( $cursor, $max_id, $limit );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			$finished = $this->state->finish_preflight();
			return is_wp_error( $finished ) ? $finished : $this->state->get();
		}

		$summary = $this->preflight->preflight( $rows, (string) ( $state['preflight_date'] ?? $today ) );
		$last = absint( end( $rows )['id'] ?? $cursor );
		$saved = $this->state->record_preflight_batch( $last, $summary );
		if ( is_wp_error( $saved ) ) { return $saved; }

		$state = $this->state->get();
		if ( $last >= $max_id || absint( $state['preflight_total'] ?? 0 ) >= absint( $state['source_count'] ?? 0 ) ) {
			$finished = $this->state->finish_preflight();
			if ( is_wp_error( $finished ) ) { return $finished; }
		}
		return $this->state->get();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function commit_batch( $limit = 100 ) {
		$state = $this->state->get();
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
			if ( absint( $state['migrated'] ?? 0 ) + absint( $state['skipped'] ?? 0 ) !== absint( $state['source_count'] ?? 0 ) ) {
				$this->state->block( 'migration_count_mismatch' );
				return new \WP_Error( 'certloom_legacy_migration_count_mismatch', __( 'Legacy migration completed its source range but the migrated row count does not match the preflight snapshot.', 'certloom' ) );
			}
			$done = $this->state->mark_complete();
			return is_wp_error( $done ) ? $done : $this->state->get();
		}

		$result = $this->commit->commit( $rows );
		$migrated = absint( $state['migrated'] ?? 0 ) + absint( $result['migrated'] ?? 0 );
		$skipped = absint( $state['skipped'] ?? 0 ) + absint( $result['skipped'] ?? 0 );
		$failed = absint( $state['failed'] ?? 0 ) + absint( $result['failed'] ?? 0 );
		$saved = $this->state->record_progress( absint( $result['last_legacy_id'] ?? $cursor ), $migrated, $skipped, $failed );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( $failed > 0 ) {
			$this->state->block( 'migration_write_failed' );
			return new \WP_Error( 'certloom_legacy_migration_write_failed', __( 'Legacy migration stopped because at least one row could not be written safely.', 'certloom' ) );
		}
		return $this->state->get();
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
