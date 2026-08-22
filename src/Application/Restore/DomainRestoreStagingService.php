<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Contract\DomainRestoreStagingInterface;
use Certloom\Application\Contract\DomainRestoreWorkspaceInterface;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

/**
 * Resumable complete-domain restore loader.
 *
 * The uploaded backup is never written directly into live tables. Each bounded
 * slice validates and idempotently inserts records into private same-schema
 * staging tables. Only a fully read, integrity-checked, relationship-valid
 * workspace is eligible for an atomic Replace commit.
 */
final class DomainRestoreStagingService implements DomainRestoreStagingInterface {
	const DEFAULT_MAX_RECORDS = 500000;

	/** @var DomainRestoreWorkspaceInterface */
	private $workspace;
	/** @var PrivateJobFileStore */
	private $files;
	/** @var DomainBackupFormat */
	private $format;
	/** @var DomainRestoreRowValidator */
	private $row_validator;

	public function __construct(
		DomainRestoreWorkspaceInterface $workspace = null,
		PrivateJobFileStore $files = null,
		DomainBackupFormat $format = null,
		DomainRestoreRowValidator $row_validator = null
	) {
		$this->workspace     = $workspace ?: new DomainRestoreWorkspace();
		$this->files         = $files ?: new PrivateJobFileStore();
		$this->format        = $format ?: new DomainBackupFormat();
		$this->row_validator = $row_validator ?: new DomainRestoreRowValidator();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function start( $path, $owner_token, $max_records = self::DEFAULT_MAX_RECORDS ) {
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'certloom_domain_restore_private_file_required', __( 'Certloom restore requires a validated private job file.', 'certloom' ) );
		}
		$size = @filesize( $path );
		if ( false !== $size && $size > DomainBackupFormat::MAX_FILE_BYTES ) {
			return new \WP_Error( 'certloom_domain_backup_file_too_large', __( 'The Certloom backup exceeds the supported restore size limit.', 'certloom' ) );
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded private restore stream.
		if ( false === $handle ) {
			return new \WP_Error( 'certloom_domain_restore_read_failed', __( 'Certloom could not open the private restore file.', 'certloom' ) );
		}
		$line = $this->format->read_line( $handle, 1 );
		if ( is_wp_error( $line ) || null === $line ) {
			fclose( $handle );
			return is_wp_error( $line ) ? $line : new \WP_Error( 'certloom_domain_backup_manifest_invalid', __( 'The Certloom backup is missing its manifest.', 'certloom' ) );
		}
		$manifest = json_decode( trim( $line ), true );
		if ( ! is_array( $manifest ) || 'manifest' !== (string) ( $manifest['type'] ?? '' ) || DomainBackupFormat::FORMAT !== (string) ( $manifest['format'] ?? '' ) || DomainBackupFormat::VERSION !== absint( $manifest['format_version'] ?? 0 ) ) {
			fclose( $handle );
			return new \WP_Error( 'certloom_domain_backup_manifest_invalid', __( 'This is not a supported Certloom domain backup.', 'certloom' ) );
		}
		$config = $this->validate_configuration( (array) ( $manifest['configuration'] ?? array() ), (string) ( $manifest['configuration_hash'] ?? '' ) );
		if ( is_wp_error( $config ) ) {
			fclose( $handle );
			return $config;
		}
		$max_records = max( 1, absint( $max_records ) );
		$declared = 0;
		$entities = array();
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$bound = isset( $manifest['entities'][ $entity ] ) && is_array( $manifest['entities'][ $entity ] ) ? $manifest['entities'][ $entity ] : null;
			if ( null === $bound ) {
				fclose( $handle );
				return new \WP_Error( 'certloom_domain_backup_manifest_invalid', __( 'The Certloom backup manifest is missing a required entity declaration.', 'certloom' ) );
			}
			$count = absint( $bound['count'] ?? 0 );
			$entities[ $entity ] = array( 'count' => $count, 'max_id' => absint( $bound['max_id'] ?? 0 ) );
			$declared += $count;
		}
		if ( $declared > $max_records ) {
			fclose( $handle );
			return new \WP_Error( 'certloom_domain_backup_limit', __( 'The Certloom backup exceeds the configured record safety limit.', 'certloom' ) );
		}
		$offset = ftell( $handle );
		fclose( $handle );

