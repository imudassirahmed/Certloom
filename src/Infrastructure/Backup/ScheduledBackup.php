<?php
namespace Certloom\Infrastructure\Backup;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Legacy-registry automatic backup scheduler and retention coordinator. */
final class ScheduledBackup {
	const HOOK               = 'certloom_run_scheduled_backup';
	const OPTION_SCHEDULE    = 'certloom_backup_schedule';
	const OPTION_RETENTION   = 'certloom_backup_retention';
	const OPTION_LAST_RESULT = 'certloom_backup_last_result';
	const LOCK_OPTION        = 'certloom_backup_run_lock';
	const LOCK_TTL           = 30 * MINUTE_IN_SECONDS;

	/** @return true|\WP_Error */
	public static function activate() {
		self::register_weekly_schedule();
		return self::reconcile_schedule();
	}

	/** @return true|\WP_Error */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		return self::reconcile_schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** @param array<string,array<string,mixed>> $schedules @return array<string,array<string,mixed>> */
	public static function cron_schedules( $schedules ) {
		$schedules['certloom_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'certloom' ),
		);
		return $schedules;
	}

	/** @return array<string,mixed> */
	public static function settings() {
		return array(
			'schedule'    => self::normalize_schedule( get_option( self::OPTION_SCHEDULE, 'off' ) ),
			'retention'   => self::normalize_retention( get_option( self::OPTION_RETENTION, 14 ) ),
			'next_run'    => self::next_run(),
			'last_result' => self::last_result(),
		);
	}

	/** @return true|\WP_Error */
	public static function save_settings( $schedule, $retention ) {
		$schedule  = self::normalize_schedule( $schedule );
		$retention = self::normalize_retention( $retention );
		if ( 'off' !== $schedule ) {
			$storage = ( new LocalBackupStore() )->test_storage();
			if ( is_wp_error( $storage ) ) { return $storage; }
		}
		$previous_schedule  = self::normalize_schedule( get_option( self::OPTION_SCHEDULE, 'off' ) );
		$previous_retention = self::normalize_retention( get_option( self::OPTION_RETENTION, 14 ) );
		$saved_retention = update_option( self::OPTION_RETENTION, $retention, false );
		if ( ! $saved_retention && $retention !== (int) get_option( self::OPTION_RETENTION, 14 ) ) {
			return new \WP_Error( 'certloom_backup_retention_save_failed', __( 'Certloom could not save the backup retention setting.', 'certloom' ) );
		}
		$saved_schedule = update_option( self::OPTION_SCHEDULE, $schedule, false );
		if ( ! $saved_schedule && $schedule !== get_option( self::OPTION_SCHEDULE, 'off' ) ) {
			update_option( self::OPTION_RETENTION, $previous_retention, false );
			return new \WP_Error( 'certloom_backup_schedule_save_failed', __( 'Certloom could not save the automatic backup schedule.', 'certloom' ) );
		}
		$reconciled = self::reconcile_schedule( true );
		if ( is_wp_error( $reconciled ) ) {
			update_option( self::OPTION_SCHEDULE, $previous_schedule, false );
			update_option( self::OPTION_RETENTION, $previous_retention, false );
			self::reconcile_schedule( true );
			return $reconciled;
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function create_now( $purpose = 'manual' ) {
		$purpose = sanitize_key( (string) $purpose );
		if ( ! in_array( $purpose, array( 'manual', 'scheduled', 'safety' ), true ) ) {
			return new \WP_Error( 'certloom_backup_purpose_invalid', __( 'Certloom refused an invalid backup purpose.', 'certloom' ) );
		}
		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) { return $lock; }
		$result = null;
		$temp   = '';
		try {
			$backup = \Ecapla_CertVerify_Backup::create_export_file();
			if ( is_wp_error( $backup ) ) { $result = $backup; }
			else {
				$temp  = (string) $backup['path'];
				$store = new LocalBackupStore();
				$result = $store->store( $temp, $backup, $purpose );
				if ( ! is_wp_error( $result ) ) {
					$pruned = $store->prune_scheduled( self::normalize_retention( get_option( self::OPTION_RETENTION, 14 ) ) );
					if ( is_wp_error( $pruned ) ) { $result = $pruned; }
					elseif ( 'scheduled' === $purpose && class_exists( GoogleDriveBackup::class ) ) {
						/* Remote copy is queued only after the verified local backup exists; Google never extends the local backup run lock. */
						GoogleDriveBackup::queue_scheduled_local( $result );
					}
				}
			}
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'certloom_backup_unexpected_failure', __( 'Certloom could not complete the backup because an unexpected local error occurred.', 'certloom' ) );
		}
		if ( '' !== $temp && file_exists( $temp ) ) { @unlink( $temp ); }
		$released = self::release_lock( $lock );
		if ( ! is_wp_error( $result ) && is_wp_error( $released ) ) { $result = $released; }
		self::save_last_result( $result, $purpose );
		return $result;
	}

