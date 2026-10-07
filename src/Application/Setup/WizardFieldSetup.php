<?php
namespace Certloom\Application\Setup;

use Certloom\Application\Fields\FieldCollectionForm;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reviewed setup intent. Building a plan never changes fields, Programs or recipient values. */
final class WizardFieldSetup {
	const SCHEMA = 'certloom-wizard-fields';
	const MAX_ROWS = 50;

	/** Reject incomplete transports before saving even an administrator's draft. */
	public function from_form( array $form ) {
		$input_rows = array_key_exists( 'setup_field', $form ) ? $form['setup_field'] : array();
		if ( '1' !== ( $form['setup_fields_version'] ?? null ) || '1' !== ( $form['setup_fields_complete'] ?? null ) || ! is_array( $input_rows ) || count( $input_rows ) > 150 ) { return $this->invalid(); }
		$rows = array();
		foreach ( $input_rows as $row ) {
			if ( ! is_array( $row ) || ( isset( $row['enabled'] ) && '1' !== $row['enabled'] ) ) { return $this->invalid(); }
			if ( ! isset( $row['enabled'] ) ) { continue; }
			unset( $row['enabled'] );
			$rows[] = $row;
		}
		return $this->normalize_intent( array(
			'schema' => self::SCHEMA, 'version' => 1,
			'catalog_revision' => $form['setup_catalog_revision'] ?? null,
			'program_revision' => $form['setup_program_revision'] ?? null,
			'program_id' => $form['setup_fields_program_id'] ?? null,
			'rows' => $rows,
		) );
	}

