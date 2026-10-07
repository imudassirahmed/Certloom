<?php
namespace Certloom\Application\Issuance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Typed source/staff evidence. A registration or recipient edit is never qualification. */
final class IssuanceEvidence {
	const SCHEMA = 'certloom-issuance-evidence';
	const VERSION = 1;
	public function normalize( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( '_meta', 'eligibility', 'checked_at', 'valid_until', 'fields' ) ) || ! is_array( $input['_meta'] ?? null ) || array_diff( array_keys( $input['_meta'] ), array( 'schema', 'schema_version' ) ) || self::SCHEMA !== ( $input['_meta']['schema'] ?? null ) || self::VERSION !== ( $input['_meta']['schema_version'] ?? null ) || ! in_array( $input['eligibility'] ?? null, array( 'unknown', 'eligible', 'not_eligible' ), true ) || ! array_key_exists( 'checked_at', $input ) || ! array_key_exists( 'valid_until', $input ) || ! is_array( $input['fields'] ?? null ) || count( $input['fields'] ) > 128 ) { return $this->invalid(); }
		foreach ( array( 'checked_at', 'valid_until' ) as $key ) {
			if ( null !== $input[ $key ] && ! $this->date( $input[ $key ] ) ) { return $this->invalid(); }
		}
		if ( 'unknown' !== $input['eligibility'] && null === $input['checked_at'] ) { return $this->invalid(); }
		if ( null !== $input['valid_until'] && ( null === $input['checked_at'] || $input['valid_until'] <= $input['checked_at'] ) ) { return $this->invalid(); }
		$fields = array();
		foreach ( $input['fields'] as $key => $field ) {
			if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 64 || sanitize_key( $key ) !== $key || ! is_array( $field ) || array_diff( array_keys( $field ), array( 'value', 'writer', 'revision' ) ) || ! is_string( $field['value'] ?? null ) || strlen( $field['value'] ) > 8192 || ! in_array( $field['writer'] ?? null, array( 'staff', 'source' ), true ) || ! is_string( $field['revision'] ?? null ) || '' === $field['revision'] || strlen( $field['revision'] ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9_.:\/-]+$/D', $field['revision'] ) ) { return $this->invalid(); }
			$fields[ $key ] = array( 'value' => $field['value'], 'writer' => $field['writer'], 'revision' => $field['revision'] );
		}
		ksort( $fields, SORT_STRING );
		return array( '_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => self::VERSION ), 'eligibility' => $input['eligibility'], 'checked_at' => $input['checked_at'], 'valid_until' => $input['valid_until'], 'fields' => $fields );
	}

	public function is_current( array $evidence, $now ) {
		$valid = $this->normalize( $evidence );
		return ! is_wp_error( $valid ) && $this->date( $now ) && 'eligible' === $valid['eligibility'] && $valid['checked_at'] <= $now && ( null === $valid['valid_until'] || $now < $valid['valid_until'] );
	}
	private function date( $value ) {
		if ( ! is_string( $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return false !== $date && ( ! is_array( $errors ) || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
	private function invalid() { return new \WP_Error( 'certloom_issuance_evidence_invalid', __( 'The issuance evidence is incomplete or uses an unsupported source contract.', 'certloom' ) ); }
}
