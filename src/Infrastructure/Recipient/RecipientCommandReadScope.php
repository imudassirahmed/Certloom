<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Current reads after context admission; never change the database isolation level. */
final class RecipientCommandReadScope {
	private static $depth = 0;
	public static function active() { return self::$depth > 0 && Transaction::owns_current(); }
	/** Caller already holds configuration -> account -> Recipient/context locks. */
	public static function run( callable $callback ) {
		if ( ! Transaction::owns_current() ) { return new \WP_Error( 'certloom_recipient_current_read_unowned', __( 'The recipient update could not be read safely. Refresh and retry.', 'certloom' ) ); }
		$values = DatabaseOptions::read( RecipientFieldContext::option_keys(), true );
		if ( is_wp_error( $values ) ) { return $values; }
		$filters = array();
		foreach ( $values as $key => $entry ) {
			// Null short-circuits a missing option too. Returning false would allow
			// WordPress to fall back to the enclosing transaction's older snapshot.
			$value = $entry['exists'] && false !== $entry['value'] ? $entry['value'] : null;
			$filters[ $key ] = static function () use ( $value ) { return $value; };
			add_filter( 'pre_option_' . $key, $filters[ $key ], PHP_INT_MAX );
		}
		++self::$depth;
		\Ecapla_CertVerify_Fields::reset_runtime_cache();
		try { return $callback(); }
		finally {
			--self::$depth;
			foreach ( $filters as $key => $filter ) { remove_filter( 'pre_option_' . $key, $filter, PHP_INT_MAX ); }
			\Ecapla_CertVerify_Fields::reset_runtime_cache();
		}
	}
}
