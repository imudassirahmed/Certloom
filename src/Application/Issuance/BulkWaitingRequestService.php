<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Recipient\CertificateNamePolicy;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\CollectedFieldValueProviderFactory;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deliberate qualified organization intake; missing customer data never grants qualification. */
final class BulkWaitingRequestService {
	public function review( array $request ) {
		$review = ( new ManualIssuanceService() )->review_waiting( $request );
		if ( is_wp_error( $review ) ) { return $review; }
		$recipient = $review['recipient'];
		if ( 'existing' !== $review['request']['recipient_mode'] || empty( $recipient['wp_user_id'] ) || ! user_can( $recipient['wp_user_id'], 'read' ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->unavailable(); }
		$missing = $review['field_resolution']['missing_fields'];
		if ( ! $missing ) { return $this->unavailable(); }
		$catalog = \Ecapla_CertVerify_Fields::get_field_map(); $allowed = array(); $registration_id = (int) $review['request']['registration_id'];
		foreach ( $missing as $field ) {
			$key = $field['key']; $policy = ( new FieldCollectionPolicy() )->for_definition( $catalog[ $key ] );
			if ( is_wp_error( $policy ) || ! ( new FieldCollectionPolicy() )->recipient_editable( $policy ) || ! in_array( $field['source_status'] ?? '', array( 'missing', 'awaiting_approval', 'needs_resubmission' ), true ) ) { return $this->unavailable(); }
			$allowed[ $key ] = true;
			if ( 'registration' === $policy['lifetime'] ) {
				$exact_id = (int) ( $review['field_resolution']['provenance'][ $key ]['context_id'] ?? 0 );
				if ( ! $exact_id || ( $registration_id && $registration_id !== $exact_id ) ) { return $this->unavailable(); }
				$registration_id = $exact_id;
			}
		}
		$name = ( new CertificateNamePolicy() )->current( $catalog ); if ( is_wp_error( $name ) ) { return $name; }
		foreach ( $review['data_readiness']['fields'] as $field ) {
			if ( 'missing' !== $field['status'] ) { continue; }
			$key = $review['request']['design_field_aliases'][ $field['field_key'] ] ?? $field['field_key'];
			$is_name = 'approved_field' === $name['source'] && isset( $allowed[ $name['field_key'] ] ) && in_array( $field['token'], array( 'recipient.name', 'recipient_name' ), true );
			if ( ! isset( $allowed[ $key ] ) && ! $is_name ) { return $this->unavailable(); }
		}
		if ( $registration_id !== (int) $review['request']['registration_id'] ) {
			$request['registration_id'] = $registration_id;
			return $this->review( $request ); // Pin the one provider-selected registration before fingerprinting.
		}
		return $review;
	}

	public function receive_reviewed( array $entry, $preflight_key, $actor_id ) {
		if ( ! is_int( $actor_id ) || $actor_id < 1 || ! user_can( $actor_id, \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return new \WP_Error( 'certloom_bulk_pending_forbidden', __( 'The organization must confirm these qualified recipients before creating waiting requests.', 'certloom' ) ); }
		if ( ! is_string( $preflight_key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $preflight_key ) || ! is_int( $entry['row_number'] ?? null ) || $entry['row_number'] < 1 || ! is_string( $entry['review_fingerprint'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $entry['review_fingerprint'] ) || ! is_array( $entry['request'] ?? null ) ) { return $this->unavailable(); }
		$requests = new IssuanceRequestRepository(); $request = $entry['request'];
		// Review metadata is derived afresh by the canonical service, not editable intake.
		unset( $request['title_program_id'] );
		$evidence = array( '_meta' => array( 'schema' => IssuanceEvidence::SCHEMA, 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => $entry['qualified_at'] ?? null, 'valid_until' => null, 'fields' => array() );
		$valid = ( new IssuanceEvidence() )->normalize( $evidence ); if ( is_wp_error( $valid ) || ! ( new IssuanceEvidence() )->is_current( $evidence, current_time( 'mysql', true ) ) ) { return $this->unavailable(); }
		$context = array( 'recipient_id' => (int) $request['recipient_id'], 'program_id' => (int) $request['program_id'], 'batch_id' => (int) $request['batch_id'], 'registration_id' => (int) $request['registration_id'], 'source_type' => 'bulk_admin', 'source_ref' => 'bulk:' . $preflight_key . ':row:' . $entry['row_number'], 'intake_key' => hash( 'sha256', 'certloom-bulk-pending:' . $preflight_key . ':' . $entry['row_number'] . ':' . $entry['review_fingerprint'] ) );
		$existing = $requests->find_by_intake_key( $context['intake_key'] ); if ( is_wp_error( $existing ) ) { return $existing; }
		if ( is_array( $existing ) ) {
			$row = $requests->receive( $context, $request, $evidence );
			if ( ! is_wp_error( $row ) ) { $row['idempotent_replay'] = true; }
			return $row;
		}
		return ( new Transaction() )->run( function () use ( $requests, $context, $request, $evidence, $entry ) {
			$locked = ( new CollectedFieldValueProviderFactory() )->lock_context( $context['recipient_id'], $context['program_id'], $context['batch_id'], $context['registration_id'] );
			if ( is_wp_error( $locked ) ) { return $locked; }
			$current = $this->review( $request ); if ( is_wp_error( $current ) ) { return $current; }
			if ( ! hash_equals( $entry['review_fingerprint'], $current['review_fingerprint'] ) ) { return new \WP_Error( 'certloom_bulk_row_review_stale', __( 'This waiting row changed after CSV review. Review it again before creating its request.', 'certloom' ) ); }
			$row = $requests->receive( $context, $request, $evidence ); if ( is_wp_error( $row ) ) { return $row; }
			return ( new PendingIssuanceService( $requests ) )->process( $row['id'] );
		} );
	}
	private function unavailable() { return new \WP_Error( 'certloom_bulk_waiting_unavailable', __( 'A waiting request needs an existing connected customer, exact Program participation when applicable, and only missing customer details or their approval. Repair staff results, connected-source evidence and issuing configuration before confirming this row.', 'certloom' ) ); }
}
