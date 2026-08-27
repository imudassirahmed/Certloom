<?php
namespace Certloom\Application\Contract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

interface IssuanceAnalyticsRepositoryInterface {
	/** @return true|\WP_Error */
	public function scan_issuance_rows( $start, $end, $consumer );
	/** @return array<string,mixed>|\WP_Error */
	public function expiry_forecast( $today, $horizon_days = 90 );
}
