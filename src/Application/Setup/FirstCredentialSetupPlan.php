<?php
namespace Certloom\Application\Setup;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\IssuanceDataReadiness;
use Certloom\Application\Issuance\MergeSnapshotBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds the compact Setup contract for the first real credential.
 *
 * Appearance decides which design tokens must be satisfiable. Profile decides
 * only how hierarchy concepts are presented; the canonical Issuer / Program /
 * Batch / Signer model remains unchanged. This planner never mutates records.
 */
final class FirstCredentialSetupPlan {
	/** @return array<string,mixed> */
	public function build( ?array $template, array $selected_fields, array $catalog, array $custom_labels = array() ) {
		$tokens = array();
		$design_name = '';
		$design_id = 0;
		if ( is_array( $template ) ) {
			$design_id = absint( $template['id'] ?? 0 );
			$design_name = (string) ( $template['name'] ?? '' );
			$layout = isset( $template['published_revision']['layout'] ) && is_array( $template['published_revision']['layout'] ) ? $template['published_revision']['layout'] : array();
			$tokens = ( new MergeSnapshotBuilder() )->required_tokens( $layout );
		}

		$readiness = ( new IssuanceDataReadiness() )->inspect( $tokens, array(), array(), $catalog );
		$context = array( 'issuer' => false, 'program' => false, 'batch' => false, 'signer' => false );
		$context_fields = array(
			'issuer' => array( 'name' => false, 'title' => false ),
			'program' => array( 'title' => false, 'code' => false ),
			'batch' => array( 'name' => false, 'code' => false ),
			'signer' => array( 'name' => false, 'title' => false, 'email' => false, 'signature' => false ),
		);
		$recipient = array();
		$credential = array();
		$grade_required = false;
		$repeating = array( 'subjects' => false, 'results' => false );

		foreach ( (array) ( $readiness['fields'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || 'generated' === (string) ( $row['status'] ?? '' ) ) { continue; }
			$token = strtolower( trim( (string) ( $row['token'] ?? '' ) ) );
			$action = sanitize_key( (string) ( $row['action'] ?? '' ) );
			$label = trim( (string) ( $row['label'] ?? '' ) );
			$key = sanitize_key( (string) ( $row['field_key'] ?? '' ) );

			if ( $this->is_issuer_token( $token ) ) {
				$context['issuer'] = true;
				$context_fields['issuer'][ in_array( $token, array( 'issuer.title', 'issuer_title' ), true ) ? 'title' : 'name' ] = true;
				continue;
			}
			if ( $this->is_program_token( $token ) ) {
				$context['program'] = true;
				$context_fields['program'][ in_array( $token, array( 'program.code', 'program_code' ), true ) ? 'code' : 'title' ] = true;
				continue;
			}
			if ( $this->is_batch_token( $token ) ) {
				$context['batch'] = true; $context['program'] = true;
				$context_fields['batch'][ in_array( $token, array( 'batch.code', 'batch_code' ), true ) ? 'code' : 'name' ] = true;
				continue;
			}
			if ( $this->is_signer_token( $token ) ) {
				$context['signer'] = true;
				if ( in_array( $token, array( 'signer.title', 'signer_title' ), true ) ) { $context_fields['signer']['title'] = true; }
				elseif ( 'signer.email' === $token ) { $context_fields['signer']['email'] = true; }
				elseif ( in_array( $token, array( 'signer.signature_asset', 'signer.signature' ), true ) ) { $context_fields['signer']['signature'] = true; }
				else { $context_fields['signer']['name'] = true; }
				continue;
			}
			if ( in_array( $token, array( 'subjects', 'results' ), true ) ) { $repeating[ $token ] = true; continue; }
			if ( 'grade' === $action || in_array( $token, array( 'grade', 'credential.grade' ), true ) ) { $grade_required = true; continue; }

			// Recipient display name, credential title/effective date and expiry are
			// first-class fields on the final Setup step and do not need duplicates.
			if ( in_array( $token, array( 'recipient.name', 'recipient_name', 'credential.title', 'credential_title', 'credential.issue_date', 'issue_date', 'credential.expiry_date', 'expiry_date' ), true ) ) { continue; }

			if ( 'recipient' === $action ) {
				if ( '' === $key ) { $key = $this->recipient_key( $token ); }
				if ( '' !== $key && 'name' !== $key ) { $this->add_field( $recipient, $key, $label, $catalog, true ); }
				continue;
			}
			if ( 'credential_detail' === $action ) {
				if ( '' === $key ) { $key = $this->bounded_key_from_token( $token ); }
				if ( '' !== $key ) { $this->add_field( $credential, $key, $label, $catalog, true ); }
			}
		}

		foreach ( array_values( array_unique( array_map( 'sanitize_key', $selected_fields ) ) ) as $key ) {
			if ( '' === $key || in_array( $key, array( 'cert_number', 'credential_number', 'credential_title', 'status', 'issue_date', 'expiry', 'expiry_date' ), true ) ) { continue; }
			$source = ScopedFieldContract::value_source( $key );
			if ( 'recipient' === $source ) { $this->add_field( $recipient, $key, '', $catalog, false ); }
			elseif ( 'credential' === $source && 'grade' === $key ) { $grade_required = true; }
			else { $this->add_field( $credential, $key, '', $catalog, false ); }
		}

		foreach ( array_slice( $custom_labels, 0, 20 ) as $custom_label ) {
			$custom_label = trim( sanitize_text_field( (string) $custom_label ) );
			if ( '' === $custom_label ) { continue; }
			$key = sanitize_key( str_replace( '-', '_', sanitize_title( $custom_label ) ) );
			if ( '' === $key ) { continue; }
			$this->add_field( $credential, $key, $custom_label, $catalog, false );
		}

		return array(
			'design_id' => $design_id,
			'design_name' => $design_name,
			'required_tokens' => array_values( $tokens ),
			'context_required' => $context,
			'context_fields' => $context_fields,
			'recipient_fields' => array_values( $recipient ),
			'credential_fields' => array_values( $credential ),
			'grade_required' => $grade_required,
			'repeating' => $repeating,
			'requirement_count' => count( $tokens ),
		);
	}

	/** @param array<string,array<string,mixed>> $target */
	private function add_field( array &$target, $key, $label, array $catalog, $required_by_design ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) { return; }
		$field = isset( $catalog[ $key ] ) && is_array( $catalog[ $key ] ) ? $catalog[ $key ] : array();
		$label = '' !== trim( (string) $label ) ? trim( (string) $label ) : (string) ( $field['label'] ?? $this->humanize( $key ) );
		$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		if ( ! in_array( $type, array( 'text', 'textarea', 'date', 'number' ), true ) ) { $type = 'text'; }
		if ( isset( $target[ $key ] ) ) {
			$target[ $key ]['required_by_design'] = ! empty( $target[ $key ]['required_by_design'] ) || (bool) $required_by_design;
			return;
		}
		$target[ $key ] = array(
			'key' => $key,
			'label' => $label,
			'type' => $type,
			'required_by_design' => (bool) $required_by_design,
			'sensitivity' => sanitize_key( (string) ( $field['sensitivity'] ?? 'personal' ) ),
		);
	}

