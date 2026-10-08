<?php
namespace Certloom\Application\Fields;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit field ownership and collection policy, independent of requirements.
 *
 * An omitted policy retains the legacy value source and never grants account
 * edit permission. The catalog owns field semantics; scoped requirements still
 * determine which fields are needed for a particular issuance context.
 */
final class FieldCollectionPolicy {
	const KEY = 'collection_policy';
	const SCHEMA = 'certloom-field-collection';
	const VERSION = 1;
	const LIFETIMES = array( 'recipient', 'registration', 'achievement', 'program' );
	const WRITERS = array( 'recipient', 'staff', 'source' );
	const TIMINGS = array( 'profile', 'signup', 'registration', 'before_issue', 'never' );

	/** @return array<string,mixed>|\WP_Error */
	public function for_definition( array $field ) {
		if ( array_key_exists( self::KEY, $field ) ) {
			return $this->normalize( $field[ self::KEY ], $field );
		}
		$key = (string) ( $field['key'] ?? '' );
		$source = ScopedFieldContract::value_source( $key );
		return array(
			'_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => self::VERSION, 'configured' => false ),
			'lifetime' => 'recipient' === $source ? 'recipient' : 'achievement',
			'writer' => 'staff',
			'collect_when' => 'never',
			'approval_required' => false,
			'show_on_certificate' => false,
			'help' => '',
		);
	}

	/**
	 * Validate the persisted envelope. Do not coerce malformed data into an
	 * editable field: a broken policy must fail closed on reads and writes.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>|\WP_Error
	 */
	public function normalize( $input, array $field ) {
		if ( ! is_array( $input ) || ! isset( $input['_meta'] ) || ! is_array( $input['_meta'] ) ) {
			return $this->invalid();
		}
		$allowed = array( '_meta', 'lifetime', 'writer', 'collect_when', 'approval_required', 'show_on_certificate', 'help' );
		if ( array_diff( array_keys( $input ), $allowed ) || array_diff( array_keys( $input['_meta'] ), array( 'schema', 'schema_version', 'configured' ) ) || self::SCHEMA !== ( $input['_meta']['schema'] ?? null ) || self::VERSION !== ( $input['_meta']['schema_version'] ?? null ) || true !== ( $input['_meta']['configured'] ?? null ) ) {
			return $this->invalid();
		}
		foreach ( array( 'lifetime' => self::LIFETIMES, 'writer' => self::WRITERS, 'collect_when' => self::TIMINGS ) as $key => $options ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || ! in_array( $input[ $key ], $options, true ) ) { return $this->invalid(); }
		}
		foreach ( array( 'approval_required', 'show_on_certificate' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || ! is_bool( $input[ $key ] ) ) { return $this->invalid(); }
		}
		$help = $input['help'] ?? '';
		if ( ! is_string( $help ) || $this->length( $help ) > 400 ) { return $this->invalid(); }
		$key = (string) ( $field['key'] ?? '' );
		if ( ! $this->supports_policy( $field ) ) {
			return new \WP_Error( 'certloom_field_collection_protected', __( 'This field is managed by its existing identity or system source. Its collection policy cannot be replaced.', 'certloom' ) );
		}
		$recipient = 'recipient' === $input['writer'];
		if ( $recipient && ( ! in_array( $input['lifetime'], array( 'recipient', 'registration' ), true ) || 'never' === $input['collect_when'] || 'internal' === ( $field['sensitivity'] ?? '' ) ) ) {
			return new \WP_Error( 'certloom_field_collection_writer_invalid', __( 'Recipients may supply only permitted personal or Program registration details. Results and Program defaults must come from staff or a trusted source.', 'certloom' ) );
		}
		if ( $recipient && in_array( $key, array( 'grade', 'score', 'result', 'marks_percentage' ), true ) ) {
			return new \WP_Error( 'certloom_field_collection_result_protected', __( 'Scores, grades and results must come from authorized staff or a trusted source.', 'certloom' ) );
		}
		if ( ! $recipient && ( 'never' !== $input['collect_when'] || $input['approval_required'] ) ) {
			return new \WP_Error( 'certloom_field_collection_timing_invalid', __( 'Staff and source-owned fields are not collected through recipient forms.', 'certloom' ) );
		}
		if ( $recipient && 'registration' === $input['lifetime'] && in_array( $input['collect_when'], array( 'profile', 'signup' ), true ) ) {
			return new \WP_Error( 'certloom_field_collection_scope_invalid', __( 'Choose a collection step that matches reusable personal details or a specific Program registration.', 'certloom' ) );
		}
		if ( $input['show_on_certificate'] && ( 'internal' === ( $field['sensitivity'] ?? '' ) || ! in_array( $field['public_exposure'] ?? 'private', array( 'profile', 'both' ), true ) ) ) {
			return new \WP_Error( 'certloom_field_collection_artwork_private', __( 'This field is private. Review its public exposure before choosing to show it on certificate artwork.', 'certloom' ) );
		}
		return array(
			'_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => self::VERSION, 'configured' => true ),
			'lifetime' => $input['lifetime'],
			'writer' => $input['writer'],
			'collect_when' => $input['collect_when'],
			'approval_required' => $input['approval_required'],
			'show_on_certificate' => $input['show_on_certificate'],
			'help' => sanitize_text_field( $help ),
		);
	}

	/** Whether a validated policy grants collection through account forms. @return bool */
	public function supports_policy( array $field ) {
		return ScopedFieldContract::is_scopable_definition( $field ) && ! in_array( (string) ( $field['key'] ?? '' ), array( 'first_name', 'middle_name', 'last_name', 'email', 'external_id', \Certloom\Application\Recipient\RecipientAccountLink::KEY ), true );
	}

	/** Whether a validated policy grants collection through account forms. @return bool */
	public function recipient_editable( array $policy ) {
		return self::SCHEMA === ( $policy['_meta']['schema'] ?? '' ) && self::VERSION === ( $policy['_meta']['schema_version'] ?? null ) && true === ( $policy['_meta']['configured'] ?? false ) && 'recipient' === ( $policy['writer'] ?? '' ) && in_array( $policy['lifetime'] ?? '', array( 'recipient', 'registration' ), true ) && in_array( $policy['collect_when'] ?? '', array( 'profile', 'signup', 'registration', 'before_issue' ), true );
	}

	/** @return \WP_Error */
	private function invalid() {
		return new \WP_Error( 'certloom_field_collection_invalid', __( 'The field collection policy is invalid or uses an unsupported version. Review its configuration before collecting or issuing information.', 'certloom' ) );
	}

	/** @return int */
	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
