<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-administrator presentation preference for the Certloom admin shell.
 *
 * This is deliberately presentation-only. It never changes capabilities,
 * credential truth, normalized-domain gates, renderer authority, or stored
 * product configuration.
 */
final class AdminUiMode {
	const META_KEY = '_certloom_admin_ui_mode';
	const SIMPLE   = 'simple';
	const ADVANCED = 'advanced';

	/** @return string */
	public static function current() {
		$user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
		if ( ! $user_id || ! function_exists( 'get_user_meta' ) ) {
			return self::SIMPLE;
		}
		$value = sanitize_key( (string) get_user_meta( $user_id, self::META_KEY, true ) );
		return self::ADVANCED === $value ? self::ADVANCED : self::SIMPLE;
	}

	/** @return bool */
	public static function is_simple() {
		return self::SIMPLE === self::current();
	}

	/** @return bool */
	public static function is_advanced() {
		return self::ADVANCED === self::current();
	}

	/** @return true|\WP_Error */
	public static function save_for_current_user( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( self::SIMPLE, self::ADVANCED ), true ) ) {
			return new \WP_Error( 'certloom_ui_mode_invalid', __( 'Choose Simple or Advanced mode.', 'certloom' ) );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
		if ( ! $user_id || ! function_exists( 'update_user_meta' ) ) {
			return new \WP_Error( 'certloom_ui_mode_user_missing', __( 'Your administrator preference could not be saved.', 'certloom' ) );
		}
		update_user_meta( $user_id, self::META_KEY, $mode );
		return true;
	}
}
