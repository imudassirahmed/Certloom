<?php
namespace Certloom\Infrastructure\Job;

use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Contract\JobSchedulerInterface;
use Certloom\Application\Job\JobRunner;

/**
 * WordPress-local scheduler/dispatcher for bounded Certloom background jobs.
 *
 * Every job has a dedicated one-shot event for prompt progress. A lightweight
 * watchdog additionally recovers expired worker leases and reschedules queued
 * jobs if a site's cron queue lost an individual event.
 */
final class JobDispatcher implements JobSchedulerInterface {
	const RUN_HOOK          = 'certloom_run_job';
	const WATCHDOG_HOOK     = 'certloom_job_watchdog';
	const WATCHDOG_SCHEDULE = 'certloom_every_five_minutes';
	const WATCHDOG_INTERVAL = 300;
	const SLICE_DELAY       = 5;
	const WORKER_LOCK       = 300;

	/** @var JobRunner */
	private $runner;

	/** @var JobRepositoryInterface */
	private $jobs;

	/** @var JobRetentionService|null */
	private $retention;

	public function __construct( JobRunner $runner, JobRepositoryInterface $jobs, ?JobRetentionService $retention = null ) {
		$this->runner    = $runner;
		$this->jobs      = $jobs;
		$this->retention = $retention;
	}

	/** Registers hooks for the current WordPress site. */
	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::RUN_HOOK, array( $this, 'run_scheduled' ), 10, 1 );
		add_action( self::WATCHDOG_HOOK, array( $this, 'watchdog' ) );
		$this->ensure_watchdog();
	}

	/** @return array<string,array<string,mixed>> */
	public static function cron_schedules( array $schedules ) {
		if ( ! isset( $schedules[ self::WATCHDOG_SCHEDULE ] ) ) {
			$schedules[ self::WATCHDOG_SCHEDULE ] = array(
				'interval' => self::WATCHDOG_INTERVAL,
				'display'  => __( 'Every five minutes (Certloom)', 'certloom' ),
			);
		}
		return $schedules;
	}

	/** @return true|\WP_Error */
	public static function activate() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		if ( wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			return true;
		}
		$scheduled = wp_schedule_event( time() + MINUTE_IN_SECONDS, self::WATCHDOG_SCHEDULE, self::WATCHDOG_HOOK, array(), true );
		return is_wp_error( $scheduled ) || false === $scheduled
			? new \WP_Error( 'certloom_job_watchdog_schedule_failed', __( 'Certloom could not schedule its background-job watchdog.', 'certloom' ) )
			: true;
	}

	/** Clears all Certloom domain-job events for the current site. */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
	}

	/** @return true|\WP_Error */
	public function schedule( $job_key, $delay_seconds = 1 ) {
		$job_key = strtolower( trim( (string) $job_key ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_key ) ) {
			return new \WP_Error( 'certloom_job_key_invalid', __( 'The Certloom job identifier is invalid.', 'certloom' ) );
		}
		$args = array( $job_key );
		if ( wp_next_scheduled( self::RUN_HOOK, $args ) ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( time() + max( 1, absint( $delay_seconds ) ), self::RUN_HOOK, $args, true );
		return is_wp_error( $scheduled ) || false === $scheduled
			? new \WP_Error( 'certloom_job_schedule_failed', __( 'Certloom could not schedule the next background-job slice.', 'certloom' ) )
			: true;
	}

	/** Executes exactly one scheduled slice. */
	public function run_scheduled( $job_key ) {
		$worker_token = $this->worker_token();
		if ( is_wp_error( $worker_token ) ) {
			$this->report( $worker_token );
			return;
		}

		$result = $this->runner->run( (string) $job_key, $worker_token, self::WORKER_LOCK );
		if ( is_wp_error( $result ) ) {
			// Duplicate/racing cron events are expected to lose the atomic claim.
			if ( ! in_array( $result->get_error_code(), array( 'certloom_job_not_claimable', 'not_claimable' ), true ) ) {
				$this->report( $result );
			}
			return;
		}
		if ( 'queued' === (string) ( $result['status'] ?? '' ) ) {
			$scheduled = $this->schedule( (string) $job_key, self::SLICE_DELAY );
			if ( is_wp_error( $scheduled ) ) {
				$this->report( $scheduled );
			}
		}
	}

	/** Recovers stale worker leases and makes sure due queued work has an event. */
	public function watchdog() {
		$recovered = $this->jobs->recover_stale_running( 25 );
		if ( is_wp_error( $recovered ) ) {
			$this->report( $recovered );
			return;
		}

		$jobs = $this->jobs->find_due_queued( 25 );
		if ( is_wp_error( $jobs ) ) {
			$this->report( $jobs );
			return;
		}
		foreach ( $jobs as $job ) {
			$job_key = (string) ( $job['job_key'] ?? '' );
			$scheduled = $this->schedule( $job_key, 1 );
			if ( is_wp_error( $scheduled ) ) {
				$this->report( $scheduled );
			}
		}

		if ( $this->retention ) {
			$cleaned = $this->retention->maybe_cleanup();
			if ( is_wp_error( $cleaned ) ) {
				$this->report( $cleaned );
			}
		}
	}

	/** Ensures plugin updates on already-active sites also gain the watchdog. */
	private function ensure_watchdog() {
		if ( wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			return;
		}
		$scheduled = wp_schedule_event( time() + MINUTE_IN_SECONDS, self::WATCHDOG_SCHEDULE, self::WATCHDOG_HOOK, array(), true );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			$this->report( new \WP_Error( 'certloom_job_watchdog_schedule_failed', __( 'Certloom could not schedule its background-job watchdog.', 'certloom' ) ) );
		}
	}

	/** @return string|\WP_Error */
	private function worker_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_job_worker_token_failed', __( 'Certloom could not create a secure background-worker identifier.', 'certloom' ) );
		}
	}

	private function report( \WP_Error $error ) {
		if ( function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $error->get_error_message() );
		}
	}
}
