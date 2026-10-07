<?php
namespace Certloom\Domain\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Stable machine identifiers for background work implemented by Certloom. */
final class JobTypes {
	const DOMAIN_BACKUP            = 'domain_backup';
	const WHOLE_BACKUP             = 'whole_state_backup';
	const WHOLE_RESTORE            = 'whole_state_restore';
	const DOMAIN_RESTORE_PREFLIGHT = 'domain_restore_preflight';
	const DOMAIN_RESTORE_REPLACE = 'domain_restore_replace';
	const DOMAIN_RESTORE_MERGE   = 'domain_restore_merge';
	const DOMAIN_IMPORT_PREFLIGHT = 'domain_import'; // Stable 1.5.4 machine identifier.
	const DOMAIN_IMPORT_COMMIT    = 'domain_import_commit';
	const BULK_ISSUANCE_PREFLIGHT = 'bulk_issuance_preflight';
	const BULK_ISSUANCE_COMMIT    = 'bulk_issuance_commit';
	const EMAIL_DELIVERY          = 'email_delivery';
	const CREDLY_PUBLISH          = 'credly_publish';
	const PENDING_REASSESSMENT    = 'pending_reassessment';
	const PENDING_CONFIGURATION  = 'pending_configuration';
	const PENDING_LIFECYCLE      = 'pending_lifecycle';
	const RECIPIENT_NOTICE       = 'recipient_notice';
	const DOMAIN_IMPORT            = self::DOMAIN_IMPORT_PREFLIGHT; // Backward-compatible alias for development callers.

	/** @return array<int,string> */
	public static function values() {
		return array(
			self::DOMAIN_BACKUP,
			self::WHOLE_BACKUP,
			self::WHOLE_RESTORE,
			self::DOMAIN_RESTORE_PREFLIGHT,
			self::DOMAIN_RESTORE_REPLACE,
			self::DOMAIN_RESTORE_MERGE,
			self::DOMAIN_IMPORT_PREFLIGHT,
			self::DOMAIN_IMPORT_COMMIT,
			self::BULK_ISSUANCE_PREFLIGHT,
			self::BULK_ISSUANCE_COMMIT,
			self::EMAIL_DELIVERY,
			self::CREDLY_PUBLISH,
			self::PENDING_REASSESSMENT,
			self::PENDING_CONFIGURATION,
			self::PENDING_LIFECYCLE,
			self::RECIPIENT_NOTICE,
		);
	}
}
