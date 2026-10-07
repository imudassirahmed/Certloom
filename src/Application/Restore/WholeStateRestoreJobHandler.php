<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Database\DatabaseSession;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobRepository;
use Certloom\Infrastructure\Restore\WholeStateRestoreIntentStore;
use Certloom\Infrastructure\Restore\WholeStateRestoreJournal;
use Certloom\Infrastructure\Restore\WholeStateRestoreWorkspace;
use Certloom\Infrastructure\Data\MaintenanceMutex;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A current native lease and immutable local approval own each bounded recovery slice. */
final class WholeStateRestoreJobHandler implements JobHandlerInterface {
	public function handle( array $job, $worker_token ) {
		$key = $job['job_key'] ?? null;
		$payload = $job['payload'] ?? null;
		$store = new WholeStateRestoreIntentStore();
		if ( JobTypes::WHOLE_RESTORE !== ( $job['job_type'] ?? null ) || ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/D', $key )
			|| ! is_array( $payload ) || count( $payload ) !== 3 || array_diff( array_keys( $payload ), array( 'schema_version', 'intent', 'source' ) ) || 1 !== $payload['schema_version']
			|| ! $store->valid_descriptor( $payload['intent'], 'whole-restore-intent' ) || ! $store->valid_descriptor( $payload['source'], 'whole-restore-source' )
			|| ! is_string( $worker_token ) || '' === $worker_token || strlen( $worker_token ) > 128 ) { return $this->invalid(); }
		return DatabaseSession::run( function () use ( $job, $worker_token, $key ) {
			global $wpdb;
			$name = self::lock_name( $key );
			$wpdb->last_error = '';
			$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
			if ( '' !== (string) $wpdb->last_error || ! in_array( (string) $held, array( '0', '1' ), true ) ) { return $this->invalid(); }
			if ( '0' === (string) $held ) { $valid = $this->current_lease( $job, $worker_token ); return is_wp_error( $valid ) ? $valid : JobSliceResult::progress( (int) ( $job['cursor'] ?? 0 ), $job['phase'] ?? null, $job['result'] ?? array() ); }
			try {
				$result = MaintenanceMutex::run( function () use ( $job, $worker_token ) {
					$valid = $this->current_lease( $job, $worker_token );
					return is_wp_error( $valid ) ? $valid : $this->handle_owned( $job, $worker_token );
				} );
			} finally {
				$wpdb->last_error = '';
				$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
			return '1' === (string) $released && '' === (string) $wpdb->last_error ? $result : $this->invalid();
		} );
	}

	public static function lock_name( $key ) { global $wpdb; return 'certloom-whole-restore-' . substr( hash( 'sha256', $wpdb->options . '|' . $key ), 0, 40 ); }

	private function handle_owned( array $job, $worker_token ) {
		$key = $job['job_key'];
		$payload = $job['payload'];
		$workspace = new WholeStateRestoreWorkspace();
		$journal = ( new WholeStateRestoreJournal() )->read();
		$receipt = $workspace->receipt( $key );
		if ( is_wp_error( $journal ) || is_wp_error( $receipt ) ) { return $this->invalid(); }
		if ( $journal && ( $journal['owner_token'] !== $key || $journal['source_sha256'] !== $payload['source']['sha256'] ) ) { return $this->invalid(); }
		if ( $journal && 'committed' === $journal['phase'] ) {
			$result = $workspace->recover();
			if ( is_wp_error( $result ) ) { return $result; }
			$journal = ( new WholeStateRestoreJournal() )->read();
			if ( is_wp_error( $journal ) ) { return $journal; }
			if ( $journal ) { return $this->progress( $job, 'cleanup', $journal['counts'] ); }
			$receipt = $workspace->receipt( $key );
		}
		if ( $receipt && ! $journal ) {
			if ( is_wp_error( $receipt ) || $receipt['source_sha256'] !== $payload['source']['sha256'] ) { return $this->invalid(); }
			$cleaned = ( new WholeStateRestoreIntentStore() )->cleanup( $payload['intent'], $payload['source'] );
			return is_wp_error( $cleaned ) ? $cleaned : JobSliceResult::succeeded( array( 'scope' => 'whole_state', 'receipt' => $receipt, 'working_files_removed' => true ), $receipt['records'] );
		}
		if ( $journal && 'building' !== $journal['phase'] && 'ready' !== $journal['phase'] ) { return $this->invalid(); }
		$packet = ( new WholeStateRestoreIntentStore() )->read( $payload['intent'], $payload['source'] );
		if ( is_wp_error( $packet ) || $packet['actor'] !== (int) ( $job['owner_user_id'] ?? 0 ) ) { return $this->invalid(); }
		$prior_user = get_current_user_id();
		try {
			wp_set_current_user( $packet['actor'] );
			$accounts = WholeStateAccountMappingProof::review( $payload['source']['path'], $payload['source']['sha256'], $packet['actor'], $packet['accounts'], 'reviewed_target' );
			$objects = WholeStateObjectMappingProof::review( $payload['source']['path'], $payload['source']['sha256'], $packet['actor'], $packet['objects'], 'reviewed_target' );
			if ( is_wp_error( $accounts ) || is_wp_error( $objects ) ) { return $this->invalid(); }
			$review = WholeStateRestoreReview::review( $payload['source']['path'], $payload['source']['sha256'], $accounts, $objects, $packet['images'] );
			if ( is_wp_error( $review ) || ! hash_equals( $packet['fingerprint'], $review->fingerprint() ) ) { return $this->invalid(); }
			// Source review can outlive a native lease on a slow host. Recheck
			// ownership immediately before starting any DDL, staging or promotion.
			$valid = $this->current_lease( $job, $worker_token ); if ( is_wp_error( $valid ) ) { return $valid; }
			if ( ! $journal ) {
				$journal = $workspace->start( $review, $key );
				if ( is_wp_error( $journal ) ) { return $journal; }
			}
			if ( 'building' === $journal['phase'] ) {
				// Reuse this request's freshly reviewed source for a short work window.
				// Each SQL slice remains bounded and rechecks live targets and ownership.
				// A slow host yields after five seconds; a busy host cannot extend its lease.
				$deadline = microtime( true ) + 5;
				for ( $slice = 0; $slice < 10; ++$slice ) {
					$valid = $this->current_lease( $job, $worker_token ); if ( is_wp_error( $valid ) ) { return $valid; }
					$journal = $workspace->continue_stage( $key, $review, 200 );
					if ( is_wp_error( $journal ) || 'building' !== $journal['phase'] || microtime( true ) >= $deadline ) { break; }
				}
				return is_wp_error( $journal ) ? $journal : $this->progress( $job, $journal['phase'], $journal['counts'] );
			}
			$committed = $workspace->commit( $key, $review );
			if ( is_wp_error( $committed ) ) { return $committed; }
			// The durable local receipt drives cleanup on the next slice, even if
			// saving this job's cursor is interrupted after the database commit.
			return $this->progress( $job, 'cleanup', $journal['counts'] );
		} finally { wp_set_current_user( $prior_user ); }
	}

	private function progress( array $job, $phase, array $counts ) {
		return JobSliceResult::progress( (int) ( $job['cursor'] ?? 0 ) + 1, $phase, array( 'scope' => 'whole_state', 'counts' => $counts ) );
	}

	private function current_lease( array $job, $worker_token ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT locked_by FROM %i WHERE job_key=%s AND job_type=%s AND status=%s AND locked_until>=%s', SchemaManager::table( 'jobs' ), $job['job_key'], JobTypes::WHOLE_RESTORE, 'running', gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || $row['locked_by'] !== $worker_token ) { return $this->invalid(); }
		$current = ( new JobRepository() )->find( $job['job_key'] );
		if ( ! is_array( $current ) ) { return $this->invalid(); }
		$canonical = new CanonicalJson();
		foreach ( array( 'owner_user_id', 'job_type', 'cursor', 'phase', 'payload', 'result' ) as $field ) { if ( $canonical->hash( $current[$field] ?? null ) !== $canonical->hash( $job[$field] ?? null ) ) { return $this->invalid(); } }
		return true;
	}

	private function invalid() {
		return new \WP_Error( 'certloom_whole_restore_job_invalid', __( 'Certloom could not confirm this recovery worker, its current target review, or its durable receipt. Review the paused recovery before retrying.', 'certloom' ) );
	}
}
