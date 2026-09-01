<?php
namespace Certloom\Infrastructure\Import;

use Certloom\Infrastructure\Files\PrivateJobFileStore;

/**
 * Bounded RFC-style CSV reader for resumable domain-import preflight jobs.
 *
 * The persisted offset is a byte position returned by ftell() only after a
 * complete fgetcsv() record, so quoted multiline cells resume safely between
 * Cron/PHP requests. Raw file data never reaches the domain writer directly;
 * every record is normalized into machine fields and then durably staged.
 */
final class DomainImportCsvReader {
	const MAX_FILE_BYTES = 10485760; // 10 MB.
	const MAX_COLUMNS    = 100;
	const MAX_CELL_BYTES = 10000;
	const MAX_ROWS       = 100000;

	/** @var PrivateJobFileStore */
	private $files;

	/** @var array<string,array<string,mixed>> */
	private $field_map;

	/** @param array<string,array<string,mixed>>|null $field_map */
	public function __construct( ?PrivateJobFileStore $files = null, ?array $field_map = null ) {
		$this->files     = $files ?: new PrivateJobFileStore();
		$this->field_map = null === $field_map ? $this->discover_field_map() : $field_map;
	}


	/**
	 * Reads and validates only the CSV header from protected private storage.
	 * This is safe to use for the short-lived admin mapping step because it does
	 * not stage or mutate any credential data.
	 *
	 * @return array{header:array<int,string>,offset:int}|\WP_Error
	 */
	public function inspect_header( $path ) {
		$path = (string) $path;
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'certloom_import_csv_path_invalid', __( 'The Certloom import source is not available in protected job storage.', 'certloom' ) );
		}
		$size = @filesize( $path );
		if ( false === $size || $size < 1 || $size > self::MAX_FILE_BYTES ) {
			return new \WP_Error( 'certloom_import_csv_size_invalid', __( 'The CSV import must be a non-empty file no larger than 10 MB.', 'certloom' ) );
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded protected local job file.
		if ( false === $handle ) {
			return new \WP_Error( 'certloom_import_csv_open_failed', __( 'Certloom could not open the protected CSV import.', 'certloom' ) );
		}
		$header = fgetcsv( $handle, 0, ',', '"', '' );
		$offset = ftell( $handle );
		fclose( $handle );
		if ( false === $header || ! is_array( $header ) || false === $offset ) {
			return new \WP_Error( 'certloom_import_csv_header_missing', __( 'The CSV import does not contain a readable header row.', 'certloom' ) );
		}
		$header = $this->normalize_header( $header );
		if ( is_wp_error( $header ) ) {
			return $header;
		}
		return array( 'header' => $header, 'offset' => (int) $offset );
	}

	/** @return array<string,string> Machine field => administrator-facing label. */
	public function field_choices() {
		$labels = array(
			'cert_number' => __( 'Certificate number', 'certloom' ),
			'holder_name' => __( 'Certificate holder', 'certloom' ),
			'status'      => __( 'Status', 'certloom' ),
			'issue_date'  => __( 'Issue date', 'certloom' ),
			'expiry_date' => __( 'Expiry date', 'certloom' ),
			'email'       => __( 'Recipient email', 'certloom' ),
			'external_id' => __( 'Recipient external ID', 'certloom' ),
			'visibility'  => __( 'Visibility', 'certloom' ),
		);
		foreach ( $this->field_map as $key => $field ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $field ) || ! empty( $field['column'] ) || isset( $labels[ $key ] ) ) {
				continue;
			}
			$label = trim( (string) ( $field['label'] ?? '' ) );
			$labels[ $key ] = '' !== $label ? $label : $key;
		}
		return $labels;
	}

	/**
	 * Validates the source/header/mapping and returns the first resumable state.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( $path, array $mapping, $clear_empty = false, $max_rows = self::MAX_ROWS ) {
		$path = (string) $path;
		$inspected = $this->inspect_header( $path );
		if ( is_wp_error( $inspected ) ) {
			return $inspected;
		}
		$header  = (array) $inspected['header'];
		$offset  = absint( $inspected['offset'] );
		$mapping = $this->normalize_mapping( $mapping, $header );
		if ( is_wp_error( $mapping ) ) { return $mapping; }

		return array(
			'path'        => $path,
			'offset'      => (int) $offset,
			'row_number'  => 0,
			'header'      => $header,
			'mapping'     => $mapping,
			'clear_empty' => (bool) $clear_empty,
			'max_rows'    => max( 1, min( self::MAX_ROWS, absint( $max_rows ) ) ),
			'complete'    => false,
		);
	}

	/**
	 * Reads at most $limit logical CSV records from the saved byte boundary.
	 *
	 * @return array{state:array<string,mixed>,records:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function continue_read( array $state, $limit = 250 ) {
		$valid = $this->validate_state( $state );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( ! empty( $state['complete'] ) ) {
			return array( 'state' => $state, 'records' => array() );
		}

		$limit  = max( 1, min( 500, absint( $limit ) ) );
		$handle = @fopen( (string) $state['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded protected local job file.
		if ( false === $handle || 0 !== fseek( $handle, (int) $state['offset'], SEEK_SET ) ) {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
			return new \WP_Error( 'certloom_import_csv_resume_failed', __( 'Certloom could not resume the protected CSV import at its saved position.', 'certloom' ) );
		}

		$records = array();
		$read    = 0;
		while ( $read < $limit ) {
			$raw = fgetcsv( $handle, 0, ',', '"', '' );
			if ( false === $raw ) { break; }
			if ( $this->blank_record( $raw ) ) {
				$state['offset'] = (int) ftell( $handle );
				continue;
			}
			$state['row_number'] = absint( $state['row_number'] ) + 1;
			if ( $state['row_number'] > absint( $state['max_rows'] ) ) {
				fclose( $handle );
				return new \WP_Error( 'certloom_import_csv_row_limit', __( 'The CSV import exceeds the configured maximum number of records.', 'certloom' ) );
			}

			$record = $this->map_record( $raw, (array) $state['header'], (array) $state['mapping'], ! empty( $state['clear_empty'] ) );
			$records[] = is_wp_error( $record )
				? array( 'row_number' => absint( $state['row_number'] ), 'error' => $record )
				: array( 'row_number' => absint( $state['row_number'] ), 'row' => $record );
			$read++;
			$position = ftell( $handle );
			if ( false === $position ) {
				fclose( $handle );
				return new \WP_Error( 'certloom_import_csv_position_failed', __( 'Certloom could not save the CSV import position safely.', 'certloom' ) );
			}
			$state['offset'] = (int) $position;
		}
		$file_size = @filesize( (string) $state['path'] );
		$state['complete'] = feof( $handle ) || ( false !== $file_size && (int) $state['offset'] >= (int) $file_size );
		fclose( $handle );
		return array( 'state' => $state, 'records' => $records );
	}

	/** @return array<int,string>|\WP_Error */
	private function normalize_header( array $header ) {
		if ( empty( $header ) || count( $header ) > self::MAX_COLUMNS ) {
			return new \WP_Error( 'certloom_import_csv_columns_invalid', __( 'The CSV header has an unsupported number of columns.', 'certloom' ) );
		}
		$seen = array();
		$out  = array();
		foreach ( array_values( $header ) as $index => $value ) {
			$value = null === $value ? '' : (string) $value;
			if ( 0 === $index ) { $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value ); }
			if ( strlen( $value ) > self::MAX_CELL_BYTES || ! $this->valid_utf8( $value ) || false !== strpos( $value, "\0" ) ) {
				return new \WP_Error( 'certloom_import_csv_header_invalid', __( 'The CSV header contains invalid or oversized text.', 'certloom' ) );
			}
			$value = trim( sanitize_text_field( $value ) );
			$key   = strtolower( preg_replace( '/\s+/u', ' ', $value ) );
			if ( '' !== $key && isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'certloom_import_csv_duplicate_header', __( 'The CSV contains duplicate header names. Rename the duplicate columns before importing.', 'certloom' ) );
			}
			if ( '' !== $key ) { $seen[ $key ] = true; }
			$out[] = $value;
		}
		return $out;
	}

	/** @return array<int,string>|\WP_Error */
	private function normalize_mapping( array $mapping, array $header ) {
		$allowed = $this->allowed_targets();
		$out     = array();
		$used    = array();
		foreach ( $mapping as $column => $target ) {
			if ( ! is_numeric( $column ) ) {
				return new \WP_Error( 'certloom_import_csv_mapping_invalid', __( 'CSV field mappings must reference a valid column number.', 'certloom' ) );
			}
			$column = absint( $column );
			$target = sanitize_key( (string) $target );
			if ( '' === $target ) { continue; }
			if ( ! array_key_exists( $column, $header ) || ! isset( $allowed[ $target ] ) ) {
				return new \WP_Error( 'certloom_import_csv_mapping_invalid', __( 'The CSV field mapping contains an unsupported column or Certloom field.', 'certloom' ) );
			}
			if ( isset( $used[ $target ] ) ) {
				return new \WP_Error( 'certloom_import_csv_mapping_duplicate', __( 'Each Certloom field may be mapped to only one CSV column.', 'certloom' ) );
			}
			$out[ $column ] = $target;
			$used[ $target ] = true;
		}
		foreach ( array( 'cert_number', 'holder_name' ) as $required ) {
			if ( ! isset( $used[ $required ] ) ) {
				return new \WP_Error( 'certloom_import_csv_mapping_required', __( 'Map both Certificate number and Certificate holder before starting the new-domain import.', 'certloom' ) );
			}
		}
		ksort( $out );
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function map_record( array $record, array $header, array $mapping, $clear_empty ) {
		if ( count( $record ) !== count( $header ) ) {
			return new \WP_Error( 'certloom_import_csv_column_mismatch', __( 'This CSV record has a different number of columns than the header.', 'certloom' ) );
		}

		$row    = array();
		$custom = array();
		$has_custom = false;
		$allowed = $this->allowed_targets();
		foreach ( $mapping as $column => $target ) {
			$value = array_key_exists( $column, $record ) && null !== $record[ $column ] ? (string) $record[ $column ] : '';
			if ( strlen( $value ) > self::MAX_CELL_BYTES || ! $this->valid_utf8( $value ) || false !== strpos( $value, "\0" ) ) {
				return new \WP_Error( 'certloom_import_csv_cell_invalid', __( 'This CSV record contains invalid or oversized text.', 'certloom' ) );
			}
			$value = trim( $value );
			$is_required = in_array( $target, array( 'cert_number', 'holder_name' ), true );
			if ( '' === $value && ! $clear_empty && ! $is_required ) { continue; }
			if ( 'status' === $target && '' === $value ) {
				return new \WP_Error( 'certloom_import_csv_status_empty', __( 'Status cannot be explicitly cleared. Leave the Status column unmapped to preserve the current state during corrections.', 'certloom' ) );
			}

			if ( ! empty( $allowed[ $target ]['custom'] ) ) {
				$sanitized = $this->sanitize_custom( $target, $value, $allowed[ $target ] );
				if ( is_wp_error( $sanitized ) ) { return $sanitized; }
				$custom[ $target ] = $sanitized;
				$has_custom = true;
				continue;
			}

			switch ( $target ) {
				case 'status':
				case 'visibility':
					$row[ $target ] = sanitize_key( $value );
					break;
				case 'email':
					$row[ $target ] = sanitize_email( $value );
					break;
				case 'cert_number':
				case 'holder_name':
				case 'external_id':
					$row[ $target ] = sanitize_text_field( $value );
					break;
				default:
					$row[ $target ] = $value; // ISO dates are validated by DomainImportRowMapper.
			}
		}
		if ( $has_custom ) { $row['custom_fields'] = $custom; }
		return $row;
	}

	/** @return array<string,array<string,mixed>> */
	private function allowed_targets() {
		$allowed = array();
		foreach ( array( 'cert_number', 'holder_name', 'status', 'issue_date', 'expiry_date', 'email', 'external_id', 'visibility' ) as $key ) {
			$allowed[ $key ] = array( 'custom' => false, 'type' => 'text' );
		}
		foreach ( $this->field_map as $key => $field ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $field ) || ! empty( $field['column'] ) || isset( $allowed[ $key ] ) ) { continue; }
			$allowed[ $key ] = array_merge( $field, array( 'custom' => true ) );
		}
		return $allowed;
	}

	/** @return string|\WP_Error */
	private function sanitize_custom( $key, $value, array $field ) {
		if ( class_exists( 'Ecapla_CertVerify_Validator' ) ) {
			$clean = \Ecapla_CertVerify_Validator::custom_field_value( $value, $field );
			return is_wp_error( $clean ) ? $clean : (string) $clean;
		}
		$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		if ( 'date' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				/* translators: custom field label */
				return new \WP_Error( 'certloom_import_csv_custom_date_invalid', sprintf( __( 'Custom field %s requires a YYYY-MM-DD date.', 'certloom' ), $key ) );
			}
			list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
			if ( ! checkdate( $month, $day, $year ) ) {
				/* translators: custom field label */
				return new \WP_Error( 'certloom_import_csv_custom_date_invalid', sprintf( __( 'Custom field %s contains an invalid calendar date.', 'certloom' ), $key ) );
			}
			return $value;
		}
		if ( 'number' === $type && '' !== $value && ! is_numeric( $value ) ) {
			/* translators: custom field label */
			return new \WP_Error( 'certloom_import_csv_custom_number_invalid', sprintf( __( 'Custom field %s requires a numeric value.', 'certloom' ), $key ) );
		}
		return 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}

	/** @return array<string,array<string,mixed>> */
	private function discover_field_map() {
		return class_exists( 'Ecapla_CertVerify_Fields' )
			? (array) \Ecapla_CertVerify_Fields::get_field_map()
			: array();
	}

	/** @return true|\WP_Error */
	private function validate_state( array $state ) {
		$path = (string) ( $state['path'] ?? '' );
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'certloom_import_csv_state_invalid', __( 'The protected CSV import state is no longer available.', 'certloom' ) );
		}
		if ( ! isset( $state['header'], $state['mapping'], $state['offset'], $state['row_number'], $state['max_rows'] ) || ! is_array( $state['header'] ) || ! is_array( $state['mapping'] ) ) {
			return new \WP_Error( 'certloom_import_csv_state_invalid', __( 'The saved CSV import state is incomplete.', 'certloom' ) );
		}
		return true;
	}

	private function blank_record( array $record ) {
		foreach ( $record as $value ) {
			if ( null !== $value && '' !== trim( (string) $value ) ) { return false; }
		}
		return true;
	}

	private function valid_utf8( $value ) {
		return '' === $value || 1 === @preg_match( '//u', (string) $value );
	}
}
