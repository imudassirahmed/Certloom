<?php
namespace Certloom\Application\Restore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Ciphertext transport only. A valid envelope does not authenticate or reconnect a destination. */
final class WholeStateSealedConnectionFormat {
	public static function validate( $value, $maximum = 65536 ) {
		if ( ! is_string( $value ) || strlen( $value ) > $maximum || ! preg_match( '/^v1:(sodium|gcm):([A-Za-z0-9+\/]+={0,2})$/D', $value, $parts ) ) { return self::invalid(); }
		$bytes = base64_decode( $parts[2], true );
		if ( ! is_string( $bytes ) || base64_encode( $bytes ) !== $parts[2] || strlen( $bytes ) <= ( 'sodium' === $parts[1] ? 40 : 28 ) ) { return self::invalid(); }
		return $value;
	}
	private static function invalid() { return new \WP_Error( 'certloom_whole_connection_ciphertext_invalid', __( 'A saved recovery connection has unsupported or malformed encrypted credentials. Reconnect it on the target site before enabling it.', 'certloom' ) ); }
}
