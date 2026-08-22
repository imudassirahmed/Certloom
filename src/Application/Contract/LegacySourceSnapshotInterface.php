<?php
namespace Certloom\Application\Contract;

/** Read-only snapshot boundary for the retiring legacy certificate registry. */
interface LegacySourceSnapshotInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function snapshot();
}
