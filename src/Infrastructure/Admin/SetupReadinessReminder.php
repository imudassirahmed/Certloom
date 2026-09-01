<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Per-administrator reminder cadence for incomplete first-run readiness. */
final class SetupReadinessReminder {
	const META_KEY = 'certloom_setup_reminder_dismissed_until';
	const SNOOZE_SECONDS = 3 * DAY_IN_SECONDS;
	const DISMISSED_FOREVER = 2147483647;

	public static function should_show( $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) { return false; }
		$until = absint( get_user_meta( $user_id, self::META_KEY, true ) );
		return $until <= time();
	}

	/** @return true|\WP_Error */
	public static function dismiss( $user_id = 0, $permanent = false ) {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) { return new \WP_Error( 'certloom_setup_reminder_user_missing', __( 'Certloom could not identify the administrator dismissing this setup reminder.', 'certloom' ) ); }
		$until = $permanent ? self::DISMISSED_FOREVER : time() + self::SNOOZE_SECONDS;
		$saved = update_user_meta( $user_id, self::META_KEY, $until );
		return false !== $saved || $until === absint( get_user_meta( $user_id, self::META_KEY, true ) ) ? true : new \WP_Error( 'certloom_setup_reminder_save_failed', __( 'Certloom could not dismiss the setup reminder.', 'certloom' ) );
	}

	public static function clear( $user_id = 0 ) {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( $user_id ) { delete_user_meta( $user_id, self::META_KEY ); }
	}
}
