<?php
namespace Certloom\Application\Contract;

/** Atomic target operation for one already-mapped legacy certificate row. */
interface LegacyMigrationTargetInterface {
	/**
	 * @param int                                 $legacy_id Legacy certificate row ID.
	 * @param array<string,array<string,mixed>>   $plan      Mapper output.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function migrate( $legacy_id, array $plan );
}
