<?php
namespace Certloom\Application\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One native completion remains one intent when its connection or flow changes. */
final class FirstPartyCompletionIdentity {
	public static function supports( $adapter ) {
		return in_array( $adapter, array( 'tutor_lms', 'woocommerce' ), true );
	}

	public static function pending_key( $adapter, $event_sha256 ) {
		if ( ! self::supports( $adapter ) || ! is_string( $event_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $event_sha256 ) ) { return null; }
		return hash( 'sha256', 'certloom-native-completion-v1:' . $adapter . ':' . $event_sha256 );
	}
}
