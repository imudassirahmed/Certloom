<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
interface JobSchedulerInterface {
	/** @return true|\WP_Error */
	public function schedule( $job_key, $delay_seconds = 1 );
}
