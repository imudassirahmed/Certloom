<?php
namespace Certloom\Application\Analytics;

use Certloom\Infrastructure\Analytics\IssuanceAnalyticsRepository;
use Certloom\Application\Contract\IssuanceAnalyticsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ANL-02 read model: immutable issuance facts plus current forward-looking expiry forecast. */
final class IssuanceAnalyticsService {
	/** @var IssuanceAnalyticsRepositoryInterface */ private $repository;
	/** @var callable */ private $clock;

	public function __construct( ?IssuanceAnalyticsRepositoryInterface $repository = null, $clock = null ) {
		$this->repository = $repository ?: new IssuanceAnalyticsRepository();
		$this->clock = is_callable( $clock ) ? $clock : function () { return time(); };
	}

	/** @return array<string,mixed>|\WP_Error */
	public function report( $days = 30 ) {
		$days = self::normalize_period( $days );
		$now = (int) call_user_func( $this->clock );
		$end = gmdate( 'Y-m-d', $now );
		$start_ts = $now - ( ( $days - 1 ) * DAY_IN_SECONDS );
		$start = gmdate( 'Y-m-d', $start_ts );
		$summary = array( 'total' => 0, 'manual' => 0, 'bulk' => 0, 'other' => 0 );
		$granularity = self::trend_granularity( $days );
		$trend = array();
		$dimensions = array( 'programs' => array(), 'templates' => array(), 'organizations' => array(), 'batches' => array() );
		$scanned = $this->repository->scan_issuance_rows( $start, $end, function ( $row ) use ( &$summary, &$trend, &$dimensions, $granularity ) {
			$summary['total']++;
			$source = isset( $summary[ $row['source'] ] ) ? $row['source'] : 'other';
			$summary[ $source ]++;
			$bucket = self::bucket( (string) $row['created_at'], $granularity );
			if ( ! isset( $trend[ $bucket ] ) ) { $trend[ $bucket ] = array( 'bucket' => $bucket, 'label' => self::bucket_label( $bucket, $granularity ), 'total' => 0 ); }
			$trend[ $bucket ]['total']++;
			self::count_dimension( $dimensions['programs'], $row['program_id'], $row['program_title'], __( 'Unattributed program', 'certloom' ), __( 'Program #%d', 'certloom' ) );
			self::count_dimension( $dimensions['templates'], $row['template_id'], $row['template_name'], __( 'Unattributed template', 'certloom' ), __( 'Template #%d', 'certloom' ) );
			self::count_dimension( $dimensions['organizations'], $row['issuer_id'], $row['issuer_name'], __( 'Unattributed organization', 'certloom' ), __( 'Organization #%d', 'certloom' ) );
			self::count_dimension( $dimensions['batches'], $row['batch_id'], $row['batch_name'], __( 'Unattributed batch', 'certloom' ), __( 'Batch #%d', 'certloom' ) );
		} );
		if ( is_wp_error( $scanned ) ) { return $scanned; }
		$trend = self::complete_trend( $start_ts, $days, $granularity, $trend );
		foreach ( $dimensions as $key => $counts ) { $dimensions[ $key ] = self::top( $counts, 10 ); }

		$forecast = $this->repository->expiry_forecast( $end, 90 );
		if ( is_wp_error( $forecast ) ) { return $forecast; }
		$forecast_view = self::forecast_view( $forecast );

		return array(
			'days' => $days, 'start' => $start, 'end' => $end,
			'summary' => $summary, 'trend' => $trend, 'trend_granularity' => $granularity,
			'dimensions' => $dimensions, 'expiry_forecast' => $forecast_view,
			'provenance' => array(
				'issuance_truth' => 'credential.issued events + immutable Version snapshots',
				'expiry_truth' => 'current Series/current Version',
				'batch_uncaptured_is_unattributed' => true,
				'visitor_data_stored' => false,
				'analytics_table_written' => false,
			),
		);
	}