	/** Strict bounded draft shape; semantic validation waits until the user continues or reviews. */
	public function normalize_intent( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'schema', 'version', 'catalog_revision', 'program_revision', 'program_id', 'rows' ) ) || self::SCHEMA !== ( $input['schema'] ?? null ) || 1 !== ( $input['version'] ?? null ) || ! is_array( $input['rows'] ?? null ) || count( $input['rows'] ) > self::MAX_ROWS ) { return $this->invalid(); }
		foreach ( array( 'catalog_revision', 'program_revision' ) as $key ) { if ( ! is_string( $input[ $key ] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input[ $key ] ) ) { return $this->invalid(); } }
		if ( ! is_string( $input['program_id'] ?? null ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', $input['program_id'] ) ) { return $this->invalid(); }
		$rows = array();
		foreach ( $input['rows'] as $row ) {
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), array( 'key', 'label', 'type', 'requirement', 'collection', 'default_value' ) ) ) { return $this->invalid(); }
			foreach ( array( 'key' => 64, 'label' => 100, 'type' => 10, 'requirement' => 10, 'default_value' => 8192 ) as $key => $max ) { if ( ! is_string( $row[ $key ] ?? null ) || strlen( $row[ $key ] ) > $max * ( 'label' === $key ? 4 : 1 ) ) { return $this->invalid(); } }
			if ( preg_match_all( '/./us', $row['label'], $characters ) > 100 || false === preg_match( '//u', $row['label'] ) ) { return $this->invalid(); }
			if ( ! in_array( $row['type'], array( 'text', 'textarea', 'number', 'date' ), true ) || ! in_array( $row['requirement'], array( 'inherit', 'optional', 'required', 'exclude' ), true ) || ! is_array( $row['collection'] ?? null ) ) { return $this->invalid(); }
			$collection = $row['collection'];
			if ( array_diff( array_keys( $collection ), array( 'writer', 'lifetime', 'collect_when', 'approval_required', 'show_on_certificate', 'help' ) ) ) { return $this->invalid(); }
			foreach ( array( 'writer', 'lifetime', 'collect_when', 'help' ) as $key ) { if ( ! is_string( $collection[ $key ] ?? null ) || strlen( $collection[ $key ] ) > ( 'help' === $key ? 1600 : 32 ) ) { return $this->invalid(); } }
			foreach ( array( 'approval_required', 'show_on_certificate' ) as $key ) { if ( ! in_array( $collection[ $key ] ?? '0', array( '0', '1' ), true ) ) { return $this->invalid(); } $collection[ $key ] = $collection[ $key ] ?? '0'; }
			$row['collection'] = $collection;
			$rows[] = $row;
		}
		$input['rows'] = $rows;
		return $input;
	}

	/** Catalog revision includes retired keys; a removed field cannot be silently reused. */
	public function catalog_revision( array $catalog, array $retired ) { return hash( 'sha256', serialize( array( $catalog, $retired ) ) ); }
	public function program_revision( ?array $program ) {
		if ( null === $program ) { return hash( 'sha256', 'new-program' ); }
		// Repository readers split the two reserved envelopes from metadata.
		return hash( 'sha256', serialize( array_intersect_key( $program, array_flip( array( 'id', 'issuer_id', 'title', 'code', 'active', 'validity_days', 'metadata', 'profile', 'field_contract' ) ) ) ) );
	}

	/** Preserve unrelated metadata and requirements. Unchecked rows never remove an existing Program rule. */
	public function build( $intent, array $catalog, array $retired, ?array $program = null ) {
		$intent = $this->normalize_intent( $intent );
		if ( is_wp_error( $intent ) ) { return $intent; }
		if ( (int) $intent['program_id'] !== (int) ( $program['id'] ?? 0 ) || ! hash_equals( $intent['catalog_revision'], $this->catalog_revision( $catalog, $retired ) ) || ! hash_equals( $intent['program_revision'], $this->program_revision( $program ) ) ) { return new \WP_Error( 'certloom_setup_fields_changed', __( 'Fields or Program settings changed after you chose them. Return to Fields, review the current settings, and continue again.', 'certloom' ) ); }
		$next = $catalog;
		$rules = $program['field_contract']['fields'] ?? array();
		$metadata = $program['metadata'] ?? array();
		$defaults = ( new ProgramFieldDefaults() )->from_program( $program ?? array(), $catalog );
		if ( is_wp_error( $defaults ) ) { return $defaults; }
		$values = $defaults['values'];
		$seen = array(); $review = array();
		foreach ( $intent['rows'] as $row ) {
			$key = $row['key'];
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $key ) || isset( $seen[ $key ] ) || isset( $retired[ $key ] ) || in_array( $key, array( 'credential_title', 'certificate_number', 'credential_number', 'expiry', 'valid_until' ), true ) ) { return $this->invalid(); }
			$seen[ $key ] = true;
			$new = ! isset( $catalog[ $key ] );
			$field = $new ? $this->new_definition( $key, $row['label'], $row['type'], count( $next ) ) : $catalog[ $key ];
			if ( ! ScopedFieldContract::is_scopable_definition( $field ) || '' === trim( $row['label'] ) || ( ! $new && ( $row['label'] !== $field['label'] || $row['type'] !== $field['type'] ) ) ) { return $this->invalid(); }
			if ( ( new FieldCollectionPolicy() )->supports_policy( $field ) ) {
				$policy = ( new FieldCollectionForm() )->normalize( $row['collection'], $field );
				if ( is_wp_error( $policy ) ) { return $policy; }
				if ( is_array( $policy ) && 'source' === $policy['writer'] && 'achievement' !== $policy['lifetime'] ) { return new \WP_Error( 'certloom_setup_source_lifetime_invalid', __( 'Connected app results belong to each certificate or result. Choose that option, or use customer / staff collection for personal and enrollment details.', 'certloom' ) ); }
				if ( null === $policy ) { unset( $field[ FieldCollectionPolicy::KEY ] ); } else { $field[ FieldCollectionPolicy::KEY ] = $policy; }
			} elseif ( 'legacy' !== $row['collection']['writer'] || '' !== $row['default_value'] ) { return $this->invalid(); }
			$next[ $key ] = $field;
			if ( 'inherit' === $row['requirement'] ) { unset( $rules[ $key ] ); } else { $rules[ $key ] = $row['requirement']; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $field );
			if ( is_wp_error( $policy ) ) { return $policy; }
			$shared = ! empty( $policy['_meta']['configured'] ) && 'program' === $policy['lifetime'] && 'staff' === $policy['writer'];
			if ( $shared ) { if ( '' === $row['default_value'] ) { unset( $values[ $key ] ); } else { $values[ $key ] = $row['default_value']; } }
			elseif ( '' !== $row['default_value'] || isset( $values[ $key ] ) ) { return new \WP_Error( 'certloom_setup_default_source_changed', __( 'A shared Program value needs an organization-owned Program field. Clear its shared value before changing who supplies it.', 'certloom' ) ); }
			$review[] = array( 'key' => $key, 'label' => $field['label'], 'new' => $new, 'requirement' => $row['requirement'], 'policy' => $policy, 'has_default' => isset( $values[ $key ] ) );
		}
		$contract = new ScopedFieldContract();
		$metadata = $contract->with_program_metadata( $metadata, $rules, $next );
		if ( is_wp_error( $metadata ) ) { return $metadata; }
		$defaults = ( new ProgramFieldDefaults() )->normalize( array( '_meta' => array( 'schema' => ProgramFieldDefaults::SCHEMA, 'schema_version' => 1 ), 'values' => $values ), $next );
		if ( is_wp_error( $defaults ) ) { return $defaults; }
		$metadata[ ProgramFieldDefaults::KEY ] = $defaults;
		return array( 'catalog' => $next, 'metadata' => $metadata, 'contract' => $metadata[ ScopedFieldContract::STORAGE_KEY ], 'review' => $review );
	}

	public function new_definition( $key, $label, $type = 'text', $order = 0 ) {
		return array( 'key' => $key, 'label' => sanitize_text_field( $label ), 'type' => $type, 'column' => null, 'core' => false, 'protected' => false, 'locked_visibility' => false, 'sensitivity' => 'personal', 'public_exposure' => 'private', 'show_on_frontend' => false, 'order' => $order, 'system_only' => false );
	}
	/** Show profile suggestions first, then saved choices and reusable catalog fields. */
	public function candidates( array $draft, array $recommendation, array $catalog, array $retired, ?array $program ) {
		$fields = array();
		foreach ( $recommendation['recommended_fields'] ?? array() as $suggestion ) {
			$key = $suggestion['key'];
			if ( isset( $retired[ $key ] ) || in_array( $key, array( 'cert_number', 'credential_number', 'certificate_number', 'credential_title', 'status', 'issue_date', 'expiry', 'expiry_date', 'valid_until' ), true ) ) { continue; }
			$type = $suggestion['type'] ?? ( in_array( $key, array( 'score', 'training_hours', 'marks_percentage' ), true ) ? 'number' : ( 'completion_date' === $key ? 'date' : 'text' ) );
			if ( ! in_array( $type, array( 'text', 'textarea', 'number', 'date' ), true ) ) { $type = 'text'; }
			$fields[ $key ] = $catalog[ $key ] ?? $this->new_definition( $key, $suggestion['label'], $type );
		}
		$staged = array();
		$intent = $this->normalize_intent( $draft['setup_field_plan'] ?? null );
		if ( ! is_wp_error( $intent ) ) {
			foreach ( $intent['rows'] as $row ) {
				$staged[ $row['key'] ] = $row;
				if ( ! isset( $fields[ $row['key'] ] ) ) { $fields[ $row['key'] ] = $catalog[ $row['key'] ] ?? $this->new_definition( $row['key'], $row['label'], $row['type'] ); }
			}
		}
		foreach ( $draft['custom_fields'] ?? array() as $label ) {
			$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
			if ( '' !== $key && ! isset( $catalog[ $key ] ) && ! isset( $retired[ $key ] ) ) { $fields[ $key ] = $this->new_definition( $key, $label ); }
		}
		foreach ( $catalog as $key => $field ) { if ( ! isset( $fields[ $key ] ) && ScopedFieldContract::is_scopable_definition( $field ) ) { $fields[ $key ] = $field; } }
		$defaults = ( new ProgramFieldDefaults() )->from_program( $program ?? array(), $catalog );
		$rows = array();
		foreach ( $fields as $key => $field ) {
			if ( ! ScopedFieldContract::is_scopable_definition( $field ) || isset( $retired[ $key ] ) ) { continue; }
			$row = array( 'key' => $key, 'label' => $field['label'], 'type' => $field['type'], 'requirement' => $program['field_contract']['fields'][ $key ] ?? 'optional', 'collection' => $this->form_collection( $field ), 'default_value' => is_wp_error( $defaults ) ? '' : ( $defaults['values'][ $key ] ?? '' ) );
			if ( isset( $staged[ $key ] ) ) {
				$row['collection'] = $staged[ $key ]['collection'];
				if ( (int) $intent['program_id'] === (int) ( $program['id'] ?? 0 ) ) { $row['requirement'] = $staged[ $key ]['requirement']; $row['default_value'] = $staged[ $key ]['default_value']; }
			}
			$row['enabled'] = isset( $staged[ $key ] ) || in_array( $key, $draft['selected_fields'] ?? array(), true ) || isset( $program['field_contract']['fields'][ $key ] ) || in_array( $field['label'], $draft['custom_fields'] ?? array(), true );
			$row['definition'] = $field;
			$row['new'] = ! isset( $catalog[ $key ] );
			$row['custom'] = $row['new'] && ! in_array( $key, array_column( $recommendation['recommended_fields'] ?? array(), 'key' ), true );
			$rows[] = $row;
		}
		return $rows;
	}
	public function form_collection( array $field ) {
		$policy = ( new FieldCollectionPolicy() )->for_definition( $field );
		if ( is_wp_error( $policy ) ) { return array( 'writer' => 'invalid', 'lifetime' => 'recipient', 'collect_when' => 'profile', 'help' => '', 'approval_required' => '0', 'show_on_certificate' => '0' ); }
		return array( 'writer' => ! empty( $policy['_meta']['configured'] ) ? $policy['writer'] : 'legacy', 'lifetime' => $policy['lifetime'], 'collect_when' => $policy['collect_when'], 'help' => $policy['help'], 'approval_required' => $policy['approval_required'] ? '1' : '0', 'show_on_certificate' => $policy['show_on_certificate'] ? '1' : '0' );
	}
	private function invalid() { return new \WP_Error( 'certloom_setup_fields_invalid', __( 'The complete field choices could not be read. Check field names and settings, then try again. No field or Program settings were changed.', 'certloom' ) ); }
}
