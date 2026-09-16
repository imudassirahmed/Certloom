<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Job\JobSliceResult;

/** One bounded resumable unit of work for a registered Certloom job type. */
interface JobHandlerInterface {
	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token );
}
