<?php
namespace Certloom\Infrastructure\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical WordPress/PHP/database runtime identity for diagnostic evidence.
 *
 * Long-lived database acceptance evidence must not survive a host/runtime
 * change merely because the Certloom version and plugin bytes stayed fixed.
 * This contract captures the non-secret environment facts that materially
 * identify the WordPress/PHP/database execution boundary used by Certloom's
 * real database gates and stored rehearsals.
 */
final class RuntimeEnvironmentEvidence {
	const SCHEMA = 'certloom-runtime-environment-evidence';
	const VERSION = 1;

	/** @return array<string,mixed>|null */
	public static function current() {
		global $wpdb, $wp_version;

		try {
			$db_server = '';
			$db_version = '';
			if ( is_object( $wpdb ) ) {
				if ( method_exists( $wpdb, 'db_server_info' ) ) {
					try { $db_server = (string) $wpdb->db_server_info(); } catch ( \Throwable $error ) {}
				}
				if ( method_exists( $wpdb, 'db_version' ) ) {
					try { $db_version = (string) $wpdb->db_version(); } catch ( \Throwable $error ) {}
				}
			}
			$value = array(
				'schema'              => self::SCHEMA,
				'version'             => self::VERSION,
				'wp_version'          => isset( $wp_version ) ? trim( (string) $wp_version ) : '',
				'php_version'         => PHP_VERSION,
				'wp_environment_type' => function_exists( 'wp_get_environment_type' ) ? strtolower( trim( (string) wp_get_environment_type() ) ) : '',
				'db_server_info'      => self::clean_text( $db_server ),
				'db_version'          => self::clean_text( $db_version ),
				'multisite'           => function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
			);
			return self::normalize( $value );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** @return array<string,mixed>|null */
	public static function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		$expected = array( 'db_server_info', 'db_version', 'multisite', 'php_version', 'schema', 'version', 'wp_environment_type', 'wp_version' );
		if ( $keys !== $expected || self::SCHEMA !== (string) ( $value['schema'] ?? '' ) || self::VERSION !== (int) ( $value['version'] ?? 0 ) ) {
			return null;
		}
		$result = array(
			'schema'              => self::SCHEMA,
			'version'             => self::VERSION,
			'wp_version'          => self::clean_text( $value['wp_version'] ?? '' ),
			'php_version'         => self::clean_text( $value['php_version'] ?? '' ),
			'wp_environment_type' => strtolower( self::clean_text( $value['wp_environment_type'] ?? '' ) ),
			'db_server_info'      => self::clean_text( $value['db_server_info'] ?? '' ),
			'db_version'          => self::clean_text( $value['db_version'] ?? '' ),
			'multisite'           => (bool) ( $value['multisite'] ?? false ),
		);
		foreach ( array( 'wp_version', 'php_version', 'wp_environment_type', 'db_server_info', 'db_version' ) as $key ) {
			if ( '' === $result[ $key ] || strlen( $result[ $key ] ) > 512 ) {
				return null;
			}
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $result['wp_environment_type'] ) ) {
			return null;
		}
		return $result;
	}

	/** @return bool */
	public static function matches_current( $value ) {
		$expected = self::normalize( $value );
		$current  = self::current();
		if ( ! is_array( $expected ) || ! is_array( $current ) ) {
			return false;
		}
		return hash_equals( self::sha256( $expected ), self::sha256( $current ) );
	}

	/** @return string */
	public static function sha256( $value ) {
		$normalized = self::normalize( $value );
		if ( ! is_array( $normalized ) ) {
			return '';
		}
		$json = json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function clean_text( $value ) {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value );
		return trim( is_string( $value ) ? $value : '' );
	}
}
