<?php
namespace Certloom\Application\Fields;

use Certloom\Application\Contract\FieldValueProviderInterface;
use Certloom\Application\Recipient\FieldApprovalProof;
use Certloom\Application\Issuance\IssuanceEvidence;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Receives canonical owned records from the persistence boundary, never a browser field payload. */
final class CollectedFieldValueProvider implements FieldValueProviderInterface {
	private $recipient; private $program; private $registration; private $submissions; private $defaults; private $request_record; private $now;
	public function __construct( array $recipient, array $program, $registration, array $submissions, array $defaults, $request_record = null, $now = null ) {
		$this->recipient = $recipient; $this->program = $program; $this->registration = $registration;
		$this->submissions = $submissions; $this->defaults = $defaults; $this->request_record = $request_record; $this->now = $now;
	}
	public function resolve( array $definition, array $policy ) {
		$key = $definition['key']; $kind = $policy['lifetime'];
		// A Program-wide staff value has no recipient owner. It must also be
		// available while canonical issuance reviews a not-yet-created recipient.
		if ( 'program' === $kind && 'staff' === $policy['writer'] ) {
			if ( empty( $this->program['id'] ) ) { return $this->value( '', 'program_required', array() ); }
			if ( ! empty( $this->recipient['privacy_anonymized_at'] ) ) { return $this->value( '', 'recipient_required', array() ); }
			$values = $this->defaults['values'];
			try { $revision = ( new CanonicalJson() )->hash( array( $this->defaults, $this->program['updated_at'] ?? '' ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
			$provenance = array( 'kind' => $kind, 'owner_id' => (int) $this->program['id'], 'context_id' => (int) $this->program['id'], 'revision' => $revision );
			return $this->value( $values[ $key ] ?? '', 'ready', $provenance );
		}
		if ( empty( $this->recipient['id'] ) || ! empty( $this->recipient['privacy_anonymized_at'] ) ) { return $this->value( '', 'recipient_required', array() ); }
		if ( 'source' === $policy['writer'] ) { return $this->source_value( $key, $kind ); }
		if ( 'recipient' === $kind ) {
			$id = (int) $this->recipient['id']; $values = $this->recipient['custom_fields'] ?? array();
			$provenance = array( 'kind' => $kind, 'owner_id' => $id, 'context_id' => $id, 'revision' => (string) ( $this->recipient['updated_at'] ?? '' ) );
		} elseif ( 'registration' === $kind ) {
			$row = $this->registration;
			if ( ! is_array( $row ) ) { return $this->value( '', 'registration_required', array() ); }
			if ( (int) $row['recipient_id'] !== (int) $this->recipient['id'] || (int) $row['program_id'] !== (int) ( $this->program['id'] ?? 0 ) || ! in_array( $row['status'], array( 'active', 'completed' ), true ) ) { return $this->invalid(); }
			$id = (int) $row['id']; $values = $row['fields'];
			$provenance = array( 'kind' => $kind, 'owner_id' => (int) $this->recipient['id'], 'context_id' => $id, 'revision' => (int) $row['revision'] );
		} else { return $this->invalid(); }
		$value = $values[ $key ] ?? '';
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) && null !== $value ) { return $this->invalid(); }
		$value = (string) $value;
		if ( $policy['approval_required'] ) {
			$submission = $this->submissions[ $kind ][ $key ] ?? null;
			if ( is_array( $submission ) ) { $provenance['submission_id'] = (int) $submission['id']; $provenance['submission_revision'] = (int) $submission['revision']; }
			if ( ! is_array( $submission ) || ! ( new FieldApprovalProof() )->matches( $submission, $definition, $this->recipient['id'], $kind, $id, $value ) ) {
				$status = is_array( $submission ) && 'rejected' === $submission['status'] ? 'needs_resubmission' : ( '' === trim( $value ) && ! is_array( $submission ) ? 'missing' : 'awaiting_approval' );
				return $this->value( '', $status, $provenance );
			}
		}
		return $this->value( $value, '' === trim( $value ) ? 'missing' : 'ready', $provenance );
	}

	private function source_value( $key, $kind ) {
		if ( 'achievement' !== $kind ) { return $this->invalid(); }
		$row = $this->request_record;
		if ( ! is_array( $row ) ) { return $this->value( '', 'missing_source_evidence', array() ); }
		if ( (int) $row['recipient_id'] !== (int) $this->recipient['id'] || (int) $row['program_id'] !== (int) ( $this->program['id'] ?? 0 ) || (int) ( $row['registration_id'] ?? 0 ) !== (int) ( $this->registration['id'] ?? 0 ) || ( is_array( $this->registration ) && (int) ( $row['batch_id'] ?? 0 ) !== (int) ( $this->registration['batch_id'] ?? 0 ) ) || 'paused' === $row['restore_state'] || ! in_array( $row['status'], array( 'received', 'waiting', 'ready', 'processing', 'failed', 'blocked' ), true ) ) { return $this->invalid(); }
		$evidence = json_decode( (string) $row['evidence_json'], true );
		$evidence = ( new IssuanceEvidence() )->normalize( $evidence );
		if ( is_wp_error( $evidence ) ) { return $evidence; }
		$provenance = array( 'kind' => 'source', 'owner_id' => (int) $row['recipient_id'], 'context_id' => (int) $row['id'], 'revision' => (int) $row['evidence_revision'] );
		if ( ! ( new IssuanceEvidence() )->is_current( $evidence, $this->now ) ) { return $this->value( '', 'source_evidence_not_current', $provenance ); }
		$field = $evidence['fields'][ $key ] ?? null;
		if ( ! is_array( $field ) || 'source' !== $field['writer'] ) { return $this->value( '', 'missing_source_evidence', $provenance ); }
		$provenance['source_revision'] = $field['revision'];
		return $this->value( $field['value'], 'ready', $provenance );
	}
	private function value( $value, $status, array $provenance ) {
		if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) { return $this->invalid(); }
		return array( 'value' => (string) $value, 'status' => $status, 'provenance' => $provenance );
	}
	private function invalid() { return new \WP_Error( 'certloom_collected_field_source_invalid', __( 'This field needs its exact owned collection or trusted source context before issuance.', 'certloom' ) ); }
}
