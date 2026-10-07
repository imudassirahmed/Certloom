<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Backup\LocalBackupStore;
use Certloom\Infrastructure\Backup\ScheduledBackup;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Database\DatabaseSession;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Job\JobRepository;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One common worker for manual/scheduled capture, publication receipt and owned-file cleanup. */
final class WholeStateBackupJobHandler implements JobHandlerInterface {
	public function handle( array $job, $worker_token ) {
		$p = $job['payload'] ?? null; $key = $job['job_key'] ?? null;
		if ( JobTypes::WHOLE_BACKUP !== ( $job['job_type'] ?? null ) || ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $key ) || ! is_array( $p ) || count( $p ) !== 2 || 1 !== ( $p['schema_version'] ?? null ) || ! in_array( $p['purpose'] ?? null, array( 'manual', 'scheduled', 'safety' ), true ) ) { return $this->failed(); }
		if ( ! is_string( $worker_token ) || '' === $worker_token || strlen( $worker_token ) > 128 ) { return $this->failed(); }
		return DatabaseSession::run( function () use ( $job, $worker_token, $key ) {
			global $wpdb;
			$name = 'certloom-whole-' . substr( hash( 'sha256', $wpdb->options . '|' . $key ), 0, 48 );
			$wpdb->last_error = '';
			$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
			if ( '' !== (string) $wpdb->last_error || ! in_array( (string) $held, array( '0', '1' ), true ) ) { return $this->failed(); }
			if ( '0' === (string) $held ) { return JobSliceResult::progress( (int) ( $job['cursor'] ?? 0 ), $job['phase'] ?? null, $job['result'] ?? array() ); }
			try { $result = $this->handle_owned( $job, $worker_token ); }
			finally { $wpdb->last_error = ''; $released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
			return '1' === (string) $released && '' === (string) $wpdb->last_error ? $result : $this->failed();
		} );
	}

