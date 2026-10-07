<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Job\JobQueue;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Restore\WholeStateRestoreIntentStore;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Job\JobRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Queue only a current typed review; confirmation is enforced by its native admin action. */
final class WholeStateRestoreCoordinator {
	private $queue;
	public function __construct( JobQueue $queue ) { $this->queue = $queue; }

	public function enqueue( WholeStateRestoreReview $review, ?callable $on_queued = null ) {
		$store = new WholeStateRestoreIntentStore();
		$stored = $store->store( $review );
		if ( is_wp_error( $stored ) ) { return $stored; }
		$key = null;
		$create = function () use ( $review, $stored, $on_queued, &$key ) {
			$job = $this->queue->enqueue( JobTypes::WHOLE_RESTORE, $review->packet()['actor'], array( 'schema_version' => 1, 'intent' => $stored['intent'], 'source' => $stored['source'] ), array_sum( $review->counts() ) );
			// The watchdog owns scheduling recovery after a durable enqueue.
			if ( is_wp_error( $job ) && 'certloom_job_schedule_failed' === $job->get_error_code() ) { $data = $job->get_error_data(); if ( is_array( $data ) && is_string( $data['job_key'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $data['job_key'] ) ) { $job = $data['job_key']; } }
			if ( is_wp_error( $job ) ) { return $job; }
			$key = $job;
			if ( $on_queued ) { $bound = $on_queued( $job ); if ( is_wp_error( $bound ) ) { return $bound; } if ( true !== $bound ) { return new \WP_Error( 'certloom_recovery_handoff_failed', __( 'The recovery review could not be consumed safely. No replacement was authorized.', 'certloom' ) ); } }
			return $job;
		};
		// File copying completes before this short SQL handoff. Consuming the
		// native draft and creating its job either commit together or roll back.
		$job = $on_queued ? ( new Transaction() )->run( $create ) : $create();
		if ( is_wp_error( $job ) ) {
			$persisted = null === $key ? null : ( new JobRepository() )->find( $key );
			if ( null === $persisted && 'certloom_job_schedule_failed' !== $job->get_error_code() ) { $store->cleanup( $stored['intent'], $stored['source'] ); }
		}
		return $job;
	}
}