	private function is_issuer_token( $token ) { return 0 === strpos( $token, 'issuer.' ) || in_array( $token, array( 'issuer_name', 'issuer_title', 'organization' ), true ); }
	private function is_program_token( $token ) { return 0 === strpos( $token, 'program.' ) || in_array( $token, array( 'program_name', 'program_code', 'course_name' ), true ); }
	private function is_batch_token( $token ) { return 0 === strpos( $token, 'batch.' ) || in_array( $token, array( 'batch_name', 'batch_code', 'cohort_name' ), true ); }
	private function is_signer_token( $token ) { return 0 === strpos( $token, 'signer.' ) || in_array( $token, array( 'signer_name', 'signer_title', 'signer_email', 'signer_signature' ), true ); }

	private function recipient_key( $token ) {
		$map = array(
			'recipient.first_name' => 'first_name', 'first_name' => 'first_name',
			'recipient.middle_name' => 'middle_name', 'middle_name' => 'middle_name',
			'recipient.last_name' => 'last_name', 'last_name' => 'last_name',
			'recipient.email' => 'email', 'email' => 'email',
			'recipient.external_id' => 'external_id', 'external_id' => 'external_id',
		);
		if ( isset( $map[ $token ] ) ) { return $map[ $token ]; }
		if ( 0 === strpos( $token, 'recipient.custom.' ) ) { return sanitize_key( substr( $token, 17 ) ); }
		return '';
	}

	private function bounded_key_from_token( $token ) {
		foreach ( array( 'credential.custom.' => 18, 'credential.' => 11, 'recipient.' => 10, 'custom.' => 7, 'credential:' => 11, 'custom:' => 7 ) as $prefix => $offset ) {
			if ( 0 === strpos( $token, $prefix ) ) {
				$key = sanitize_key( substr( $token, $offset ) );
				return preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $key ) ? $key : '';
			}
		}
		$key = sanitize_key( $token );
		return preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $key ) ? $key : '';
	}

	private function humanize( $key ) { return ucwords( str_replace( array( '-', '_' ), ' ', (string) $key ) ); }
}
