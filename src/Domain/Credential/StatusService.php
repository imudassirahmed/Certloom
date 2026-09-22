<?php
namespace Certloom\Domain\Credential;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use InvalidArgumentException;

/**
 * Core-owned authoritative credential status evaluator.
 *
 * The service is deliberately pure: it has no WordPress, database, Cron,
 * license, presentation, or translation dependency. This guarantees that a
 * later Pro lock cannot remove the logic required to tell the truth about an
 * already-issued credential. A narrowly scoped legacy_expired compatibility
 * input preserves ambiguous stored Expired rows during the 1.5 migration; new
 * credentials never need that flag because temporal expiry is date-derived.
 */
final class StatusService {
	/**
	 * @param array<string,mixed> $credential Required lifecycle fields.
	 * @param string|null         $today      Canonical Y-m-d date for deterministic tests. Defaults to UTC today.
	 * @return StatusResult
	 */
	public function evaluate( array $credential, $today = null ) {
		$issuance = isset( $credential['issuance_state'] ) ? (string) $credential['issuance_state'] : '';
		$admin    = isset( $credential['administrative_state'] ) ? (string) $credential['administrative_state'] : '';
		$legacy_expired = ! empty( $credential['legacy_expired'] );
		$compliance = isset( $credential['compliance_state'] ) && '' !== (string) $credential['compliance_state']
			? (string) $credential['compliance_state']
			: States::COMPLIANCE_NOT_APPLICABLE;

		$this->assert_known_state( $issuance, States::issuance_values(), 'issuance_state' );
		$this->assert_known_state( $admin, States::administrative_values(), 'administrative_state' );
		$this->assert_known_state( $compliance, States::compliance_values(), 'compliance_state' );

		$today = null === $today ? gmdate( 'Y-m-d' ) : (string) $today;
		$this->assert_date( $today, 'today', false );

		$valid_from = isset( $credential['valid_from'] ) ? $this->nullable_date( $credential['valid_from'], 'valid_from' ) : null;
		$expires_at = isset( $credential['expires_at'] ) ? $this->nullable_date( $credential['expires_at'], 'expires_at' ) : null;
		$temporal   = $this->temporal_state( $valid_from, $expires_at, $today );
		$factors    = $this->contributors( $issuance, $admin, $temporal, $compliance, $legacy_expired );

		/*
		 * Precedence intentionally separates a primary public explanation from
		 * the complete contributing-state list. That avoids losing important
		 * facts when (for example) a suspended credential later also expires.
		 */
		if ( States::ISSUANCE_VOIDED === $issuance ) {
			return $this->result( false, 'voided', 'issuance_voided', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::ISSUANCE_DRAFT === $issuance ) {
			return $this->result( false, 'not_issued', 'issuance_not_issued', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::ADMIN_REVOKED === $admin ) {
			return $this->result( false, 'revoked', 'administratively_revoked', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::ADMIN_SUSPENDED === $admin ) {
			return $this->result( false, 'suspended', 'administratively_suspended', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( $legacy_expired ) {
			return $this->result( false, 'expired', 'legacy_expired_compatibility', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::TEMPORAL_NOT_YET_VALID === $temporal ) {
			return $this->result( false, 'not_yet_valid', 'validity_window_not_started', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::TEMPORAL_EXPIRED === $temporal ) {
			return $this->result( false, 'expired', 'validity_window_expired', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::COMPLIANCE_PENDING === $compliance ) {
			return $this->result( false, 'pending_review', 'compliance_pending', $issuance, $admin, $temporal, $compliance, $factors );
		}
		if ( States::COMPLIANCE_NONCOMPLIANT === $compliance ) {
			return $this->result( false, 'suspended_noncompliant', 'compliance_not_met', $issuance, $admin, $temporal, $compliance, $factors );
		}

		return $this->result( true, 'valid', 'credential_valid', $issuance, $admin, $temporal, $compliance, $factors );
	}

	/** @return string */
	private function temporal_state( $valid_from, $expires_at, $today ) {
		if ( null !== $valid_from && $today < $valid_from ) {
			return States::TEMPORAL_NOT_YET_VALID;
		}
		if ( null !== $expires_at && $today > $expires_at ) {
			return States::TEMPORAL_EXPIRED;
		}
		return States::TEMPORAL_CURRENT;
	}

	/** @return string[] */
	private function contributors( $issuance, $admin, $temporal, $compliance, $legacy_expired = false ) {
		$factors = array();
		if ( States::ISSUANCE_ISSUED !== $issuance ) {
			$factors[] = 'issuance:' . $issuance;
		}
		if ( States::ADMIN_ACTIVE !== $admin ) {
			$factors[] = 'administrative:' . $admin;
		}
		if ( States::TEMPORAL_CURRENT !== $temporal ) {
			$factors[] = 'temporal:' . $temporal;
		}
		if ( ! in_array( $compliance, array( States::COMPLIANCE_NOT_APPLICABLE, States::COMPLIANCE_COMPLIANT ), true ) ) {
			$factors[] = 'compliance:' . $compliance;
		}
		if ( $legacy_expired ) {
			$factors[] = 'compatibility:legacy_expired';
		}
		return $factors;
	}

	/** @return StatusResult */
	private function result( $valid, $code, $reason, $issuance, $admin, $temporal, $compliance, array $factors ) {
		return new StatusResult( $valid, $code, $reason, $issuance, $admin, $temporal, $compliance, $factors );
	}

	/** @return void */
	private function assert_known_state( $value, array $allowed, $field ) {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown %s value: %s', $field, $value ) );
		}
	}

	/** @return string|null */
	private function nullable_date( $value, $field ) {
		$value = null === $value ? '' : trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$this->assert_date( $value, $field, true );
		return $value;
	}

	/** @return void */
	private function assert_date( $value, $field, $allow_empty ) {
		if ( $allow_empty && '' === $value ) {
			return;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be an exact Y-m-d date.', $field ) );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			throw new InvalidArgumentException( sprintf( '%s is not a valid calendar date.', $field ) );
		}
	}
}
