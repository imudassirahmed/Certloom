<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complete, versioned Certloom backup and restore workflow.
 *
 * Version 2 uses newline-delimited JSON so backups can be built, validated,
 * reviewed, and restored without loading the complete registry into memory.
 */
class Ecapla_CertVerify_Backup {

	const FORMAT              = 'certverify-backup';
	const FORMAT_VERSION        = 3;
	const LEGACY_STREAM_VERSION = 2;
	const LEGACY_FORMAT_VERSION = 1;
	const SESSION_PREFIX      = 'ecapla_certverify_backup_';
	const SESSION_TTL         = 30 * MINUTE_IN_SECONDS;
	const RECORD_CHUNK_SIZE   = 200;
	const MAX_FILE_BYTES      = 25 * MB_IN_BYTES;
	const MAX_LEGACY_BYTES    = 5 * MB_IN_BYTES;
	const MAX_RECORDS              = 100000;
	const JOURNAL_OPTION            = 'certverify_restore_journal';
	const MAINTENANCE_LOCK_OPTION   = 'certverify_restore_maintenance_lock';
	const GENERATION_OPTION         = 'certverify_data_generation';
	const RESTORE_LOCK_TTL          = 35 * MINUTE_IN_SECONDS;

	/** @var bool Allows the restore commit itself to persist protected configuration. */
	private static $internal_restore_write = false;

	/**
	 * Prevents normal Certloom writes while a complete restore is being built.
	 * Public verification remains available because read paths do not call this.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_writes_allowed() {
		if ( self::$internal_restore_write ) {
			return true;
		}
		if ( class_exists( '\Certloom\Infrastructure\Data\DomainMaintenanceLock' ) ) {
			$domain_allowed = ( new \Certloom\Infrastructure\Data\DomainMaintenanceLock() )->assert_writes_allowed();
			if ( is_wp_error( $domain_allowed ) ) { return $domain_allowed; }
		}
		$lock = self::get_active_maintenance_lock();
		if ( $lock ) {
			return new WP_Error(
				'ecapla_certverify_restore_write_locked',
				__( 'A complete Certloom restore is in progress. Certificate and configuration changes are temporarily paused until it finishes or expires.', 'certloom' )
			);
		}
		return true;
	}

	public static function is_internal_restore_write() {
		return self::$internal_restore_write;
	}

	/** Whether a non-expired complete-restore maintenance lock is active. */
	public static function is_restore_in_progress() {
		return (bool) self::get_active_maintenance_lock();
	}

	public static function current_generation() {
		return (string) get_option( self::GENERATION_OPTION, '0' );
	}

	/** Marks a successful live registry or configuration mutation. */
	public static function mark_mutation() {
		$value = sprintf( '%.6F|%s', microtime( true ), wp_generate_password( 20, false, false ) );
		$saved = update_option( self::GENERATION_OPTION, $value, false );
		return $saved || $value === (string) get_option( self::GENERATION_OPTION, '0' );
	}

	/**
	 * Reserves a fresh legacy source-generation marker before an external mutation.
	 *
	 * A false-positive generation change is safe: it merely makes migration/preflight
	 * evidence stale. The unsafe direction is the opposite—a real certificate or
	 * configuration change succeeding while the migration boundary silently remains
	 * unchanged. Internal complete-restore writes own one generation transition at
	 * their atomic completion boundary and therefore bypass this per-write reservation.
	 *
	 * @return true|WP_Error
	 */
	public static function reserve_mutation_generation() {
		if ( self::is_internal_restore_write() ) {
			return true;
		}
		if ( self::mark_mutation() ) {
			return true;
		}
		return new WP_Error(
			'ecapla_certverify_generation_marker_failed',
			__( 'Certloom could not reserve its registry change marker, so the requested change was not attempted. Retry after WordPress database/options storage is healthy.', 'certloom' )
		);
	}

	/**
	 * Builds the entire backup in a temporary file before sending headers.
	 * Any read, encoding, or write failure aborts without delivering a partial
	 * file that could be mistaken for a complete backup.
	 */
	public static function stream_export() {
		$temp = wp_tempnam( 'certloom-backup-' . gmdate( 'Y-m-d-His' ) . '.cvbackup' );
		if ( ! $temp ) {
			self::download_error( __( 'Certloom could not create a temporary backup file.', 'certloom' ) );
		}

		$handle = fopen( $temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A private temporary file is required for atomic download generation.
		if ( false === $handle ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'Certloom could not open its temporary backup file.', 'certloom' ) );
		}

		$fields         = Ecapla_CertVerify_Fields::get_fields();
		$retired_fields = Ecapla_CertVerify_Fields::get_retired_fields();
		$settings       = Ecapla_CertVerify_Settings::get_all();
		$state_marker   = self::capture_state_marker();
		if ( is_wp_error( $state_marker ) ) {
			self::close_handle( $handle );
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( $state_marker->get_error_message() );
		}

		$manifest = array(
			'type'           => 'manifest',
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => ECAPLA_CERTVERIFY_VERSION,
			'schema_version' => ECAPLA_CERTVERIFY_SCHEMA_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'fields'         => $fields,
			'retired_fields' => $retired_fields,
			'settings'       => $settings,
		);

