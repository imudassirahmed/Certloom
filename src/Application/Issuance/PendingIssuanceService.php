<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Contract\PendingRequestExecutorInterface;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\CollectedFieldValueProviderFactory;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One short atomic request assessment/commit. GETs never call this service. */
final class PendingIssuanceService implements PendingRequestExecutorInterface {
	private $requests; private $issuance;
	public function __construct( ?IssuanceRequestRepository $requests = null, ?ManualIssuanceService $issuance = null ) {
		$this->requests = $requests ?: new IssuanceRequestRepository();
		$this->issuance = $issuance ?: new ManualIssuanceService();
	}

	public function process( $request_id ) {
		$row = $this->requests->find( $request_id );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! is_array( $row ) ) { return $this->invalid(); }
		if ( in_array( $row['status'], array( 'issued', 'cancelled' ), true ) ) { return $row; }
		return ( new Transaction() )->run( function () use ( $row ) {
			$locked = ( new CollectedFieldValueProviderFactory() )->lock_context( $row['recipient_id'], $row['program_id'], $row['batch_id'], $row['registration_id'] );
			$account_unavailable = is_wp_error( $locked ) && 'certloom_recipient_account_missing' === $locked->get_error_code();
			if ( is_wp_error( $locked ) && ! $account_unavailable ) { return $locked; }
			return \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::run( function () use ( $row, $account_unavailable ) {
			$current = $this->requests->find( $row['id'], true );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! is_array( $current ) ) { return $this->invalid(); }
			if ( in_array( $current['status'], array( 'issued', 'cancelled' ), true ) ) { return $current; }
			$receipt = $this->issuance->pending_receipt( $current['id'] );
			if ( is_wp_error( $receipt ) ) { return $receipt; }
			if ( is_array( $receipt ) ) {
				// A matching canonical Version is the authority for an interrupted receipt write.
				return $this->finish( $current, $receipt );
			}
			$policy = ( new PendingRequestPolicy() )->read();
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( ( new PendingRequestPolicy() )->expired( $current, $policy, time() ) ) { return $this->close_expired( $current ); }
			if ( 'not_eligible' === $current['status'] ) { return $current; }
			if ( $account_unavailable ) { return $this->hold( $current, 'blocked', array( array( 'code' => 'account_connection_unavailable' ) ) ); }
			if ( 'processing' === $current['status'] ) {
				$current = $this->hold( $current, 'waiting', array( array( 'code' => 'reassessment_required' ) ) );
				if ( is_wp_error( $current ) ) { return $current; }
			}
			$review = $this->issuance->review_pending( $current['id'], true );
			if ( is_wp_error( $review ) ) {
				$reason = $review->get_error_data()['reason_code'] ?? null;
				if ( 'not_eligible' === $reason ) { return $this->hold( $current, 'not_eligible', array( array( 'code' => 'not_eligible' ) ) ); }
				if ( 'missing_current_evidence' === $reason ) { return $this->hold( $current, 'waiting', array( array( 'code' => $reason ) ) ); }
				$code = is_string( $reason ) ? $reason : sanitize_key( $review->get_error_code() );
				return $this->hold( $current, 'blocked', array( array( 'code' => substr( $code, 0, 64 ) ) ) );
			}
			$blockers = array();
			foreach ( $review['field_resolution']['missing_fields'] as $field ) {
				$blockers[] = array( 'code' => $field['source_status'] ?? $field['status'], 'field_key' => $field['key'] );
			}
			if ( 'design' === $review['request']['presentation_mode'] && empty( $review['data_readiness']['ready'] ) ) { $blockers[] = array( 'code' => 'design_data_missing' ); }
			if ( $blockers ) { return $this->hold( $current, 'waiting', array_slice( $blockers, 0, 128 ) ); }
			$ready = $this->requests->transition( $current['id'], $current['revision'], array( 'status' => 'ready', 'review_sha256' => $review['review_fingerprint'], 'blockers_json' => '[]', 'next_attempt_at' => null ), 'readiness_confirmed' );
			if ( is_wp_error( $ready ) ) { return $ready; }
			$processing = $this->requests->transition( $ready['id'], $ready['revision'], array( 'status' => 'processing', 'attempt_count' => (int) $ready['attempt_count'] + 1 ), 'issuance_started' );
			if ( is_wp_error( $processing ) ) { return $processing; }
			$receipt = $this->issuance->issue_pending( $processing['id'], $review['review_fingerprint'] );
			if ( is_wp_error( $receipt ) ) {
				return $this->hold( $processing, 'blocked', array( array( 'code' => substr( sanitize_key( $receipt->get_error_code() ), 0, 64 ) ) ) );
			}
			return $this->finish( $processing, $receipt );
			} );
		} );
	}

	private function hold( array $row, $status, array $blockers ) {
		$json = ( new CanonicalJson() )->encode( $blockers );
		if ( $row['status'] === $status && $row['blockers_json'] === $json && null === $row['review_sha256'] && null === $row['next_attempt_at'] ) { return $row; }
		return $this->requests->transition( $row['id'], $row['revision'], array( 'status' => $status, 'blockers_json' => $json, 'review_sha256' => null, 'next_attempt_at' => null ), 'readiness_blocked' );
	}

	private function finish( array $row, array $receipt ) {
		return $this->requests->transition( $row['id'], $row['revision'], array( 'status' => 'issued', 'series_id' => (int) $receipt['series_id'], 'version_id' => (int) $receipt['version_id'], 'blockers_json' => '[]', 'next_attempt_at' => null ), 'issuance_completed' );
	}

	/** Cron expiry does not require a still-active Program, account or source mapping. */
	public function expire( $request_id ) {
		$initial = $this->requests->find( $request_id );
		if ( is_wp_error( $initial ) || ! is_array( $initial ) ) { return is_wp_error( $initial ) ? $initial : $this->invalid(); }
		return ( new Transaction() )->run( function () use ( $initial ) {
			// The current policy lock precedes Recipient/request locks, matching issuance.
			$policy = ( new PendingRequestPolicy() )->read( true );
			if ( is_wp_error( $policy ) ) { return $policy; }
			$recipient = ( new \Certloom\Infrastructure\Repository\RecipientRepository() )->find_for_update( $initial['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $initial; }
			$row = $this->requests->find( $initial['id'], true );
			if ( is_wp_error( $row ) || ! is_array( $row ) ) { return is_wp_error( $row ) ? $row : $this->invalid(); }
			if ( in_array( $row['status'], array( 'issued', 'cancelled' ), true ) || 'local' !== $row['restore_state'] ) { return $row; }
			$receipt = $this->issuance->pending_receipt( $row['id'] );
			if ( is_wp_error( $receipt ) ) { return $receipt; }
			if ( is_array( $receipt ) ) { return $this->finish( $row, $receipt ); }
			return ( new PendingRequestPolicy() )->expired( $row, $policy, time() ) ? $this->close_expired( $row ) : $row;
		} );
	}
	private function close_expired( array $row ) {
		return $this->requests->transition( $row['id'], $row['revision'], array( 'status' => 'cancelled', 'next_attempt_at' => null, 'review_sha256' => null, 'blockers_json' => '[{"code":"request_expired"}]' ), 'request_expired' );
	}
	private function invalid() { return new \WP_Error( 'certloom_pending_reconcile_required', __( 'Certloom could not safely reconcile this certificate request. Review its history before retrying.', 'certloom' ) ); }
}