	/** @return int */ public static function normalize_period( $days ) { $days = absint( $days ); return in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30; }
	/** @return string */ public static function trend_granularity( $days ) { $days = self::normalize_period( $days ); return $days >= 365 ? 'monthly' : ( $days >= 90 ? 'weekly' : 'daily' ); }

	private static function count_dimension( array &$counts, $id, $label, $fallback, $captured_fallback_format ) {
		$id = $id ? absint( $id ) : 0;
		$label = trim( (string) $label );
		$key = $id > 0 ? 'id:' . $id : 'unattributed';
		if ( '' === $label ) {
			$label = $id > 0 ? sprintf( $captured_fallback_format, $id ) : $fallback;
		}
		if ( ! isset( $counts[ $key ] ) ) { $counts[ $key ] = array( 'id' => $id > 0 ? $id : null, 'label' => $label, 'total' => 0 ); }
		$counts[ $key ]['total']++;
	}
	/** @return array<int,array<string,mixed>> */
	private static function top( array $counts, $limit ) {
		$items = array_values( $counts );
		usort( $items, function ( $a, $b ) { $cmp = (int) $b['total'] <=> (int) $a['total']; return 0 !== $cmp ? $cmp : strcmp( (string) $a['label'], (string) $b['label'] ); } );
		return array_slice( $items, 0, max( 1, absint( $limit ) ) );
	}
	/** @return string */
	private static function bucket( $datetime, $granularity ) {
		$ts = strtotime( $datetime . ' UTC' ); if ( false === $ts ) { $ts = 0; }
		if ( 'monthly' === $granularity ) { return gmdate( 'Y-m-01', $ts ); }
		if ( 'weekly' === $granularity ) { $day = (int) gmdate( 'N', $ts ); return gmdate( 'Y-m-d', $ts - ( ( $day - 1 ) * DAY_IN_SECONDS ) ); }
		return gmdate( 'Y-m-d', $ts );
	}
	/** @return string */
	private static function bucket_label( $bucket, $granularity ) {
		$ts = strtotime( $bucket . ' UTC' ); if ( false === $ts ) { return $bucket; }
		return 'monthly' === $granularity ? gmdate( 'M Y', $ts ) : gmdate( 'M j, Y', $ts );
	}
	/** @return array<int,array<string,mixed>> */
	private static function complete_trend( $start_ts, $days, $granularity, array $known ) {
		$result = array(); $seen = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$day = gmdate( 'Y-m-d H:i:s', $start_ts + ( $i * DAY_IN_SECONDS ) );
			$key = self::bucket( $day, $granularity ); if ( isset( $seen[ $key ] ) ) { continue; } $seen[ $key ] = true;
			$result[] = $known[ $key ] ?? array( 'bucket' => $key, 'label' => self::bucket_label( $key, $granularity ), 'total' => 0 );
		}
		return $result;
	}
	/** @return array<string,mixed> */
	private static function forecast_view( array $forecast ) {
		$buckets = array( '0_30' => 0, '31_60' => 0, '61_90' => 0 ); $programs = array();
		foreach ( (array) ( $forecast['rows'] ?? array() ) as $row ) {
			$bucket = (string) ( $row['forecast_bucket'] ?? '' ); $total = max( 0, (int) ( $row['total'] ?? 0 ) );
			if ( isset( $buckets[ $bucket ] ) ) { $buckets[ $bucket ] += $total; }
			$id = absint( $row['program_id'] ?? 0 ); $label = trim( (string) ( $row['program_title'] ?? '' ) ); $key = $id ? 'id:' . $id : 'unattributed';
			if ( ! isset( $programs[ $key ] ) ) { $programs[ $key ] = array( 'id' => $id ?: null, 'label' => $label ?: __( 'Unattributed program', 'certloom' ), 'total' => 0 ); }
			$programs[ $key ]['total'] += $total;
		}
		return array( 'buckets' => $buckets, 'total_90' => array_sum( $buckets ), 'permanent' => max( 0, (int) ( $forecast['permanent'] ?? 0 ) ), 'top_programs' => self::top( $programs, 10 ), 'today' => $forecast['today'] ?? '', 'end' => $forecast['end'] ?? '' );
	}
}
