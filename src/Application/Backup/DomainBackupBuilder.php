<?php
namespace Certloom\Application\Backup;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\DomainBackupBuilderInterface;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

/** Incremental complete-domain backup builder suitable for one bounded job slice per request. */
final class DomainBackupBuilder implements DomainBackupBuilderInterface {
	private $repository;
	private $files;
	private $format;
	private $generation;
	private $maintenance;

	public function __construct( ?DomainBackupRepository $repository = null, ?PrivateJobFileStore $files = null, ?DomainBackupFormat $format = null, ?DomainGeneration $generation = null, ?DomainMaintenanceLock $maintenance = null ) {
		$this->repository = $repository ?: new DomainBackupRepository();
		$this->files = $files ?: new PrivateJobFileStore();
		$this->format = $format ?: new DomainBackupFormat();
		$this->generation = $generation ?: new DomainGeneration();
		$this->maintenance = $maintenance ?: new DomainMaintenanceLock();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function start( $job_key, $plugin_version ) {
		$job_key = strtolower( trim( (string) $job_key ) );
		$locked = $this->maintenance->acquire( $job_key, 'domain_backup_snapshot', 120 );
		if ( is_wp_error( $locked ) ) { return $locked; }
		$snapshot = $this->repository->snapshot();
		$released = $this->maintenance->release( $job_key );
		if ( is_wp_error( $released ) ) {
			return new \WP_Error(
				'certloom_domain_backup_snapshot_cleanup_failed',
				sprintf(
					/* translators: %s: lock release error. */ __( 'Certloom could not release its backup snapshot lock safely (%s). Credential writes remain paused until maintenance cleanup succeeds.', 'certloom' ),
					$released->get_error_message()
				)
			);
		}
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$path = $this->files->create_path( $job_key, 'backup' );
		if ( is_wp_error( $path ) ) { return $path; }
		$manifest = $this->format->manifest( $snapshot, $plugin_version );
		$line = $this->format->encode_line( $manifest );
		if ( is_wp_error( $line ) ) { return $line; }
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive private backup file creation.
		if ( false === $handle ) { return new \WP_Error( 'certloom_domain_backup_file_failed', __( 'Certloom could not create the private domain backup file.', 'certloom' ) ); }
		$written = fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming private backup format.
		$flushed = fflush( $handle );
		fclose( $handle );
		if ( strlen( $line ) !== (int) $written || ! $flushed ) {
			return $this->discard_invalid_backup( $path, 'certloom_domain_backup_write_failed', __( 'Certloom could not write the domain backup manifest.', 'certloom' ) );
		}
		@chmod( $path, 0600 );
		$file_size = @filesize( $path );
		if ( false === $file_size ) {
			return $this->discard_invalid_backup( $path, 'certloom_domain_backup_stat_failed', __( 'Certloom could not record the private backup file position.', 'certloom' ) );
		}
		return array(
			'path' => $path,
			'offset' => absint( $file_size ),
			'generation' => (string) $snapshot['generation'],
			'configuration_hash' => (string) ( $snapshot['configuration_hash'] ?? '' ),
			'entities' => (array) $snapshot['entities'],
			'entity_index' => 0,
			'cursor' => 0,
			'records' => 0,
			'hash' => $this->format->next_hash( '', $line ),
			'complete' => false,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function continue_build( array $state, $limit = 200 ) {
		$valid = $this->validate_state( $state );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$types = DomainBackupRepository::entity_types();
		$index = absint( $state['entity_index'] );
		if ( $index >= count( $types ) ) { return $this->finish( $state ); }
		$type = $types[ $index ];
		$bound = isset( $state['entities'][ $type ] ) && is_array( $state['entities'][ $type ] ) ? $state['entities'][ $type ] : array();
		$rows = $this->repository->read_batch( $type, absint( $state['cursor'] ), absint( $bound['max_id'] ?? 0 ), $limit );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( empty( $rows ) ) {
			$state['entity_index'] = $index + 1;
			$state['cursor'] = 0;
			return $state['entity_index'] >= count( $types ) ? $this->finish( $state ) : $state;
		}
		$handle = $this->open_at_persisted_offset( $state );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}
		foreach ( $rows as $row ) {
			$line = $this->format->encode_line( array( 'type' => 'entity', 'entity' => $type, 'data' => $row ) );
			if ( is_wp_error( $line ) || strlen( $line ) !== (int) fwrite( $handle, is_wp_error( $line ) ? '' : $line ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $handle );
				return is_wp_error( $line ) ? $line : new \WP_Error( 'certloom_domain_backup_write_failed', __( 'Certloom could not append a domain backup record.', 'certloom' ) );
			}
			$state['hash'] = $this->format->next_hash( (string) $state['hash'], $line );
			$state['cursor'] = absint( $row['id'] ?? $state['cursor'] );
			$state['records'] = absint( $state['records'] ) + 1;
		}
		$flushed = fflush( $handle );
		$offset  = ftell( $handle );
		fclose( $handle );
		if ( ! $flushed || false === $offset ) {
			return new \WP_Error( 'certloom_domain_backup_flush_failed', __( 'Certloom could not durably record the domain backup batch.', 'certloom' ) );
		}
		$state['offset'] = absint( $offset );
		return $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function finish( array $state ) {
		if ( ! hash_equals( (string) $state['generation'], $this->generation->current() ) ) {
			return $this->discard_invalid_backup( $state['path'], 'certloom_domain_backup_concurrent_change', __( 'Certloom data changed while the complete backup was being built. No mixed-state backup was kept.', 'certloom' ) );
		}
		$config = $this->repository->configuration_snapshot();
		if ( is_wp_error( $config ) || ! hash_equals( (string) ( $state['configuration_hash'] ?? '' ), is_wp_error( $config ) ? '' : (string) $config['hash'] ) ) {
			return $this->discard_invalid_backup( $state['path'], 'certloom_domain_backup_configuration_changed', __( 'Certloom configuration changed while the complete backup was being built. No mixed-state backup was kept.', 'certloom' ) );
		}
		$integrity = array( 'type' => 'integrity', 'records' => absint( $state['records'] ), 'chain_sha256' => (string) $state['hash'] );
		$line = $this->format->encode_line( $integrity );
		if ( is_wp_error( $line ) ) { return $line; }
		$handle = $this->open_at_persisted_offset( $state );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}
		$written = fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming private backup footer.
		$flushed = fflush( $handle );
		$offset  = ftell( $handle );
		fclose( $handle );
		if ( strlen( $line ) !== (int) $written || ! $flushed || false === $offset ) {
			return new \WP_Error( 'certloom_domain_backup_finalize_failed', __( 'Certloom could not finalize the domain backup integrity record.', 'certloom' ) );
		}
		$state['offset']   = absint( $offset );
		$state['complete'] = true;
		return $state;
	}


	/**
	 * Opens the private backup exactly at the last state persisted in the job row.
	 *
	 * A worker can die after appending a slice but before JobRunner saves its new
	 * cursor/result. On retry, any uncommitted tail is truncated back to the
	 * persisted offset before the same deterministic slice is written again.
	 *
	 * @return resource|\WP_Error
	 */
	private function open_at_persisted_offset( array $state ) {
		$path   = (string) $state['path'];
		// Several slices may run in one PHP request; filesize() otherwise keeps
		// the size from an earlier slice and rejects a correctly appended file.
		clearstatcache( true, $path );
		$offset = absint( $state['offset'] );
		$size   = @filesize( $path );
		if ( false === $size || $size < $offset ) {
			return new \WP_Error( 'certloom_domain_backup_resume_invalid', __( 'The private Certloom backup file is shorter than its persisted job state.', 'certloom' ) );
		}

		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Controlled private resumable backup stream.
		if ( false === $handle ) {
			return new \WP_Error( 'certloom_domain_backup_file_failed', __( 'Certloom could not reopen its private domain backup file.', 'certloom' ) );
		}
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			return new \WP_Error( 'certloom_domain_backup_resume_failed', __( 'Certloom could not resume its private backup at the persisted position.', 'certloom' ) );
		}
		if ( $size > $offset && ! ftruncate( $handle, $offset ) ) {
			fclose( $handle );
			return new \WP_Error( 'certloom_domain_backup_repair_failed', __( 'Certloom could not discard an incomplete backup slice safely.', 'certloom' ) );
		}
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			return new \WP_Error( 'certloom_domain_backup_resume_failed', __( 'Certloom could not position its private backup stream safely.', 'certloom' ) );
		}
		return $handle;
	}

