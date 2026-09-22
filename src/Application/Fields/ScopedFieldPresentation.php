<?php
namespace Certloom\Application\Fields;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Privacy-safe presentation of immutable FLD-02 Version snapshots.
 *
 * Public exposure is deliberately the intersection of the frozen issuance
 * policy and the current global policy. Current configuration may tighten old
 * data immediately, but cannot retroactively loosen the privacy ceiling that
 * existed when the credential was issued.
 */
final class ScopedFieldPresentation {
	/** @return array<int,array<string,mixed>> */
	public function public_fields( array $snapshot, $context = 'verification' ) {
		$context = 'profile' === self::key( $context ) ? 'profile' : 'verification';
		$catalog = $this->catalog();
		$out = array();
		foreach ( $this->snapshot_rows( $snapshot ) as $row ) {
			$key = self::key( $row['key'] ?? '' );
			if ( '' === $key || ! array_key_exists( 'value', $row ) ) { continue; }
			$current = $catalog[ $key ] ?? null;
			if ( ! is_array( $current ) || ! $this->current_definition_public_safe( $current ) ) { continue; }
			if ( ! $this->exposure_allows( $row['public_exposure'] ?? 'private', $context ) || ! $this->exposure_allows( $current['public_exposure'] ?? 'private', $context ) ) { continue; }
			$value = $this->display_value( $row['value'] );
			if ( '' === $value ) { continue; }
			$out[] = array(
				'key'        => $key,
				'label'      => (string) ( $row['label'] ?? $current['label'] ?? $key ),
				'value'      => $value,
				'type'       => self::key( $row['type'] ?? $current['type'] ?? 'text' ),
				'required'   => ! empty( $row['required'] ),
				'value_source' => self::key( $row['value_source'] ?? ScopedFieldContract::value_source( $key ) ),
				'is_status'  => false,
			);
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function recipient_fields( array $snapshot ) {
		$catalog = $this->catalog();
		$out = array();
		foreach ( $this->snapshot_rows( $snapshot ) as $row ) {
			$key = self::key( $row['key'] ?? '' );
			if ( '' === $key || ! array_key_exists( 'value', $row ) ) { continue; }
			$frozen_sensitivity = self::key( $row['sensitivity'] ?? 'personal' );
			if ( 'internal' === $frozen_sensitivity ) { continue; }
			$current = $catalog[ $key ] ?? null;
			if ( is_array( $current ) && ( ! empty( $current['locked_visibility'] ) || 'internal' === self::key( $current['sensitivity'] ?? '' ) ) ) { continue; }
			$value = $this->display_value( $row['value'] );
			if ( '' === $value ) { continue; }
			$out[] = array(
				'key'        => $key,
				'label'      => (string) ( $row['label'] ?? ( is_array( $current ) ? ( $current['label'] ?? $key ) : $key ) ),
				'value'      => $value,
				'type'       => self::key( $row['type'] ?? ( is_array( $current ) ? ( $current['type'] ?? 'text' ) : 'text' ) ),
				'required'   => ! empty( $row['required'] ),
				'value_source' => self::key( $row['value_source'] ?? ScopedFieldContract::value_source( $key ) ),
			);
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function snapshot_rows( array $snapshot ) {
		$fields = isset( $snapshot['fields'] ) && is_array( $snapshot['fields'] ) ? $snapshot['fields'] : array();
		$contract = isset( $fields['contract']['fields'] ) && is_array( $fields['contract']['fields'] ) ? $fields['contract']['fields'] : array();
		$values = isset( $fields['values'] ) && is_array( $fields['values'] ) ? $fields['values'] : array();
		$out = array(); $seen = array();
		foreach ( $contract as $definition ) {
			if ( ! is_array( $definition ) ) { continue; }
			$key = sanitize_key( (string) ( $definition['key'] ?? '' ) );
			if ( '' === $key || isset( $seen[ $key ] ) || ! array_key_exists( $key, $values ) ) { continue; }
			$seen[ $key ] = true;
			$definition['key'] = $key;
			$definition['value'] = $values[ $key ];
			$out[] = $definition;
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	private function catalog() {
		return class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
	}

	/** @return bool */
	private function current_definition_public_safe( array $field ) {
		return empty( $field['locked_visibility'] ) && 'internal' !== self::key( $field['sensitivity'] ?? '' );
	}

	/** @return bool */
	private function exposure_allows( $exposure, $context ) {
		$exposure = self::key( $exposure );
		return 'both' === $exposure || $context === $exposure;
	}

	/** @return string */
	private static function key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9_\-]/', '', $value );
	}

	/** @return string */
	private function display_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) { return trim( (string) $value ); }
		return '';
	}
}
