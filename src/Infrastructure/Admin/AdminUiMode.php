<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compatibility shim for the retired Simple/Advanced presentation split.
 *
 * Certloom now has one workspace. Older code may still ask whether the former
 * task-focused surface is active while progressive-disclosure migrations are being
 * completed. Returning the task-focused presentation keeps everyday workflows
 * concise; specialist capability must remain reachable through explicit Technical
 * details/More tools disclosures rather than through a second workspace mode. Legacy preference writes are accepted
 * only so stale forms/bookmarks do not fail during upgrade.
 */
final class AdminUiMode {
	const META_KEY = '_certloom_admin_ui_mode';
	const SIMPLE   = 'simple';
	const ADVANCED = 'advanced';
	const UNIFIED  = 'unified';

	/** @return string */
	public static function current() { return self::UNIFIED; }
	/** @return bool */
	public static function is_simple() { return true; }
	/** @return bool */
	public static function is_advanced() { return false; }

	/** @return true|\WP_Error */
	public static function save_for_current_user( $mode ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( self::SIMPLE, self::ADVANCED, self::UNIFIED ), true ) ) {
			return new \WP_Error( 'certloom_ui_mode_invalid', __( 'That retired Certloom workspace preference is not recognized.', 'certloom' ) );
		}
		$user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
		if ( $user_id && function_exists( 'delete_user_meta' ) ) { delete_user_meta( $user_id, self::META_KEY ); }
		return true;
	}
}
