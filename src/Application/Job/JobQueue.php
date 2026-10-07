<?php
namespace Certloom\Application\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\JobSchedulerInterface;

/** Creates durable jobs and immediately requests their first local WP-Cron slice. */
final class JobQueue {
	/** @var JobService */
	private $jobs;

	/** @var JobSchedulerInterface */
	private $scheduler;

	public function __construct( JobService $jobs, JobSchedulerInterface $scheduler ) {
		$this->jobs      = $jobs;
		$this->scheduler = $scheduler;
	}

	/** @return string|\WP_Error */
	public function enqueue( $type, $owner_user_id = 0, array $payload = array(), $total = null, $selection_max_id = null, $parent_job_key = null ) {
		$job_key = $this->jobs->create( $type, $owner_user_id, $payload, $total, $selection_max_id, $parent_job_key );
		if ( is_wp_error( $job_key ) ) {
			return $job_key;
		}

		$scheduled = $this->scheduler->schedule( $job_key );
		if ( is_wp_error( $scheduled ) ) {
			return new \WP_Error(
				'certloom_job_schedule_failed',
				__( 'Certloom saved the background job but could not schedule its first worker slice. The watchdog will retry queued work.', 'certloom' ),
				array( 'job_key' => $job_key )
			);
		}
		return $job_key;
	}
}
