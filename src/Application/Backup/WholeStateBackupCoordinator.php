<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Job\JobQueue;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Domain\Job\JobTypes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Manual and scheduled requests use identical capture jobs; cron never creates a recovery key. */
final class WholeStateBackupCoordinator {
	private $queue;
	public function __construct( JobQueue $queue ) { $this->queue = $queue; }
	public function enqueue( $purpose, $actor_id = 0 ) {
		if ( ! in_array( $purpose, array( 'manual', 'scheduled', 'safety' ), true ) || ! is_int( $actor_id ) || $actor_id < 0 ) { return $this->invalid(); }
		$root = new WholeStateProvenanceRepository();
		if ( 'manual' === $purpose ) {
			$identity = $root->ensure( $actor_id );
		} else {
			$identity = $root->identity();
		}
		if ( is_wp_error( $identity ) ) { return $identity; }
		$job = $this->queue->enqueue( JobTypes::WHOLE_BACKUP, $actor_id, array( 'schema_version' => 1, 'purpose' => $purpose ) );
		if ( is_wp_error( $job ) && 'certloom_job_schedule_failed' === $job->get_error_code() ) { $data = $job->get_error_data(); if ( is_array( $data ) && is_string( $data['job_key'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $data['job_key'] ) ) { return $data['job_key']; } }
		return $job;
	}
	private function invalid() { return new \WP_Error( 'certloom_whole_backup_intent_invalid', __( 'Choose a valid complete recovery capture purpose.', 'certloom' ) ); }
}
