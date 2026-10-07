<?php
namespace Certloom\Application\Setup;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\MergeSnapshotBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Display guidance only. Neither a recommendation nor a token grants public exposure. */
final class WizardDesignCoverage {
	public function inspect( ?array $template, array $keys, array $catalog ) {
		$tokens = null === $template ? array() : ( new MergeSnapshotBuilder() )->required_tokens( $template['published_revision']['layout'] ?? array() );
		$shown = array();
		foreach ( $tokens as $token ) {
			$key = $this->field_key( $token, $catalog );
			if ( null !== $key ) { $shown[ $key ] = true; }
		}
		$selected = array_fill_keys( $keys, true );
		$rows = array(); $missing = 0; $privacy = 0; $unreviewed = 0;
		// Show chosen fields first, then references found only in the design. This
		// is advice, not a substitute for resolving inherited Program requirements.
		foreach ( array_values( array_unique( array_merge( $keys, array_keys( $shown ) ) ) ) as $key ) {
			$field = $catalog[ $key ] ?? null;
			if ( null === $field ) {
				if ( isset( $shown[ $key ] ) ) { ++$unreviewed; $rows[] = array( 'key' => $key, 'label' => $key, 'status' => 'undefined', 'message' => __( 'This design uses a field that has not been defined. Add it in Fields Manager and choose its source, or remove it from the design.', 'certloom' ) ); }
				continue;
			}
			if ( ! ScopedFieldContract::is_scopable_definition( $field ) ) { continue; }
			$private = 'internal' === ( $field['sensitivity'] ?? '' ) || ! in_array( $field['public_exposure'] ?? 'private', array( 'profile', 'both' ), true );
			$status = isset( $shown[ $key ] ) ? ( $private ? 'private_on_design' : 'shown' ) : ( $private ? 'private' : ( null === $template ? 'choose_design' : 'missing' ) );
			if ( 'shown' === $status && ! isset( $selected[ $key ] ) ) { $status = 'design_only'; ++$unreviewed; }
			if ( 'missing' === $status ) { ++$missing; }
			if ( 'private_on_design' === $status ) { ++$privacy; }
			$messages = array( 'private' => __( 'Private detail: collect it without placing it on public artwork.', 'certloom' ), 'private_on_design' => __( 'The design references a private detail. Review its exposure or remove it from the design.', 'certloom' ), 'shown' => __( 'Included in the selected design.', 'certloom' ), 'missing' => __( 'Not on this design. Add it now, choose another design, or customize it later in Design Center.', 'certloom' ) );
			$messages['choose_design'] = __( 'Choose a published design to check where this detail appears.', 'certloom' );
			$messages['design_only'] = __( 'Used by this design. Review where its value comes from and include it in your Program fields if needed. Inherited field rules are checked when you save.', 'certloom' );
			$rows[] = array( 'key' => $key, 'label' => $field['label'], 'status' => $status, 'message' => $messages[ $status ] );
		}
		return array( 'rows' => $rows, 'missing_count' => $missing, 'privacy_count' => $privacy, 'unreviewed_count' => $unreviewed, 'has_design' => null !== $template );
	}

	/** Match the historical namespaces checked by TemplateFieldCompatibility. */
	private function field_key( $token, array $catalog ) {
		$key = ScopedFieldContract::field_key_from_merge_token( $token );
		if ( null !== $key ) { return $key; }
		if ( isset( $catalog[ $token ] ) ) { return $token; }
		foreach ( array( 'recipient.', 'credential.', 'custom.', 'credential:' ) as $prefix ) {
			if ( 0 !== strpos( $token, $prefix ) ) { continue; }
			$key = substr( $token, strlen( $prefix ) );
			if ( isset( $catalog[ $key ] ) ) { return $key; }
		}
		return null;
	}
}
