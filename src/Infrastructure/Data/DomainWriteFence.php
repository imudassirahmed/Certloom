<?php
namespace Certloom\Infrastructure\Data;

use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A permanent InnoDB row joins short domain transactions to maintenance acquisition. */
final class DomainWriteFence {
	const OPTION = 'certloom_domain_write_fence';

	/** Ensure the row exists before START TRANSACTION, without rewriting an existing row. */
	public static function prepare() {
		global $wpdb;
		$wpdb->last_error = '';
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->options ) );
		if ( '' !== (string) $wpdb->last_error || 'innodb' !== strtolower( (string) $engine ) ) {
			return new \WP_Error( 'certloom_write_fence_engine_unsupported', __( 'Certloom needs an InnoDB WordPress options table to protect credential changes during maintenance.', 'certloom' ) );
		}
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		if ( '' !== (string) $wpdb->last_error ) { return self::failed(); }
		if ( '1' === (string) $value ) { return true; }
		if ( null !== $value ) { return self::failed(); }
		$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::OPTION, '1', 'no' ) );
		return false === $result || '' !== (string) $wpdb->last_error ? self::failed() : true;
	}

	/** Shared locks last until the caller's checked COMMIT/ROLLBACK. Ordinary transactions may overlap. */
	public static function shared() { return self::lock( false ); }

	/** Publish a maintenance lease only after all previously admitted transactions finish. */
	public static function exclusive( callable $callback ) {
		global $wpdb;
		$wpdb->last_error = '';
		$timeout = $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		if ( '' !== (string) $wpdb->last_error || ! is_numeric( $timeout ) || (int) $timeout < 1 ) { return self::failed(); }
		$timeout = (int) $timeout;
		$set = $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . min( 3, $timeout ) );
		if ( false === $set || '' !== (string) $wpdb->last_error ) { return self::failed(); }
		try {
			$result = DomainMaintenanceLock::run_internal( static function () use ( $callback ) {
				return ( new Transaction() )->run( static function () use ( $callback ) {
					$locked = self::lock( true );
					return is_wp_error( $locked ) ? $locked : $callback();
				} );
			} );
		} finally {
			$wpdb->last_error = '';
			$reset = $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . $timeout );
			$reset_failed = false === $reset || '' !== (string) $wpdb->last_error;
			// add_option/update_option may have cached a lease that the transaction rolled back.
			foreach ( array( DomainMaintenanceLock::OPTION, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
		}
		return $reset_failed ? self::failed() : $result;
	}

	private static function lock( $exclusive ) {
		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s' . ( $exclusive ? ' FOR UPDATE' : ' LOCK IN SHARE MODE' ), $wpdb->options, self::OPTION ) );
		return '' === (string) $wpdb->last_error && '1' === (string) $value ? true : self::failed();
	}

	private static function failed() {
		return new \WP_Error( 'certloom_write_fence_unavailable', __( 'Certloom could not safely coordinate credential changes with maintenance. Wait for active updates to finish and retry.', 'certloom' ) );
	}
}
