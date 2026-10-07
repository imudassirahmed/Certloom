<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A recipient patch contains only explicitly editable, typed field values. */
final class RecipientFieldEditValidator {
	/** Validate a Program form without treating reusable details as registration values. */
	public function validate_form( $values, array $definitions, $kind ) {
		if ( ! is_array( $values ) || count( $values ) > 128 || ! in_array( $kind, array( 'recipient', 'registration' ), true ) ) { return $this->invalid(); }
		$patches = array( 'recipient' => array(), 'registration' => array() );
		$scopes = array( 'recipient' => array(), 'registration' => array() );
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $definitions[ $key ] ) ) { return $this->invalid(); }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definitions[ $key ] );
			if ( is_wp_error( $policy ) ) { return $policy; }
			$scope = $policy['lifetime'];
			if ( ! isset( $patches[ $scope ] ) || ( 'recipient' === $kind && 'recipient' !== $scope ) ) { return $this->invalid(); }
			$patches[ $scope ][ $key ] = $value; $scopes[ $scope ][ $key ] = $definitions[ $key ];
		}
		foreach ( $patches as $scope => $patch ) {
			$patches[ $scope ] = $this->validate( $patch, $scopes[ $scope ], $scope );
			if ( is_wp_error( $patches[ $scope ] ) ) { return $patches[ $scope ]; }
		}
		return $patches;
	}

	public function validate( $values, array $definitions, $kind ) {
		if ( ! is_array( $values ) || count( $values ) > 128 || ! in_array( $kind, array( 'recipient', 'registration' ), true ) ) { return $this->invalid(); }
		$out = array();
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $definitions[ $key ] ) || ! is_array( $definitions[ $key ] ) || ( null !== $value && ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) || ( is_float( $value ) && ! is_finite( $value ) ) ) { return $this->invalid(); }
			$definition = $definitions[ $key ];
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( ! ( new FieldCollectionPolicy() )->recipient_editable( $policy ) || $kind !== $policy['lifetime'] ) { return $this->invalid(); }
			$clean = \Ecapla_CertVerify_Validator::custom_field_value( $value, $definition );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( strlen( (string) $clean ) > 8192 ) { return $this->invalid(); }
			if ( class_exists( 'Ecapla_CertVerify_Settings' ) && 'approved_field' === \Ecapla_CertVerify_Settings::get( 'certificate_name_source', 'profile' ) && $key === \Ecapla_CertVerify_Settings::get( 'certificate_name_field_key', '' ) && ! ( new CertificateNamePolicy() )->valid_name( (string) $clean, true ) ) { return ( new CertificateNamePolicy() )->invalid_value(); }
			$out[ $key ] = (string) $clean;
		}
		return $out;
	}

	private function invalid() { return new \WP_Error( 'certloom_recipient_field_edit_forbidden', __( 'These changes include a field that this account cannot edit. Refresh the form and try again.', 'certloom' ), array( 'status' => 400 ) ); }
}
