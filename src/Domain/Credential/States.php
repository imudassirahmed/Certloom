<?php
namespace Certloom\Domain\Credential;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Stable machine values for credential lifecycle dimensions.
 *
 * Display labels are intentionally not stored here. UI wording can be
 * translated or customized without changing persisted semantics.
 */
final class States {
	const ISSUANCE_DRAFT  = 'draft';
	const ISSUANCE_ISSUED = 'issued';
	const ISSUANCE_VOIDED = 'voided';

	const ADMIN_ACTIVE    = 'active';
	const ADMIN_SUSPENDED = 'suspended';
	const ADMIN_REVOKED   = 'revoked';

	const TEMPORAL_NOT_YET_VALID = 'not_yet_valid';
	const TEMPORAL_CURRENT       = 'current';
	const TEMPORAL_EXPIRED       = 'expired';

	const COMPLIANCE_NOT_APPLICABLE = 'not_applicable';
	const COMPLIANCE_PENDING        = 'pending';
	const COMPLIANCE_COMPLIANT      = 'compliant';
	const COMPLIANCE_NONCOMPLIANT   = 'noncompliant';

	/** @return string[] */
	public static function issuance_values() {
		return array( self::ISSUANCE_DRAFT, self::ISSUANCE_ISSUED, self::ISSUANCE_VOIDED );
	}

	/** @return string[] */
	public static function administrative_values() {
		return array( self::ADMIN_ACTIVE, self::ADMIN_SUSPENDED, self::ADMIN_REVOKED );
	}

	/** @return string[] */
	public static function compliance_values() {
		return array( self::COMPLIANCE_NOT_APPLICABLE, self::COMPLIANCE_PENDING, self::COMPLIANCE_COMPLIANT, self::COMPLIANCE_NONCOMPLIANT );
	}
}
