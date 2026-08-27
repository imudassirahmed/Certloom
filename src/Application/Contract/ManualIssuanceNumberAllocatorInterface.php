<?php
namespace Certloom\Application\Contract;

/** Human certificate-number policy boundary for Manual Issuance. */
interface ManualIssuanceNumberAllocatorInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function review( $manual_number = '' );
	/** @return array<string,mixed>|\WP_Error */
	public function allocate( $manual_number = '' );
	/** @return bool */
	public function is_automatic();
	/** @return array<string,mixed> */
	public function policy_identity();
}
