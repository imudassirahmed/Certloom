<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Contract\PendingConfigurationSelectionInterface;
use Certloom\Application\Job\JobService;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Job\JobRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Transactional configuration outbox. Lost cron events cannot lose the signal. */
final class PendingConfigurationQueue implements PendingConfigurationSelectionInterface {
	const OPTION = 'certloom_pending_configuration';
	const STATUSES = array( 'received', 'waiting', 'ready', 'processing', 'blocked', 'failed' );

	/** Read-only health projection. A GET never creates/locks an outbox or dispatches work. */
	public function pending() {
		$values = \Certloom\Infrastructure\Data\DatabaseOptions::read( array( self::OPTION ) );
		if ( is_wp_error( $values ) ) { return $values; }
		if ( ! $values[ self::OPTION ]['exists'] ) { return false; }
		$state = $this->decode( $values[ self::OPTION ]['value'] );
		return is_wp_error( $state ) ? $state : $state['pending'];
	}

	/** Join the configuration command's transaction; reject the edit if this write fails. */
	public function changed( $program_id = 0 ) {
		// Older schema installation has no pending-workflow stores yet.
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) { return true; }
		if ( ! is_int( $program_id ) || $program_id < 0 ) { return $this->failed( 'scope' ); }
		return ( new Transaction() )->run( function () use ( $program_id ) {
			global $wpdb;
			$state = $this->locked_state( true );
			if ( is_wp_error( $state ) ) { return $state; }
			$wpdb->last_error = '';
			$max_id = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', SchemaManager::table( 'issuance_requests' ) ) );
			if ( '' !== (string) $wpdb->last_error ) { return $this->failed( 'ceiling' ); }
			try { $generation = bin2hex( random_bytes( 16 ) ); } catch ( \Throwable $error ) { return $this->failed( 'generation' ); }
			// Coalesce repeated edits; different affected Programs require one global pass.
			$scope = $state['pending'] && $state['program_id'] !== $program_id ? 0 : $program_id;
			return $this->save( array( 'schema_version' => 1, 'generation' => $generation, 'pending' => true, 'program_id' => $scope, 'max_id' => (int) $max_id, 'job_key' => $state['job_key'] ) );
		} );
	}

	/** One coalesced worker; a newer edit remains pending while an older worker finishes. */
	public function dispatch( JobService $jobs ) {
		return ( new Transaction() )->run( function () use ( $jobs ) {
			$state = $this->locked_state( false );
			if ( is_wp_error( $state ) || null === $state || ! $state['pending'] ) { return is_wp_error( $state ) ? $state : null; }
			if ( null !== $state['job_key'] ) {
				$job = ( new JobRepository() )->find( $state['job_key'] );
				if ( is_wp_error( $job ) ) { return $job; }
				if ( is_array( $job ) && JobTypes::PENDING_CONFIGURATION === $job['job_type'] && in_array( $job['status'], array( 'queued', 'running', 'paused' ), true ) ) { return $state['job_key']; }
			}
			if ( 0 === $state['max_id'] ) { $state['pending'] = false; return is_wp_error( $saved = $this->save( $state ) ) ? $saved : null; }
			$key = $jobs->create( JobTypes::PENDING_CONFIGURATION, 0, array( 'schema_version' => 1, 'generation' => $state['generation'], 'program_id' => $state['program_id'], 'max_id' => $state['max_id'] ), null, $state['max_id'] );
			if ( is_wp_error( $key ) ) { return $key; }
			$state['job_key'] = $key;
			$saved = $this->save( $state );
			return is_wp_error( $saved ) ? $saved : $key;
		} );
	}

	/** A stale/crashed worker cannot consume a subsequent configuration change. */
	public function acknowledge( $generation ) {
		if ( ! is_string( $generation ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $generation ) ) { return $this->failed( 'generation' ); }
		return ( new Transaction() )->run( function () use ( $generation ) {
			$state = $this->locked_state( false );
			if ( is_wp_error( $state ) ) { return $state; }
			if ( null === $state || ! hash_equals( $state['generation'], $generation ) || ! $state['pending'] ) { return true; }
			$state['pending'] = false;
			return $this->save( $state );
		} );
	}

	public function configuration_rows( $program_id, $after_id, $max_id, $limit = 11 ) {
		if ( ! is_int( $program_id ) || $program_id < 0 || ! is_int( $after_id ) || $after_id < 0 || ! is_int( $max_id ) || $max_id < $after_id || ! is_int( $limit ) || $limit < 1 || $limit > 11 ) { return $this->failed( 'selection' ); }
		global $wpdb;
		$columns = 'id,program_id,status,restore_state,source_type,mapping_sha256';
		$table = SchemaManager::table( 'issuance_requests' );
		$wpdb->last_error = '';
		if ( 0 === $program_id ) {
			// Primary-key pages include terminal rows, so even a mostly-issued table is bounded.
			$sql = $wpdb->prepare( 'SELECT ' . $columns . ' FROM %i WHERE id>%d AND id<=%d ORDER BY id LIMIT %d', $table, $after_id, $max_id, $limit );
		} else {
			// Each branch uses (program_id,status,id). Merge at most 6 * 11 rows.
			$branches = array();
			foreach ( self::STATUSES as $status ) { $branches[] = '(' . $wpdb->prepare( 'SELECT ' . $columns . ' FROM %i FORCE INDEX (program_status) WHERE program_id=%d AND status=%s AND id>%d AND id<=%d ORDER BY id LIMIT %d', $table, $program_id, $status, $after_id, $max_id, $limit ) . ')'; }
			$sql = implode( ' UNION ALL ', $branches ) . ' ORDER BY id LIMIT ' . $limit;
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->failed( 'selection' ); }
		foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['program_id'] = (int) $row['program_id']; } unset( $row );
		return $rows;
	}

	private function locked_state( $create ) {
		global $wpdb;
		if ( $create ) {
			$initial = array( 'schema_version' => 1, 'generation' => str_repeat( '0', 32 ), 'pending' => false, 'program_id' => 0, 'max_id' => 0, 'job_key' => null );
			$wpdb->last_error = '';
			$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $wpdb->options, self::OPTION, wp_json_encode( $initial ), 'off' ) );
			if ( false === $result || '' !== (string) $wpdb->last_error ) { return $this->failed( 'insert' ); }
			LegacyWriteGuard::invalidate_option( self::OPTION );
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::OPTION ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->failed( 'read' ); }
		if ( null === $row && ! $create ) { return null; }
		if ( ! is_array( $row ) || ! is_string( $row['option_value'] ?? null ) ) { return $this->failed( 'read' ); }
		return $this->decode( $row['option_value'] );
	}
	private function decode( $value ) {
		if ( ! is_string( $value ) ) { return $this->failed( 'state' ); }
		$state = json_decode( $value, true );
		if ( ! is_array( $state ) || count( $state ) !== 6 || array_diff( array_keys( $state ), array( 'schema_version', 'generation', 'pending', 'program_id', 'max_id', 'job_key' ) ) || 1 !== ( $state['schema_version'] ?? null ) || ! is_bool( $state['pending'] ?? null ) || ! is_int( $state['program_id'] ?? null ) || $state['program_id'] < 0 || ! is_int( $state['max_id'] ?? null ) || $state['max_id'] < 0 || ! is_string( $state['generation'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $state['generation'] ) || ( null !== ( $state['job_key'] ?? null ) && ( ! is_string( $state['job_key'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $state['job_key'] ) ) ) ) { return $this->failed( 'state' ); }
		return $state;
	}

	private function save( array $state ) {
		global $wpdb;
		$value = wp_json_encode( $state );
		if ( ! is_string( $value ) ) { return $this->failed( 'encode' ); }
		$wpdb->last_error = '';
		$saved = $wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => self::OPTION ) );
		LegacyWriteGuard::invalidate_option( self::OPTION );
		return 1 !== $saved || '' !== (string) $wpdb->last_error ? $this->failed( 'save' ) : true;
	}
	private function failed( $stage ) { return new \WP_Error( 'certloom_pending_configuration_failed', __( 'Certloom could not safely retain the pending-certificate configuration change. Retry when the database is available.', 'certloom' ), array( 'stage' => $stage ) ); }
}
