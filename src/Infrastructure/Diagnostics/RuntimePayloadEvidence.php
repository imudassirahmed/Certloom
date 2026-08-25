<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\Rendering\RuntimePayloadFingerprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exact Certloom production-payload identity for stored diagnostic evidence.
 *
 * Database acceptance, migration, restore and cutover proofs are long-lived
 * evidence. A semantic plugin version is not enough to prove that the same
 * executable Certloom bytes are still installed. This helper reuses the
 * deterministic production-payload contract already used by renderer release
 * authority so stored evidence fails closed after any production-byte change,
 * even when the version string was not changed.
 */
final class RuntimePayloadEvidence {
	/** @return array<string,mixed>|null */
	public static function current() {
		try {
			$root = defined( 'CERTLOOM_PATH' ) ? (string) CERTLOOM_PATH : ( defined( 'ECAPLA_CERTVERIFY_PATH' ) ? (string) ECAPLA_CERTVERIFY_PATH : dirname( __DIR__, 3 ) );
			return ( new RuntimePayloadFingerprint() )->fingerprint( $root );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** @return array<string,mixed>|null */
	public static function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		try {
			return ( new RuntimePayloadFingerprint() )->normalize( $value );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** @return bool */
	public static function matches_current( $value ) {
		$expected = self::normalize( $value );
		$current  = self::current();
		if ( ! is_array( $expected ) || ! is_array( $current ) ) {
			return false;
		}
		return hash_equals( (string) $expected['sha256'], (string) $current['sha256'] )
			&& (int) $expected['file_count'] === (int) $current['file_count']
			&& (int) $expected['total_bytes'] === (int) $current['total_bytes'];
	}
}
