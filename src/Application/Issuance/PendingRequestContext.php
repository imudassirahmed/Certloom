<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** No recipient form or registration alone supplies permission to issue. */
final class PendingRequestContext {
	public static function supports_source( array $row ) {
		if ( in_array( $row['source_type'] ?? null, array( 'manual', 'bulk_admin' ), true ) ) { return null === ( $row['mapping_sha256'] ?? null ); }
		return in_array( $row['source_type'] ?? null, array( 'webhook_v2', \Certloom\Application\Integration\TutorPendingContext::SOURCE, \Certloom\Application\Integration\WooCommercePendingContext::SOURCE ), true ) && is_string( $row['mapping_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $row['mapping_sha256'] );
	}
	public function resolve( array $row, $now ) {
		$intake = ( new IssuanceIntake() )->from_row( $row );
		if ( is_wp_error( $intake ) ) { return $intake; }
		if ( 'local' !== ( $row['restore_state'] ?? null ) ) { return $this->blocked( 'restore_review_required' ); }
		// Other adapters need their own current mapping/qualification contract before activation.
		if ( ! self::supports_source( $row ) ) { return $this->blocked( 'source_execution_not_supported' ); }
		if ( 'webhook_v2' === $row['source_type'] ) { $source = ( new \Certloom\Application\Integration\WebhookPendingContext() )->validate( $row ); if ( is_wp_error( $source ) ) { return $source; } }
		if ( \Certloom\Application\Integration\TutorPendingContext::SOURCE === $row['source_type'] ) { $source = ( new \Certloom\Application\Integration\TutorPendingContext() )->validate( $row ); if ( is_wp_error( $source ) ) { return $source; } }
		if ( \Certloom\Application\Integration\WooCommercePendingContext::SOURCE === $row['source_type'] ) { $source = ( new \Certloom\Application\Integration\WooCommercePendingContext() )->validate( $row ); if ( is_wp_error( $source ) ) { return $source; } }
		if ( ! in_array( $row['status'] ?? null, array( 'received', 'waiting', 'ready', 'processing', 'failed', 'blocked' ), true ) ) { return $this->blocked( 'request_not_executable' ); }
		$evidence = ( new IssuanceEvidence() )->normalize( json_decode( (string) ( $row['evidence_json'] ?? '' ), true ) );
		if ( is_wp_error( $evidence ) ) { return $evidence; }
		if ( 'not_eligible' === $evidence['eligibility'] ) { return $this->blocked( 'not_eligible' ); }
		if ( ! ( new IssuanceEvidence() )->is_current( $evidence, $now ) ) { return $this->blocked( 'missing_current_evidence' ); }
		$request = $intake['request'];
		// v1 omitted enrollment ID from the immutable payload; row ownership remains authoritative.
		$request['registration_id'] = (int) ( $row['registration_id'] ?? 0 );
		$context = array( 'request_id' => (int) $row['id'], 'public_ref' => $row['public_ref'], 'intake_key' => $row['intake_key'], 'payload_sha256' => $row['payload_sha256'], 'recipient_id' => (int) $row['recipient_id'], 'program_id' => (int) $row['program_id'], 'batch_id' => (int) ( $row['batch_id'] ?? 0 ), 'registration_id' => $request['registration_id'], 'source_type' => $row['source_type'], 'mapping_sha256' => $row['mapping_sha256'], 'evidence_revision' => (int) $row['evidence_revision'], 'evidence_sha256' => ( new CanonicalJson() )->hash( $evidence ) );
		return array( 'request' => $request, 'context' => $context );
	}

	private function blocked( $reason ) { return new \WP_Error( 'certloom_pending_' . $reason, __( 'This request needs current qualification and an approved source context before issuance.', 'certloom' ), array( 'reason_code' => $reason ) ); }
}
