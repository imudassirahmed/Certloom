<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Fields\FieldCollectionPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Source mappings never grant write access to personal details, staff results or defaults. */
final class WebhookFieldMapping {
	public function validate( array $mapping, array $contract, array $catalog ) {
		$mapping = ( new WebhookV2Contract() )->mapping( $mapping );
		if ( is_wp_error( $mapping ) ) { return $mapping; }
		$applicable = array_column( $contract['fields'] ?? array(), null, 'key' );
		foreach ( $mapping as $target ) {
			if ( ! isset( $applicable[ $target ], $catalog[ $target ][ FieldCollectionPolicy::KEY ] ) ) { return $this->invalid(); }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $catalog[ $target ] );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( 'achievement' !== $policy['lifetime'] || 'source' !== $policy['writer'] ) { return $this->invalid(); }
		}
		$targets = array_fill_keys( array_values( $mapping ), true );
		foreach ( $applicable as $key => $row ) {
			if ( empty( $row['required'] ) ) { continue; }
			if ( isset( $catalog[ $key ][ FieldCollectionPolicy::KEY ] ) ) {
				$policy = ( new FieldCollectionPolicy() )->for_definition( $catalog[ $key ] );
				if ( is_wp_error( $policy ) ) { return $policy; }
				if ( ( new FieldCollectionPolicy() )->recipient_editable( $policy ) || ( 'program' === $policy['lifetime'] && 'staff' === $policy['writer'] ) ) { continue; }
				if ( 'achievement' === $policy['lifetime'] && 'source' === $policy['writer'] && isset( $targets[ $key ] ) ) { continue; }
			} elseif ( 'recipient' === ( $row['value_source'] ?? '' ) ) { continue; }
			return $this->invalid();
		}
		return $mapping;
	}

	public function values( array $evidence, array $catalog ) {
		foreach ( $evidence['fields'] as $key => $field ) {
			if ( ! isset( $catalog[ $key ] ) ) { return $this->invalid(); }
			$clean = \Ecapla_CertVerify_Validator::custom_field_value( $field['value'], $catalog[ $key ] );
			if ( is_wp_error( $clean ) ) { return $clean; }
			$evidence['fields'][ $key ]['value'] = (string) $clean;
		}
		return $evidence;
	}
	private function invalid() { return new \WP_Error( 'certloom_webhook_v2_field_mapping_invalid', __( 'Map every required app result to an applicable source-owned achievement field. Customer details, staff results and shared Program values use their own source.', 'certloom' ) ); }
}
