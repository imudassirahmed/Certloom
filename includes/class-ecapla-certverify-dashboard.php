<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only data composer for the premium Certloom administrator dashboard.
 *
 * The dashboard intentionally reads only the proven live registry and
 * privacy-safe system diagnostics. It does not provision the future domain,
 * mutate certificate state, or run acceptance proofs merely by being viewed.
 */
class Ecapla_CertVerify_Dashboard {

	/** Current-production health checks suitable for the everyday dashboard. */
	const HEALTH_CHECK_IDS = array(
		'php_version',
		'legacy_registry',
		'authority',
		'maintenance',
		'cron',
		'https',
		'pretty_permalinks',
		'memory_limit',
	);

	/**
	 * Builds one bounded dashboard payload.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_data() {
		$metrics = Ecapla_CertVerify_Database::get_dashboard_metrics();
		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		$recent = Ecapla_CertVerify_Database::get_list(
			array(
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 5,
				'page'     => 1,
			)
		);
		if ( is_wp_error( $recent ) ) {
			return $recent;
		}

		return array(
			'metrics'             => self::normalize_metrics( $metrics ),
			'recent'              => isset( $recent['items'] ) && is_array( $recent['items'] ) ? array_slice( $recent['items'], 0, 5 ) : array(),
			'health'              => self::health_summary( Ecapla_CertVerify_System_Status::get_checks() ),
			'legacy_review_count' => max( 0, (int) get_option( Ecapla_CertVerify_Database::LEGACY_REVIEW_OPTION, 0 ) ),
		);
	}

	/**
	 * Converts database values to a stable dashboard contract.
	 *
	 * @param array<string,mixed> $metrics Raw metrics.
	 * @return array<string,int>
	 */
	public static function normalize_metrics( $metrics ) {
		$keys = array( 'total', 'valid', 'suspended', 'withdrawn', 'expired', 'expiring_soon', 'issued_this_month' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = max( 0, (int) ( $metrics[ $key ] ?? 0 ) );
		}
		return $out;
	}

	/**
	 * Summarizes only health signals relevant to the current live product.
	 * Development acceptance proofs deliberately stay on System Status rather
	 * than making an otherwise healthy production dashboard look degraded.
	 *
	 * @param array<int,array<string,mixed>> $checks System-status checks.
	 * @return array<string,mixed>
	 */
	public static function health_summary( $checks ) {
		$selected = array();
		$counts   = array( 'good' => 0, 'warning' => 0, 'bad' => 0 );
		foreach ( (array) $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $check['id'] ?? '' ) );
			if ( ! in_array( $id, self::HEALTH_CHECK_IDS, true ) ) {
				continue;
			}
			$status = sanitize_key( (string) ( $check['status'] ?? 'warning' ) );
			if ( ! isset( $counts[ $status ] ) ) {
				$status = 'warning';
			}
			$counts[ $status ]++;
			$check['status'] = $status;
			$selected[]      = $check;
		}

		$overall = $counts['bad'] > 0 ? 'bad' : ( $counts['warning'] > 0 ? 'warning' : 'good' );
		$issues  = array();
		foreach ( $selected as $check ) {
			if ( 'good' !== $check['status'] ) {
				$issues[] = $check;
			}
			if ( count( $issues ) >= 4 ) {
				break;
			}
		}

		return array(
			'overall' => $overall,
			'counts'  => $counts,
			'issues'  => $issues,
			'checks'  => $selected,
		);
	}
}
