<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Display follows the enrollment context; write authority follows each field's lifetime. */
final class RecipientCollectionFields {
	public function select( array $context, $kind, $for_decision = false ) {
		if ( ! in_array( $kind, array( 'recipient', 'registration' ), true ) ) { return $this->invalid(); }
		$applicable = array();
		foreach ( $context['contract']['fields'] as $row ) { $applicable[ $row['key'] ] = true; }
		$out = array(); $policies = new FieldCollectionPolicy();
		foreach ( $context['catalog'] as $key => $definition ) {
			if ( ! array_key_exists( FieldCollectionPolicy::KEY, $definition ) ) { continue; }
			$policy = $policies->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( ! $policies->recipient_editable( $policy ) ) { continue; }
			if ( 'recipient' === $kind ) {
				if ( 'recipient' !== $policy['lifetime'] ) { continue; }
				if ( ! $for_decision && ( ! empty( $context['request_id'] ) ? ! isset( $applicable[ $key ] ) : ! in_array( $policy['collect_when'], array( 'profile', 'signup' ), true ) ) ) { continue; }
			} elseif ( ! isset( $applicable[ $key ] ) || ( $for_decision && 'registration' !== $policy['lifetime'] ) ) { continue; }
			$out[ $key ] = $definition;
		}
		return $out;
	}

	private function invalid() { return new \WP_Error( 'certloom_recipient_collection_context_invalid', __( 'Refresh the form to load its current field settings.', 'certloom' ) ); }
}
