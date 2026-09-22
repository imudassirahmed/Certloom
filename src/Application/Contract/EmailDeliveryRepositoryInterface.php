<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
interface EmailDeliveryRepositoryInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version( $version_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_delivery( $delivery_key );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_delivery_by_job( $job_key );
	/** @return string|\WP_Error Delivery key. */
	public function create_delivery( array $data );
	/** @return true|\WP_Error */
	public function attach_job( $delivery_key, $job_key );
	/** @return true|\WP_Error */
	public function mark_sending( $delivery_key, $attempt_token );
	/** @return true|\WP_Error */
	public function mark_sent( $delivery_key, array $evidence = array() );
	/** @return true|\WP_Error */
	public function mark_failed( $delivery_key, $failure_code, array $evidence = array(), $uncertain = false );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function recent( $limit = 50 );
	/** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}|\WP_Error */
	public function list_page( $page = 1, $per_page = 50 );
}
