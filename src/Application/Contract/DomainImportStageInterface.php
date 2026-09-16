<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Durable staging boundary used to carry validated import plans across requests. */
interface DomainImportStageInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function stage( $job_key, $row_number, array $plan );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function read_batch( $job_key, $after_row, $limit = 500, $outcome = 'ready' );
	/** @return array<string,int>|\WP_Error */
	public function summary( $job_key );
	/** @return true|\WP_Error */
	public function mark_committed( $job_key, $row_number, $outcome, $series_id = null, $version_id = null );
	/** @return array<string,int>|\WP_Error */
	public function commit_summary( $job_key );
	/** @return true|\WP_Error */
	public function clear( $job_key );
}
