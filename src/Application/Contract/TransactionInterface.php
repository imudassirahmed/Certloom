<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Short transaction boundary for atomic state + event commands. */
interface TransactionInterface {
	/** @param callable $callback @return mixed|\WP_Error */
	public function run( callable $callback );
}
