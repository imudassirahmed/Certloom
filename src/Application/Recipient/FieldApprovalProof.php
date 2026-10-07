<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Domain\Recipient\FieldSubmissionScope;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Site-bound approval evidence for one typed value under one exact current policy. */
final class FieldApprovalProof {
	public function digest( array $definition, $recipient_id, $kind, $scope_id, $value ) {
		$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
		if ( is_wp_error( $policy ) ) { return $policy; }
		if ( ! function_exists( 'wp_salt' ) || ! is_string( $value ) ) { return $this->invalid(); }
		$secret = (string) wp_salt( 'auth' );
		if ( '' === $secret ) { return $this->invalid(); }
		$body = array(
			'schema' => 'certloom-field-approval-v1',
			'scope' => FieldSubmissionScope::key( $recipient_id, $kind, $scope_id, $definition['key'] ?? '' ),
			'type' => $definition['type'] ?? 'text',
			'sensitivity' => $definition['sensitivity'] ?? 'personal',
			'public_exposure' => $definition['public_exposure'] ?? 'private',
			'policy' => $policy,
			'value' => $value,
		);
		try { $json = ( new CanonicalJson() )->encode( $body ); } catch ( \Throwable $error ) { return $this->invalid(); }
		return hash_hmac( 'sha256', $json, $secret );
	}

	public function matches( array $submission, array $definition, $recipient_id, $kind, $scope_id, $value ) {
		if ( 'approved' !== ( $submission['status'] ?? '' ) || empty( $submission['decided_by'] ) || empty( $submission['decided_at'] ) || (string) ( $submission['candidate_value'] ?? '' ) !== $value ) { return false; }
		$expected = $this->digest( $definition, $recipient_id, $kind, $scope_id, $value );
		return ! is_wp_error( $expected ) && is_string( $submission['policy_sha256'] ?? null ) && hash_equals( $expected, $submission['policy_sha256'] );
	}

	private function invalid() { return new \WP_Error( 'certloom_field_approval_proof_invalid', __( 'Certloom could not verify this field approval safely.', 'certloom' ) ); }
}