	private function handle_owned( array $job, $worker_token ) {
		$valid = $this->current_lease( $job, $worker_token ); if ( is_wp_error( $valid ) ) { return $valid; }
		$key = $job['job_key']; $p = $job['payload'];
		$result = is_array( $job['result'] ?? null ) ? $job['result'] : array();
		if ( isset( $result['backup_id'] ) ) {
			return ( new Transaction() )->run( function () use ( $job, $worker_token, $key, $p, $result ) {
				$valid = $this->current_lease( $job, $worker_token, true ); return is_wp_error( $valid ) ? $valid : $this->cleanup( $key, $p['purpose'], $result );
			} );
		}
		$builder = new WholeStateBackupBuilder();
		if ( isset( $result['checkpoint'] ) ) { if ( ! is_array( $result['checkpoint'] ) ) { return $this->failed(); } $state = $builder->load_checkpoint( $result['checkpoint'], $key ); }
		else { $state = $builder->start( $key ); }
		if ( is_wp_error( $state ) ) { return $state; }
		// Only the checkpoint already committed to this job is needed for replay.
		// Uncommitted newer checkpoints are safe to discard under the exact job lock.
		$pruned = $this->prune_checkpoints( $key, $result['checkpoint']['path'] ?? null ); if ( is_wp_error( $pruned ) ) { return $pruned; }
		if ( $state['complete'] ) {
			$metadata = array( 'file_sha256' => $state['archive_sha256'], 'bytes' => $state['archive_bytes'], 'records' => $state['domain']['records'] + $state['legacy']['records'], 'schema_version' => \Certloom\Infrastructure\Database\SchemaManager::SCHEMA_VERSION );
			$entry = ( new Transaction() )->run( function () use ( $job, $worker_token, $state, $metadata, $p ) {
				$valid = $this->current_lease( $job, $worker_token, true ); return is_wp_error( $valid ) ? $valid : ( new LocalBackupStore() )->store_whole( $state['archive_path'], $metadata, $p['purpose'] );
			} ); if ( is_wp_error( $entry ) ) { return $entry; }
			$files = $this->working_files( $key ); if ( is_wp_error( $files ) ) { return $files; }
			return JobSliceResult::progress( (int) ( $job['cursor'] ?? 0 ) + 1, 'cleanup', array( 'backup_id' => $entry['id'], 'scope' => 'whole_state', 'file_sha256' => $entry['file_sha256'], 'bytes' => $entry['bytes'], 'records' => $entry['records'], 'cleanup_files' => $files, 'cleanup_cursor' => 0 ) );
		}
		$state = $builder->continue_build( $state ); if ( is_wp_error( $state ) ) { return $state; }
		$checkpoint = $builder->save_checkpoint( $state ); if ( is_wp_error( $checkpoint ) ) { return $checkpoint; }
		$valid = $this->current_lease( $job, $worker_token ); if ( is_wp_error( $valid ) ) { return $valid; }
		return JobSliceResult::progress( (int) ( $job['cursor'] ?? 0 ) + 1, $state['complete'] ? 'publish' : $state['phase'], array( 'checkpoint' => $checkpoint, 'scope' => 'whole_state' ) );
	}
	private function cleanup( $key, $purpose, array $result ) {
		if ( ! is_string( $result['backup_id'] ) || ! is_array( $result['cleanup_files'] ?? null ) || count( $result['cleanup_files'] ) > 5000 || ! is_int( $result['cleanup_cursor'] ?? null ) || $result['cleanup_cursor'] < 0 || $result['cleanup_cursor'] > count( $result['cleanup_files'] ) || ( $result['cleanup_files'] && array_keys( $result['cleanup_files'] ) !== range( 0, count( $result['cleanup_files'] ) - 1 ) ) ) { return $this->failed(); }
		$entry = ( new LocalBackupStore() )->resolve( $result['backup_id'] ); if ( is_wp_error( $entry ) ) { return $entry; }
		if ( 'whole_state' !== $entry['scope'] || $key !== $entry['export_id'] || $purpose !== $entry['purpose'] || ( $result['file_sha256'] ?? null ) !== $entry['file_sha256'] || ( $result['bytes'] ?? null ) !== $entry['bytes'] ) { return $this->failed(); }
		$files = new PrivateJobFileStore();
		$seen = array();
		foreach ( $result['cleanup_files'] as $path ) { if ( ! is_string( $path ) || isset( $seen[$path] ) || ! $files->owns( $path ) || 1 !== preg_match( $this->working_pattern( $key ), basename( $path ) ) ) { return $this->failed(); } $seen[$path] = true; }
		for ( $i = 0; $i < 10 && $result['cleanup_cursor'] < count( $result['cleanup_files'] ); ++$i, ++$result['cleanup_cursor'] ) {
			$path = $result['cleanup_files'][$result['cleanup_cursor']];
			if ( ! $files->owns( $path ) || 1 !== preg_match( $this->working_pattern( $key ), basename( $path ) ) ) { return $this->failed(); }
			$deleted = $files->delete( $path ); if ( is_wp_error( $deleted ) || true !== $deleted ) { return $this->failed(); }
		}
		if ( $result['cleanup_cursor'] < count( $result['cleanup_files'] ) ) { return JobSliceResult::progress( $result['cleanup_cursor'], 'cleanup', $result ); }
		if ( 'scheduled' === $purpose ) {
			$pruned = ( new LocalBackupStore() )->prune_scheduled( ScheduledBackup::settings()['retention'] );
			if ( is_wp_error( $pruned ) ) { return $pruned; }
		}
		if ( 'safety' === $purpose ) {
			$pruned = ( new LocalBackupStore() )->prune_safety( get_option( 'certloom_safety_backup_retention', 10 ) );
			if ( is_wp_error( $pruned ) ) { return $pruned; }
		}
		return JobSliceResult::succeeded( array( 'backup_id' => $entry['id'], 'scope' => 'whole_state', 'file_sha256' => $entry['file_sha256'], 'bytes' => $entry['bytes'], 'records' => $entry['records'], 'working_files_removed' => true ), $entry['records'] );
	}
	private function working_files( $key ) {
		$files = new PrivateJobFileStore(); $root = $files->directory(); if ( is_wp_error( $root ) ) { return $root; }
		$owned = array();
		try { foreach ( new \DirectoryIterator( $root ) as $file ) { $name = $file->getFilename(); if ( 1 !== preg_match( $this->working_pattern( $key ), $name ) ) { continue; } $path = $root . '/' . $name; if ( ! $files->owns( $path ) || ! is_file( $path ) ) { return $this->failed(); } $owned[] = $path; if ( count( $owned ) > 5000 ) { return $this->failed(); } } }
		catch ( \Throwable $error ) { return $this->failed(); }
		return $owned;
	}
	private function prune_checkpoints( $key, $retain ) {
		$files = $this->working_files( $key ); if ( is_wp_error( $files ) ) { return $files; }
		$store = new PrivateJobFileStore();
		foreach ( $files as $path ) { if ( $path === $retain || 1 !== preg_match( '/^' . $key . '-whole-checkpoint-[a-f0-9]{48}\.data$/D', basename( $path ) ) ) { continue; } if ( true !== $store->delete( $path ) ) { return $this->failed(); } }
		return true;
	}
	private function current_lease( array $job, $worker_token, $lock = false ) {
		global $wpdb;
		if ( $lock && ! Transaction::owns_current() ) { return $this->failed(); }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT job_key,locked_by FROM %i WHERE job_key=%s AND status=%s AND locked_until>=%s' . ( $lock ? ' FOR UPDATE' : '' ), SchemaManager::table( 'jobs' ), $job['job_key'], 'running', gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || $worker_token !== $row['locked_by'] ) { return $this->failed(); }
		$current = ( new JobRepository() )->find( $job['job_key'] ); if ( ! is_array( $current ) ) { return $this->failed(); }
		foreach ( array( 'job_type', 'cursor', 'phase', 'payload', 'result' ) as $field ) { if ( ( new CanonicalJson() )->hash( $job[$field] ?? null ) !== ( new CanonicalJson() )->hash( $current[$field] ?? null ) ) { return $this->failed(); } }
		return true;
	}
	private function working_pattern( $key ) { return '/^' . $key . '-(?:backup|whole-(?:context|checkpoint|legacy|deliveries|references|configuration|authoring|asset|archive))-[a-f0-9]{48}\.data$/D'; }
	private function failed() { return new \WP_Error( 'certloom_whole_backup_job_invalid', __( 'Certloom could not confirm the complete recovery job or its stored receipt. Working data remains private for review.', 'certloom' ) ); }
}
