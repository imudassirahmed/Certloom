<?php
namespace Certloom\Application\Issuance;

use Certloom\Domain\Rendering\TableDataContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable, bounded request data. Validation never rewrites its replay digest. */
final class IssuanceIntake {
	const SCHEMA = 'certloom-issuance-intake';
	const VERSION = 1;

	public function validate_request( $request ) {
		$ids = array( 'recipient_id', 'program_id', 'batch_id', 'registration_id', 'issuer_id', 'template_id', 'signer_id', 'certificate_image_id' );
		$text = array( 'credential_title' => 1020, 'grade' => 764, 'display_number' => 764 );
		$choices = array( 'recipient_mode' => array( 'existing' ), 'credential_basis' => array( 'program' ), 'title_source' => array( 'program', 'custom' ), 'presentation_mode' => array( 'design', 'image', 'details_only' ), 'design_source' => array( 'inherited', 'assigned', 'none' ), 'expiry_mode' => array( 'program', 'permanent', 'date' ) );
		$allowed = array_merge( $ids, array_keys( $text ), array_keys( $choices ), array( 'new_recipient', 'valid_from', 'expires_at', 'custom_merge_fields', 'design_field_aliases', 'repeating_data', 'verification_field_keys' ) );
		if ( ! is_array( $request ) || array_diff( array_keys( $request ), $allowed ) ) { return $this->invalid(); }
		foreach ( $ids as $key ) {
			if ( array_key_exists( $key, $request ) && ! $this->integer( $request[ $key ], in_array( $key, array( 'recipient_id', 'program_id' ), true ) ? 1 : 0 ) ) { return $this->invalid(); }
		}
		foreach ( $choices as $key => $values ) {
			if ( array_key_exists( $key, $request ) && ! in_array( $request[ $key ], $values, true ) ) { return $this->invalid(); }
		}
		foreach ( $text as $key => $limit ) {
			if ( array_key_exists( $key, $request ) && ! $this->text( $request[ $key ], $limit ) ) { return $this->invalid(); }
		}
		foreach ( array( 'valid_from', 'expires_at' ) as $key ) {
			if ( array_key_exists( $key, $request ) && ( ! is_string( $request[ $key ] ) || ( '' !== $request[ $key ] && ! $this->date( $request[ $key ] ) ) ) ) { return $this->invalid(); }
		}
		if ( array_key_exists( 'new_recipient', $request ) && array() !== $request['new_recipient'] ) { return $this->invalid(); }
		foreach ( array( 'custom_merge_fields', 'design_field_aliases' ) as $key ) {
			if ( ! array_key_exists( $key, $request ) ) { continue; }
			if ( ! is_array( $request[ $key ] ) || count( $request[ $key ] ) > 30 ) { return $this->invalid(); }
			$bytes = 0;
			foreach ( $request[ $key ] as $field => $value ) {
				if ( ! $this->key( $field ) || ( 'design_field_aliases' === $key ? ! $this->key( $value ) : ! $this->scalar( $value ) ) ) { return $this->invalid(); }
				$bytes += strlen( $field ) + strlen( (string) $value );
				if ( strlen( (string) $value ) > 8000 || $bytes > 32768 ) { return $this->invalid(); }
			}
		}
		if ( array_key_exists( 'verification_field_keys', $request ) && null !== $request['verification_field_keys'] ) {
			$list = $request['verification_field_keys'];
			if ( ! is_array( $list ) || count( $list ) > 128 || ( $list && array_keys( $list ) !== range( 0, count( $list ) - 1 ) ) ) { return $this->invalid(); }
			foreach ( $list as $key ) { if ( ! $this->key( $key ) ) { return $this->invalid(); } }
		}
		if ( array_key_exists( 'repeating_data', $request ) ) {
			$data = $request['repeating_data'];
			if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'subjects', 'results' ) ) ) { return $this->invalid(); }
			try {
				foreach ( $data as $key => $rows ) {
					if ( ! is_array( $rows ) ) { return $this->invalid(); }
					foreach ( $rows as $row ) {
						if ( ! is_array( $row ) ) { return $this->invalid(); }
						foreach ( $row as $cell ) { if ( ! $this->scalar( $cell ) ) { return $this->invalid(); } }
					}
					TableDataContract::normalize_rows( 'subjects' === $key ? TableDataContract::SUBJECTS : TableDataContract::RESULTS, $rows );
				}
			} catch ( \InvalidArgumentException $error ) { return $this->invalid(); }
		}
		return true;
	}

	public function envelope( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( '_meta', 'request', 'initial_evidence' ) ) || ! is_array( $input['_meta'] ?? null ) || array_diff( array_keys( $input['_meta'] ), array( 'schema', 'schema_version' ) ) || self::SCHEMA !== ( $input['_meta']['schema'] ?? null ) || self::VERSION !== ( $input['_meta']['schema_version'] ?? null ) ) { return $this->invalid(); }
		$request = $input['request'] ?? null;
		$valid = $this->validate_request( $request );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( 'existing' !== ( $request['recipient_mode'] ?? null ) || 'program' !== ( $request['credential_basis'] ?? null ) || ! isset( $request['recipient_id'], $request['program_id'], $request['batch_id'] ) ) { return $this->invalid(); }
		$evidence = ( new IssuanceEvidence() )->normalize( $input['initial_evidence'] ?? null );
		return is_wp_error( $evidence ) ? $evidence : $input;
	}

	/** Paused restore rows retain original IDs and original payload; they cannot execute. */
	public function from_row( array $row ) {
		$json = $row['intake_json'] ?? null;
		if ( ! is_string( $json ) || strlen( $json ) > 1024 * 1024 || ! is_string( $row['payload_sha256'] ?? null ) || ! hash_equals( $row['payload_sha256'], hash( 'sha256', $json ) ) ) { return $this->invalid(); }
		$input = json_decode( $json, true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() ) { return $this->invalid(); }
		$input = $this->envelope( $input );
		if ( is_wp_error( $input ) ) { return $input; }
		if ( 'paused' !== ( $row['restore_state'] ?? null ) ) {
			foreach ( array( 'recipient_id', 'program_id', 'batch_id' ) as $key ) {
				if ( (int) $input['request'][ $key ] !== (int) ( $row[ $key ] ?? 0 ) ) { return $this->invalid(); }
			}
			if ( isset( $input['request']['registration_id'] ) && (int) $input['request']['registration_id'] !== (int) ( $row['registration_id'] ?? 0 ) ) { return $this->invalid(); }
		}
		return $input;
	}

	/** Minimal tombstone left by privacy erasure, never executable. */
	public function is_erased( array $row ) {
		return in_array( $row['status'] ?? null, array( 'cancelled', 'issued' ), true ) && '{}' === ( $row['intake_json'] ?? null ) && null === ( $row['evidence_json'] ?? null ) && null === ( $row['source_ref'] ?? null ) && null === ( $row['review_sha256'] ?? null ) && null === ( $row['next_attempt_at'] ?? null );
	}

	private function integer( $value, $minimum ) { return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', (string) $value ) && strlen( (string) $value ) <= strlen( (string) PHP_INT_MAX ) && ( strlen( (string) $value ) < strlen( (string) PHP_INT_MAX ) || strcmp( (string) $value, (string) PHP_INT_MAX ) <= 0 ) && (int) $value >= $minimum; }
	private function key( $value ) { return is_string( $value ) && '' !== $value && strlen( $value ) <= 64 && sanitize_key( $value ) === $value; }
	private function scalar( $value ) { return ( null === $value || is_string( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) && $this->text( (string) $value, 8000 ); }
	private function text( $value, $limit ) { return is_string( $value ) && strlen( $value ) <= $limit && false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value ); }
	private function date( $value ) { return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ); }
	private function invalid() { return new \WP_Error( 'certloom_issuance_intake_invalid', __( 'This certificate request has invalid or changed intake data. Review its source before issuing.', 'certloom' ) ); }
}
