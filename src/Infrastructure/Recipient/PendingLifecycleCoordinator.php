<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Job\JobService;
use Certloom\Application\Contract\JobSchedulerInterface;
use Certloom\Application\Issuance\PendingRequestPolicy;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobRepository;
use Certloom\Infrastructure\Job\JobDispatcher;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One daily bounded sweep; the marker is disposable and excluded from recovery. */
final class PendingLifecycleCoordinator {
	const OPTION = 'certloom_pending_lifecycle_job';
	private $jobs; private $scheduler;
	public function __construct( JobService $jobs, JobSchedulerInterface $scheduler ) { $this->jobs = $jobs; $this->scheduler = $scheduler; }
	public function register() { add_action( JobDispatcher::WATCHDOG_HOOK, array( $this, 'dispatch' ), 22 ); }
	public function dispatch() {
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) { return null; }
		$policy = ( new PendingRequestPolicy() )->read(); if ( is_wp_error( $policy ) ) { return $policy; }
		if ( ! $policy['reminder_days'] && ! $policy['completion_notice'] && ! $policy['cancellation_notice'] && ! $policy['expiry_days'] ) { return null; }
		$day = gmdate( 'Y-m-d' );
		$key = ( new Transaction() )->run( function () use ( $day ) {
			global $wpdb; $wpdb->last_error = '';
			$created = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $wpdb->options, self::OPTION, '', 'off' ) );
			if ( false === $created || '' !== (string) $wpdb->last_error ) { return $this->failed(); }
			$wpdb->last_error = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::OPTION ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! is_string( $row['option_value'] ?? null ) ) { return $this->failed(); }
			$marker = $row['option_value'];
			$parts = explode( '|', $marker );
			if ( count( $parts ) === 2 && 1 === preg_match( '/^[a-f0-9]{32}$/D', $parts[1] ) ) {
				$job = ( new JobRepository() )->find( $parts[1] ); if ( is_wp_error( $job ) ) { return $job; }
				if ( is_array( $job ) && JobTypes::PENDING_LIFECYCLE === $job['job_type'] ) {
					if ( in_array( $job['status'], array( 'queued', 'running', 'paused', 'failed' ), true ) ) { return in_array( $job['status'], array( 'queued', 'running' ), true ) ? $parts[1] : null; }
					if ( $parts[0] === $day ) { return null; }
				}
			}
			$wpdb->last_error = '';
			$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', SchemaManager::table( 'issuance_requests' ) ) );
			if ( '' !== (string) $wpdb->last_error ) { return $this->failed(); } if ( ! $max ) { return null; }
			$key = $this->jobs->create( JobTypes::PENDING_LIFECYCLE, 0, array( 'schema_version' => 1, 'day' => $day, 'max_id' => (int) $max ), null, (int) $max );
			if ( is_wp_error( $key ) ) { return $key; }
			$wpdb->last_error = ''; $written = $wpdb->update( $wpdb->options, array( 'option_value' => $day . '|' . $key ), array( 'option_name' => self::OPTION ) );
			return 1 === $written && '' === (string) $wpdb->last_error ? $key : $this->failed();
		} );
		DatabaseOptions::invalidate( array( self::OPTION ) );
		if ( is_string( $key ) ) { $scheduled = $this->scheduler->schedule( $key ); if ( is_wp_error( $scheduled ) ) { return $scheduled; } }
		return $key;
	}
	private function failed() { return new \WP_Error( 'certloom_pending_lifecycle_schedule_failed', __( 'Request reminders and expiry could not be scheduled safely. Review background job history.', 'certloom' ) ); }
}
