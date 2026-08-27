<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Certloom owns a bounded aggregate analytics table.

/**
 * Privacy-minimized verification analytics.
 *
 * ANL-01 intentionally stores daily aggregate counters instead of request logs.
 * No IP address, User-Agent, raw failed identifier, secondary proof, or public
 * URL is persisted. Resolved credentials are represented only by their existing
 * numeric legacy-row ID so administrators can calculate "most verified" without
 * duplicating the certificate number into analytics storage.
 */
class Ecapla_CertVerify_Analytics {

	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'certloom_verification_analytics_schema_version';
	const PRUNE_HOOK     = 'certloom_verification_analytics_prune';
	const RETENTION_DAYS = 730;

	/** @return string */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'certloom_verification_analytics';
	}

	/** @return true|WP_Error */
	public static function activate() {
		global $wpdb;
		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket_date DATE NOT NULL,
			credential_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			outcome VARCHAR(20) NOT NULL,
			source VARCHAR(20) NOT NULL,
			event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket (bucket_date, credential_id, outcome, source),
			KEY bucket_date (bucket_date),
			KEY credential_id (credential_id),
			KEY outcome (outcome),
			KEY source (source)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$wpdb->last_error = '';
		dbDelta( $sql );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'certloom_analytics_schema_failed', __( 'Certloom could not create or update its verification analytics table.', 'certloom' ) );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== (string) $exists ) {
			return new WP_Error( 'certloom_analytics_schema_missing', __( 'Certloom could not verify its verification analytics table.', 'certloom' ) );
		}

		$saved = update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		if ( ! $saved && self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return new WP_Error( 'certloom_analytics_schema_marker_failed', __( 'Certloom created its verification analytics table but could not save its schema marker.', 'certloom' ) );
		}

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			$scheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
			if ( false === $scheduled || is_wp_error( $scheduled ) ) {
				return new WP_Error( 'certloom_analytics_retention_schedule_failed', __( 'Certloom could not schedule verification analytics retention cleanup.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function maybe_upgrade() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
			return true;
		}
		return self::activate();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}

	/** @return bool */
	public static function ready() {
		return self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' );
	}

	/** @return array<string,bool> */
	public static function health() {
		global $wpdb;
		$table = self::table_name();
		$marker_ready = self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' );
		$exists = $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$scheduled = false !== wp_next_scheduled( self::PRUNE_HOOK );
		return array(
			'ready' => $marker_ready && $exists,
			'schema_marker' => $marker_ready,
			'table' => $exists,
			'retention_scheduled' => $scheduled,
		);
	}

	/**
	 * Records one public verification as a daily aggregate increment.
	 *
	 * Public verification must never fail because analytics storage is unavailable,
	 * therefore false means "not recorded" and callers deliberately ignore it.
	 *
	 * @return bool
	 */
	public static function record( $outcome, $source = 'manual', $credential_id = 0, $timestamp = null ) {
		if ( ! self::ready() ) {
			return false;
		}
		$outcome = self::normalize_outcome( $outcome );
		$source  = self::normalize_source( $source );
		if ( '' === $outcome ) {
			return false;
		}
		$credential_id = max( 0, absint( $credential_id ) );
		$timestamp = null === $timestamp ? self::utc_timestamp() : (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return false;
		}
		$day = gmdate( 'Y-m-d', $timestamp );
		$now = gmdate( 'Y-m-d H:i:s', $timestamp );
		global $wpdb;
		$table = self::table_name();
		$wpdb->last_error = '';
		$query = $wpdb->prepare(
			"INSERT INTO $table (bucket_date, credential_id, outcome, source, event_count, created_at, updated_at)
			 VALUES (%s, %d, %s, %s, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE event_count = event_count + 1, updated_at = VALUES(updated_at)",
			$day,
			$credential_id,
			$outcome,
			$source,
			$now,
			$now
		);
		$result = $wpdb->query( $query );
		return false !== $result && '' === (string) $wpdb->last_error;
	}

	/** @return int|WP_Error */
	public static function prune() {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d', self::utc_timestamp() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->last_error = '';
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE bucket_date < %s', $cutoff ) );
		if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'certloom_analytics_prune_failed', __( 'Certloom could not complete verification analytics retention cleanup.', 'certloom' ) );
		}
		return (int) $deleted;
	}

	/**
	 * Returns the bounded administrator report for ANL-01.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function report( $days = 30 ) {
		$days = self::normalize_period( $days );
		if ( ! self::ready() ) {
			return new WP_Error( 'certloom_analytics_not_ready', __( 'Verification analytics storage is not ready yet. Open Certloom again after database setup completes.', 'certloom' ) );
		}
		global $wpdb;
		$table = self::table_name();
		$start_ts = self::utc_timestamp() - ( ( $days - 1 ) * DAY_IN_SECONDS );
		$start = gmdate( 'Y-m-d', $start_ts );
		$end   = gmdate( 'Y-m-d', self::utc_timestamp() );

		$wpdb->last_error = '';
		$summary_rows = $wpdb->get_results( $wpdb->prepare( "SELECT outcome, SUM(event_count) AS total FROM $table WHERE bucket_date >= %s AND bucket_date <= %s GROUP BY outcome", $start, $end ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $summary_rows ) ) {
			return new WP_Error( 'certloom_analytics_read_failed', __( 'Certloom could not read verification analytics.', 'certloom' ) );
		}
		$summary = array_fill_keys( self::outcomes(), 0 );
		foreach ( $summary_rows as $row ) {
			$key = isset( $row['outcome'] ) ? self::normalize_outcome( $row['outcome'] ) : '';
			if ( isset( $summary[ $key ] ) ) {
				$summary[ $key ] = max( 0, (int) $row['total'] );
			}
		}
		$summary['total'] = array_sum( $summary );

		$trend_rows = $wpdb->get_results( $wpdb->prepare( "SELECT bucket_date, outcome, SUM(event_count) AS total FROM $table WHERE bucket_date >= %s AND bucket_date <= %s GROUP BY bucket_date, outcome ORDER BY bucket_date ASC", $start, $end ), ARRAY_A );
		$source_rows = $wpdb->get_results( $wpdb->prepare( "SELECT source, SUM(event_count) AS total FROM $table WHERE bucket_date >= %s AND bucket_date <= %s GROUP BY source ORDER BY total DESC", $start, $end ), ARRAY_A );
		$certificate_table = Ecapla_CertVerify_Database::table_name();
		$most_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.credential_id, c.cert_number, SUM(a.event_count) AS total
				 FROM $table a INNER JOIN $certificate_table c ON c.id = a.credential_id
				 WHERE a.bucket_date >= %s AND a.bucket_date <= %s AND a.credential_id > 0
				 GROUP BY a.credential_id, c.cert_number ORDER BY total DESC, a.credential_id ASC LIMIT 10",
				$start,
				$end
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $trend_rows ) || ! is_array( $source_rows ) || ! is_array( $most_rows ) ) {
			return new WP_Error( 'certloom_analytics_read_failed', __( 'Certloom could not read verification analytics.', 'certloom' ) );
		}

		$granularity = self::trend_granularity( $days );
		$trend = self::build_trend( $start_ts, $days, $granularity, $trend_rows );

		$sources = array( 'manual' => 0, 'qr' => 0, 'api' => 0 );
		foreach ( $source_rows as $row ) {
			$key = isset( $row['source'] ) ? self::normalize_source( $row['source'] ) : 'api';
			$sources[ $key ] = ( $sources[ $key ] ?? 0 ) + max( 0, (int) $row['total'] );
		}

		$most = array();
		foreach ( array_slice( $most_rows, 0, 10 ) as $row ) {
			$most[] = array(
				'credential_id' => max( 0, absint( $row['credential_id'] ?? 0 ) ),
				'certificate_number' => isset( $row['cert_number'] ) ? (string) $row['cert_number'] : '',
				'total' => max( 0, (int) ( $row['total'] ?? 0 ) ),
			);
		}

		return array(
			'days' => $days,
			'start' => $start,
			'end' => $end,
			'summary' => $summary,
			'trend' => $trend,
			'trend_granularity' => $granularity,
			'sources' => $sources,
			'most_verified' => $most,
			'retention_days' => self::RETENTION_DAYS,
			'privacy' => array(
				'raw_ip_stored' => false,
				'user_agent_stored' => false,
				'raw_failed_identifier_stored' => false,
				'aggregation' => 'daily',
			),
		);
	}

	/** @return string */
	public static function trend_granularity( $days ) {
		$days = self::normalize_period( $days );
		if ( $days >= 365 ) {
			return 'monthly';
		}
		if ( $days >= 90 ) {
			return 'weekly';
		}
		return 'daily';
	}

	/**
	 * Aggregates daily storage into the roadmap's daily/weekly/monthly report buckets.
	 *
	 * Storage deliberately remains daily so changing dashboard periods never changes
	 * retention semantics or creates another visitor-level analytics surface.
	 *
	 * @param int                         $start_ts    UTC start timestamp.
	 * @param int                         $days        Bounded reporting period.
	 * @param string                      $granularity daily, weekly or monthly.
	 * @param array<int,array<string,mixed>> $rows      Daily aggregate rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_trend( $start_ts, $days, $granularity, array $rows ) {
		$buckets = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$bucket = self::trend_bucket( $start_ts + ( $i * DAY_IN_SECONDS ), $granularity );
			if ( ! isset( $buckets[ $bucket['key'] ] ) ) {
				$buckets[ $bucket['key'] ] = array_merge(
					array( 'date' => $bucket['key'], 'label' => $bucket['label'] ),
					array_fill_keys( self::outcomes(), 0 )
				);
			}
		}
		foreach ( $rows as $row ) {
			$day = isset( $row['bucket_date'] ) ? (string) $row['bucket_date'] : '';
			$timestamp = strtotime( $day . ' 00:00:00 UTC' );
			$outcome = isset( $row['outcome'] ) ? self::normalize_outcome( $row['outcome'] ) : '';
			if ( false === $timestamp || '' === $outcome ) {
				continue;
			}
			$bucket = self::trend_bucket( (int) $timestamp, $granularity );
			if ( isset( $buckets[ $bucket['key'] ][ $outcome ] ) ) {
				$buckets[ $bucket['key'] ][ $outcome ] += max( 0, (int) ( $row['total'] ?? 0 ) );
			}
		}
		return array_values( $buckets );
	}

	/** @return array{key:string,label:string} */
	private static function trend_bucket( $timestamp, $granularity ) {
		$timestamp = (int) $timestamp;
		if ( 'monthly' === $granularity ) {
			return array( 'key' => gmdate( 'Y-m-01', $timestamp ), 'label' => gmdate( 'Y-m', $timestamp ) );
		}
		if ( 'weekly' === $granularity ) {
			$weekday = max( 1, min( 7, (int) gmdate( 'N', $timestamp ) ) );
			$week_start = $timestamp - ( ( $weekday - 1 ) * DAY_IN_SECONDS );
			$key = gmdate( 'Y-m-d', $week_start );
			return array( 'key' => $key, 'label' => $key );
		}
		$key = gmdate( 'Y-m-d', $timestamp );
		return array( 'key' => $key, 'label' => $key );
	}

	/** @return int */
	public static function normalize_period( $days ) {
		$days = absint( $days );
		return in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;
	}

	/** @return string */
	public static function normalize_source( $source ) {
		$source = sanitize_key( (string) $source );
		return in_array( $source, array( 'manual', 'qr', 'api' ), true ) ? $source : 'manual';
	}

	/** @return string */
	public static function normalize_outcome( $outcome ) {
		$outcome = sanitize_key( (string) $outcome );
		return in_array( $outcome, self::outcomes(), true ) ? $outcome : '';
	}

	/** @return array<int,string> */
	private static function outcomes() {
		return array( 'success', 'failed', 'invalid', 'expired', 'revoked' );
	}

	/** @return int */
	private static function utc_timestamp() {
		if ( function_exists( 'current_time' ) ) {
			$value = current_time( 'timestamp', true );
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
		}
		return time();
	}
}
