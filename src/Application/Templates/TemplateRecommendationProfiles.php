<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Setup\PurposePresetEngine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Presentation-only usage-profile metadata for certificate design discovery.
 *
 * These tags mirror the Setup usage profiles. They are recommendation metadata
 * only and never gate Template use, issuance, rendering or verification.
 */
final class TemplateRecommendationProfiles {
	/** @return array<string,string> */
	public static function labels() {
		$out = array();
		foreach ( ( new PurposePresetEngine() )->profiles() as $key => $profile ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) { continue; }
			$out[ $key ] = (string) ( $profile['label'] ?? $key );
		}
		return $out;
	}

	/** @return array<int,string> */
	public static function normalize( array $profiles ) {
		$allowed = array_fill_keys( array_keys( self::labels() ), true );
		$out = array();
		foreach ( $profiles as $profile ) {
			$key = sanitize_key( (string) $profile );
			if ( '' === $key || ! isset( $allowed[ $key ] ) || isset( $out[ $key ] ) ) { continue; }
			$out[ $key ] = $key;
		}
		return array_values( $out );
	}
}
