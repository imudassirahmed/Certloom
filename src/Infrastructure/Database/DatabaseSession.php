<?php
namespace Certloom\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Core wpdb must never replay a command on a new connection after losing session locks. */
final class DatabaseSession {
	public static function run( callable $callback ) {
		global $wpdb;
		// Database adapters without core's reconnect mechanism have no retry setting to change.
		if ( ! method_exists( $wpdb, 'check_connection' ) ) { return $callback(); }
		$connection = $wpdb;
		try {
			if ( ! property_exists( $connection, 'reconnect_retries' ) ) { return self::unsupported(); }
			$retries = $connection->reconnect_retries;
			if ( ! is_int( $retries ) || $retries < 0 ) { return self::unsupported(); }
		} catch ( \Throwable $error ) { return self::unsupported(); }
		try { $connection->reconnect_retries = 0; } catch ( \Throwable $error ) { return self::unsupported(); }
		try {
			if ( 0 !== $connection->reconnect_retries ) { return self::unsupported(); }
			return $callback();
		} finally {
			$connection->reconnect_retries = $retries;
		}
	}

	private static function unsupported() {
		return new \WP_Error( 'certloom_database_session_unsupported', __( 'Certloom could not protect this database session from reconnecting during an update. No changes were started.', 'certloom' ) );
	}
}
