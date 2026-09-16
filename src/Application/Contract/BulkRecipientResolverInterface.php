<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Resolves one Bulk Issuance row to an existing Recipient when identity is exact and unambiguous. */
interface BulkRecipientResolverInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function resolve( array $request );
}
