<?php
namespace Certloom\Application\Contract;

interface JobRepositoryInterface {
	/** @return string|\WP_Error */
	public function create( array $data );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $job_key );
	/** @return array<string,mixed>|\WP_Error */
	public function claim( $job_key, $worker_token, $lock_seconds );
	/** @return true|\WP_Error */
	public function save_progress( $job_key, $worker_token, array $progress );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function find_due_queued( $limit = 20 );
	/** @return int|\WP_Error */
	public function recover_stale_running( $limit = 20 );
	/** @return true|\WP_Error */
	public function transition( $job_key, $expected_state, $new_state, array $changes = array(), $worker_token = null );
}
