<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Job\JobService;
use Certloom\Application\Contract\JobSchedulerInterface;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobRepository;
use Certloom\Infrastructure\Job\JobDispatcher;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local durable due flags plus one coalesced disposable worker. No account GET issues. */
final class PendingWorkflowCoordinator {
	const JOB_OPTION = 'certloom_pending_reassessment_job';
	private $jobs; private $scheduler;
	public function __construct( JobService $jobs, JobSchedulerInterface $scheduler ) { $this->jobs = $jobs; $this->scheduler = $scheduler; }
	public function register() {
		add_action( 'certloom_recipient_fields_changed', array( $this, 'recipient_changed' ) );
		add_action( 'certloom_issuance_request_changed', array( $this, 'request_changed' ) );
		add_action( 'certloom_pending_configuration_changed', array( $this, 'configuration_changed' ) );
		add_action( JobDispatcher::WATCHDOG_HOOK, array( $this, 'dispatch' ), 20 );
		add_action( JobDispatcher::WATCHDOG_HOOK, array( $this, 'configuration_changed' ), 21 );
	}
	public function recipient_changed( $recipient_id ) {
		$result = ( new IssuanceRequestRepository() )->mark_due_for_recipient( $recipient_id );
		if ( ! is_wp_error( $result ) && $result ) { $this->dispatch(); }
	}
	public function request_changed() { $this->dispatch(); }
	public function configuration_changed() {
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) { return $this->failed( 'schema' ); }
		$key = ( new PendingConfigurationQueue() )->dispatch( $this->jobs );
		if ( is_wp_error( $key ) || null === $key ) { return $key; }
		$scheduled = $this->scheduler->schedule( $key );
		return is_wp_error( $scheduled ) ? $scheduled : $key;
	}
	public function dispatch() {
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) { return $this->failed( 'schema' ); }
		$cutoff = current_time( 'mysql', true );
		$due = ( new IssuanceRequestRepository() )->due_ids( $cutoff, 0, PHP_INT_MAX, 1 );
		if ( is_wp_error( $due ) ) { return $due; }
		if ( ! $due ) { return null; }
		$key = ( new Transaction() )->run( function () use ( $cutoff ) {
			global $wpdb;
			// A unique option row serializes simultaneous signals without a full jobs-table scan.
			$wpdb->last_error = '';
			$created = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $wpdb->options, self::JOB_OPTION, '', 'off' ) );
			if ( false === $created || '' !== (string) $wpdb->last_error ) { return $this->failed( 'queue_row_insert' ); }
			$wpdb->last_error = '';
			$marker = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::JOB_OPTION ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $marker ) || ! is_string( $marker['option_value'] ?? null ) ) { return $this->failed( 'queue_row_read' ); }
			$current_key = $marker['option_value'];
			if ( 1 === preg_match( '/^[a-f0-9]{32}$/D', $current_key ) ) {
				$job = ( new JobRepository() )->find( $current_key );
				if ( is_wp_error( $job ) ) { return $job; }
				if ( is_array( $job ) && JobTypes::PENDING_REASSESSMENT === $job['job_type'] && in_array( $job['status'], array( 'queued', 'running', 'paused' ), true ) ) { return $current_key; }
			}
			$wpdb->last_error = '';
			$max_id = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', SchemaManager::table( 'issuance_requests' ) ) );
			if ( '' !== (string) $wpdb->last_error || ! $max_id ) { return $this->failed( 'selection' ); }
			$key = $this->jobs->create( JobTypes::PENDING_REASSESSMENT, 0, array( 'schema_version' => 1, 'cutoff' => $cutoff, 'max_id' => (int) $max_id ), null, (int) $max_id );
			if ( is_wp_error( $key ) ) { return $key; }
			$wpdb->last_error = '';
			$saved = $wpdb->update( $wpdb->options, array( 'option_value' => $key ), array( 'option_name' => self::JOB_OPTION ) );
			return false === $saved || '' !== (string) $wpdb->last_error ? $this->failed( 'job_ref_save' ) : $key;
		} );
		DatabaseOptions::invalidate( array( self::JOB_OPTION ) );
		if ( is_wp_error( $key ) ) { return $key; }
		$scheduled = $this->scheduler->schedule( $key );
		return is_wp_error( $scheduled ) ? $scheduled : $key;
	}
	private function failed( $stage ) { return new \WP_Error( 'certloom_pending_schedule_failed', __( 'Certloom could not safely queue pending certificate work. The watchdog will retry.', 'certloom' ), array( 'stage' => $stage ) ); }
}
