<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secure CSV import, mapping, export, and template generation.
 */
class Ecapla_CertVerify_Import {

	const ROW_PREFIX    = 'ecapla_certverify_import_';
	const RESULT_PREFIX = 'ecapla_certverify_import_result_';
	const PROCESS_TTL   = 30 * MINUTE_IN_SECONDS;
	const PROCESS_LOCK_TTL = 2 * MINUTE_IN_SECONDS;
	const MAX_ROWS       = 5000;
	const MAX_COLUMNS    = 100;
	const MAX_CELL_BYTES = 10000;
	const CHUNK_ROWS     = 250;

	/**
	 * @return array|WP_Error
	 */
	public static function parse_csv_string( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return new WP_Error( 'ecapla_certverify_empty_csv', __( 'The CSV file is empty or could not be read.', 'certloom' ) );
		}

		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		if ( false !== strpos( $content, "\0" ) ) {
			return new WP_Error( 'ecapla_certverify_binary_csv', __( 'The uploaded file contains binary data and cannot be imported as CSV.', 'certloom' ) );
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return new WP_Error( 'ecapla_certverify_csv_encoding', __( 'The CSV must use UTF-8 character encoding.', 'certloom' ) );
		}

		try {
			$stream = new SplTempFileObject( 5 * MB_IN_BYTES );
			$stream->fwrite( $content );
			$stream->rewind();
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'ecapla_certverify_csv_stream', __( 'The CSV file could not be opened for analysis.', 'certloom' ) );
		}

		$rows         = array();
		$column_count = null;
		$line         = 0;

		while ( ! $stream->eof() ) {
			$row = $stream->fgetcsv( ',', '"', '' );
			$line++;
			if ( false === $row || array( null ) === $row || self::row_is_empty( $row ) ) {
				continue;
			}

			if ( count( $row ) > self::MAX_COLUMNS ) {
				return new WP_Error(
					'ecapla_certverify_too_many_columns',
					sprintf(
						/* translators: 1: CSV row number, 2: maximum allowed column count. */
						__( 'Row %1$d contains more than %2$d columns.', 'certloom' ),
						$line,
						self::MAX_COLUMNS
					)
				);
			}
			if ( null === $column_count ) {
				$column_count = count( $row );
			} elseif ( count( $row ) !== $column_count ) {
				return new WP_Error(
					'ecapla_certverify_column_mismatch',
					sprintf(
						/* translators: %d: CSV row number. */
						__( 'Row %d does not contain the same number of columns as the header row.', 'certloom' ),
						$line
					)
				);
			}
			foreach ( $row as $cell ) {
				if ( strlen( (string) $cell ) > self::MAX_CELL_BYTES ) {
					return new WP_Error(
						'ecapla_certverify_cell_too_large',
						sprintf(
							/* translators: %d: CSV row number. */
							__( 'Row %d contains a cell that is too large to import safely.', 'certloom' ),
							$line
						)
					);
				}
			}
			$rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS + 1 ) {
				return new WP_Error(
					'ecapla_certverify_too_many_rows',
					sprintf(
						/* translators: %d: maximum number of certificate rows per import. */
						__( 'A single import can contain no more than %d certificate rows.', 'certloom' ),
						self::MAX_ROWS
					)
				);
			}
		}

		return $rows;
	}

	/**
	 * Stores parsed rows in bounded transient chunks for the mapping step.
	 *
	 * @param array $rows Parsed CSV rows including the header.
	 * @return string|WP_Error
	 */
	public static function store_rows( $rows ) {
		$rows = array_values( (array) $rows );
		if ( count( $rows ) < 2 ) {
			return new WP_Error( 'ecapla_certverify_empty_csv', __( 'The CSV needs a header row plus at least one certificate row.', 'certloom' ) );
		}
		$header = array_shift( $rows );
		return self::store_chunked_session( $header, $rows );
	}

	/**
	 * Parses an uploaded CSV directly into bounded transient chunks.
	 *
	 * @param string $path Uploaded temporary file path.
	 * @return string|WP_Error
	 */
	public static function store_uploaded_csv( $path ) {
		try {
			$stream = new SplFileObject( $path, 'rb' );
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'ecapla_certverify_csv_stream', __( 'The CSV file could not be opened for analysis.', 'certloom' ) );
		}

		$token        = self::ROW_PREFIX . wp_generate_password( 24, false, false );
		$header       = null;
		$column_count = null;
		$chunk        = array();
		$chunk_count  = 0;
		$row_count    = 0;
		$line         = 0;
		$preview      = array();

		while ( ! $stream->eof() ) {
			$row = $stream->fgetcsv( ',', '"', '' );
			$line++;
			if ( false === $row || array( null ) === $row || self::row_is_empty( $row ) ) {
				continue;
			}
			if ( null === $header && isset( $row[0] ) ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
			}
			if ( count( $row ) > self::MAX_COLUMNS ) {
				self::delete_chunked_session( $token, $chunk_count );
				return new WP_Error(
					'ecapla_certverify_too_many_columns',
					sprintf(
						/* translators: 1: CSV row number, 2: maximum permitted number of columns. */
						__( 'Row %1$d contains more than %2$d columns.', 'certloom' ),
						$line,
						self::MAX_COLUMNS
					)
				);
			}
			if ( null === $column_count ) {
				$column_count = count( $row );
			} elseif ( count( $row ) !== $column_count ) {
				self::delete_chunked_session( $token, $chunk_count );
				return new WP_Error(
					'ecapla_certverify_column_mismatch',
					sprintf(
						/* translators: %d: CSV row number. */
						__( 'Row %d does not contain the same number of columns as the header row.', 'certloom' ),
						$line
					)
				);
			}
			foreach ( $row as $cell ) {
				$cell = (string) $cell;
				if ( false !== strpos( $cell, "\0" ) ) {
					self::delete_chunked_session( $token, $chunk_count );
					return new WP_Error( 'ecapla_certverify_binary_csv', __( 'The uploaded file contains binary data and cannot be imported as CSV.', 'certloom' ) );
				}
				if ( strlen( $cell ) > self::MAX_CELL_BYTES ) {
					self::delete_chunked_session( $token, $chunk_count );
					return new WP_Error(
						'ecapla_certverify_cell_too_large',
						sprintf(
							/* translators: %d: CSV row number. */
							__( 'Row %d contains a cell that is too large to import safely.', 'certloom' ),
							$line
						)
					);
				}
				if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $cell, 'UTF-8' ) ) {
					self::delete_chunked_session( $token, $chunk_count );
					return new WP_Error( 'ecapla_certverify_csv_encoding', __( 'The CSV must use UTF-8 character encoding.', 'certloom' ) );
				}
			}

			if ( null === $header ) {
				$header = $row;
				continue;
			}
			$row_count++;
			if ( $row_count > self::MAX_ROWS ) {
				self::delete_chunked_session( $token, $chunk_count );
				return new WP_Error(
					'ecapla_certverify_too_many_rows',
					sprintf(
						/* translators: %d: maximum number of certificate rows allowed in one import. */
						__( 'A single import can contain no more than %d certificate rows.', 'certloom' ),
						self::MAX_ROWS
					)
				);
			}
			if ( count( $preview ) < 5 ) {
				$preview[] = $row;
			}
			$chunk[] = $row;
			if ( count( $chunk ) >= self::CHUNK_ROWS ) {
				if ( ! self::store_chunk( $token, $chunk_count, $chunk ) ) {
					self::delete_chunked_session( $token, $chunk_count );
					return new WP_Error( 'ecapla_certverify_import_storage_failed', __( 'The CSV was read, but its temporary import session could not be stored. Check the database or object cache and try again.', 'certloom' ) );
				}
				$chunk_count++;
				$chunk = array();
			}
		}

		if ( null === $header || 0 === $row_count ) {
			self::delete_chunked_session( $token, $chunk_count );
			return new WP_Error( 'ecapla_certverify_empty_csv', __( 'The CSV needs a header row plus at least one certificate row.', 'certloom' ) );
		}
		if ( $chunk ) {
			if ( ! self::store_chunk( $token, $chunk_count, $chunk ) ) {
				self::delete_chunked_session( $token, $chunk_count );
				return new WP_Error( 'ecapla_certverify_import_storage_failed', __( 'The CSV was read, but its temporary import session could not be stored. Check the database or object cache and try again.', 'certloom' ) );
			}
			$chunk_count++;
		}

		$manifest = array( 'user_id' => get_current_user_id(), 'header' => $header, 'preview' => $preview, 'chunks' => $chunk_count, 'rows' => $row_count );
		if ( ! set_transient( $token, $manifest, 30 * MINUTE_IN_SECONDS ) ) {
			self::delete_chunked_session( $token, $chunk_count );
			return new WP_Error( 'ecapla_certverify_import_storage_failed', __( 'The CSV was read, but its temporary import session could not be stored. Check the database or object cache and try again.', 'certloom' ) );
		}
		return $token;
	}

	private static function store_chunked_session( $header, $rows ) {
		$token       = self::ROW_PREFIX . wp_generate_password( 24, false, false );
		$chunks      = array_chunk( array_values( $rows ), self::CHUNK_ROWS );
		$chunk_count = 0;
		foreach ( $chunks as $chunk ) {
			if ( ! self::store_chunk( $token, $chunk_count, $chunk ) ) {
				self::delete_chunked_session( $token, $chunk_count );
				return new WP_Error( 'ecapla_certverify_import_storage_failed', __( 'The CSV was read, but its temporary import session could not be stored. Check the database or object cache and try again.', 'certloom' ) );
			}
			$chunk_count++;
		}
		$manifest = array( 'user_id' => get_current_user_id(), 'header' => $header, 'preview' => array_slice( $rows, 0, 5 ), 'chunks' => $chunk_count, 'rows' => count( $rows ) );
		if ( ! set_transient( $token, $manifest, 30 * MINUTE_IN_SECONDS ) ) {
			self::delete_chunked_session( $token, $chunk_count );
			return new WP_Error( 'ecapla_certverify_import_storage_failed', __( 'The CSV was read, but its temporary import session could not be stored. Check the database or object cache and try again.', 'certloom' ) );
		}
		return $token;
	}

	private static function store_chunk( $token, $index, $rows ) {
		return set_transient( $token . '_chunk_' . absint( $index ), array( 'user_id' => get_current_user_id(), 'rows' => array_values( $rows ) ), 30 * MINUTE_IN_SECONDS );
	}

	private static function get_manifest( $token ) {
		$token = sanitize_text_field( $token );
		if ( 0 !== strpos( $token, self::ROW_PREFIX ) ) {
			return false;
		}
		$manifest = get_transient( $token );
		return is_array( $manifest ) && isset( $manifest['user_id'], $manifest['header'], $manifest['chunks'] ) && (int) $manifest['user_id'] === get_current_user_id() ? $manifest : false;
	}

	public static function get_preview( $token ) {
		$manifest = self::get_manifest( $token );
		return $manifest ? array( 'header' => $manifest['header'], 'preview' => $manifest['preview'] ?? array(), 'rows' => absint( $manifest['rows'] ?? 0 ) ) : false;
	}

	public static function get_rows( $token ) {
		$manifest = self::get_manifest( $token );
		if ( ! $manifest ) {
			return false;
		}
		$rows = array( $manifest['header'] );
		for ( $i = 0; $i < absint( $manifest['chunks'] ); $i++ ) {
			$chunk = get_transient( sanitize_text_field( $token ) . '_chunk_' . $i );
			if ( ! is_array( $chunk ) || (int) ( $chunk['user_id'] ?? 0 ) !== get_current_user_id() || ! isset( $chunk['rows'] ) ) {
				return false;
			}
			$rows = array_merge( $rows, $chunk['rows'] );
		}
		return $rows;
	}

	public static function delete_rows( $token ) {
		$manifest = self::get_manifest( $token );
		if ( $manifest ) {
			self::delete_chunked_session( sanitize_text_field( $token ), absint( $manifest['chunks'] ) );
		}
	}

	private static function delete_chunked_session( $token, $chunks ) {
		for ( $i = 0; $i < absint( $chunks ); $i++ ) {
			delete_transient( $token . '_chunk_' . $i );
		}
		delete_transient( $token );
	}

	public static function guess_mapping( $header, $fields ) {
		$known = array(
			'certnumber'        => 'cert_number',
			'certificatenumber' => 'cert_number',
			'number'            => 'cert_number',
			'status'            => 'status',
			'currentstatus'     => 'status',
			'holder'            => 'holder_name',
			'certificateholder' => 'holder_name',
			'holdername'        => 'holder_name',
			'issuedate'         => 'issue_date',
			'expirydate'        => 'expiry_date',
			'expirationdate'    => 'expiry_date',
		);
		$guess = array();
		foreach ( $header as $col_index => $col_name ) {
			$normalized = self::normalize( $col_name );
			$match      = isset( $known[ $normalized ] ) ? $known[ $normalized ] : '';
			if ( '' === $match ) {
				foreach ( $fields as $field ) {
					if ( $normalized === self::normalize( $field['key'] ) || $normalized === self::normalize( $field['label'] ) ) {
						$match = $field['key'];
						break;
					}
				}
			}
			$guess[ $col_index ] = $match;
		}
		return $guess;
	}

	/**
	 * Imports mapped rows. Existing rows receive partial updates only;
	 * unmapped values and inactive custom fields are preserved.
	 */
	public static function process( $rows, $mapping, $update_existing, $clear_empty = false, $historical_restore = false, $manage_batch = true, $line_offset = 0 ) {
		$rows = array_values( (array) $rows );
		array_shift( $rows );
		$results = array(
			'created'  => 0,
			'updated'  => 0,
			'restored' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);
		$historical_restore = (bool) $historical_restore;
		$field_map       = Ecapla_CertVerify_Fields::get_field_map();
		$allowed_mapping = array_keys( $field_map );
		$clean_mapping   = array();
		$mapped_fields   = array();

		foreach ( (array) $mapping as $col_index => $field_key ) {
			$field_key = sanitize_key( $field_key );
			if ( '' === $field_key || ! in_array( $field_key, $allowed_mapping, true ) ) {
				continue;
			}
			if ( isset( $mapped_fields[ $field_key ] ) ) {
				$results['skipped'] = count( $rows );
				$results['errors'][] = __( 'Each certificate field can be mapped to only one CSV column.', 'certloom' );
				return $results;
			}
			$mapped_fields[ $field_key ] = true;
			$clean_mapping[ absint( $col_index ) ] = $field_key;
		}

		if ( ! isset( $mapped_fields['cert_number'] ) ) {
			$results['skipped'] = count( $rows );
			$results['errors'][] = __( 'Map one CSV column to Certificate number before running the import.', 'certloom' );
			return $results;
		}
		$status_is_mapped = isset( $mapped_fields['status'] );

		$cert_column = array_search( 'cert_number', $clean_mapping, true );
		$numbers     = array();
		if ( false !== $cert_column ) {
			foreach ( $rows as $source_row ) {
				if ( array_key_exists( $cert_column, (array) $source_row ) ) {
					$number = trim( (string) $source_row[ $cert_column ] );
					if ( '' !== $number ) {
						$numbers[] = $number;
					}
				}
			}
		}
		$existing_records = Ecapla_CertVerify_Database::get_records_by_numbers( $numbers );
		if ( is_wp_error( $existing_records ) ) {
			$results['skipped'] = count( $rows );
			$results['errors'][] = $existing_records->get_error_message();
			return $results;
		}

		if ( $manage_batch ) {
			Ecapla_CertVerify_Database::begin_batch();
		}

		foreach ( $rows as $row_index => $row ) {
			$line             = absint( $line_offset ) + $row_index + 2;
			$data             = array();
			$row_error        = '';
			$status_was_blank = false;

			foreach ( $clean_mapping as $col_index => $field_key ) {
				$field_key = sanitize_key( $field_key );
				if ( '' === $field_key || ! in_array( $field_key, $allowed_mapping, true ) || ! array_key_exists( $col_index, $row ) ) {
					continue;
				}
				$value = trim( (string) $row[ $col_index ] );
				if ( '' === $value ) {
					if ( 'status' === $field_key ) {
						$status_was_blank = true;
						if ( $clear_empty ) {
							$row_error = __( 'Status cannot be cleared. Enter Valid, Suspended, or Withdrawn, or turn off blank-cell clearing.', 'certloom' );
							break;
						}
						continue;
					}
					if ( ! $clear_empty ) {
						continue;
					}
				}
				$field = $field_map[ $field_key ];
				if ( ! empty( $field['column'] ) ) {
					$data[ $field_key ] = $value;
				} else {
					if ( ! isset( $data['custom_fields'] ) ) {
						$data['custom_fields'] = array();
					}
					$data['custom_fields'][ $field_key ] = $value;
				}
			}

			if ( '' !== $row_error ) {
				$results['skipped']++;
				$results['errors'][] = sprintf(
					/* translators: 1: CSV row number, 2: validation error message. */
					__( 'Row %1$d: %2$s', 'certloom' ),
					$line,
					$row_error
				);
				continue;
			}

			if ( empty( $data['cert_number'] ) ) {
				$results['skipped']++;
				$results['errors'][] = sprintf(
					/* translators: %d: CSV row number. */
					__( 'Row %d: certificate number is missing or was not mapped.', 'certloom' ),
					$line
				);
				continue;
			}

			$existing_key = Ecapla_CertVerify_Database::normalize_certificate_number( $data['cert_number'] );
			$existing     = isset( $existing_records[ $existing_key ] ) ? $existing_records[ $existing_key ] : null;
			if ( ! $existing && $status_is_mapped && $status_was_blank ) {
				$results['skipped']++;
				$results['errors'][] = sprintf(
					/* translators: %d: CSV row number. */
					__( 'Row %d: Status is mapped but blank. Enter Valid, Suspended, or Withdrawn, or remove the Status mapping to use the default Valid state.', 'certloom' ),
					$line
				);
				continue;
			}
			$is_historical_restore = false;
			if ( isset( $data['status'] ) && 'expired' === sanitize_key( (string) $data['status'] ) ) {
				$legacy_expired        = $existing && ( 'expired' === $existing['status'] || 'legacy' === $existing['status_source'] );
				$is_historical_restore = ! $existing && $historical_restore;
				if ( ! $legacy_expired && ! $is_historical_restore ) {
					$results['skipped']++;
					$results['errors'][] = sprintf(
						/* translators: %d: CSV row number. */
						__( 'Row %d: Expired is calculated from the expiry date. Enable Historical restore mode only when restoring protected legacy data.', 'certloom' ),
						$line
					);
					continue;
				}
			}
			if ( $existing ) {
				if ( ! $update_existing ) {
					$results['skipped']++;
					continue;
				}
				$result = Ecapla_CertVerify_Database::update( $existing['id'], $data );
				$bucket = 'updated';
			} else {
				if ( ! isset( $data['status'] ) ) {
					$data['status'] = 'valid';
				}
				$result = Ecapla_CertVerify_Database::insert( $data );
				$bucket = 'created';
			}

			if ( is_wp_error( $result ) ) {
				$results['skipped']++;
				$results['errors'][] = sprintf(
					/* translators: 1: CSV row number, 2: validation error message. */
					__( 'Row %1$d: %2$s', 'certloom' ),
					$line,
					$result->get_error_message()
				);
			} else {
				$results[ $bucket ]++;
				if ( $is_historical_restore ) {
					$results['restored']++;
				}
			}
		}

		if ( $manage_batch ) {
			$batch_result = Ecapla_CertVerify_Database::end_batch();
			if ( is_wp_error( $batch_result ) ) {
				$results['errors'][] = $batch_result->get_error_message();
			}
		}
		return $results;
	}

	/**
	 * Processes a chunked import session without loading all rows into one object.
	 *
	 * @return array|false
	 */
	public static function process_stored( $token, $mapping, $update_existing, $clear_empty = false, $historical_restore = false ) {
		return self::start_stored_process( $token, $mapping, $update_existing, $clear_empty, $historical_restore );
	}


	/** Starts a resumable import and processes only its first bounded chunk. */
	public static function start_stored_process( $token, $mapping, $update_existing, $clear_empty = false, $historical_restore = false ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$manifest = self::get_manifest( $token );
		if ( ! $manifest ) {
			return false;
		}
		$clean_mapping = array();
		foreach ( (array) $mapping as $column => $field_key ) {
			$clean_mapping[ absint( $column ) ] = sanitize_key( $field_key );
		}
		$manifest['process'] = array(
			'mapping'            => $clean_mapping,
			'update_existing'    => (bool) $update_existing,
			'clear_empty'        => (bool) $clear_empty,
			'historical_restore' => (bool) $historical_restore,
			'cursor'             => 0,
			'results'            => array( 'created' => 0, 'updated' => 0, 'restored' => 0, 'skipped' => 0, 'errors' => array() ),
			'started_at'         => time(),
		);
		if ( ! set_transient( sanitize_text_field( $token ), $manifest, self::PROCESS_TTL ) ) {
			return new WP_Error( 'ecapla_certverify_import_progress_failed', __( 'Certloom could not save the import progress state.', 'certloom' ) );
		}
		return self::continue_stored_process( $token );
	}

	/** Processes exactly one stored CSV chunk and saves a durable cursor. */
	public static function continue_stored_process( $token ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$token = sanitize_text_field( $token );
		$lock  = self::acquire_process_lock( $token );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$manifest = self::get_manifest( $token );
			if ( ! $manifest || empty( $manifest['process'] ) || ! is_array( $manifest['process'] ) ) {
				return false;
			}
			$process = $manifest['process'];
			$cursor  = absint( $process['cursor'] ?? 0 );
			$total   = absint( $manifest['chunks'] );
			if ( $cursor >= $total ) {
				return self::progress_response( $manifest, true );
			}
			$receipt_key = self::receipt_key( $token, $cursor );
			$result      = get_transient( $receipt_key );
			if ( ! is_array( $result ) ) {
				$chunk = get_transient( $token . '_chunk_' . $cursor );
				if ( ! is_array( $chunk ) || (int) ( $chunk['user_id'] ?? 0 ) !== get_current_user_id() || ! isset( $chunk['rows'] ) ) {
					return new WP_Error( 'ecapla_certverify_import_chunk_expired', __( 'One temporary import chunk expired. Upload the CSV again.', 'certloom' ) );
				}
				$result = self::process(
					array_merge( array( $manifest['header'] ), $chunk['rows'] ),
					(array) $process['mapping'],
					! empty( $process['update_existing'] ),
					! empty( $process['clear_empty'] ),
					! empty( $process['historical_restore'] ),
					true,
					$cursor * self::CHUNK_ROWS
				);
				if ( ! set_transient( $receipt_key, $result, self::PROCESS_TTL ) ) {
					return new WP_Error( 'ecapla_certverify_import_receipt_failed', __( 'The import chunk finished, but Certloom could not save its completion receipt. Review the certificate list before retrying.', 'certloom' ) );
				}
			}
			foreach ( array( 'created', 'updated', 'restored', 'skipped' ) as $key ) {
				$process['results'][ $key ] = absint( $process['results'][ $key ] ?? 0 ) + absint( $result[ $key ] ?? 0 );
			}
			$process['results']['errors'] = array_slice( array_merge( (array) ( $process['results']['errors'] ?? array() ), (array) ( $result['errors'] ?? array() ) ), 0, 500 );
			$process['cursor']             = $cursor + 1;
			$manifest['process']           = $process;
			if ( ! set_transient( $token, $manifest, self::PROCESS_TTL ) ) {
				return new WP_Error( 'ecapla_certverify_import_progress_failed', __( 'The import chunk finished, but Certloom could not save its progress. Review the certificate list before retrying.', 'certloom' ) );
			}
			delete_transient( $receipt_key );
			return self::progress_response( $manifest, $process['cursor'] >= $total );
		} finally {
			self::release_process_lock( $token, $lock );
		}
	}

	private static function receipt_key( $token, $cursor ) {
		return self::ROW_PREFIX . 'receipt_' . md5( $token ) . '_' . absint( $cursor );
	}

	private static function process_lock_key( $token ) {
		return 'certverify_import_lock_' . md5( $token );
	}

	private static function acquire_process_lock( $token ) {
		global $wpdb;
		$key   = self::process_lock_key( $token );
		$owner = wp_generate_password( 24, false, false );
		$value = $owner . '|' . time();
		if ( add_option( $key, $value, '', false ) ) {
			return $owner;
		}
		$stored = (string) get_option( $key, '' );
		$parts  = explode( '|', $stored, 2 );
		if ( isset( $parts[1] ) && ( time() - absint( $parts[1] ) ) < self::PROCESS_LOCK_TTL ) {
			return new WP_Error( 'ecapla_certverify_import_busy', __( 'This import batch is already being processed. Wait a moment and continue again.', 'certloom' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is required so only one request can take over an expired import lock.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, $key, $stored ) );
		wp_cache_delete( $key, 'options' );
		return 1 === (int) $updated ? $owner : new WP_Error( 'ecapla_certverify_import_busy', __( 'This import batch is already being processed. Wait a moment and continue again.', 'certloom' ) );
	}

	private static function release_process_lock( $token, $owner ) {
		global $wpdb;
		$key    = self::process_lock_key( $token );
		$stored = (string) get_option( $key, '' );
		if ( 0 !== strpos( $stored, sanitize_text_field( $owner ) . '|' ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owner-matched deletion prevents one request from releasing another request's import lock.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $key, $stored ) );
		wp_cache_delete( $key, 'options' );
	}

	public static function get_process_progress( $token ) {
		$manifest = self::get_manifest( $token );
		return $manifest && ! empty( $manifest['process'] ) ? self::progress_response( $manifest, absint( $manifest['process']['cursor'] ?? 0 ) >= absint( $manifest['chunks'] ) ) : false;
	}

	private static function progress_response( $manifest, $complete ) {
		$process = (array) ( $manifest['process'] ?? array() );
		$cursor  = absint( $process['cursor'] ?? 0 );
		$chunks  = max( 1, absint( $manifest['chunks'] ?? 1 ) );
		return array(
			'complete'       => (bool) $complete,
			'processed_rows' => min( absint( $manifest['rows'] ?? 0 ), $cursor * self::CHUNK_ROWS ),
			'total_rows'     => absint( $manifest['rows'] ?? 0 ),
			'percent'        => min( 100, (int) floor( ( $cursor / $chunks ) * 100 ) ),
			'results'        => (array) ( $process['results'] ?? array() ),
		);
	}

	/**
	 * Stores the post-import result summary.
	 *
	 * @param array $results Import result counters and messages.
	 * @return string|WP_Error Result token or a storage error.
	 */
	public static function store_results( $results ) {
		$token  = self::RESULT_PREFIX . wp_generate_password( 18, false, false );
		$stored = set_transient(
			$token,
			array(
				'user_id' => get_current_user_id(),
				'results' => $results,
			),
			10 * MINUTE_IN_SECONDS
		);
		if ( ! $stored ) {
			return new WP_Error( 'ecapla_certverify_import_result_storage_failed', __( 'The import finished, but its result summary could not be stored. Review the certificate list before running the file again.', 'certloom' ) );
		}
		return $token;
	}

	public static function get_results( $token ) {
		$token = sanitize_text_field( $token );
		if ( 0 !== strpos( $token, self::RESULT_PREFIX ) ) {
			return false;
		}
		$stored = get_transient( $token );
		if ( ! is_array( $stored ) || ! isset( $stored['user_id'] ) || (int) $stored['user_id'] !== get_current_user_id() ) {
			return false;
		}
		delete_transient( $token );
		return isset( $stored['results'] ) ? $stored['results'] : false;
	}

	public static function stream_export() {
		$fields  = Ecapla_CertVerify_Fields::get_fields();
		$stream  = new SplTempFileObject( 2 * MB_IN_BYTES );
		$last_id = 0;
		$limit   = 500;

		$stream->fwrite( "\xEF\xBB\xBF" );
		$stream->fputcsv( array_map( array( __CLASS__, 'escape_spreadsheet_cell' ), wp_list_pluck( $fields, 'label' ) ), ',', '"', '' );

		do {
			$items = Ecapla_CertVerify_Database::get_export_batch( $last_id, $limit );
			if ( is_wp_error( $items ) ) {
				wp_die( esc_html( $items->get_error_message() ), esc_html__( 'Certloom export unavailable', 'certloom' ), array( 'response' => 503 ) );
			}
			foreach ( $items as $certificate ) {
				$last_id = max( $last_id, absint( $certificate['id'] ) );
				$row = array();
				foreach ( $fields as $field ) {
					if ( 'status' === $field['key'] ) {
						$value = Ecapla_CertVerify_Database::workflow_status( $certificate );
					} elseif ( ! empty( $field['column'] ) ) {
						$value = $certificate[ $field['key'] ] ?? '';
					} else {
						$value = $certificate['custom_fields'][ $field['key'] ] ?? '';
					}
					$row[] = self::escape_spreadsheet_cell( $value );
				}
				$stream->fputcsv( $row, ',', '"', '' );
			}
		} while ( count( $items ) === $limit );

		self::send_csv_headers( 'certificates-export-' . gmdate( 'Y-m-d' ) . '.csv' );
		$stream->rewind();
		while ( ! $stream->eof() ) {
			$chunk = $stream->fgets();
			if ( false !== $chunk ) {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated CSV download content.
			}
		}
	}

	public static function stream_template() {
		$fields = Ecapla_CertVerify_Fields::get_fields();
		$header = array_map( array( __CLASS__, 'escape_spreadsheet_cell' ), wp_list_pluck( $fields, 'label' ) );
		$sample = array();
		foreach ( $fields as $field ) {
			if ( 'cert_number' === $field['key'] ) {
				$sample[] = 'ECAPLA-CERT-2026-0001';
			} elseif ( 'status' === $field['type'] ) {
				$sample[] = 'valid';
			} elseif ( 'date' === $field['type'] ) {
				$sample[] = gmdate( 'Y-m-d' );
			} elseif ( 'holder_name' === $field['key'] ) {
				$sample[] = 'Example Organization Ltd';
			} else {
				$sample[] = '';
			}
		}
		self::send_csv_headers( 'certloom-import-template.csv' );
		self::output_csv_rows( array( $header, $sample ), true );
	}

	/**
	 * Emits CSV rows through a bounded temporary object. SplTempFileObject is
	 * suitable for generated response streams and avoids direct filesystem APIs
	 * that would incorrectly imply the plugin is modifying site files.
	 *
	 * @param array $rows Rows to write.
	 * @param bool  $with_bom Whether to prepend a UTF-8 byte-order mark.
	 */
	private static function output_csv_rows( $rows, $with_bom = false ) {
		if ( empty( $rows ) && ! $with_bom ) {
			return;
		}

		$stream = new SplTempFileObject( 2 * MB_IN_BYTES );
		if ( $with_bom ) {
			$stream->fwrite( "\xEF\xBB\xBF" );
		}
		foreach ( $rows as $row ) {
			$stream->fputcsv( (array) $row, ',', '"', '' );
		}
		$stream->rewind();

		while ( ! $stream->eof() ) {
			$chunk = $stream->fgets();
			if ( false !== $chunk ) {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentionally generated CSV download content.
			}
		}
	}

	public static function escape_spreadsheet_cell( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\x00-\x20\x{FEFF}]*[=+\-@]/u', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private static function normalize( $string ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '', (string) $string ) );
	}

	private static function row_is_empty( $row ) {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function send_csv_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
	}
}
