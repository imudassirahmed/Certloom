<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Resumable restore loader/commit boundary used by the shared job engine. */
interface DomainRestoreStagingInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function start( $path, $owner_token, $max_records = 500000 );

	/** @return array<string,mixed>|\WP_Error */
	public function continue_stage( array $state, $limit = 200 );

	/** @return true|\WP_Error */
	public function commit_replace( array $state );

	/** @return array<string,mixed>|null|\WP_Error */
	public function completion_receipt( $owner_token );
}
