<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
interface DomainImportTargetInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function import( $row_number, array $plan, $duplicate_policy = 'skip' );
}