	/** @return \WP_Error */
	private function discard_invalid_backup( $path, $error_code, $message ) {
		$deleted = $this->files->delete( $path );
		if ( is_wp_error( $deleted ) || false === $deleted ) {
			$cleanup_message = is_wp_error( $deleted ) ? $deleted->get_error_message() : __( 'The private backup file could not be removed.', 'certloom' );
			return new \WP_Error(
				'certloom_domain_backup_cleanup_failed',
				sprintf(
					/* translators: %1$s: message; %2$s: cleanup message. */ __( '%1$s Certloom also could not remove the invalid private backup file safely (%2$s).', 'certloom' ),
					(string) $message,
					$cleanup_message
				),
				array( 'original_error' => (string) $error_code )
			);
		}
		return new \WP_Error( (string) $error_code, (string) $message );
	}

	/** @return true|\WP_Error */
	private function validate_state( array $state ) {
		foreach ( array( 'path', 'offset', 'generation', 'configuration_hash', 'entities', 'entity_index', 'cursor', 'records', 'hash' ) as $key ) {
			if ( ! array_key_exists( $key, $state ) ) { return new \WP_Error( 'certloom_domain_backup_state_invalid', __( 'The resumable domain backup state is incomplete.', 'certloom' ) ); }
		}
		if ( ! $this->files->owns( $state['path'] ) || ! is_file( $state['path'] ) ) {
			return new \WP_Error( 'certloom_domain_backup_file_missing', __( 'The private domain backup file is no longer available.', 'certloom' ) );
		}
		return true;
	}
}