	public static function run_scheduled() {
		return self::create_now( 'scheduled' );
	}

	/** @return int */
	public static function next_run() {
		$next = wp_next_scheduled( self::HOOK );
		return false === $next ? 0 : absint( $next );
	}

	/** @return array<string,mixed> */
	public static function last_result() {
		$result = get_option( self::OPTION_LAST_RESULT, array() );
		return is_array( $result ) ? $result : array();
	}

	/** @return true|\WP_Error */
	private static function reconcile_schedule( $force = false ) {
		$schedule = self::normalize_schedule( get_option( self::OPTION_SCHEDULE, 'off' ) );
		$event    = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::HOOK ) : null;
		$wanted   = 'weekly' === $schedule ? 'certloom_weekly' : $schedule;
		if ( 'off' === $schedule ) {
			$cleared = wp_clear_scheduled_hook( self::HOOK, array(), true );
			return is_wp_error( $cleared ) ? $cleared : true;
		}
		$matches = ! $force && $event && isset( $event->schedule ) && $wanted === (string) $event->schedule;
		if ( $matches ) { return true; }
		$cleared = wp_clear_scheduled_hook( self::HOOK, array(), true );
		if ( is_wp_error( $cleared ) ) { return $cleared; }
		self::register_weekly_schedule();
		$scheduled = wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $wanted, self::HOOK, array(), true );
		return is_wp_error( $scheduled ) ? $scheduled : ( $scheduled ? true : new \WP_Error( 'certloom_backup_schedule_failed', __( 'Certloom could not schedule automatic backups with WordPress Cron.', 'certloom' ) ) );
	}

	private static function register_weekly_schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
	}

	private static function normalize_schedule( $value ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'off', 'daily', 'weekly' ), true ) ? $value : 'off';
	}

	private static function normalize_retention( $value ) {
		return max( 1, min( 60, absint( $value ) ?: 14 ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function acquire_lock() {
		try { $owner = bin2hex( random_bytes( 16 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_backup_lock_identity_failed', __( 'Certloom could not create a safe backup-run identity.', 'certloom' ) ); }
		$payload = array( 'owner' => $owner, 'started' => time(), 'expires' => time() + self::LOCK_TTL );
		if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) { return $payload; }
		$current = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $current ) || empty( $current['owner'] ) || absint( $current['expires'] ?? 0 ) > time() ) {
			return new \WP_Error( 'certloom_backup_locked', __( 'Another Certloom backup is already running. Wait for it to finish before starting another.', 'certloom' ) );
		}
		if ( ! self::compare_and_delete_lock( $current ) || ! add_option( self::LOCK_OPTION, $payload, '', false ) ) {
			return new \WP_Error( 'certloom_backup_locked', __( 'Another Certloom backup acquired the run lock first. Try again after it finishes.', 'certloom' ) );
		}
		return $payload;
	}

	/** @return true|\WP_Error */
	private static function release_lock( array $payload ) {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $current ) || empty( $current['owner'] ) || ! hash_equals( (string) $payload['owner'], (string) $current['owner'] ) ) {
			return new \WP_Error( 'certloom_backup_lock_owner_changed', __( 'The backup completed, but Certloom refused to release a run lock that is no longer owned by this process.', 'certloom' ) );
		}
		return self::compare_and_delete_lock( $current )
			? true
			: new \WP_Error( 'certloom_backup_lock_release_failed', __( 'The backup completed, but Certloom could not safely release its run lock. Wait for the short lease to expire before starting another backup.', 'certloom' ) );
	}

	private static function compare_and_delete_lock( array $expected ) {
		global $wpdb;
		if ( isset( $wpdb, $wpdb->options ) && method_exists( $wpdb, 'delete' ) ) {
			$deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION, 'option_value' => maybe_serialize( $expected ) ), array( '%s', '%s' ) );
			if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::LOCK_OPTION, 'options' ); }
			return false !== $deleted && $deleted > 0;
		}
		$current = get_option( self::LOCK_OPTION, array() );
		return $current === $expected && delete_option( self::LOCK_OPTION );
	}

	private static function save_last_result( $result, $purpose ) {
		$payload = array(
			'status'   => is_wp_error( $result ) ? 'failed' : 'success',
			'purpose'  => sanitize_key( (string) $purpose ),
			'ran_at'   => gmdate( 'c' ),
			'ran_ts'   => time(),
			'message'  => is_wp_error( $result ) ? sanitize_text_field( $result->get_error_message() ) : __( 'Backup completed and verified.', 'certloom' ),
		);
		if ( is_array( $result ) ) {
			$payload['backup_id'] = sanitize_text_field( (string) ( $result['id'] ?? '' ) );
			$payload['bytes']     = absint( $result['bytes'] ?? 0 );
			$payload['records']   = absint( $result['records'] ?? 0 );
		}
		update_option( self::OPTION_LAST_RESULT, $payload, false );
	}
}
