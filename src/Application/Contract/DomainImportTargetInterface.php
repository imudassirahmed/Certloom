<?php
namespace Certloom\Application\Contract;

interface DomainImportTargetInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function import( $row_number, array $plan, $duplicate_policy = 'skip' );
}
