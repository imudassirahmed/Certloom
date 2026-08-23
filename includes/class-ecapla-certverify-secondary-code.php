<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secondary verification proof for the currently authoritative legacy registry.
 *
 * The code is never an identifier: Certloom always performs the exact certificate
 * number lookup first, then checks this optional second proof against the matched
 * record. Only a one-way portable digest is stored.
 */
final class Ecapla_CertVerify_Secondary_Code {
	const FIELD_KEY = '__certloom_verification_code_hash';
	const RAW_LENGTH = 16;
	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const DUMMY_HASH = '$2y$10$/wJg0lufnFSZD4OCzNcbRODoeOjxhMLsb2FxJqo0FEcijY05Y7giK';

	/** @return string|WP_Error */
	public static function normalize( $value ) {
		$value = strtoupper( trim( sanitize_text_field( (string) $value ) ) );
		$value = preg_replace( '/[\s-]+/', '', $value );
		if ( ! is_string( $value ) || self::RAW_LENGTH !== strlen( $value ) || ! preg_match( '/^[0-9A-HJKMNP-TV-Z]{16}$/', $value ) ) {
			return new WP_Error( 'certloom_secondary_code_invalid', __( 'Verification codes must contain exactly 16 Crockford Base32 characters. Spaces and hyphens are optional.', 'certloom' ) );
		}
		if ( count( array_unique( str_split( $value ) ) ) < 6 ) {
			return new WP_Error( 'certloom_secondary_code_weak', __( 'Choose a less repetitive verification code or use Generate secure code.', 'certloom' ) );
		}
		return $value;
	}

	/** @return string|WP_Error */
	public static function hash_from_input( $value ) {
		$normalized = self::normalize( $value );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$hash = password_hash( 'certloom-secondary-v1|' . $normalized, PASSWORD_BCRYPT, array( 'cost' => 10 ) );
		return is_string( $hash ) && '' !== $hash ? $hash : new WP_Error( 'certloom_secondary_code_hash_failed', __( 'Certloom could not protect the verification code. Try again.', 'certloom' ) );
	}

	/**
	 * Whether a record requires secondary proof.
	 *
	 * Any non-empty stored value requires proof, even if corrupt. This is
	 * deliberately fail-closed: malformed direct-database state must never be
	 * reinterpreted as "no secondary code configured". matches() will reject a
	 * malformed hash until an administrator explicitly rotates or clears it.
	 *
	 * @return bool
	 */
	public static function has_code( array $certificate ) {
		$custom = isset( $certificate['custom_fields'] ) && is_array( $certificate['custom_fields'] ) ? $certificate['custom_fields'] : array();
		$hash = isset( $custom[ self::FIELD_KEY ] ) ? trim( (string) $custom[ self::FIELD_KEY ] ) : '';
		return '' !== $hash;
	}

	/** @return bool */
	public static function matches( array $certificate, $input ) {
		if ( ! self::has_code( $certificate ) ) {
			return true;
		}
		$custom = (array) $certificate['custom_fields'];
		$expected = (string) $custom[ self::FIELD_KEY ];
		$normalized = self::normalize( $input );
		return ! is_wp_error( $normalized ) && password_verify( 'certloom-secondary-v1|' . $normalized, $expected );
	}


	/**
	 * Performs equivalent bcrypt work for a syntactically valid proof input when
	 * there is no protected row to verify. This reduces the timing distinction
	 * between an exact lookup miss/private result and an existing proof-protected
	 * credential without turning the proof into a searchable identifier.
	 *
	 * @return void
	 */
	public static function equalize_public_miss( $input ) {
		$normalized = self::normalize( $input );
		if ( is_wp_error( $normalized ) ) {
			return;
		}
		password_verify( 'certloom-secondary-v1|' . $normalized, self::DUMMY_HASH );
	}

	/** @return string */
	public static function format( $normalized ) {
		$normalized = self::normalize( $normalized );
		return ! is_wp_error( $normalized ) ? implode( '-', str_split( $normalized, 4 ) ) : '';
	}
}
