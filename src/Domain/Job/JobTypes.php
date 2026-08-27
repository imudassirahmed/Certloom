<?php
namespace Certloom\Domain\Job;

/** Stable machine identifiers for background work implemented by Certloom. */
final class JobTypes {
	const DOMAIN_BACKUP            = 'domain_backup';
	const DOMAIN_RESTORE_PREFLIGHT = 'domain_restore_preflight';
	const DOMAIN_RESTORE_REPLACE = 'domain_restore_replace';
	const DOMAIN_RESTORE_MERGE   = 'domain_restore_merge';
	const DOMAIN_IMPORT_PREFLIGHT = 'domain_import'; // Stable 1.5.4 machine identifier.
	const DOMAIN_IMPORT_COMMIT    = 'domain_import_commit';
	const BULK_ISSUANCE_PREFLIGHT = 'bulk_issuance_preflight';
	const BULK_ISSUANCE_COMMIT    = 'bulk_issuance_commit';
	const DOMAIN_IMPORT            = self::DOMAIN_IMPORT_PREFLIGHT; // Backward-compatible alias for development callers.

	/** @return array<int,string> */
	public static function values() {
		return array(
			self::DOMAIN_BACKUP,
			self::DOMAIN_RESTORE_PREFLIGHT,
			self::DOMAIN_RESTORE_REPLACE,
			self::DOMAIN_RESTORE_MERGE,
			self::DOMAIN_IMPORT_PREFLIGHT,
			self::DOMAIN_IMPORT_COMMIT,
			self::BULK_ISSUANCE_PREFLIGHT,
			self::BULK_ISSUANCE_COMMIT,
		);
	}
}