		$workspace = $this->workspace->create( $owner_token );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		return array(
			'path'          => (string) $path,
			'owner_token'   => (string) $owner_token,
			'workspace'     => $workspace,
			'manifest'      => $manifest,
			'configuration' => $config,
			'offset'        => absint( $offset ),
			'line_no'       => 1,
			'entity_order'  => -1,
			'records'       => 0,
			'counts'        => array_fill_keys( DomainBackupRepository::entity_types(), 0 ),
			'hash'          => $this->format->next_hash( '', $line ),
			'phase'         => 'load',
			'complete'      => false,
			'max_records'   => $max_records,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function continue_stage( array $state, $limit = 200 ) {
		$valid = $this->validate_state( $state );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! empty( $state['complete'] ) ) {
			return $state;
		}
		$touched = $this->workspace->touch( $state['workspace'] );
		if ( is_wp_error( $touched ) ) {
			return $touched;
		}
		$handle = @fopen( $state['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded private restore stream.
		if ( false === $handle || 0 !== fseek( $handle, absint( $state['offset'] ) ) ) {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
			return $this->abort( $state, new \WP_Error( 'certloom_domain_restore_resume_failed', __( 'Certloom could not resume the private restore stream.', 'certloom' ) ) );
		}

		$limit = max( 1, min( 500, absint( $limit ) ) );
		$processed = 0;
		$order = array_flip( DomainBackupRepository::entity_types() );
		while ( $processed < $limit ) {
			$next_line_no = absint( $state['line_no'] ) + 1;
			$line = $this->format->read_line( $handle, $next_line_no );
			if ( is_wp_error( $line ) ) {
				fclose( $handle );
				return $this->abort( $state, $line );
			}
			if ( null === $line ) {
				fclose( $handle );
				if ( 'trailing' !== $state['phase'] ) {
					return $this->abort( $state, new \WP_Error( 'certloom_domain_backup_integrity_failed', __( 'The Certloom domain backup ended before its integrity record.', 'certloom' ) ) );
				}
				return $this->finalize( $state );
			}
			$state['line_no'] = $next_line_no;
			$state['offset']  = absint( ftell( $handle ) );

			if ( 'trailing' === $state['phase'] ) {
				if ( '' !== trim( $line ) ) {
					fclose( $handle );
					return $this->abort( $state, new \WP_Error( 'certloom_domain_backup_trailing_data', __( 'The Certloom domain backup contains unexpected data after its integrity record.', 'certloom' ) ) );
				}
				$processed++;
				continue;
			}

			$item = json_decode( trim( $line ), true );
			if ( ! is_array( $item ) ) {
				fclose( $handle );
				return $this->abort( $state, new \WP_Error( 'certloom_domain_backup_json_invalid', sprintf( __( 'The Certloom domain backup contains invalid JSON on line %d.', 'certloom' ), $next_line_no ) ) );
			}
			$type = (string) ( $item['type'] ?? '' );
			if ( 'integrity' === $type ) {
				if ( absint( $item['records'] ?? -1 ) !== absint( $state['records'] ) || ! hash_equals( (string) $state['hash'], (string) ( $item['chain_sha256'] ?? '' ) ) ) {
					fclose( $handle );
					return $this->abort( $state, new \WP_Error( 'certloom_domain_backup_integrity_failed', __( 'The Certloom domain backup failed its integrity check.', 'certloom' ) ) );
				}
				$counts_valid = $this->validate_counts( $state );
				if ( is_wp_error( $counts_valid ) ) {
					fclose( $handle );
					return $this->abort( $state, $counts_valid );
				}
				$state['phase'] = 'trailing';
				$processed++;
				continue;
			}
			if ( 'entity' !== $type || empty( $item['entity'] ) || ! is_array( $item['data'] ?? null ) ) {
				fclose( $handle );
				return $this->abort( $state, new \WP_Error( 'certloom_domain_backup_record_invalid', sprintf( __( 'The Certloom domain backup contains an unsupported record on line %d.', 'certloom' ), $next_line_no ) ) );
			}
			$entity = sanitize_key( (string) $item['entity'] );
			if ( ! isset( $order[ $entity ] ) || $order[ $entity ] < (int) $state['entity_order'] ) {
				fclose( $handle );
				return $this->abort( $state, new \WP_Error( 'certloom_domain_restore_entity_order_invalid', __( 'The Certloom backup entities are not in the required dependency order.', 'certloom' ) ) );
			}
			$scalar = $this->row_validator->validate( $entity, $item['data'] );
			if ( is_wp_error( $scalar ) ) {
				fclose( $handle );
				return $this->abort( $state, $scalar );
			}
			$inserted = $this->workspace->insert_row( $state['workspace'], $entity, $item['data'] );
			if ( is_wp_error( $inserted ) ) {
				fclose( $handle );
				return $this->abort( $state, $inserted );
			}
			$state['entity_order'] = $order[ $entity ];
			$state['records'] = absint( $state['records'] ) + 1;
			if ( $state['records'] > absint( $state['max_records'] ) ) {
				fclose( $handle );
				return $this->abort( $state, new \WP_Error( 'certloom_domain_backup_limit', __( 'The Certloom backup exceeds the configured record safety limit.', 'certloom' ) ) );
			}
			$state['counts'][ $entity ] = absint( $state['counts'][ $entity ] ?? 0 ) + 1;
			$state['hash'] = $this->format->next_hash( (string) $state['hash'], $line );
			$processed++;
		}
		fclose( $handle );
		return $state;
	}

	/** @return true|\WP_Error */
	public function commit_replace( array $state ) {
		if ( empty( $state['complete'] ) || 'ready' !== (string) ( $state['phase'] ?? '' ) ) {
			return new \WP_Error( 'certloom_domain_restore_not_ready', __( 'The Certloom restore workspace has not completed validation.', 'certloom' ) );
		}
		return $this->workspace->commit_replace( (array) $state['workspace'], (array) $state['configuration'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function finalize( array $state ) {
		$relationships = $this->workspace->validate_relationships( $state['workspace'] );
		if ( is_wp_error( $relationships ) ) {
			return $this->abort( $state, $relationships );
		}
		$state['phase']    = 'ready';
		$state['complete'] = true;
		return $state;
	}

	/** @return \WP_Error */
	private function abort( array $state, \WP_Error $error ) {
		if ( ! empty( $state['workspace'] ) && is_array( $state['workspace'] ) ) {
			$abandoned = $this->workspace->abandon( $state['workspace'] );
			if ( is_wp_error( $abandoned ) ) {
				return $abandoned;
			}
		}
		return $error;
	}

	/** @return true|\WP_Error */
	private function validate_counts( array $state ) {
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$expected = absint( $state['manifest']['entities'][ $entity ]['count'] ?? 0 );
			$actual   = absint( $state['counts'][ $entity ] ?? 0 );
			if ( $expected !== $actual ) {
				return new \WP_Error( 'certloom_domain_backup_count_mismatch', __( 'The Certloom domain backup record counts do not match its manifest.', 'certloom' ) );
			}
		}
		return true;
	}


	/** @return array<string,mixed>|null|\WP_Error */
	public function completion_receipt( $owner_token ) {
		return $this->workspace->completion_receipt( $owner_token );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validate_configuration( array $configuration, $expected_hash ) {
		foreach ( DomainBackupRepository::configuration_keys() as $key ) {
			if ( ! isset( $configuration[ $key ] ) || ! is_array( $configuration[ $key ] ) || ! array_key_exists( 'exists', $configuration[ $key ] ) || ! array_key_exists( 'value', $configuration[ $key ] ) ) {
				return new \WP_Error( 'certloom_domain_restore_configuration_invalid', __( 'The Certloom backup is missing required configuration metadata.', 'certloom' ) );
			}
		}
		$json = wp_json_encode( $configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $expected_hash ) ) || ! hash_equals( strtolower( (string) $expected_hash ), hash( 'sha256', $json ) ) ) {
			return new \WP_Error( 'certloom_domain_restore_configuration_hash_invalid', __( 'The Certloom backup configuration metadata failed its integrity consistency check.', 'certloom' ) );
		}
		return $configuration;
	}

	/** @return true|\WP_Error */
	private function validate_state( array $state ) {
		foreach ( array( 'path', 'owner_token', 'workspace', 'manifest', 'configuration', 'offset', 'line_no', 'entity_order', 'records', 'counts', 'hash', 'phase', 'max_records' ) as $key ) {
			if ( ! array_key_exists( $key, $state ) ) {
				return new \WP_Error( 'certloom_domain_restore_state_invalid', __( 'The resumable Certloom restore state is incomplete.', 'certloom' ) );
			}
		}
		if ( ! $this->files->owns( $state['path'] ) || ! is_file( $state['path'] ) ) {
			return new \WP_Error( 'certloom_domain_restore_file_missing', __( 'The private Certloom restore file is no longer available.', 'certloom' ) );
		}
		return true;
	}
}
