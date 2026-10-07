<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobDispatcher;
use Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository;
use Certloom\Domain\Job\JobTypes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded operational projection. Never returns customer values, source secrets or job payloads. */
final class PendingWorkflowHealth {
	const LIMIT = 50;
	public function read() {
		global $wpdb;
		$cutoff = current_time( 'mysql', true ); $due_count = 0; $oldest = null;
		foreach ( PendingConfigurationQueue::STATUSES as $status ) {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT next_attempt_at FROM %i FORCE INDEX (work_due) WHERE status=%s AND next_attempt_at<=%s ORDER BY next_attempt_at,id LIMIT 51', SchemaManager::table( 'issuance_requests' ), $status, $cutoff ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->failed(); }
			$due_count += count( $rows );
			if ( $rows && ( null === $oldest || $rows[0]['next_attempt_at'] < $oldest ) ) { $oldest = $rows[0]['next_attempt_at']; }
		}
		$jobs = array();
		foreach ( array( 'queued', 'running', 'paused', 'failed' ) as $status ) {
			$count = 0;
			foreach ( array( JobTypes::PENDING_REASSESSMENT, JobTypes::PENDING_CONFIGURATION, JobTypes::PENDING_LIFECYCLE, JobTypes::RECIPIENT_NOTICE ) as $type ) {
				$wpdb->last_error = '';
				$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i FORCE INDEX (type_status) WHERE job_type=%s AND status=%s LIMIT 51', SchemaManager::table( 'jobs' ), $type, $status ) );
				if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->failed(); }
				$count += count( $rows );
			}
			$jobs[ $status ] = $this->count( $count );
		}
		$pending = ( new PendingConfigurationQueue() )->pending(); if ( is_wp_error( $pending ) ) { return $pending; }
		$sources = ( new WebhookV2ConnectionRepository() )->read();
		$tutor_sources = ( new \Certloom\Infrastructure\Integration\TutorPendingMappingRepository() )->read();
		$woo_sources = ( new \Certloom\Infrastructure\Integration\WooCommercePendingMappingRepository() )->read();
		$next = wp_next_scheduled( JobDispatcher::WATCHDOG_HOOK );
		return array(
			'as_of' => $cutoff, 'due' => $this->count( $due_count ) + array( 'oldest_at' => $oldest ), 'jobs' => $jobs,
			'configuration_pending' => $pending,
			'cron' => array( 'traffic_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), 'next_watchdog' => $next ? (int) $next : null, 'lag_seconds' => $next ? max( 0, time() - (int) $next ) : null ),
			'app_connections_need_review' => is_wp_error( $sources ) || is_wp_error( $tutor_sources ) || is_wp_error( $woo_sources ),
		);
	}
	private function count( $count ) { return array( 'count' => min( self::LIMIT, $count ), 'has_more' => $count > self::LIMIT ); }
	private function failed() { return new \WP_Error( 'certloom_pending_health_unavailable', __( 'Background checks could not be read safely. Refresh when the database is available.', 'certloom' ), array( 'status' => 503 ) ); }
}
