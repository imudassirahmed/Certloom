<?php
namespace Certloom\Application\Job;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\JobRepositoryInterface;

/** Claims one queued job and executes exactly one bounded handler slice. */
final class JobRunner {
	/** @var JobService */
	private $service;

	/** @var JobRepositoryInterface */
	private $jobs;

	/** @var array<string,JobHandlerInterface> */
	private $handlers = array();

	/** @param array<string,JobHandlerInterface> $handlers */
	public function __construct( JobService $service, JobRepositoryInterface $jobs, array $handlers = array() ) {
		$this->service = $service;
		$this->jobs    = $jobs;

		foreach ( $handlers as $type => $handler ) {
			$this->register( $type, $handler );
		}
	}

	public function register( $type, JobHandlerInterface $handler ) {
		$type = sanitize_key( (string) $type );
		if ( '' === $type ) {
			throw new \InvalidArgumentException( 'Job handler type is required.' );
		}
		$this->handlers[ $type ] = $handler;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function run( $job_key, $worker_token, $lock_seconds = 120 ) {
		$job = $this->service->begin( $job_key, $worker_token, $lock_seconds );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$type = sanitize_key( (string) ( $job['job_type'] ?? '' ) );
		if ( ! isset( $this->handlers[ $type ] ) ) {
			return $this->fail_job(
				$job_key,
				$worker_token,
				new \WP_Error(
					'certloom_job_handler_missing',
					__( 'No Certloom handler is registered for this background job type.', 'certloom' )
				)
			);
		}

		try {
			$slice = $this->handlers[ $type ]->handle( $job, $worker_token );
		} catch ( \Throwable $error ) {
			$slice = new \WP_Error(
				'certloom_job_handler_exception',
				__( 'The Certloom background job encountered an unexpected error.', 'certloom' )
			);
		}

		if ( is_wp_error( $slice ) ) {
			return $this->fail_job( $job_key, $worker_token, $slice );
		}
		if ( ! $slice instanceof JobSliceResult ) {
			return $this->fail_job(
				$job_key,
				$worker_token,
				new \WP_Error(
					'certloom_job_handler_result_invalid',
					__( 'A Certloom background job returned an invalid slice result.', 'certloom' )
				)
			);
		}

		switch ( $slice->state() ) {
			case JobSliceResult::PROGRESS:
				return $this->save_progress_and_yield( $job_key, $worker_token, $slice, $lock_seconds );

			case JobSliceResult::SUCCEEDED:
				$done = $this->service->succeed( $job_key, $worker_token, $slice->result() );
				return is_wp_error( $done ) ? $done : array( 'status' => 'succeeded', 'result' => $slice->result() );

			case JobSliceResult::PARTIAL:
				$done = $this->service->partially_succeed( $job_key, $worker_token, $slice->result() );
				return is_wp_error( $done ) ? $done : array( 'status' => 'partially_succeeded', 'result' => $slice->result() );

			default:
				$done = $this->service->fail( $job_key, $worker_token, $slice->error(), $slice->result() );
				return is_wp_error( $done )
					? $done
					: array( 'status' => 'failed', 'error' => $slice->error(), 'result' => $slice->result() );
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	private function save_progress_and_yield( $job_key, $worker_token, JobSliceResult $slice, $lock_seconds ) {
		$saved = $this->service->progress(
			$job_key,
			$worker_token,
			$slice->cursor(),
			$slice->phase(),
			$slice->result(),
			$lock_seconds
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$yielded = $this->service->yield_work( $job_key, $worker_token );
		return is_wp_error( $yielded )
			? $yielded
			: array(
				'status' => 'queued',
				'cursor' => $slice->cursor(),
				'phase'  => $slice->phase(),
				'result' => $slice->result(),
			);
	}

	/** @return \WP_Error */
	private function fail_job( $job_key, $worker_token, \WP_Error $error ) {
		$failed = $this->service->fail(
			$job_key,
			$worker_token,
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			)
		);

		return is_wp_error( $failed ) ? $failed : $error;
	}
}
