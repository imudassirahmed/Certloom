<?php
namespace Certloom\Application\Fields;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Translate organization form choices into the same strict stored policy used by every reader. */
final class FieldCollectionForm {
	/** Null deliberately keeps the legacy source; malformed choices never grant edit permission. */
	public function normalize( $input, array $field ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'writer', 'lifetime', 'collect_when', 'approval_required', 'show_on_certificate', 'help' ) ) ) { return $this->invalid(); }
		foreach ( array( 'writer', 'lifetime', 'collect_when', 'help' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) { return $this->invalid(); }
		}
		if ( ! in_array( $input['writer'], array_merge( array( 'legacy' ), FieldCollectionPolicy::WRITERS ), true ) || ! in_array( $input['lifetime'], FieldCollectionPolicy::LIFETIMES, true ) || ! in_array( $input['collect_when'], FieldCollectionPolicy::TIMINGS, true ) ) { return $this->invalid(); }
		$flags = array();
		foreach ( array( 'approval_required', 'show_on_certificate' ) as $key ) {
			$value = $input[ $key ] ?? '0';
			if ( ! is_string( $value ) || ! in_array( $value, array( '0', '1' ), true ) ) { return $this->invalid(); }
			$flags[ $key ] = '1' === $value;
		}
		if ( 'legacy' === $input['writer'] ) { return null; }
		$recipient = 'recipient' === $input['writer'];
		return ( new FieldCollectionPolicy() )->normalize( array(
			'_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => FieldCollectionPolicy::VERSION, 'configured' => true ),
			'writer' => $input['writer'], 'lifetime' => $input['lifetime'],
			'collect_when' => $recipient ? $input['collect_when'] : 'never',
			'approval_required' => $recipient && $flags['approval_required'],
			'show_on_certificate' => $flags['show_on_certificate'], 'help' => $input['help'],
		), $field );
	}

	private function invalid() { return new \WP_Error( 'certloom_field_collection_form_invalid', __( 'Choose valid field collection settings before saving.', 'certloom' ) ); }
}
