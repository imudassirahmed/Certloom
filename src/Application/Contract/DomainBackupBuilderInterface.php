<?php
namespace Certloom\Application\Contract;

interface DomainBackupBuilderInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function start( $job_key, $plugin_version );
	/** @return array<string,mixed>|\WP_Error */
	public function continue_build( array $state, $limit = 200 );
}
