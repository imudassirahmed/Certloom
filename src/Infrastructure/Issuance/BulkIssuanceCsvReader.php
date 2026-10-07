<?php
namespace Certloom\Infrastructure\Issuance;

use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded RFC-style CSV reader for Bulk Issuance.
 *
 * Program/Template/date policy is frozen outside the file. CSV rows carry only
 * Recipient identity plus row-specific grade/number/custom merge values. This
 * keeps the bulk contract small and lets every row reuse ManualIssuanceService.
 */
final class BulkIssuanceCsvReader {
	const MAX_FILE_BYTES = 10485760;
	const MAX_COLUMNS = 50;
	const MAX_CELL_BYTES = 10000;
	const MAX_ROWS = 5000;

	/** @var PrivateJobFileStore */ private $files;
	public function __construct( ?PrivateJobFileStore $files = null ) { $this->files = $files ?: new PrivateJobFileStore(); }

	/** @return array{header:array<int,string>,offset:int}|\WP_Error */
	public function inspect_header( $path ) {
		$path = (string) $path;
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'certloom_bulk_csv_path_invalid', __( 'The Bulk Issuance CSV is not available in protected Certloom storage.', 'certloom' ) );
		}
		$size = @filesize( $path );
		if ( false === $size || $size < 1 || $size > self::MAX_FILE_BYTES ) {
			return new \WP_Error( 'certloom_bulk_csv_size_invalid', __( 'The Bulk Issuance CSV must be non-empty and no larger than 10 MB.', 'certloom' ) );
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) { return new \WP_Error( 'certloom_bulk_csv_open_failed', __( 'Certloom could not open the protected Bulk Issuance CSV.', 'certloom' ) ); }
		$raw = fgetcsv( $handle, 0, ',', '"', '' );
		$offset = ftell( $handle ); fclose( $handle );
		if ( false === $raw || ! is_array( $raw ) || false === $offset ) { return new \WP_Error( 'certloom_bulk_csv_header_missing', __( 'The Bulk Issuance CSV does not contain a readable header row.', 'certloom' ) ); }
		$header = $this->normalize_header( $raw );
		return is_wp_error( $header ) ? $header : array( 'header' => $header, 'offset' => (int) $offset );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function start( $path, array $shared, $max_rows = self::MAX_ROWS ) {
		$head = $this->inspect_header( $path );
		if ( is_wp_error( $head ) ) { return $head; }
		return array(
			'path' => (string) $path,
			'offset' => (int) $head['offset'],
			'row_number' => 0,
			'header' => $head['header'],
			'shared' => $shared,
			'max_rows' => max( 1, min( self::MAX_ROWS, absint( $max_rows ) ) ),
			'complete' => false,
		);
	}

	/** @return array{state:array<string,mixed>,records:array<int,array<string,mixed>>}|\WP_Error */
	public function continue_read( array $state, $limit = 100 ) {
		$valid = $this->validate_state( $state ); if ( is_wp_error( $valid ) ) { return $valid; }
		if ( ! empty( $state['complete'] ) ) { return array( 'state' => $state, 'records' => array() ); }
		$handle = @fopen( (string) $state['path'], 'rb' );
		if ( false === $handle || 0 !== fseek( $handle, (int) $state['offset'], SEEK_SET ) ) { if ( is_resource( $handle ) ) { fclose( $handle ); } return new \WP_Error( 'certloom_bulk_csv_resume_failed', __( 'Certloom could not resume the protected Bulk Issuance CSV.', 'certloom' ) ); }
		$limit = max( 1, min( 250, absint( $limit ) ) ); $records = array(); $read = 0;
		while ( $read < $limit ) {
			$raw = fgetcsv( $handle, 0, ',', '"', '' ); if ( false === $raw ) { break; }
			if ( $this->blank( $raw ) ) { $state['offset'] = (int) ftell( $handle ); continue; }
			$state['row_number'] = absint( $state['row_number'] ) + 1;
			if ( $state['row_number'] > absint( $state['max_rows'] ) ) { fclose( $handle ); return new \WP_Error( 'certloom_bulk_csv_row_limit', sprintf( /* translators: %d: credential count. */ __( 'Bulk Issuance is limited to %d credential rows per reviewed job in this release.', 'certloom' ), absint( $state['max_rows'] ) ) ); }
			$request = $this->map_row( $raw, (array) $state['header'], (array) $state['shared'] );
			$records[] = is_wp_error( $request ) ? array( 'row_number' => absint( $state['row_number'] ), 'error' => $request ) : array( 'row_number' => absint( $state['row_number'] ), 'request' => $request );
			$read++;
			$pos = ftell( $handle ); if ( false === $pos ) { fclose( $handle ); return new \WP_Error( 'certloom_bulk_csv_position_failed', __( 'Certloom could not save the Bulk Issuance CSV position safely.', 'certloom' ) ); }
			$state['offset'] = (int) $pos;
		}
		$size = @filesize( (string) $state['path'] );
		$state['complete'] = feof( $handle ) || ( false !== $size && (int) $state['offset'] >= (int) $size ); fclose( $handle );
		return array( 'state' => $state, 'records' => $records );
	}

	/** @return array<int,string> */
	public function identity_keys( array $request ) {
		if ( 'existing' === (string) ( $request['recipient_mode'] ?? '' ) ) { $id = absint( $request['recipient_id'] ?? 0 ); return $id ? array( 'recipient:' . $id ) : array(); }
		$new = isset( $request['new_recipient'] ) && is_array( $request['new_recipient'] ) ? $request['new_recipient'] : array(); $keys = array();
		$email = strtolower( trim( (string) ( $new['email'] ?? '' ) ) ); $external = trim( (string) ( $new['external_id'] ?? '' ) );
		if ( '' !== $email ) { $keys[] = 'email:' . hash( 'sha256', $email ); }
		if ( '' !== $external ) { $keys[] = 'external:' . hash( 'sha256', $external ); }
		return $keys;
	}

	/** @return array<int,string>|\WP_Error */
	private function normalize_header( array $raw ) {
		if ( empty( $raw ) || count( $raw ) > self::MAX_COLUMNS ) { return new \WP_Error( 'certloom_bulk_csv_columns_invalid', __( 'The Bulk Issuance CSV header has an unsupported number of columns.', 'certloom' ) ); }
		$allowed = array( 'recipient_id', 'registration_id', 'display_name', 'first_name', 'middle_name', 'last_name', 'email', 'external_id', 'grade', 'certificate_number' );
		$out = array(); $seen = array();
		foreach ( array_values( $raw ) as $i => $value ) {
			$value = null === $value ? '' : (string) $value; if ( 0 === $i ) { $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value ); }
			if ( strlen( $value ) > self::MAX_CELL_BYTES || ! $this->valid_utf8( $value ) || false !== strpos( $value, "\0" ) ) { return new \WP_Error( 'certloom_bulk_csv_header_invalid', __( 'The Bulk Issuance CSV header contains invalid or oversized text.', 'certloom' ) ); }
			$key = strtolower( trim( $value ) ); $key = str_replace( array( ' ', '-' ), '_', $key );
			if ( 0 === strpos( $key, 'custom:' ) ) { $custom = substr( $key, 7 ); if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $custom ) ) { return new \WP_Error( 'certloom_bulk_csv_custom_header_invalid', __( 'Custom Bulk Issuance columns must use custom:machine_key with a 1–64 character machine key.', 'certloom' ) ); } $key = 'custom:' . $custom; }
			elseif ( ! in_array( $key, $allowed, true ) ) { return new \WP_Error( 'certloom_bulk_csv_header_unknown', sprintf( /* translators: %s: value. */ __( 'Unsupported Bulk Issuance CSV column: %s.', 'certloom' ), $value ) ); }
			if ( isset( $seen[ $key ] ) ) { return new \WP_Error( 'certloom_bulk_csv_duplicate_header', __( 'The Bulk Issuance CSV contains duplicate column names.', 'certloom' ) ); }
			$seen[ $key ] = true; $out[] = $key;
		}
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function map_row( array $raw, array $header, array $shared ) {
		if ( count( $raw ) > count( $header ) ) { return new \WP_Error( 'certloom_bulk_csv_row_columns_invalid', __( 'A Bulk Issuance CSV row contains more values than its header.', 'certloom' ) ); }
		$values = array(); foreach ( $header as $i => $key ) { $v = array_key_exists( $i, $raw ) && null !== $raw[ $i ] ? (string) $raw[ $i ] : ''; if ( strlen( $v ) > self::MAX_CELL_BYTES || ! $this->valid_utf8( $v ) || false !== strpos( $v, "\0" ) ) { return new \WP_Error( 'certloom_bulk_csv_cell_invalid', __( 'A Bulk Issuance CSV cell contains invalid or oversized text.', 'certloom' ) ); } $v = trim( $v ); if ( '' !== $v && $this->formula_like( $v ) ) { return new \WP_Error( 'certloom_bulk_csv_formula_like', __( 'Bulk Issuance rejects spreadsheet-formula-like cell values. Store the intended literal value without a leading =, +, -, or @ character.', 'certloom' ) ); } $values[ $key ] = $v; }
		$recipient_id = $this->identifier( $values['recipient_id'] ?? '' );
		$registration_id = $this->identifier( $values['registration_id'] ?? '' );
		if ( is_wp_error( $recipient_id ) || is_wp_error( $registration_id ) ) { return is_wp_error( $recipient_id ) ? $recipient_id : $registration_id; }
		if ( $registration_id && ! $recipient_id ) { return new \WP_Error( 'certloom_bulk_csv_registration_recipient_required', __( 'An exact Program registration needs its existing Recipient ID in the same CSV row.', 'certloom' ) ); }
		$new_fields = array( 'display_name', 'first_name', 'middle_name', 'last_name', 'email', 'external_id' ); $has_new = false; foreach ( $new_fields as $key ) { if ( '' !== (string) ( $values[ $key ] ?? '' ) ) { $has_new = true; } }
		if ( $recipient_id && $has_new ) { return new \WP_Error( 'certloom_bulk_csv_recipient_ambiguous', __( 'Use either recipient_id or new Recipient identity columns in one Bulk Issuance row, not both.', 'certloom' ) ); }
		if ( ! $recipient_id && ! $has_new ) { return new \WP_Error( 'certloom_bulk_csv_recipient_required', __( 'Each Bulk Issuance row must provide recipient_id or new Recipient identity fields.', 'certloom' ) ); }
		$new = array(); foreach ( $new_fields as $key ) { $new[ $key ] = (string) ( $values[ $key ] ?? '' ); }
		if ( ! $recipient_id && '' === trim( $new['display_name'] ) ) { return new \WP_Error( 'certloom_bulk_csv_recipient_name_required', __( 'New Bulk Issuance recipients require display_name.', 'certloom' ) ); }
		if ( ! $recipient_id && '' === trim( $new['email'] ) && '' === trim( $new['external_id'] ) ) { return new \WP_Error( 'certloom_bulk_csv_recipient_identity_required', __( 'New Bulk Issuance recipients require email or external_id for deterministic identity resolution.', 'certloom' ) ); }
		$custom = array(); foreach ( $values as $key => $value ) { if ( 0 === strpos( $key, 'custom:' ) && '' !== $value ) { $custom[ substr( $key, 7 ) ] = $value; } } ksort( $custom, SORT_STRING );
		return array(
			'recipient_mode' => $recipient_id ? 'existing' : 'new', 'recipient_id' => $recipient_id, 'new_recipient' => $new,
			'registration_id' => $registration_id,
			'program_id' => $shared['program_id'] ?? 0, 'batch_id' => $shared['batch_id'] ?? 0, 'template_id' => $shared['template_id'] ?? 0,
			'valid_from' => (string) ( $shared['valid_from'] ?? '' ), 'expiry_mode' => (string) ( $shared['expiry_mode'] ?? 'program' ), 'expires_at' => (string) ( $shared['expires_at'] ?? '' ),
			'grade' => '' !== (string) ( $values['grade'] ?? '' ) ? (string) $values['grade'] : (string) ( $shared['grade'] ?? '' ),
			'display_number' => (string) ( $values['certificate_number'] ?? '' ), 'custom_merge_fields' => $custom,
		);
	}

	/** IDs must not silently truncate decimals, malformed values or integer overflow. */
	private function identifier( $value ) {
		if ( '' === $value ) { return 0; }
		$canonical = ltrim( $value, '0' ); if ( '' === $canonical ) { $canonical = '0'; }
		if ( ! preg_match( '/^[0-9]+$/D', $value ) || strlen( $canonical ) > strlen( (string) PHP_INT_MAX ) || (string) (int) $canonical !== $canonical ) { return new \WP_Error( 'certloom_bulk_csv_identity_invalid', __( 'Recipient and registration IDs must be whole numbers copied from Certloom. Do not use a customer-entered ID field as a record ID.', 'certloom' ) ); }
		return (int) $canonical;
	}

	/** @return true|\WP_Error */
	private function validate_state( array $state ) { $path = (string) ( $state['path'] ?? '' ); if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) || ! isset( $state['header'], $state['shared'], $state['offset'], $state['row_number'], $state['max_rows'] ) || ! is_array( $state['header'] ) || ! is_array( $state['shared'] ) ) { return new \WP_Error( 'certloom_bulk_csv_state_invalid', __( 'The saved Bulk Issuance CSV state is incomplete or unavailable.', 'certloom' ) ); } return true; }
	private function blank( array $row ) { foreach ( $row as $v ) { if ( null !== $v && '' !== trim( (string) $v ) ) { return false; } } return true; }
	private function valid_utf8( $v ) { return '' === (string) $v || 1 === @preg_match( '//u', (string) $v ); }
	private function formula_like( $v ) { return (bool) preg_match( '/^[\x20\x09]*[=+\-@]/u', (string) $v ); }
}
