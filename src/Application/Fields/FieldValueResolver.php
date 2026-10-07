<?php
namespace Certloom\Application\Fields;

use Certloom\Application\Contract\FieldValueProviderInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves the current scoped field contract without mutating any source.
 *
 * Values and their completeness share one implementation for review, preview
 * and final issuance. Missing values are descriptive here; the caller decides
 * whether it is preparing a draft or committing a reviewed credential.
 * Collection policies and trusted providers must be resolved before adding
 * new value sources. A submitted custom value is not a recipient profile value.
 */
final class FieldValueResolver {

	/**
	 * @param array<string,mixed> $contract Resolved Global/Issuer/Program/Batch contract.
	 * @param array<string,mixed> $recipient Canonical recipient snapshot.
	 * @param array<string,mixed> $request Normalized issuance request.
	 * @param array<string,array<string,mixed>> $catalog Canonical field definitions.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve( array $contract, array $recipient, array $request, array $catalog, ?FieldValueProviderInterface $provider = null ) {
		$rows = isset( $contract['fields'] ) && is_array( $contract['fields'] ) ? $contract['fields'] : array();
		$submitted = isset( $request['custom_merge_fields'] ) && is_array( $request['custom_merge_fields'] ) ? $request['custom_merge_fields'] : array();
		$assigned = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' !== $key ) { $assigned[ $key ] = true; }
		}
		if ( ! empty( $contract['_meta']['configured'] ) && '' !== trim( (string) ( $request['grade'] ?? '' ) ) && ! isset( $assigned['grade'] ) ) {
			return new \WP_Error( 'certloom_manual_issue_unassigned_grade', __( 'Grade / result is not assigned to the selected Program/Batch credential-field contract.', 'certloom' ) );
		}

		$values = array();
		$fields = array();
		$missing = array();
		$provenance = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key || ! isset( $catalog[ $key ] ) || ! is_array( $catalog[ $key ] ) ) { continue; }
			$provided = null;
			if ( array_key_exists( FieldCollectionPolicy::KEY, $catalog[ $key ] ) ) {
				$policy = ( new FieldCollectionPolicy() )->for_definition( $catalog[ $key ] );
				if ( is_wp_error( $policy ) ) { return $policy; }
				if ( 'achievement' !== $policy['lifetime'] || 'staff' !== $policy['writer'] ) {
					if ( null === $provider ) { return new \WP_Error( 'certloom_field_collection_provider_unavailable', __( 'This field needs its configured collection or trusted source provider before issuance. It cannot be replaced with an unrelated one-off value.', 'certloom' ) ); }
					$provided = $provider->resolve( $catalog[ $key ], $policy );
					if ( is_wp_error( $provided ) ) { return $provided; }
					if ( ! is_array( $provided ) || ! is_string( $provided['value'] ?? null ) || ! is_array( $provided['provenance'] ?? null ) || ! in_array( $provided['status'] ?? '', array( 'ready', 'missing', 'awaiting_approval', 'needs_resubmission', 'registration_required', 'recipient_required', 'program_required', 'missing_source_evidence', 'source_evidence_not_current' ), true ) ) { return new \WP_Error( 'certloom_field_provider_invalid', __( 'A field provider returned an unsupported value contract.', 'certloom' ) ); }
					$provenance[ $key ] = $provided['provenance'];
				}
			}
			$source = (string) ( $row['value_source'] ?? ScopedFieldContract::value_source( $key ) );
			if ( is_array( $provided ) ) {
				$raw = 'ready' === $provided['status'] ? $provided['value'] : '';
			} elseif ( 'recipient' === $source ) {
				$raw = $recipient[ $key ] ?? '';
			} elseif ( 'credential' === $source && 'grade' === $key ) {
				$raw = $request['grade'] ?? '';
			} elseif ( 'credential_custom' === $source ) {
				$raw = $submitted[ $key ] ?? '';
			} else {
				return new \WP_Error( 'certloom_field_value_source_invalid', __( 'A credential field has an unsupported value source. Review its field configuration before issuing.', 'certloom' ) );
			}
			if ( null !== $raw && ! is_scalar( $raw ) ) {
				return new \WP_Error( 'certloom_field_value_invalid', __( 'Credential field values must be text, numbers or dates, rather than a list or object.', 'certloom' ) );
			}
			$clean = \Ecapla_CertVerify_Validator::custom_field_value( $raw, $catalog[ $key ] );
			if ( is_wp_error( $clean ) ) { return $clean; }
			$present = '' !== trim( (string) $clean );
			$field = array(
				'key' => $key,
				'label' => (string) ( $row['label'] ?? $catalog[ $key ]['label'] ?? $key ),
				'value_source' => $source,
				'required' => ! empty( $row['required'] ),
				'status' => $present ? 'ready' : ( ! empty( $row['required'] ) ? 'missing' : 'optional' ),
			);
			if ( is_array( $provided ) ) {
				$field['source_status'] = $provided['status'];
				if ( 'ready' !== $provided['status'] && $field['required'] ) { $field['status'] = $provided['status']; }
			}
			$values[ $key ] = (string) $clean;
			$fields[] = $field;
			if ( ! $present && $field['required'] ) { $missing[] = $field; }
		}
		ksort( $values, SORT_STRING );
		ksort( $provenance, SORT_STRING );
		return array(
			'values' => $values,
			'ready' => empty( $missing ),
			'missing_count' => count( $missing ),
			'missing_fields' => $missing,
			'fields' => $fields,
			'provenance' => $provenance,
		);
	}

	/** Return the established issuance error without exposing private values. */
	public function required_error( array $resolution ) {
		$missing = isset( $resolution['missing_fields'] ) && is_array( $resolution['missing_fields'] ) ? $resolution['missing_fields'] : array();
		if ( empty( $missing ) ) { return null; }
		$field = reset( $missing );
		return new \WP_Error(
			'certloom_manual_issue_required_field_missing',
			sprintf( /* translators: %s: label. */ __( 'Complete required credential field “%s” before issuing.', 'certloom' ), (string) ( $field['label'] ?? $field['key'] ?? '' ) )
		);
	}
}
