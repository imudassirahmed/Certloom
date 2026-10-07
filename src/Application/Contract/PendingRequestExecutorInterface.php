<?php
namespace Certloom\Application\Contract;
if ( ! defined( 'ABSPATH' ) ) { exit; }
interface PendingRequestExecutorInterface {
	/** Assess one server-owned request; return the current durable row or WP_Error. */
	public function process( $request_id );
}
