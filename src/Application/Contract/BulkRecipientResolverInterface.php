<?php
namespace Certloom\Application\Contract;

/** Resolves one Bulk Issuance row to an existing Recipient when identity is exact and unambiguous. */
interface BulkRecipientResolverInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function resolve( array $request );
}
