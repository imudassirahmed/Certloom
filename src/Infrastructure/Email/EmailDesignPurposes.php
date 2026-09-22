<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical purposes for reusable email designs. */
final class EmailDesignPurposes {
	const ISSUED          = 'credential_issued';
	const EXPIRY_REMINDER = 'expiry_reminder';
	const EXPIRED         = 'credential_expired';
	const SUSPENDED       = 'credential_suspended';
	const REVOKED         = 'credential_revoked';
	const REINSTATED      = 'credential_reinstated';
	const UPDATED         = 'credential_updated';

	/** @return array<int,string> */
	public static function all() {
		return array(
			self::ISSUED,
			self::EXPIRY_REMINDER,
			self::EXPIRED,
			self::SUSPENDED,
			self::REVOKED,
			self::REINSTATED,
			self::UPDATED,
		);
	}

	/** @return array<string,string> */
	public static function labels() {
		return array(
			self::ISSUED          => __( 'Credential issued', 'certloom' ),
			self::EXPIRY_REMINDER => __( 'Expiry reminder', 'certloom' ),
			self::EXPIRED         => __( 'Credential expired', 'certloom' ),
			self::SUSPENDED       => __( 'Credential suspended', 'certloom' ),
			self::REVOKED         => __( 'Credential revoked', 'certloom' ),
			self::REINSTATED      => __( 'Credential reinstated', 'certloom' ),
			self::UPDATED         => __( 'Credential updated', 'certloom' ),
		);
	}

	/** @return bool */
	public static function is_valid( $purpose ) {
		$purpose = sanitize_key( (string) $purpose );
		return in_array( $purpose, self::all(), true );
	}

	/**
	 * Compatibility normalizer for stored pre-purpose data.
	 *
	 * Mutation boundaries must call is_valid() first so malformed input cannot
	 * silently become a Credential issued message.
	 *
	 * @return string
	 */
	public static function normalize( $purpose ) {
		$purpose = sanitize_key( (string) $purpose );
		return self::is_valid( $purpose ) ? $purpose : self::ISSUED;
	}
}