		$manifest_json = wp_json_encode( $manifest );
		$hash          = hash_init( 'sha256' );
		$manifest_line = is_string( $manifest_json ) ? $manifest_json . "\n" : '';
		if ( ! is_string( $manifest_json ) || ! self::write_all( $handle, $manifest_line ) ) {
			self::close_handle( $handle );
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'Certloom could not encode the backup manifest.', 'certloom' ) );
		}

		hash_update( $hash, $manifest_line );
		$count   = 0;
		$last_id = 0;
		do {
			$records = Ecapla_CertVerify_Database::get_export_batch( $last_id, 500 );
			if ( is_wp_error( $records ) ) {
				self::close_handle( $handle );
				@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
				self::download_error( $records->get_error_message() );
			}
			foreach ( $records as $record ) {
				$last_id = max( $last_id, absint( $record['id'] ) );
				$item    = array(
					'type' => 'record',
					'data' => array(
						'cert_number'   => (string) $record['cert_number'],
						'status'        => Ecapla_CertVerify_Database::workflow_status( $record ),
						'status_source' => Ecapla_CertVerify_Database::effective_status_source( $record ),
						'holder_name'   => (string) $record['holder_name'],
						'issue_date'    => $record['issue_date'],
						'expiry_date'   => $record['expiry_date'],
						'custom_fields' => (array) $record['custom_fields'],
						'created_at'    => (string) $record['created_at'],
						'updated_at'    => (string) $record['updated_at'],
					),
				);
				$json = wp_json_encode( $item );
				if ( ! is_string( $json ) ) {
					self::close_handle( $handle );
					@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
					self::download_error(
						sprintf(
							/* translators: %d: internal certificate record ID. */
							__( 'Certificate ID %d could not be encoded. No backup was downloaded.', 'certloom' ),
							absint( $record['id'] )
						)
					);
				}
				$line = $json . "\n";
				if ( ! self::write_all( $handle, $line ) ) {
					self::close_handle( $handle );
					@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
					self::download_error( __( 'Certloom could not finish writing the backup.', 'certloom' ) );
				}
				hash_update( $hash, $line );
				$count++;
				if ( $count > self::MAX_RECORDS ) {
					self::close_handle( $handle );
					@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
					self::download_error( __( 'The registry exceeds the current complete-backup safety limit.', 'certloom' ) );
				}
			}
		} while ( count( $records ) === 500 );

		$final_marker = self::capture_state_marker();
		if ( is_wp_error( $final_marker ) || ! self::state_markers_match( $state_marker, $final_marker ) ) {
			self::close_handle( $handle );
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'Certificates or Certloom configuration changed while the backup was being generated. No backup was downloaded; try again after current edits finish.', 'certloom' ) );
		}

		$integrity = wp_json_encode(
			array(
				'type'    => 'integrity',
				'records' => $count,
				'sha256'  => hash_final( $hash ),
			)
		);
		if ( ! is_string( $integrity ) || ! self::write_all( $handle, $integrity . "\n" ) ) {
			self::close_handle( $handle );
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'Certloom could not finalize the backup integrity record.', 'certloom' ) );
		}
		if ( ! fflush( $handle ) ) {
			self::close_handle( $handle );
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'Certloom could not flush the completed backup to disk.', 'certloom' ) );
		}
		self::close_handle( $handle );

		$size = filesize( $temp );
		if ( false === $size || $size <= 0 || $size > self::MAX_FILE_BYTES ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'The completed backup exceeds the 25 MB download limit.', 'certloom' ) );
		}
		if ( ! self::preflight_download_file( $temp, $size ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'The completed backup could not be read reliably from temporary storage. No backup was downloaded.', 'certloom' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . absint( $size ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'certloom-backup-' . gmdate( 'Y-m-d-His' ) . '.cvbackup' ) . '"' );
		$download = fopen( $temp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $download ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			self::download_error( __( 'The completed backup could not be opened for download.', 'certloom' ) );
		}
		while ( ! feof( $download ) ) {
			$buffer = fread( $download, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buffer ) {
				break;
			}
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated administrator-requested backup bytes.
		}
		self::close_handle( $download );
		@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Validates an uploaded v2 stream or legacy v1 JSON backup and stores only
	 * bounded record chunks for the administrator's review session.
	 *
	 * @param string $path Uploaded temporary path.
	 * @return string|WP_Error Session token.
	 */
	public static function analyze_file( $path ) {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size <= 0 || $size > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'ecapla_certverify_backup_size', __( 'Choose a Certloom backup no larger than 25 MB.', 'certloom' ) );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'ecapla_certverify_backup_read', __( 'The backup file could not be read.', 'certloom' ) );
		}
		$first = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		self::close_handle( $handle );
		$first_item = is_string( $first ) ? json_decode( trim( $first ), true ) : null;
		$stream_version = is_array( $first_item ) && 'manifest' === ( $first_item['type'] ?? '' ) ? absint( $first_item['format_version'] ?? 0 ) : 0;
		if ( in_array( $stream_version, array( self::LEGACY_STREAM_VERSION, self::FORMAT_VERSION ), true ) ) {
			return self::analyze_stream( $path, self::FORMAT_VERSION === $stream_version );
		}
		if ( $size > self::MAX_LEGACY_BYTES ) {
			return new WP_Error( 'ecapla_certverify_backup_legacy_size', __( 'Legacy JSON backups larger than 5 MB must first be restored with Certloom 1.4.6 and exported again in the current streaming format.', 'certloom' ) );
		}
		return self::analyze_legacy_json( $path );
	}

	public static function get_preview( $token ) {
		$manifest = self::get_manifest( $token );
		return $manifest ? $manifest['preview'] : false;
	}

	/**
	 * Recovers an interrupted complete restore before normal plugin work resumes.
	 * A restore marked committed keeps the new registry and removes its rollback
	 * table; every earlier state restores the original table and options.
	 *
	 * @return true|WP_Error
	 */
	public static function recover_interrupted_restore() {
		$cleanup = self::cleanup_stale_restore_builds();
		if ( is_wp_error( $cleanup ) ) {
			return $cleanup;
		}
		$journal = get_option( self::JOURNAL_OPTION, array() );
		if ( ! is_array( $journal ) || empty( $journal['suffix'] ) ) {
			self::get_active_maintenance_lock();
			return true;
		}
		$suffix   = sanitize_key( (string) $journal['suffix'] );
		$staging  = isset( $journal['staging'] ) ? (string) $journal['staging'] : '';
		$rollback = isset( $journal['rollback'] ) ? (string) $journal['rollback'] : self::rollback_table_name( $suffix );
		$state    = sanitize_key( (string) ( $journal['state'] ?? 'staging' ) );

		if ( 'committed' === $state ) {
			if ( ! self::mark_mutation() ) {
				return new WP_Error( 'ecapla_certverify_restore_generation_marker_failed', __( 'Certloom recovered the committed registry but could not refresh its mutation marker. Writes remain paused until recovery succeeds.', 'certloom' ) );
			}
			if ( $rollback && self::table_exists( $rollback ) && ! Ecapla_CertVerify_Database::drop_restore_table( $rollback ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom could not remove a completed restore rollback table.', 'certloom' ) );
			}
			if ( $staging && self::table_exists( $staging ) && ! Ecapla_CertVerify_Database::drop_restore_table( $staging ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom could not remove a completed restore staging table.', 'certloom' ) );
			}
			if ( ! empty( $journal['lock_hash'] ) && ! self::delete_maintenance_lock_if_owner( sanitize_text_field( (string) $journal['lock_hash'] ) ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom recovered the committed restore but could not release its maintenance lock safely. The recovery journal was preserved for retry.', 'certloom' ) );
			}
			if ( ! empty( $journal['lock_hash'] ) ) {
				$unregistered = self::unregister_restore_build_by_hash( sanitize_text_field( (string) $journal['lock_hash'] ) );
				if ( is_wp_error( $unregistered ) ) {
					return $unregistered;
				}
			}
			if ( ! self::delete_journal() ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom recovered the committed restore but could not clear its recovery journal. The journal was preserved for retry.', 'certloom' ) );
			}
			return true;
		}

		if ( $rollback && self::table_exists( $rollback ) ) {
			if ( ! Ecapla_CertVerify_Database::rollback_restore_table( $rollback, $suffix ) ) {
				return new WP_Error( 'ecapla_certverify_restore_recovery_failed', __( 'Certloom detected an interrupted restore but could not reactivate the original registry.', 'certloom' ) );
			}
		}
		$options_ok = self::restore_option( Ecapla_CertVerify_Fields::OPTION_KEY, $journal['old_fields'] ?? null )
			&& self::restore_option( Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, $journal['old_retired'] ?? null )
			&& self::restore_option( Ecapla_CertVerify_Settings::OPTION_KEY, $journal['old_settings'] ?? null );
		if ( ! $options_ok ) {
			return new WP_Error( 'ecapla_certverify_restore_recovery_failed', __( 'Certloom reactivated the original registry but could not restore all previous configuration.', 'certloom' ) );
		}
		if ( $staging && self::table_exists( $staging ) && ! Ecapla_CertVerify_Database::drop_restore_table( $staging ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom restored the original registry but could not remove the interrupted restore staging table. The recovery journal was preserved for retry.', 'certloom' ) );
		}
		Ecapla_CertVerify_Fields::reset_runtime_cache();
		if ( ! empty( $journal['lock_hash'] ) && ! self::delete_maintenance_lock_if_owner( sanitize_text_field( (string) $journal['lock_hash'] ) ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom restored the original registry but could not release its maintenance lock safely. The recovery journal was preserved for retry.', 'certloom' ) );
		}
		if ( ! empty( $journal['lock_hash'] ) ) {
			$unregistered = self::unregister_restore_build_by_hash( sanitize_text_field( (string) $journal['lock_hash'] ) );
			if ( is_wp_error( $unregistered ) ) {
				return $unregistered;
			}
		}
		if ( ! self::delete_journal() ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom restored the original registry but could not clear its recovery journal. The journal was preserved for retry.', 'certloom' ) );
		}
		return true;
	}

	/**
	 * Starts a resumable restore. Certificate rows are prepared in a private
	 * staging table one bounded chunk per authenticated request. The live table
	 * is not changed until every chunk has been validated and written.
	 *
	 * @param string $token Session token.
	 * @param string $conflict_policy skip|update.
	 * @return array|WP_Error Progress state.
	 */
	public static function restore( $token, $conflict_policy, $restore_mode = 'replace' ) {
		return self::start_restore( $token, $conflict_policy, $restore_mode );
	}

	public static function start_restore( $token, $conflict_policy, $restore_mode = 'replace' ) {
		$manifest = self::get_manifest( $token );
		if ( ! $manifest ) {
			return new WP_Error( 'ecapla_certverify_backup_expired', __( 'That backup review session expired. Upload the backup again.', 'certloom' ) );
		}
		if ( ! empty( $manifest['restore_process'] ) && is_array( $manifest['restore_process'] ) ) {
			$maintenance = self::acquire_restore_maintenance_lock( $token );
			return is_wp_error( $maintenance ) ? $maintenance : self::restore_progress_response( $manifest['restore_process'] );
		}

		$definitions = self::definitions_from_manifest( $manifest );
		if ( is_wp_error( $definitions ) ) {
			return $definitions;
		}
		$restore_mode    = 'merge' === sanitize_key( $restore_mode ) ? 'merge' : 'replace';
		$conflict_policy = 'update' === sanitize_key( $conflict_policy ) ? 'update' : 'skip';
		if ( 'replace' === $restore_mode ) {
			$conflict_policy = 'update';
		}

		$maintenance = self::acquire_restore_maintenance_lock( $token );
		if ( is_wp_error( $maintenance ) ) {
			return $maintenance;
		}

		$before = self::capture_state_marker();
		if ( is_wp_error( $before ) ) {
			return self::return_after_maintenance_release( $token, $before );
		}
		if ( empty( $manifest['preview_marker'] ) || ! self::state_markers_match( $manifest['preview_marker'], $before ) ) {
			return self::return_after_maintenance_release(
				$token,
				new WP_Error( 'ecapla_certverify_restore_preview_stale', __( 'Certloom data changed after this backup preview was prepared. Nothing was changed; upload the backup again to review current replacement and merge counts.', 'certloom' ) )
			);
		}

		$suffix  = substr( hash( 'sha256', $token . '|' . wp_generate_password( 32, false, false ) ), 0, 12 );
		$staging = Ecapla_CertVerify_Database::create_restore_staging_table( $suffix, 'merge' === $restore_mode );
		if ( is_wp_error( $staging ) ) {
			return self::return_after_maintenance_release( $token, $staging );
		}

		$after = self::capture_state_marker();
		if ( is_wp_error( $after ) || ! self::state_markers_match( $before, $after ) ) {
			$operation_error = new WP_Error( 'ecapla_certverify_restore_concurrent_change', __( 'Certloom data changed while the restore workspace was being prepared. Nothing was changed; review the backup and try again.', 'certloom' ) );
			if ( ! Ecapla_CertVerify_Database::drop_restore_table( $staging ) ) {
				return self::cleanup_error_with_operation( $operation_error, 'staging_drop', __( 'Certloom stopped the restore before changing live data, but could not remove its temporary restore workspace. Cleanup must succeed before the restore can be considered fully abandoned.', 'certloom' ) );
			}
			return self::return_after_maintenance_release( $token, $operation_error );
		}

		$preview      = isset( $manifest['preview'] ) && is_array( $manifest['preview'] ) ? $manifest['preview'] : array();
		$current_only = absint( $preview['current_only'] ?? 0 );
		$process      = array(
			'suffix'          => $suffix,
			'staging'         => $staging,
			'restore_mode'    => $restore_mode,
			'conflict_policy' => $conflict_policy,
			'baseline_marker' => $after,
			'cursor'          => 0,
			'total_chunks'    => absint( $manifest['record_chunks'] ),
			'results'         => array(
				'created'  => 0,
				'updated'  => 0,
				'restored' => 0,
				'skipped'  => 0,
				'removed'  => 'replace' === $restore_mode ? $current_only : 0,
				'retained' => 'merge' === $restore_mode ? $current_only : 0,
				'errors'   => array(),
			),
			'started_at'      => time(),
			'updated_at'      => time(),
		);
		$manifest['restore_process'] = $process;
		if ( ! self::save_manifest( $token, $manifest ) || ! self::register_restore_build( $token, $staging ) ) {
			$operation_error = new WP_Error( 'ecapla_certverify_restore_process_storage', __( 'Certloom could not save the resumable restore state. No live data was changed.', 'certloom' ) );
			$cleanup = self::abandon_restore_process( $token, $manifest );
			return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $operation_error, 'abandon', $cleanup->get_error_message(), $cleanup ) : $operation_error;
		}
		return self::restore_progress_response( $process );
	}

	/**
	 * Processes one restore chunk and atomically commits after the last chunk.
	 *
	 * @param string $token Session token.
	 * @return array|WP_Error Progress or completed results.
	 */
	public static function continue_restore( $token ) {
		$manifest = self::get_manifest( $token );
		if ( ! $manifest || empty( $manifest['restore_process'] ) || ! is_array( $manifest['restore_process'] ) ) {
			$released = self::release_restore_maintenance_lock( $token );
			return $released
				? new WP_Error( 'ecapla_certverify_restore_process_expired', __( 'That restore process expired. Upload the backup again.', 'certloom' ) )
				: new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'That restore process expired and Certloom could not release its maintenance lock safely. Retry recovery after the lease expires.', 'certloom' ) );
		}
		$maintenance = self::acquire_restore_maintenance_lock( $token );
		if ( is_wp_error( $maintenance ) ) {
			return $maintenance;
		}

		$lock = self::acquire_restore_lock( $token );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$result   = self::continue_restore_under_lock( $token );
		$released = self::release_restore_lock( $token, $lock );
		if ( is_wp_error( $released ) ) {
			return new WP_Error(
				'ecapla_certverify_restore_request_lock_release_failed',
				__( 'The restore request finished its bounded work, but Certloom could not release the per-request restore lock safely. Wait for the short lease or repair cleanup before continuing.', 'certloom' ),
				array( 'operation_error' => is_wp_error( $result ) ? $result->get_error_code() : '', 'cleanup_error' => $released->get_error_code() )
			);
		}
		return $result;
	}

	/** Processes one restore slice while the caller owns the short per-request restore lock. */
	private static function continue_restore_under_lock( $token ) {
		$manifest = self::get_manifest( $token );
		if ( ! $manifest || empty( $manifest['restore_process'] ) || ! is_array( $manifest['restore_process'] ) ) {
			return self::return_after_maintenance_release( $token, new WP_Error( 'ecapla_certverify_restore_process_expired', __( 'That restore process expired. Upload the backup again.', 'certloom' ) ) );
		}
		$process = $manifest['restore_process'];
		$cursor  = absint( $process['cursor'] ?? 0 );
		$total   = absint( $process['total_chunks'] ?? 0 );
		$staging = (string) ( $process['staging'] ?? '' );
		if ( ! $staging || ! self::table_exists( $staging ) ) {
			$operation_error = new WP_Error( 'ecapla_certverify_restore_stage_missing', __( 'The temporary restore workspace is no longer available. Upload the backup again.', 'certloom' ) );
			$cleanup = self::abandon_restore_process( $token, $manifest );
			return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $operation_error, 'abandon', $cleanup->get_error_message(), $cleanup ) : $operation_error;
		}

		if ( $cursor < $total ) {
			$records = self::load_record_chunk( $token, $cursor );
			if ( is_wp_error( $records ) ) {
				$cleanup = self::abandon_restore_process( $token, $manifest );
				return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $records, 'abandon', $cleanup->get_error_message(), $cleanup ) : $records;
			}
			$definitions = self::definitions_from_manifest( $manifest );
			if ( is_wp_error( $definitions ) ) {
				$cleanup = self::abandon_restore_process( $token, $manifest );
				return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $definitions, 'abandon', $cleanup->get_error_message(), $cleanup ) : $definitions;
			}
			$existing = Ecapla_CertVerify_Database::get_records_by_numbers_from_table( $staging, wp_list_pluck( $records, 'cert_number' ) );
			if ( is_wp_error( $existing ) ) {
				$cleanup = self::abandon_restore_process( $token, $manifest );
				return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $existing, 'abandon', $cleanup->get_error_message(), $cleanup ) : $existing;
			}
			$results = isset( $process['results'] ) && is_array( $process['results'] ) ? $process['results'] : array( 'created' => 0, 'updated' => 0, 'restored' => 0, 'skipped' => 0, 'removed' => 0, 'retained' => 0, 'errors' => array() );
			foreach ( $records as $record_index => $record ) {
				$key         = Ecapla_CertVerify_Database::normalize_certificate_number( $record['cert_number'] );
				$existing_id = isset( $existing[ $key ] ) ? absint( $existing[ $key ]['id'] ) : 0;
				if ( $existing_id && 'skip' === ( $process['conflict_policy'] ?? 'skip' ) ) {
					$results['skipped']++;
					continue;
				}
				$result = Ecapla_CertVerify_Database::restore_record_to_table( $staging, $record, $existing_id, $definitions );
				if ( is_wp_error( $result ) ) {
					$operation_error = new WP_Error(
						'ecapla_certverify_backup_record_restore_failed',
						sprintf(
							/* translators: 1: backup record number, 2: error. */
							__( 'Backup record %1$d could not be prepared: %2$s No live data was changed.', 'certloom' ),
							( $cursor * self::RECORD_CHUNK_SIZE ) + $record_index + 1,
							$result->get_error_message()
						)
					);
					$cleanup = self::abandon_restore_process( $token, $manifest );
					return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $operation_error, 'abandon', $cleanup->get_error_message(), $cleanup ) : $operation_error;
				}
				$existing_id ? $results['updated']++ : $results['created']++;
				if ( 'expired' === sanitize_key( (string) $record['status'] ) ) {
					$results['restored']++;
				}
			}
			$process['cursor']     = $cursor + 1;
			$process['results']    = $results;
			$process['updated_at'] = time();
			$manifest['restore_process'] = $process;
			if ( ! self::save_manifest( $token, $manifest ) || ! self::touch_restore_build( $token ) || ! self::touch_restore_maintenance_lock( $token ) ) {
				$operation_error = new WP_Error( 'ecapla_certverify_restore_process_storage', __( 'Certloom could not save restore progress. No live data was changed.', 'certloom' ) );
				$cleanup = self::abandon_restore_process( $token, $manifest );
				return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $operation_error, 'abandon', $cleanup->get_error_message(), $cleanup ) : $operation_error;
			}
			if ( $process['cursor'] < $total ) {
				return self::restore_progress_response( $process );
			}
		}

		return self::commit_restore( $token, $manifest );
	}

	public static function get_restore_progress( $token ) {
		$manifest = self::get_manifest( $token );
		if ( ! $manifest || empty( $manifest['restore_process'] ) || ! is_array( $manifest['restore_process'] ) ) {
			return false;
		}
		return self::restore_progress_response( $manifest['restore_process'] );
	}

	private static function commit_restore( $token, $manifest ) {
		$process  = $manifest['restore_process'];
		$suffix   = sanitize_key( (string) $process['suffix'] );
		$staging  = (string) $process['staging'];
		$results  = (array) $process['results'];
		$mode     = 'merge' === sanitize_key( (string) ( $process['restore_mode'] ?? 'replace' ) ) ? 'merge' : 'replace';
		$baseline = isset( $process['baseline_marker'] ) && is_array( $process['baseline_marker'] ) ? $process['baseline_marker'] : array();
		$current  = self::capture_state_marker();
		if ( is_wp_error( $current ) || empty( $baseline ) || ! self::state_markers_match( $baseline, $current ) ) {
			$operation_error = new WP_Error( 'ecapla_certverify_restore_concurrent_change', __( 'Certificates or Certloom configuration changed while the restore was being prepared. Nothing was replaced; review the backup and try again.', 'certloom' ) );
			$cleanup = self::abandon_restore_process( $token, $manifest );
			return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $operation_error, 'abandon', $cleanup->get_error_message(), $cleanup ) : $operation_error;
		}

		$old_fields   = get_option( Ecapla_CertVerify_Fields::OPTION_KEY, null );
		$old_retired  = get_option( Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, null );
		$old_settings = get_option( Ecapla_CertVerify_Settings::OPTION_KEY, null );
		$journal      = array(
			'state'        => 'prepared',
			'suffix'       => $suffix,
			'staging'      => $staging,
			'rollback'     => self::rollback_table_name( $suffix ),
			'lock_hash'    => self::token_hash( $token ),
			'restore_mode' => $mode,
			'old_fields'   => $old_fields,
			'old_retired'  => $old_retired,
			'old_settings' => $old_settings,
		);
		if ( ! self::save_journal( $journal ) ) {
			$operation_error = new WP_Error( 'ecapla_certverify_restore_journal_failed', __( 'Certloom could not save the rollback journal, so no live data was changed.', 'certloom' ) );
			$cleanup = self::abandon_restore_process( $token, $manifest );
			return is_wp_error( $cleanup ) ? self::cleanup_error_with_operation( $operation_error, 'abandon', $cleanup->get_error_message(), $cleanup ) : $operation_error;
		}
		$rollback = Ecapla_CertVerify_Database::swap_restore_table( $staging, $suffix );
		if ( is_wp_error( $rollback ) ) {
			if ( self::table_exists( $staging ) && ! Ecapla_CertVerify_Database::drop_restore_table( $staging ) ) {
				return self::cleanup_error_with_operation( $rollback, 'staging_drop', __( 'Certloom could not promote the restore workspace and also could not remove the temporary staging table. Recovery state was preserved for retry.', 'certloom' ) );
			}
			if ( ! self::release_restore_maintenance_lock( $token ) ) {
				return self::cleanup_error_with_operation( $rollback, 'maintenance_release', __( 'Certloom could not promote the restore workspace and could not release its maintenance lock safely. Recovery state was preserved for retry.', 'certloom' ) );
			}
			$unregistered = self::unregister_restore_build( $token );
			if ( is_wp_error( $unregistered ) ) {
				return self::cleanup_error_with_operation( $rollback, 'workspace_tracking', $unregistered->get_error_message(), $unregistered );
			}
			if ( ! self::delete_journal() ) {
				return self::cleanup_error_with_operation( $rollback, 'journal_delete', __( 'Certloom could not promote the restore workspace and could not clear its prepared recovery journal. The journal was preserved for retry.', 'certloom' ) );
			}
			return $rollback;
		}
		$journal['state']    = 'swapped';
		$journal['rollback'] = $rollback;
		if ( ! self::save_journal( $journal ) ) {
			$rolled_back = Ecapla_CertVerify_Database::rollback_restore_table( $rollback, $suffix );
			$operation_error = new WP_Error(
				'ecapla_certverify_restore_journal_failed',
				$rolled_back
					? __( 'Certloom could not update the rollback journal. The original registry was restored.', 'certloom' )
					: __( 'Certloom could not update the rollback journal and automatic rollback was incomplete. Restore the site database backup before continuing.', 'certloom' )
			);
			if ( ! $rolled_back ) {
				return $operation_error;
			}
			return self::finish_rolled_back_restore_cleanup( $token, $operation_error );
		}

		self::$internal_restore_write = true;
		try {
			if ( 'replace' === $mode ) {
				$config_saved = Ecapla_CertVerify_Fields::save_fields( $manifest['fields'] )
					&& Ecapla_CertVerify_Fields::save_retired_fields( $manifest['retired_fields'] )
					&& Ecapla_CertVerify_Settings::save( $manifest['settings'] );
			} else {
				$config_saved = Ecapla_CertVerify_Fields::save_retired_fields( self::merge_backup_only_fields_as_retired( $manifest ) );
			}
		} finally {
			self::$internal_restore_write = false;
		}
		if ( ! $config_saved ) {
			$option_rollback = self::restore_option( Ecapla_CertVerify_Fields::OPTION_KEY, $old_fields )
				&& self::restore_option( Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, $old_retired )
				&& self::restore_option( Ecapla_CertVerify_Settings::OPTION_KEY, $old_settings );
			$table_rollback = Ecapla_CertVerify_Database::rollback_restore_table( $rollback, $suffix );
			Ecapla_CertVerify_Fields::reset_runtime_cache();
			$operation_error = new WP_Error(
				'ecapla_certverify_backup_config_restore_failed',
				$option_rollback && $table_rollback
					? __( 'Backup configuration could not be saved. The original registry and configuration were restored.', 'certloom' )
					: __( 'Backup configuration could not be saved and automatic rollback was incomplete. Restore the site database backup before continuing.', 'certloom' )
			);
			if ( ! $option_rollback || ! $table_rollback ) {
				return $operation_error;
			}
			return self::finish_rolled_back_restore_cleanup( $token, $operation_error );
		}

		Ecapla_CertVerify_Fields::reset_runtime_cache();
		$journal['state'] = 'committed';
		if ( ! self::save_journal( $journal ) ) {
			$option_rollback = self::restore_option( Ecapla_CertVerify_Fields::OPTION_KEY, $old_fields )
				&& self::restore_option( Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, $old_retired )
				&& self::restore_option( Ecapla_CertVerify_Settings::OPTION_KEY, $old_settings );
			$table_rollback = Ecapla_CertVerify_Database::rollback_restore_table( $rollback, $suffix );
			$operation_error = new WP_Error(
				'ecapla_certverify_restore_commit_failed',
				$option_rollback && $table_rollback
					? __( 'Certloom could not commit the restore journal. The original registry and configuration were restored.', 'certloom' )
					: __( 'Certloom could not commit the restore journal and automatic rollback was incomplete. Restore the site database backup before continuing.', 'certloom' )
			);
			if ( ! $option_rollback || ! $table_rollback ) {
				return $operation_error;
			}
			return self::finish_rolled_back_restore_cleanup( $token, $operation_error );
		}
		if ( ! self::mark_mutation() ) {
			return new WP_Error( 'ecapla_certverify_restore_generation_marker_failed', __( 'The registry restore was committed, but Certloom could not refresh its mutation marker. Writes remain paused and the recovery journal was preserved.', 'certloom' ) );
		}
		if ( ! Ecapla_CertVerify_Database::drop_restore_table( $rollback ) ) {
			update_option( 'certverify_restore_cleanup_needed', $rollback, false );
			return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'The registry restore was committed, but Certloom could not remove its rollback table. Writes remain paused and recovery can retry cleanup safely.', 'certloom' ) );
		}
		delete_option( 'certverify_restore_cleanup_needed' );
		$review_count = Ecapla_CertVerify_Database::refresh_legacy_expired_review_count();
		if ( is_wp_error( $review_count ) ) {
			update_option( 'certverify_legacy_review_refresh_needed', 1, false );
		}
		if ( ! self::release_restore_maintenance_lock( $token ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'The registry restore was committed, but Certloom could not release its maintenance lock safely. The recovery journal was preserved and normal writes remain paused until cleanup succeeds.', 'certloom' ) );
		}
		$unregistered = self::unregister_restore_build( $token );
		if ( is_wp_error( $unregistered ) ) {
			return $unregistered;
		}
		if ( ! self::delete_journal() ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'The registry restore was committed and its maintenance lock was released, but Certloom could not clear its recovery journal. The journal was preserved for safe replay.', 'certloom' ) );
		}
		self::delete_session( $token );
		return array( 'complete' => true, 'results' => $results );
	}

	/**
	 * Finishes cleanup after the original live table/configuration was restored.
	 * The prepared recovery journal is deliberately the last thing removed so a
	 * transient lock/workspace-tracking failure remains retryable on next boot.
	 *
	 * @param string   $token Restore token.
	 * @param WP_Error $operation_error Original restore failure after rollback.
	 * @return WP_Error
	 */
	private static function finish_rolled_back_restore_cleanup( $token, $operation_error ) {
		if ( ! self::release_restore_maintenance_lock( $token ) ) {
			return self::cleanup_error_with_operation( $operation_error, 'maintenance_release', __( 'The original registry was restored, but Certloom could not release its maintenance lock safely. Recovery state was preserved for retry.', 'certloom' ) );
		}
		$unregistered = self::unregister_restore_build( $token );
		if ( is_wp_error( $unregistered ) ) {
			return self::cleanup_error_with_operation( $operation_error, 'workspace_tracking', $unregistered->get_error_message(), $unregistered );
		}
		if ( ! self::delete_journal() ) {
			return self::cleanup_error_with_operation( $operation_error, 'journal_delete', __( 'The original registry was restored and maintenance released, but Certloom could not clear its recovery journal. The journal was preserved for retry.', 'certloom' ) );
		}
		return $operation_error;
	}

	private static function restore_progress_response( $process ) {
		$total     = max( 1, absint( $process['total_chunks'] ?? 0 ) );
		$processed = min( $total, absint( $process['cursor'] ?? 0 ) );
		return array(
			'complete'         => false,
			'processed_chunks' => $processed,
			'total_chunks'     => absint( $process['total_chunks'] ?? 0 ),
			'percent'          => absint( floor( ( $processed / $total ) * 100 ) ),
			'restore_mode'     => 'merge' === ( $process['restore_mode'] ?? '' ) ? 'merge' : 'replace',
			'results'          => (array) ( $process['results'] ?? array() ),
		);
	}

	private static function save_manifest( $token, $manifest ) {
		$saved = set_transient( sanitize_text_field( $token ), $manifest, self::SESSION_TTL );
		return $saved || $manifest === get_transient( sanitize_text_field( $token ) );
	}

	/**
	 * Abandons an uncommitted restore only after every cleanup step is proven.
	 *
	 * Cleanup uncertainty deliberately preserves the manifest/build ownership so
	 * the same restore session can retry instead of reporting a clean abandon
	 * while a staging table or maintenance lock may still exist.
	 *
	 * @return true|WP_Error
	 */
	private static function abandon_restore_process( $token, $manifest ) {
		$process = isset( $manifest['restore_process'] ) && is_array( $manifest['restore_process'] ) ? $manifest['restore_process'] : array();
		if ( ! empty( $process['staging'] ) && self::table_exists( (string) $process['staging'] ) && ! Ecapla_CertVerify_Database::drop_restore_table( (string) $process['staging'] ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom stopped the restore before changing live data, but could not remove its temporary restore workspace. Cleanup state was preserved for retry.', 'certloom' ) );
		}
		if ( ! self::release_restore_maintenance_lock( $token ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom stopped the restore before changing live data, but could not release its maintenance lock safely. Cleanup state was preserved for retry.', 'certloom' ) );
		}
		unset( $manifest['restore_process'] );
		if ( ! self::save_manifest( $token, $manifest ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom released the restore workspace and maintenance lock, but could not clear the resumable restore state. Upload/review the backup again before retrying.', 'certloom' ) );
		}
		$unregistered = self::unregister_restore_build( $token );
		if ( is_wp_error( $unregistered ) ) {
			return $unregistered;
		}
		return true;
	}

	/**
	 * Returns the original safe-stop error only when maintenance cleanup is
	 * confirmed. Otherwise cleanup uncertainty takes precedence and the original
	 * operation code is retained as diagnostic data.
	 *
	 * @param string   $token Restore session token.
	 * @param WP_Error $operation_error Original operation failure.
	 * @return WP_Error
	 */
	private static function return_after_maintenance_release( $token, $operation_error ) {
		if ( self::release_restore_maintenance_lock( $token ) ) {
			return $operation_error;
		}
		return self::cleanup_error_with_operation(
			$operation_error,
			'maintenance_release',
			__( 'Certloom stopped the restore before changing live data, but could not release its maintenance lock safely. Normal writes remain paused until cleanup succeeds.', 'certloom' )
		);
	}

	/** Builds a cleanup-first error while preserving the operation that triggered it. */
	private static function cleanup_error_with_operation( $operation_error, $cleanup_step, $message, $cleanup_error = null ) {
		$data = array(
			'operation_error' => is_wp_error( $operation_error ) ? $operation_error->get_error_code() : '',
			'cleanup_step'    => sanitize_key( (string) $cleanup_step ),
		);
		if ( is_wp_error( $cleanup_error ) ) {
			$data['cleanup_error'] = $cleanup_error->get_error_code();
		}
		return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', $message, $data );
	}

	private static function token_hash( $token ) {
		return substr( hash( 'sha256', sanitize_text_field( $token ) ), 0, 40 );
	}

	private static function get_active_maintenance_lock() {
		$missing = new stdClass();
		$lock    = get_option( self::MAINTENANCE_LOCK_OPTION, $missing );
		if ( $missing === $lock || false === $lock || '' === $lock || array() === $lock ) {
			return false;
		}
		if ( ! is_array( $lock ) || empty( $lock['token_hash'] ) || empty( $lock['expires_at'] ) ) {
			// A non-empty/corrupt maintenance option must fail closed for normal
			// writes. acquire_restore_maintenance_lock() can repair it later with
			// an atomic compare-and-swap owned by a real maintenance request.
			return array( 'corrupt' => true );
		}
		if ( absint( $lock['expires_at'] ) > time() ) {
			return $lock;
		}
		if ( ! self::delete_maintenance_lock_if_owner( sanitize_text_field( (string) $lock['token_hash'] ) ) ) {
			return array( 'corrupt' => true );
		}
		return false;
	}

	private static function acquire_restore_maintenance_lock( $token ) {
		global $wpdb;
		$token_hash = self::token_hash( $token );
		$now        = time();
		$payload    = array(
			'token_hash'  => $token_hash,
			'user_id'     => get_current_user_id(),
			'acquired_at' => $now,
			'updated_at'  => $now,
			'expires_at'  => $now + self::RESTORE_LOCK_TTL,
		);
		if ( add_option( self::MAINTENANCE_LOCK_OPTION, $payload, '', false ) ) {
			return true;
		}
		$current = get_option( self::MAINTENANCE_LOCK_OPTION, array() );
		if ( is_array( $current ) && $token_hash === (string) ( $current['token_hash'] ?? '' ) ) {
			$payload['acquired_at'] = absint( $current['acquired_at'] ?? $now );
			$saved = update_option( self::MAINTENANCE_LOCK_OPTION, $payload, false );
			return $saved || $payload === get_option( self::MAINTENANCE_LOCK_OPTION, array() )
				? true
				: new WP_Error( 'ecapla_certverify_restore_lock_storage', __( 'Certloom could not extend the restore safety lock. No live data was changed.', 'certloom' ) );
		}
		if ( is_array( $current ) && absint( $current['expires_at'] ?? 0 ) > $now ) {
			return new WP_Error( 'ecapla_certverify_restore_maintenance_locked', __( 'Another complete Certloom restore is already in progress.', 'certloom' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic stale-lock takeover prevents two complete restores from becoming active together.
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $payload ),
				self::MAINTENANCE_LOCK_OPTION,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( self::MAINTENANCE_LOCK_OPTION, 'options' );
		return 1 === (int) $changed
			? true
			: new WP_Error( 'ecapla_certverify_restore_maintenance_locked', __( 'Another complete Certloom restore is already in progress.', 'certloom' ) );
	}

	private static function touch_restore_maintenance_lock( $token ) {
		$result = self::acquire_restore_maintenance_lock( $token );
		return true === $result;
	}

	private static function release_restore_maintenance_lock( $token ) {
		return self::delete_maintenance_lock_if_owner( self::token_hash( $token ) );
	}

	private static function delete_maintenance_lock_if_owner( $token_hash ) {
		global $wpdb;
		$current = get_option( self::MAINTENANCE_LOCK_OPTION, array() );
		if ( ! is_array( $current ) || $token_hash !== (string) ( $current['token_hash'] ?? '' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owner-matched deletion prevents one restore from releasing another restore's maintenance lock.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::MAINTENANCE_LOCK_OPTION,
				maybe_serialize( $current )
			)
		);
		wp_cache_delete( self::MAINTENANCE_LOCK_OPTION, 'options' );
		return 1 === (int) $deleted || false === get_option( self::MAINTENANCE_LOCK_OPTION, false );
	}

	private static function capture_state_marker() {
		$registry = Ecapla_CertVerify_Database::get_registry_snapshot();
		if ( is_wp_error( $registry ) ) {
			return $registry;
		}
		$config = array(
			'fields'         => get_option( Ecapla_CertVerify_Fields::OPTION_KEY, null ),
			'retired_fields' => get_option( Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, null ),
			'settings'       => get_option( Ecapla_CertVerify_Settings::OPTION_KEY, null ),
		);
		return array(
			'generation'  => self::current_generation(),
			'registry'    => $registry,
			'config_hash' => hash( 'sha256', maybe_serialize( $config ) ),
		);
	}

	private static function state_markers_match( $left, $right ) {
		$left_json  = wp_json_encode( $left );
		$right_json = wp_json_encode( $right );
		return is_string( $left_json ) && is_string( $right_json ) && hash_equals( hash( 'sha256', $left_json ), hash( 'sha256', $right_json ) );
	}

	private static function merge_backup_only_fields_as_retired( $manifest ) {
		$retired = Ecapla_CertVerify_Fields::get_retired_fields();
		$known   = array_fill_keys( array_merge( array_keys( Ecapla_CertVerify_Fields::get_field_map() ), array_keys( $retired ) ), true );
		foreach ( array_merge( (array) ( $manifest['fields'] ?? array() ), array_values( (array) ( $manifest['retired_fields'] ?? array() ) ) ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			$key = sanitize_key( $field['key'] );
			if ( '' === $key || isset( $known[ $key ] ) || ! empty( $field['column'] ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			$retired[ $key ] = array(
				'key'        => $key,
				'label'      => sanitize_text_field( (string) ( $field['label'] ?? $key ) ),
				'type'       => in_array( $type, array( 'text', 'textarea', 'date', 'number' ), true ) ? $type : 'text',
				'retired_at' => current_time( 'mysql' ),
			);
			$known[ $key ] = true;
		}
		return $retired;
	}

	private static function restore_lock_name( $token ) {
		return 'certverify_restore_lock_' . substr( hash( 'sha256', sanitize_text_field( $token ) ), 0, 32 );
	}

	private static function acquire_restore_lock( $token ) {
		global $wpdb;
		$name    = self::restore_lock_name( $token );
		$payload = wp_generate_password( 32, false, false ) . '|' . time();
		if ( add_option( $name, $payload, '', false ) ) {
			return $payload;
		}
		$current = get_option( $name, '' );
		$parts   = explode( '|', (string) $current );
		$created = isset( $parts[1] ) ? absint( $parts[1] ) : time();
		if ( time() - $created <= 120 ) {
			return new WP_Error( 'ecapla_certverify_restore_locked', __( 'Another restore request is already running. Wait a moment and continue.', 'certloom' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is required for safe stale restore-lock takeover.
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$payload,
				$name,
				$current
			)
		);
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $changed ? $payload : new WP_Error( 'ecapla_certverify_restore_locked', __( 'Another restore request is already running. Wait a moment and continue.', 'certloom' ) );
	}

	private static function release_restore_lock( $token, $payload ) {
		global $wpdb;
		$name    = self::restore_lock_name( $token );
		$current = (string) get_option( $name, '' );
		if ( '' === $current ) {
			return true;
		}
		if ( ! hash_equals( $current, (string) $payload ) ) {
			return new WP_Error( 'ecapla_certverify_restore_lock_owner_changed', __( 'Certloom refused to release a restore request lock that is no longer owned by this request.', 'certloom' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owner-matched deletion prevents one request from releasing another request's restore lock.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$name,
				$current
			)
		);
		wp_cache_delete( $name, 'options' );
		if ( 1 === (int) $deleted || '' === (string) get_option( $name, '' ) ) {
			return true;
		}
		return new WP_Error( 'ecapla_certverify_restore_lock_release_failed', __( 'Certloom could not release the restore request lock safely. Wait for the short lease before continuing.', 'certloom' ) );
	}


	private static function cleanup_stale_restore_builds() {
		$builds  = (array) get_option( 'certverify_restore_builds', array() );
		$changed = false;
		foreach ( $builds as $key => $build ) {
			$updated    = absint( $build['updated_at'] ?? 0 );
			$table      = isset( $build['table'] ) ? (string) $build['table'] : '';
			$token_hash = sanitize_text_field( (string) ( $build['token_hash'] ?? '' ) );
			if ( $updated && time() - $updated <= HOUR_IN_SECONDS ) {
				continue;
			}
			if ( $table && self::table_exists( $table ) && ! Ecapla_CertVerify_Database::drop_restore_table( $table ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom could not remove an expired restore workspace.', 'certloom' ) );
			}
			if ( $token_hash && ! self::delete_maintenance_lock_if_owner( $token_hash ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_failed', __( 'Certloom removed an expired restore workspace but could not release its maintenance lock safely. Cleanup state was preserved for retry.', 'certloom' ) );
			}
			unset( $builds[ $key ] );
			$changed = true;
		}
		if ( $changed ) {
			if ( $builds ) {
				$saved = update_option( 'certverify_restore_builds', $builds, false );
				if ( ! $saved && $builds !== get_option( 'certverify_restore_builds', array() ) ) {
					return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom removed expired restore workspaces but could not save the cleanup state.', 'certloom' ) );
				}
			} elseif ( ! delete_option( 'certverify_restore_builds' ) && false !== get_option( 'certverify_restore_builds', false ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom removed expired restore workspaces but could not clear the cleanup state.', 'certloom' ) );
			}
		}
		return true;
	}

	private static function register_restore_build( $token, $table ) {
		$builds = (array) get_option( 'certverify_restore_builds', array() );
		$key = substr( hash( 'sha256', sanitize_text_field( $token ) ), 0, 32 );
		$builds[ $key ] = array( 'table' => (string) $table, 'token_hash' => self::token_hash( $token ), 'updated_at' => time() );
		$saved = update_option( 'certverify_restore_builds', $builds, false );
		return $saved || $builds === get_option( 'certverify_restore_builds', array() );
	}

	private static function touch_restore_build( $token ) {
		$builds = (array) get_option( 'certverify_restore_builds', array() );
		$key = substr( hash( 'sha256', sanitize_text_field( $token ) ), 0, 32 );
		if ( empty( $builds[ $key ] ) ) {
			return false;
		}
		$builds[ $key ]['updated_at'] = time();
		$saved = update_option( 'certverify_restore_builds', $builds, false );
		return $saved || $builds === get_option( 'certverify_restore_builds', array() );
	}

	private static function unregister_restore_build( $token ) {
		return self::unregister_restore_build_by_hash( self::token_hash( $token ) );
	}

	private static function unregister_restore_build_by_hash( $token_hash ) {
		$token_hash = sanitize_text_field( (string) $token_hash );
		$builds     = (array) get_option( 'certverify_restore_builds', array() );
		$key        = substr( $token_hash, 0, 32 );
		if ( ! isset( $builds[ $key ] ) ) {
			return true;
		}
		$stored_hash = sanitize_text_field( (string) ( $builds[ $key ]['token_hash'] ?? '' ) );
		if ( '' !== $stored_hash && ! hash_equals( $stored_hash, $token_hash ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom refused to clear restore-workspace tracking that no longer belongs to this restore.', 'certloom' ) );
		}
		unset( $builds[ $key ] );
		if ( $builds ) {
			$saved = update_option( 'certverify_restore_builds', $builds, false );
			if ( ! $saved && $builds !== get_option( 'certverify_restore_builds', array() ) ) {
				return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom completed restore cleanup but could not update its restore-workspace tracking state.', 'certloom' ) );
			}
		} elseif ( ! delete_option( 'certverify_restore_builds' ) && false !== get_option( 'certverify_restore_builds', false ) ) {
			return new WP_Error( 'ecapla_certverify_restore_cleanup_state_failed', __( 'Certloom completed restore cleanup but could not clear its restore-workspace tracking state.', 'certloom' ) );
		}
		return true;
	}

	/** Analyze the current streaming backup format. */
	private static function analyze_stream( $path, $hash_manifest = true ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'ecapla_certverify_backup_read', __( 'The backup file could not be read.', 'certloom' ) );
		}
		$token       = self::SESSION_PREFIX . wp_generate_password( 24, false, false );
		$line_number = 0;
		$manifest    = null;
		$definitions = array();
		$seen        = array();
		$records     = array();
		$chunk_count = 0;
		$conflicts   = 0;
		$count       = 0;
		$hash          = hash_init( 'sha256' );
		$integrity     = null;
		$review_marker = self::capture_state_marker();
		if ( is_wp_error( $review_marker ) ) {
			self::close_handle( $handle );
			return $review_marker;
		}

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line_number++;
			if ( '' === trim( $line ) ) {
				continue;
			}
			$item = json_decode( trim( $line ), true );
			if ( ! is_array( $item ) ) {
				self::close_handle( $handle );
				self::delete_chunks( $token, $chunk_count );
				return new WP_Error(
					'ecapla_certverify_backup_json',
					sprintf(
						/* translators: %d: line number in the uploaded backup file. */
						__( 'The backup contains invalid JSON on line %d.', 'certloom' ),
						$line_number
					)
				);
			}
			$type = sanitize_key( (string) ( $item['type'] ?? '' ) );
			if ( null === $manifest ) {
				if ( 'manifest' !== $type ) {
					self::close_handle( $handle );
					return new WP_Error( 'ecapla_certverify_backup_manifest', __( 'The backup manifest is missing.', 'certloom' ) );
				}
				$valid = self::validate_manifest( $item );
				if ( is_wp_error( $valid ) ) {
					self::close_handle( $handle );
					return $valid;
				}
				$manifest    = $item;
				$definitions = self::definitions_from_manifest( $manifest );
				if ( is_wp_error( $definitions ) ) {
					self::close_handle( $handle );
					return $definitions;
				}
				if ( $hash_manifest ) {
					hash_update( $hash, rtrim( $line, "\r\n" ) . "\n" );
				}
				continue;
			}
			if ( 'record' === $type ) {
				if ( $integrity ) {
					self::close_handle( $handle );
					self::delete_chunks( $token, $chunk_count );
					return new WP_Error( 'ecapla_certverify_backup_order', __( 'The backup contains data after its integrity record.', 'certloom' ) );
				}
				$record = $item['data'] ?? null;
				$valid  = self::validate_record( $record, $definitions, $seen );
				if ( is_wp_error( $valid ) ) {
					self::close_handle( $handle );
					self::delete_chunks( $token, $chunk_count );
					return $valid;
				}
				$records[] = $record;
				$count++;
				hash_update( $hash, rtrim( $line, "\r\n" ) . "\n" );
				if ( $count > self::MAX_RECORDS ) {
					self::close_handle( $handle );
					self::delete_chunks( $token, $chunk_count );
					return new WP_Error( 'ecapla_certverify_backup_limits', __( 'The backup exceeds Certloom safety limits.', 'certloom' ) );
				}
				if ( count( $records ) >= self::RECORD_CHUNK_SIZE ) {
					$chunk_conflicts = self::count_conflicts( array( $records ) );
					if ( is_wp_error( $chunk_conflicts ) || ! self::store_record_chunk( $token, $chunk_count, $records ) ) {
						self::close_handle( $handle );
						self::delete_chunks( $token, $chunk_count + 1 );
						return is_wp_error( $chunk_conflicts ) ? $chunk_conflicts : new WP_Error( 'ecapla_certverify_backup_storage', __( 'The backup was valid, but its temporary review session could not be stored.', 'certloom' ) );
					}
					$conflicts += $chunk_conflicts;
					$chunk_count++;
					$records = array();
				}
				continue;
			}
			if ( 'integrity' === $type ) {
				$integrity = $item;
				continue;
			}
			self::close_handle( $handle );
			self::delete_chunks( $token, $chunk_count );
			return new WP_Error(
				'ecapla_certverify_backup_line_type',
				sprintf(
					/* translators: %d: line number in the uploaded backup file. */
					__( 'The backup contains an unsupported line type on line %d.', 'certloom' ),
					$line_number
				)
			);
		}
		self::close_handle( $handle );
		if ( $records ) {
			$chunk_conflicts = self::count_conflicts( array( $records ) );
			if ( is_wp_error( $chunk_conflicts ) || ! self::store_record_chunk( $token, $chunk_count, $records ) ) {
				self::delete_chunks( $token, $chunk_count + 1 );
				return is_wp_error( $chunk_conflicts ) ? $chunk_conflicts : new WP_Error( 'ecapla_certverify_backup_storage', __( 'The backup was valid, but its temporary review session could not be stored.', 'certloom' ) );
			}
			$conflicts += $chunk_conflicts;
			$chunk_count++;
		}
		if ( ! $manifest || ! is_array( $integrity ) || $count !== absint( $integrity['records'] ?? -1 ) || ! hash_equals( (string) ( $integrity['sha256'] ?? '' ), hash_final( $hash ) ) ) {
			self::delete_chunks( $token, $chunk_count );
			return new WP_Error( 'ecapla_certverify_backup_integrity', __( 'The backup is incomplete or its integrity check failed.', 'certloom' ) );
		}
		$preview = self::preview_from_manifest( $manifest, $count, $conflicts );
		if ( is_wp_error( $preview ) ) {
			self::delete_chunks( $token, $chunk_count );
			return $preview;
		}
		$review_complete = self::capture_state_marker();
		if ( is_wp_error( $review_complete ) || ! self::state_markers_match( $review_marker, $review_complete ) ) {
			self::delete_chunks( $token, $chunk_count );
			return new WP_Error( 'ecapla_certverify_backup_preview_changed', __( 'Certloom data changed while the backup preview was being prepared. Upload the backup again after current edits finish.', 'certloom' ) );
		}
		$session = array(
			'user_id'        => get_current_user_id(),
			'record_chunks'  => $chunk_count,
			'preview'        => $preview,
			'preview_marker' => $review_complete,
			'fields'         => array_values( (array) $manifest['fields'] ),
			'retired_fields' => (array) $manifest['retired_fields'],
			'settings'       => (array) $manifest['settings'],
		);
		if ( ! set_transient( $token, $session, self::SESSION_TTL ) ) {
			self::delete_chunks( $token, $chunk_count );
			return new WP_Error( 'ecapla_certverify_backup_storage', __( 'The backup was valid, but its temporary review session could not be stored.', 'certloom' ) );
		}
		return $token;
	}

	/** Supports bounded legacy v1 JSON backups for backward compatibility. */
	private static function analyze_legacy_json( $path ) {
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return new WP_Error( 'ecapla_certverify_backup_read', __( 'The backup file could not be read.', 'certloom' ) );
		}
		$payload = json_decode( $content, true );
		if ( ! is_array( $payload ) || self::FORMAT !== ( $payload['format'] ?? '' ) || self::LEGACY_FORMAT_VERSION !== absint( $payload['format_version'] ?? 0 ) ) {
			return new WP_Error( 'ecapla_certverify_backup_format', __( 'This is not a supported Certloom backup file.', 'certloom' ) );
		}
		$manifest = array(
			'type'           => 'manifest',
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => $payload['plugin_version'] ?? '',
			'schema_version' => $payload['schema_version'] ?? '',
			'generated_at'   => $payload['generated_at'] ?? '',
			'fields'         => $payload['fields'] ?? array(),
			'retired_fields' => $payload['retired_fields'] ?? array(),
			'settings'       => $payload['settings'] ?? array(),
		);
		$valid = self::validate_manifest( $manifest );
		if ( is_wp_error( $valid ) || ! isset( $payload['records'] ) || ! is_array( $payload['records'] ) ) {
			return is_wp_error( $valid ) ? $valid : new WP_Error( 'ecapla_certverify_backup_structure', __( 'The legacy backup is incomplete or damaged.', 'certloom' ) );
		}
		$definitions = self::definitions_from_manifest( $manifest );
		$seen        = array();
		$chunks      = array();
		$chunk       = array();
		foreach ( $payload['records'] as $record ) {
			$valid = self::validate_record( $record, $definitions, $seen );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$chunk[] = $record;
			if ( count( $chunk ) >= self::RECORD_CHUNK_SIZE ) {
				$chunks[] = $chunk;
				$chunk    = array();
			}
		}
		if ( $chunk ) {
			$chunks[] = $chunk;
		}
		$count         = count( $payload['records'] );
		$review_marker = self::capture_state_marker();
		if ( is_wp_error( $review_marker ) ) {
			return $review_marker;
		}
		if ( $count > self::MAX_RECORDS ) {
			return new WP_Error( 'ecapla_certverify_backup_limits', __( 'The backup exceeds Certloom safety limits.', 'certloom' ) );
		}
		$conflicts = self::count_conflicts( $chunks );
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		$preview = self::preview_from_manifest( $manifest, $count, $conflicts );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$review_complete = self::capture_state_marker();
		if ( is_wp_error( $review_complete ) || ! self::state_markers_match( $review_marker, $review_complete ) ) {
			return new WP_Error( 'ecapla_certverify_backup_preview_changed', __( 'Certloom data changed while the backup preview was being prepared. Upload the backup again after current edits finish.', 'certloom' ) );
		}
		return self::store_session( $manifest, $chunks, $preview, $review_complete );
	}

	private static function validate_manifest( $manifest ) {
		$format_version = absint( $manifest['format_version'] ?? 0 );
		if ( ! is_array( $manifest ) || self::FORMAT !== ( $manifest['format'] ?? '' ) || ! in_array( $format_version, array( self::LEGACY_STREAM_VERSION, self::FORMAT_VERSION ), true ) ) {
			return new WP_Error( 'ecapla_certverify_backup_format', __( 'This is not a supported Certloom backup file.', 'certloom' ) );
		}
		foreach ( array( 'generated_at', 'plugin_version', 'schema_version' ) as $key ) {
			if ( ! isset( $manifest[ $key ] ) || is_array( $manifest[ $key ] ) || is_object( $manifest[ $key ] ) ) {
				return new WP_Error( 'ecapla_certverify_backup_structure', __( 'The backup manifest is incomplete or damaged.', 'certloom' ) );
			}
		}
		foreach ( array( 'fields', 'retired_fields', 'settings' ) as $key ) {
			if ( ! isset( $manifest[ $key ] ) || ! is_array( $manifest[ $key ] ) ) {
				return new WP_Error( 'ecapla_certverify_backup_structure', __( 'The backup manifest is incomplete or damaged.', 'certloom' ) );
			}
		}
		if ( count( $manifest['fields'] ) > 500 || count( $manifest['retired_fields'] ) > 500 ) {
			return new WP_Error( 'ecapla_certverify_backup_limits', __( 'The backup exceeds Certloom field-definition limits.', 'certloom' ) );
		}
		$definitions = self::definitions_from_manifest( $manifest );
		if ( is_wp_error( $definitions ) ) {
			return $definitions;
		}
		return true;
	}

	private static function definitions_from_manifest( $manifest ) {
		$definitions = array();
		foreach ( array_merge( array_values( (array) $manifest['fields'] ), array_values( (array) $manifest['retired_fields'] ) ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				return new WP_Error( 'ecapla_certverify_backup_field', __( 'The backup contains an invalid field definition.', 'certloom' ) );
			}
			$key = sanitize_key( $field['key'] );
			if ( '' === $key || isset( $definitions[ $key ] ) ) {
				return new WP_Error( 'ecapla_certverify_backup_field', __( 'The backup contains duplicate or invalid field keys.', 'certloom' ) );
			}
			$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
			if ( ! in_array( $type, array( 'text', 'textarea', 'date', 'number', 'status' ), true ) ) {
				return new WP_Error( 'ecapla_certverify_backup_field', __( 'The backup contains an unsupported field type.', 'certloom' ) );
			}
			$definitions[ $key ] = array(
				'key'    => $key,
				'label'  => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $key,
				'type'   => $type,
				'column' => isset( $field['column'] ) ? sanitize_key( (string) $field['column'] ) : '',
			);
		}
		return $definitions;
	}

	private static function validate_record( $record, $definitions, &$seen ) {
		if ( ! is_array( $record ) || empty( $record['cert_number'] ) ) {
			return new WP_Error( 'ecapla_certverify_backup_record', __( 'The backup contains an invalid certificate record.', 'certloom' ) );
		}
		$core = $record;
		unset( $core['custom_fields'], $core['id'], $core['cert_number_hash'], $core['status_source'], $core['created_at'], $core['updated_at'] );
		$clean = Ecapla_CertVerify_Validator::certificate_data( $core, false );
		if ( is_wp_error( $clean ) ) {
			return new WP_Error( 'ecapla_certverify_backup_record', $clean->get_error_message() );
		}
		$hash = Ecapla_CertVerify_Database::certificate_hash( $clean['cert_number'] );
		if ( isset( $seen[ $hash ] ) ) {
			return new WP_Error( 'ecapla_certverify_backup_duplicate', __( 'The backup contains duplicate certificate numbers. Letter case is ignored.', 'certloom' ) );
		}
		$seen[ $hash ] = true;
		foreach ( (array) ( $record['custom_fields'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! isset( $definitions[ $key ] ) || ! empty( $definitions[ $key ]['column'] ) ) {
				return new WP_Error( 'ecapla_certverify_backup_custom_field', __( 'The backup contains a certificate value without a matching custom-field definition.', 'certloom' ) );
			}
			$value = Ecapla_CertVerify_Validator::custom_field_value( $value, $definitions[ $key ] );
			if ( is_wp_error( $value ) ) {
				return new WP_Error( 'ecapla_certverify_backup_custom_field', $value->get_error_message() );
			}
		}
		return true;
	}

	private static function count_conflicts( $chunks ) {
		$conflicts = 0;
		foreach ( $chunks as $records ) {
			$existing = Ecapla_CertVerify_Database::get_existing_number_map( wp_list_pluck( $records, 'cert_number' ) );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
			foreach ( $records as $record ) {
				$conflicts += isset( $existing[ Ecapla_CertVerify_Database::certificate_hash( $record['cert_number'] ) ] ) ? 1 : 0;
			}
		}
		return $conflicts;
	}

	private static function preview_from_manifest( $manifest, $records, $conflicts ) {
		$current = Ecapla_CertVerify_Database::get_registry_count();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$current_keys = array_fill_keys( array_merge( array_keys( Ecapla_CertVerify_Fields::get_field_map() ), array_keys( Ecapla_CertVerify_Fields::get_retired_fields() ) ), true );
		$backup_only  = 0;
		foreach ( array_merge( (array) $manifest['fields'], array_values( (array) $manifest['retired_fields'] ) ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || ! empty( $field['column'] ) ) {
				continue;
			}
			$key = sanitize_key( $field['key'] );
			if ( $key && ! isset( $current_keys[ $key ] ) ) {
				$current_keys[ $key ] = true;
				$backup_only++;
			}
		}
		return array(
			'records'           => absint( $records ),
			'current_records'   => absint( $current ),
			'conflicts'         => absint( $conflicts ),
			'new_records'       => max( 0, absint( $records ) - absint( $conflicts ) ),
			'current_only'      => max( 0, absint( $current ) - absint( $conflicts ) ),
			'fields'            => count( (array) $manifest['fields'] ),
			'retired_fields'    => count( (array) $manifest['retired_fields'] ),
			'backup_only_fields'=> absint( $backup_only ),
			'generated_at'      => sanitize_text_field( (string) $manifest['generated_at'] ),
			'plugin_version'    => sanitize_text_field( (string) $manifest['plugin_version'] ),
		);
	}


	private static function store_record_chunk( $token, $index, $records ) {
		return set_transient(
			$token . '_records_' . absint( $index ),
			array( 'user_id' => get_current_user_id(), 'records' => array_values( (array) $records ) ),
			self::SESSION_TTL
		);
	}

	private static function store_session( $source_manifest, $chunks, $preview, $preview_marker ) {
		$token = self::SESSION_PREFIX . wp_generate_password( 24, false, false );
		foreach ( $chunks as $index => $records ) {
			if ( ! self::store_record_chunk( $token, $index, $records ) ) {
				self::delete_chunks( $token, count( $chunks ) );
				return new WP_Error( 'ecapla_certverify_backup_storage', __( 'The backup was valid, but its temporary review session could not be stored.', 'certloom' ) );
			}
		}
		$manifest = array(
			'user_id'        => get_current_user_id(),
			'record_chunks'  => count( $chunks ),
			'preview'        => $preview,
			'preview_marker' => $preview_marker,
			'fields'         => array_values( (array) $source_manifest['fields'] ),
			'retired_fields' => (array) $source_manifest['retired_fields'],
			'settings'       => (array) $source_manifest['settings'],
		);
		if ( ! set_transient( $token, $manifest, self::SESSION_TTL ) ) {
			self::delete_chunks( $token, count( $chunks ) );
			return new WP_Error( 'ecapla_certverify_backup_storage', __( 'The backup was valid, but its temporary review session could not be stored.', 'certloom' ) );
		}
		return $token;
	}

	private static function get_manifest( $token ) {
		$token = sanitize_text_field( $token );
		if ( 0 !== strpos( $token, self::SESSION_PREFIX ) ) {
			return false;
		}
		$manifest = get_transient( $token );
		return is_array( $manifest ) && (int) ( $manifest['user_id'] ?? 0 ) === get_current_user_id() ? $manifest : false;
	}

	private static function load_record_chunk( $token, $index ) {
		$chunk = get_transient( sanitize_text_field( $token ) . '_records_' . absint( $index ) );
		if ( ! is_array( $chunk ) || (int) ( $chunk['user_id'] ?? 0 ) !== get_current_user_id() || ! isset( $chunk['records'] ) || ! is_array( $chunk['records'] ) ) {
			return new WP_Error( 'ecapla_certverify_backup_expired', __( 'Part of that backup review session expired. Upload the backup again.', 'certloom' ) );
		}
		return $chunk['records'];
	}

	public static function delete_session( $token ) {
		$manifest = self::get_manifest( $token );
		self::delete_chunks( sanitize_text_field( $token ), $manifest ? absint( $manifest['record_chunks'] ) : 0 );
	}

	private static function delete_chunks( $token, $count ) {
		for ( $i = 0; $i < absint( $count ); $i++ ) {
			delete_transient( $token . '_records_' . $i );
		}
		delete_transient( $token );
	}

	private static function restore_option( $key, $value ) {
		if ( null === $value ) {
			$deleted = delete_option( $key );
			return $deleted || null === get_option( $key, null );
		}
		$saved = update_option( $key, $value, false );
		return $saved || $value === get_option( $key, null );
	}


	private static function save_journal( $journal ) {
		$saved = update_option( self::JOURNAL_OPTION, $journal, false );
		return $saved || $journal === get_option( self::JOURNAL_OPTION, array() );
	}

	private static function delete_journal() {
		$deleted = delete_option( self::JOURNAL_OPTION );
		return $deleted || false === get_option( self::JOURNAL_OPTION, false );
	}

	private static function rollback_table_name( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'certverify_rollback_' . substr( preg_replace( '/[^a-z0-9]/i', '', (string) $suffix ), 0, 12 );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		if ( 0 !== strpos( (string) $table, $wpdb->prefix . 'certverify_' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore workspace tables are short-lived and must be checked against the live database, not a potentially stale cache.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (string) $found === (string) $table;
	}

	private static function preflight_download_file( $path, $expected_size ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Full preflight read prevents sending a known-partial backup.
		if ( false === $handle ) {
			return false;
		}
		$read = 0;
		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buffer ) {
				self::close_handle( $handle );
				return false;
			}
			$read += strlen( $buffer );
		}
		self::close_handle( $handle );
		return absint( $expected_size ) === $read;
	}

	/**
	 * Closes a streaming file handle used for an administrator-requested backup.
	 *
	 * WP_Filesystem does not expose the resource handles required for bounded
	 * streaming, hashing, atomic temporary-file generation, and downloads.
	 *
	 * @param resource $handle Open file handle.
	 */
	private static function close_handle( $handle ) {
		if ( is_resource( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See method documentation; WP_Filesystem has no compatible streaming-resource API.
			fclose( $handle );
		}
	}

	private static function write_all( $handle, $data ) {
		$length = strlen( $data );
		$offset = 0;
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $data, $offset ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private static function download_error( $message ) {
		wp_die( esc_html( $message ), esc_html__( 'Certloom backup unavailable', 'certloom' ), array( 'response' => 503 ) );
	}
}
