<?php
namespace Certloom\Application\Fields;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit typed staff defaults; unrelated Program metadata never becomes credential input. */
final class ProgramFieldDefaults {
	const KEY = 'certloom_field_defaults';
	const SCHEMA = 'certloom-program-field-defaults';
	public function normalize( $input, array $catalog ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( '_meta', 'values' ) ) || ! is_array( $input['_meta'] ?? null ) || array_diff( array_keys( $input['_meta'] ), array( 'schema', 'schema_version' ) ) || self::SCHEMA !== ( $input['_meta']['schema'] ?? null ) || 1 !== ( $input['_meta']['schema_version'] ?? null ) || ! is_array( $input['values'] ?? null ) || count( $input['values'] ) > 128 ) { return $this->invalid(); }
		$values = array();
		foreach ( $input['values'] as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $catalog[ $key ][ FieldCollectionPolicy::KEY ] ) || ( null !== $value && ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) || ( is_float( $value ) && ! is_finite( $value ) ) ) { return $this->invalid(); }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $catalog[ $key ] );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( 'program' !== $policy['lifetime'] || 'staff' !== $policy['writer'] ) { return $this->invalid(); }
			$clean = \Ecapla_CertVerify_Validator::custom_field_value( $value, $catalog[ $key ] );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( 'textarea' === ( $catalog[ $key ]['type'] ?? '' ) ) { $clean = str_replace( array( "\r\n", "\r" ), "\n", (string) $clean ); }
			// The legacy date validator returns an empty value for an invalid date.
			// Reject that conversion here so an attempted edit cannot silently clear a default.
			if ( 'date' === ( $catalog[ $key ]['type'] ?? '' ) && '' !== trim( (string) $value ) && '' === (string) $clean ) { return $this->invalid(); }
			if ( 'number' === ( $catalog[ $key ]['type'] ?? '' ) && '' !== (string) $clean && ! is_finite( (float) $clean ) ) { return $this->invalid(); }
			if ( strlen( (string) $clean ) > 8192 ) { return $this->invalid(); }
			$values[ $key ] = (string) $clean;
		}
		ksort( $values, SORT_STRING );
		return array( '_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => 1 ), 'values' => $values );
	}
	public function from_program( array $program, array $catalog ) {
		if ( ! array_key_exists( self::KEY, $program['metadata'] ?? array() ) ) { return array( '_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => 1 ), 'values' => array() ); }
		return $this->normalize( $program['metadata'][ self::KEY ], $catalog );
	}
	/** Fields with an explicit, valid organization-owned Program policy. */
	public function editable_fields( array $catalog ) {
		$fields = array();
		foreach ( $catalog as $key => $field ) {
			if ( ! is_string( $key ) || ! is_array( $field ) || ! array_key_exists( FieldCollectionPolicy::KEY, $field ) ) { continue; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $field );
			if ( ! is_wp_error( $policy ) && 'program' === $policy['lifetime'] && 'staff' === $policy['writer'] ) { $fields[ $key ] = $field; }
		}
		return $fields;
	}
	/**
	 * Translate a complete HTML form without promoting arbitrary metadata.
	 * Omitted controls preserve existing defaults; a truncated form is rejected.
	 * WordPress slashes must be removed by the transport before calling this method.
	 */
	public function with_form_metadata( array $metadata, array $form, array $catalog ) {
		if ( array_key_exists( 'program_form_version', $form ) && ( '1' !== $form['program_form_version'] || '1' !== ( $form['program_form_complete'] ?? null ) ) ) {
			return new \WP_Error( 'certloom_program_form_incomplete', __( 'The complete Program form was not received. Your saved settings are unchanged. Reload the page and try again.', 'certloom' ) );
		}
		if ( ! array_key_exists( 'program_field_defaults_present', $form ) ) {
			return array_key_exists( 'program_field_default', $form ) ? $this->invalid() : $metadata;
		}
		if ( '1' !== $form['program_field_defaults_present'] || '1' !== ( $form['program_form_complete'] ?? null ) ) {
			return new \WP_Error( 'certloom_program_form_incomplete', __( 'The complete Program form was not received. Your saved settings are unchanged. Reload the page and try again.', 'certloom' ) );
		}
		$values = $form['program_field_default'] ?? array();
		if ( ! is_array( $values ) ) { return $this->invalid(); }
		foreach ( $values as $value ) { if ( ! is_string( $value ) ) { return $this->invalid(); } }
		$defaults = $this->normalize( array( '_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => 1 ), 'values' => $values ), $catalog );
		if ( is_wp_error( $defaults ) ) { return $defaults; }
		$defaults['values'] = array_filter( $defaults['values'], static function ( $value ) { return '' !== $value; } );
		$metadata[ self::KEY ] = $defaults;
		return $metadata;
	}
	private function invalid() { return new \WP_Error( 'certloom_program_field_defaults_invalid', __( 'Review the Program’s default field values before issuing.', 'certloom' ) ); }
}
