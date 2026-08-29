<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retires the former stored-expiry synchronization task.
 *
 * Expired is now calculated from expiry_date at read time, so no recurring task
 * is required. The legacy hook remains callable for backward compatibility and
 * only marks ambiguous old status=expired rows for administrator review.
 */
class Ecapla_CertVerify_Cron {

	const HOOK           = 'certverify_daily_expiry_check';
	const RETIRED_OPTION = 'certverify_expiry_cron_retired';

	/**
	 * @return true|WP_Error
	 */
	public static function activate() {
		$retired = self::retire_schedule();
		if ( is_wp_error( $retired ) ) { return $retired; }
		return \Certloom\Infrastructure\Backup\ScheduledBackup::activate();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( 'ecapla_certverify_continue_migrations' );
		\Certloom\Infrastructure\Backup\ScheduledBackup::deactivate();
	}

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! get_option( self::RETIRED_OPTION, false ) ) {
			$retired = self::retire_schedule();
			if ( is_wp_error( $retired ) ) { return $retired; }
		}
		return \Certloom\Infrastructure\Backup\ScheduledBackup::init();
	}

	/**
	 * @return true|WP_Error
	 */
	private static function retire_schedule() {
		$cleared = wp_clear_scheduled_hook( self::HOOK, array(), true );
		if ( is_wp_error( $cleared ) || false === $cleared ) {
			return new WP_Error( 'ecapla_certverify_cron_cleanup_failed', __( 'Certloom could not retire its legacy scheduled task.', 'certloom' ) );
		}
		$saved = update_option( self::RETIRED_OPTION, true, false );
		if ( ! $saved && true !== (bool) get_option( self::RETIRED_OPTION, false ) ) {
			return new WP_Error( 'ecapla_certverify_cron_marker_failed', __( 'Certloom could not save its scheduled-task migration state.', 'certloom' ) );
		}
		return true;
	}

	/**
	 * Preserves legacy rows if an old scheduled event or integration invokes the
	 * retired hook.
	 *
	 * @return int|WP_Error Number of legacy rows awaiting review, or a database error.
	 */
	public static function run() {
		return Ecapla_CertVerify_Database::normalize_legacy_expired_statuses();
	}

	/**
	 * Backward-compatible value retained for integrations.
	 *
	 * @return string
	 */
	public static function last_run() {
		return '';
	}
}
