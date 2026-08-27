<?php
namespace Certloom\Infrastructure\Analytics;

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Application\Contract\IssuanceAnalyticsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only ANL-02 projection over immutable issuance events and current expiry truth. */
final class IssuanceAnalyticsRepository implements IssuanceAnalyticsRepositoryInterface {
	const CHUNK_SIZE = 500;

	/**
	 * Streams compact immutable issuance facts without loading full certificate layouts into PHP.
	 * SQL JSON extraction is preferred; a bounded-chunk PHP fallback keeps MariaDB/MySQL portability.
	 *
	 * @param callable $consumer Receives one normalized issuance row.
	 * @return true|\WP_Error
	 */
	public function scan_issuance_rows( $start, $end, $consumer ) {
		if ( ! self::valid_date( $start ) || ! self::valid_date( $end ) || ! is_callable( $consumer ) ) {
			return new \WP_Error( 'certloom_issuance_analytics_date_invalid', __( 'The issuance analytics date range is invalid.', 'certloom' ) );
		}
		return $this->json_projection_supported()
			? $this->scan_issuance_rows_json( $start, $end, $consumer )
			: $this->scan_issuance_rows_fallback( $start, $end, $consumer );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function expiry_forecast( $today, $horizon_days = 90 ) {
		if ( ! self::valid_date( $today ) ) {
			return new \WP_Error( 'certloom_issuance_analytics_forecast_date_invalid', __( 'The expiry forecast date is invalid.', 'certloom' ) );
		}
		$horizon_days = max( 1, min( 90, absint( $horizon_days ) ) );
		try {
			$end = ( new \DateTimeImmutable( $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $horizon_days . ' days' )->format( 'Y-m-d' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_issuance_analytics_forecast_date_invalid', __( 'The expiry forecast date is invalid.', 'certloom' ) );
		}

		global $wpdb;
		$series   = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$programs = SchemaManager::table( 'programs' );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN DATEDIFF(v.expires_at, %s) BETWEEN 0 AND 30 THEN '0_30'
						WHEN DATEDIFF(v.expires_at, %s) BETWEEN 31 AND 60 THEN '31_60'
						WHEN DATEDIFF(v.expires_at, %s) BETWEEN 61 AND 90 THEN '61_90'
						ELSE 'later'
					END AS forecast_bucket,
					s.program_id,
					COALESCE(p.title, '') AS program_title,
					COUNT(*) AS total
				 FROM %i s
				 INNER JOIN %i v ON v.id = s.current_version_id
				 LEFT JOIN %i p ON p.id = s.program_id
				 WHERE v.issuance_state = 'issued'
				   AND s.administrative_state <> 'revoked'
				   AND v.expires_at IS NOT NULL
				   AND v.expires_at >= %s AND v.expires_at <= %s
				 GROUP BY forecast_bucket, s.program_id, p.title
				 ORDER BY forecast_bucket ASC, total DESC, s.program_id ASC",
				$today, $today, $today,
				$series, $versions, $programs,
				$today, $end
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_issuance_analytics_forecast_failed', __( 'Certloom could not calculate the credential expiry forecast.', 'certloom' ) );
		}
		$permanent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i s INNER JOIN %i v ON v.id = s.current_version_id WHERE v.issuance_state = 'issued' AND s.administrative_state <> 'revoked' AND v.expires_at IS NULL",
				$series, $versions
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_issuance_analytics_forecast_failed', __( 'Certloom could not calculate the credential expiry forecast.', 'certloom' ) );
		}
		return array( 'rows' => $rows, 'permanent' => max( 0, $permanent ), 'today' => $today, 'end' => $end );
	}

	/** @return bool */
	private function json_projection_supported() {
		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var( "SELECT JSON_UNQUOTE(JSON_EXTRACT('{\"certloom\":1}', '$.certloom'))" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static capability probe.
		return '' === (string) $wpdb->last_error && '1' === (string) $value;
	}

	/** @return true|\WP_Error */
	private function scan_issuance_rows_json( $start, $end, $consumer ) {
		global $wpdb;
		$events   = SchemaManager::table( 'credential_events' );
		$versions = SchemaManager::table( 'credential_versions' );
		$cursor = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.id, e.created_at, v.source_type,
					 JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.program.id')) AS program_id,
					 JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.program.title')) AS program_title,
					 JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.issuer.id')) AS issuer_id,
					 JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.issuer.name')) AS issuer_name,
					 JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.template.id')) AS template_id,
					 JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json, '$.template.name')) AS template_name,
					 JSON_UNQUOTE(JSON_EXTRACT(e.meta_json, '$.batch_id')) AS batch_id,
					 JSON_UNQUOTE(JSON_EXTRACT(e.meta_json, '$.batch_name')) AS batch_name
					 FROM %i e INNER JOIN %i v ON v.id = e.version_id
					 WHERE e.event_type = 'credential.issued' AND e.created_at >= %s AND e.created_at < %s AND e.id > %d
					 ORDER BY e.id ASC LIMIT %d",
					$events, $versions, $start . ' 00:00:00', self::next_day( $end ) . ' 00:00:00', $cursor, self::CHUNK_SIZE
				), ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_issuance_analytics_read_failed', __( 'Certloom could not read issuance analytics.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$cursor = max( $cursor, absint( $row['id'] ?? 0 ) );
				call_user_func( $consumer, self::normalize_issuance_row( $row ) );
			}
		} while ( count( $rows ) === self::CHUNK_SIZE );
		return true;
	}

	/** @return true|\WP_Error */
	private function scan_issuance_rows_fallback( $start, $end, $consumer ) {
		global $wpdb;
		$events   = SchemaManager::table( 'credential_events' );
		$versions = SchemaManager::table( 'credential_versions' );
		$cursor = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.id, e.created_at, e.meta_json, v.source_type, v.snapshot_json FROM %i e INNER JOIN %i v ON v.id = e.version_id WHERE e.event_type = 'credential.issued' AND e.created_at >= %s AND e.created_at < %s AND e.id > %d ORDER BY e.id ASC LIMIT %d",
					$events, $versions, $start . ' 00:00:00', self::next_day( $end ) . ' 00:00:00', $cursor, self::CHUNK_SIZE
				), ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_issuance_analytics_read_failed', __( 'Certloom could not read issuance analytics.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$cursor = max( $cursor, absint( $row['id'] ?? 0 ) );
				$snapshot = json_decode( (string) ( $row['snapshot_json'] ?? '' ), true );
				$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
				$snapshot = is_array( $snapshot ) ? $snapshot : array();
				$meta = is_array( $meta ) ? $meta : array();
				$row += array(
					'program_id' => $snapshot['program']['id'] ?? null,
					'program_title' => $snapshot['program']['title'] ?? '',
					'issuer_id' => $snapshot['issuer']['id'] ?? null,
					'issuer_name' => $snapshot['issuer']['name'] ?? '',
					'template_id' => $snapshot['template']['id'] ?? null,
					'template_name' => $snapshot['template']['name'] ?? '',
					'batch_id' => $meta['batch_id'] ?? null,
					'batch_name' => $meta['batch_name'] ?? '',
				);
				call_user_func( $consumer, self::normalize_issuance_row( $row ) );
			}
		} while ( count( $rows ) === self::CHUNK_SIZE );
		return true;
	}

	/** @return array<string,mixed> */
	private static function normalize_issuance_row( array $row ) {
		return array(
			'id' => absint( $row['id'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'source' => self::source( $row['source_type'] ?? '' ),
			'program_id' => self::nullable_id( $row['program_id'] ?? null ),
			'program_title' => self::label( $row['program_title'] ?? '' ),
			'issuer_id' => self::nullable_id( $row['issuer_id'] ?? null ),
			'issuer_name' => self::label( $row['issuer_name'] ?? '' ),
			'template_id' => self::nullable_id( $row['template_id'] ?? null ),
			'template_name' => self::label( $row['template_name'] ?? '' ),
			'batch_id' => self::nullable_id( $row['batch_id'] ?? null ),
			'batch_name' => self::label( $row['batch_name'] ?? '' ),
		);
	}

	/** @return string */
	private static function source( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( 'manual_admin' === $value ) { return 'manual'; }
		if ( 'bulk_admin' === $value ) { return 'bulk'; }
		return 'other';
	}
	/** @return int|null */ private static function nullable_id( $value ) { $id = absint( $value ); return $id > 0 ? $id : null; }
	/** @return string */ private static function label( $value ) { $value = trim( wp_strip_all_tags( (string) $value ) ); return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 255 ) : substr( $value, 0, 255 ); }
	/** @return bool */ private static function valid_date( $value ) { return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ); }
	/** @return string */ private static function next_day( $day ) { try { return ( new \DateTimeImmutable( $day . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' ); } catch ( \Throwable $e ) { return $day; } }
}
