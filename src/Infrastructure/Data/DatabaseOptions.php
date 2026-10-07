<?php
namespace Certloom\Infrastructure\Data;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checked, uncached option snapshots for integrity decisions and rollback journals. */
final class DatabaseOptions {
	/** Clear WordPress's read cache before option API writes and after transaction rollback. */
	public static function invalidate( array $keys ) {
		foreach ( array_merge( $keys, array( 'alloptions', 'notoptions' ) ) as $key ) { wp_cache_delete( $key, 'options' ); }
	}
	/** @return array<string,array{exists:bool,value:mixed}>|\WP_Error */
	public static function read( array $keys, $lock = false ) {
		global $wpdb;
		$lock = $lock || \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::active();
		$keys = array_values( array_unique( $keys ) );
		if ( ! $keys ) { return array(); }
		$values = array_fill_keys( $keys, array( 'exists' => false, 'value' => null ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')' . ( $lock ? ' ORDER BY option_name FOR UPDATE' : '' ),
			array_merge( array( $wpdb->options ), $keys )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return self::failed(); }
		foreach ( $rows as $row ) {
			$key = $row['option_name'] ?? null;
			if ( ! is_string( $key ) || ! array_key_exists( $key, $values ) || $values[ $key ]['exists'] || ! isset( $row['option_value'] ) || ! is_string( $row['option_value'] ) ) { return self::failed(); }
			$values[ $key ] = array( 'exists' => true, 'value' => maybe_unserialize( $row['option_value'] ) );
		}
		return $values;
	}

	private static function failed() {
		return new \WP_Error( 'certloom_database_options_read_failed', __( 'Certloom could not read its saved configuration safely. Retry when the database is available.', 'certloom' ) );
	}
}
