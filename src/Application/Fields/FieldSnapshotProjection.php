<?php
namespace Certloom\Application\Fields;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Newly collected details are retained only as assigned, resolved credential facts. */
final class FieldSnapshotProjection {
	/** Bind collection/privacy changes, including fields unrelated to this issuance, to review. */
	public function catalog_contract( array $catalog ) {
		$out = array();
		foreach ( $catalog as $key => $definition ) {
			if ( ! array_key_exists( FieldCollectionPolicy::KEY, $definition ) ) { continue; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			$out[ $key ] = array( 'type' => $definition['type'] ?? 'text', 'sensitivity' => $definition['sensitivity'] ?? 'personal', 'public_exposure' => $definition['public_exposure'] ?? 'private', 'policy' => $policy );
		}
		ksort( $out, SORT_STRING ); return $out;
	}

	/** Preserve historical legacy behavior while removing unrelated managed account details. */
	public function strip_managed( array $recipient, array $one_off, array $catalog ) {
		$custom = isset( $recipient['custom_fields'] ) && is_array( $recipient['custom_fields'] ) ? $recipient['custom_fields'] : array();
		foreach ( $catalog as $key => $definition ) {
			if ( ! array_key_exists( FieldCollectionPolicy::KEY, $definition ) ) { continue; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			unset( $custom[ $key ], $one_off[ $key ] );
		}
		$recipient['custom_fields'] = $custom;
		return array( 'recipient' => $recipient, 'one_off' => $one_off );
	}

	/** Private assigned facts may be needed for readiness, but cannot become artwork inputs. */
	public function artwork_values( array $values, array $catalog ) {
		foreach ( $values as $key => $value ) {
			$definition = $catalog[ $key ] ?? null;
			if ( ! is_array( $definition ) || ! array_key_exists( FieldCollectionPolicy::KEY, $definition ) ) { continue; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( ! $this->artwork_allowed( $definition ) ) { unset( $values[ $key ] ); }
		}
		return $values;
	}

	public function artwork_allowed( array $definition ) {
		return 'internal' !== ( $definition['sensitivity'] ?? '' ) && in_array( $definition['public_exposure'] ?? 'private', array( 'profile', 'both' ), true );
	}
}
