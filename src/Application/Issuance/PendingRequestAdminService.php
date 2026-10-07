<?php
namespace Certloom\Application\Issuance;

use Certloom\Domain\Issuance\IssuanceRequestState;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Organization operations on durable intent. No answer/evidence or qualification override. */
final class PendingRequestAdminService {
	private $requests;
	public function __construct( ?IssuanceRequestRepository $requests = null ) { $this->requests = $requests ?: new IssuanceRequestRepository(); }

	public function queue( $actor, $status = 'waiting', $cursor_id = 0, $cursor_due = null, array $input_filters = array() ) {
		if ( ! $this->allowed( $actor ) ) { return $this->forbidden(); }
		if ( ! in_array( $status, array_merge( array( 'all' ), IssuanceRequestState::all() ), true ) || ! $this->id( $cursor_id, 0 ) || ! $this->date( $cursor_due ) || ( 0 === $cursor_id && null !== $cursor_due ) || ( 'all' === $status && null !== $cursor_due ) ) { return $this->invalid(); }
		$predicate = new PendingRequestFilters(); $filters = $predicate->normalize( $input_filters );
		if ( is_wp_error( $filters ) ) { return $filters; }
		if ( $filters['program_id'] && null !== $cursor_due ) { return $this->invalid(); }
		$catalog = \Ecapla_CertVerify_Fields::get_field_map();
		$items = array(); $scanned = 0; $next = null; $more = false;
		// Sparse predicates may return no matches while keeping an explicit cursor.
		// Never scan or count the complete queue to fill a filtered page.
		for ( $slice = 0; $slice < 4; ++$slice ) {
			$rows = $this->requests->staff_page( $status, $cursor_id, $cursor_due, $filters['program_id'] );
			if ( is_wp_error( $rows ) ) { return $rows; }
			$more = count( $rows ) > 50; $rows = array_slice( $rows, 0, 50 );
			foreach ( $rows as $index => $row ) {
				++$scanned; $summary = $this->summary( $row, $actor, $catalog );
				$next = array( 'id' => $row['id'], 'due' => 'all' === $status || $filters['program_id'] ? null : $row['next_attempt_at'] );
				if ( $predicate->matches( $row, $summary, $filters ) ) { $items[] = $summary; }
				if ( 50 === count( $items ) ) { $more = $more || $index + 1 < count( $rows ); break 2; }
			}
			if ( ! $more || ! $next ) { break; }
			$cursor_id = $next['id']; $cursor_due = $next['due'];
		}
		return array( 'items' => $items, 'has_more' => $more, 'next_cursor' => $more ? $next : null, 'filters' => $filters, 'scanned' => $scanned );
	}

	/** Every Program stays reachable with primary-key pages, including inactive ones. */
	public function programs( $actor, $after_id = 0 ) {
		if ( ! $this->allowed( $actor ) ) { return $this->forbidden(); }
		if ( ! $this->id( $after_id, 0 ) ) { return $this->invalid(); }
		global $wpdb; $wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,title,code FROM %i WHERE id>%d ORDER BY id LIMIT 51', \Certloom\Infrastructure\Database\SchemaManager::table( 'programs' ), $after_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
		$more = count( $rows ) > 50; $rows = array_slice( $rows, 0, 50 ); $items = array();
		foreach ( $rows as $row ) { $items[] = array( 'id' => (int) $row['id'], 'label' => $row['title'] . ( '' !== $row['code'] ? ' · ' . $row['code'] : '' ) ); }
		return array( 'items' => $items, 'has_more' => $more, 'next_id' => $more && $items ? $items[ count( $items ) - 1 ]['id'] : null );
	}

	public function history( $actor, $id, $after_id = 0 ) {
		if ( ! $this->allowed( $actor ) ) { return $this->forbidden(); }
		if ( ! $this->id( $id ) || ! $this->id( $after_id, 0 ) ) { return $this->invalid(); }
		$row = $this->requests->find( $id );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! is_array( $row ) ) { return $this->missing(); }
		$events = $this->requests->history( $id, $after_id );
		if ( is_wp_error( $events ) ) { return $events; }
		$more = count( $events ) > 50; $events = array_slice( $events, 0, 50 ); $items = array();
		foreach ( $events as $event ) {
			$meta = json_decode( (string) $event['meta_json'], true );
			$items[] = array( 'id' => $event['id'], 'at' => $event['created_at'], 'revision' => $event['request_revision'], 'actor' => $event['actor_user_id'] ? __( 'Organization staff', 'certloom' ) : __( 'Certloom or trusted source', 'certloom' ), 'label' => $this->event_label( $event['reason_code'] ), 'from' => $this->status_label( $meta['from_status'] ?? '' ), 'to' => $this->status_label( $meta['to_status'] ?? '' ) );
		}
		return array( 'id' => $id, 'revision' => $row['revision'], 'status_label' => $this->status_label( $row['status'] ), 'items' => $items, 'has_more' => $more, 'next_id' => $more && $items ? $items[ count( $items ) - 1 ]['id'] : null );
	}

	public function command( $actor, $id, $revision, $action ) {
		if ( ! $this->allowed( $actor ) || ( 'recheck' === $action && ! user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) ) ) { return $this->forbidden(); }
		if ( ! $this->id( $id ) || ! $this->id( $revision ) || ! in_array( $action, array( 'recheck', 'cancel' ), true ) ) { return $this->invalid(); }
		$initial = $this->requests->find( $id );
		if ( is_wp_error( $initial ) ) { return $initial; }
		if ( ! is_array( $initial ) ) { return $this->missing(); }
		$result = ( new Transaction() )->run( function () use ( $initial, $actor, $revision, $action ) {
			// This subset of the worker's lock order needs no configuration locks:
			// we never assess or issue, and cancellation must work for an inactive context.
			$recipient = ( new RecipientRepository() )->find_for_update( $initial['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->conflict(); }
			$row = $this->requests->find( $initial['id'], true );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $row ) || $row['revision'] !== $revision || in_array( $row['status'], array( 'issued', 'cancelled' ), true ) ) { return $this->conflict(); }
			$receipt = ( new ManualIssuanceService() )->pending_receipt( $row['id'] );
			if ( is_wp_error( $receipt ) ) { return $receipt; }
			if ( is_array( $receipt ) ) {
				$changed = $this->requests->transition( $row['id'], $revision, array( 'status' => 'issued', 'series_id' => (int) $receipt['series_id'], 'version_id' => (int) $receipt['version_id'], 'blockers_json' => '[]', 'review_sha256' => null, 'next_attempt_at' => null ), 'canonical_receipt_reconciled', $actor );
				return is_wp_error( $changed ) ? $changed : $this->receipt( $changed, 'already_issued' );
			}
			if ( 'recheck' === $action && ! $this->executable( $row ) ) { return new \WP_Error( 'certloom_request_recheck_unavailable', __( 'This request needs new trusted qualification or an approved source/restore review. Rechecking cannot provide it.', 'certloom' ), array( 'status' => 409 ) ); }
			$patch = 'cancel' === $action ? array( 'status' => 'cancelled', 'next_attempt_at' => null, 'review_sha256' => null ) : array( 'status' => 'waiting', 'next_attempt_at' => current_time( 'mysql', true ), 'review_sha256' => null );
			$changed = $this->requests->transition( $row['id'], $revision, $patch, 'cancel' === $action ? 'organization_cancelled' : 'organization_recheck_requested', $actor );
			return is_wp_error( $changed ) ? $changed : $this->receipt( $changed, 'cancel' === $action ? 'cancelled' : 'queued' );
		} );
		if ( ! is_wp_error( $result ) && 'queued' === $result['outcome'] ) { do_action( 'certloom_issuance_request_changed' ); }
		return $result;
	}

	private function summary( array $row, $actor, array $catalog ) {
		$terminal = in_array( $row['status'], array( 'issued', 'cancelled' ), true );
		$blockers = array();
		foreach ( $terminal ? array() : ( json_decode( (string) $row['blockers_json'], true ) ?: array() ) as $blocker ) {
			$field = isset( $blocker['field_key'] ) ? ( $catalog[ $blocker['field_key'] ] ?? null ) : null;
			$policy = is_array( $field ) ? ( new \Certloom\Application\Fields\FieldCollectionPolicy() )->for_definition( $field ) : null;
			$owner = is_array( $policy ) && 'recipient' === $policy['writer'] ? 'recipient' : ( is_array( $policy ) && 'source' === $policy['writer'] ? 'source' : 'organization' );
			$code = $blocker['code'];
			if ( in_array( $code, array( 'awaiting_approval', 'design_data_missing', 'registration_required' ), true ) ) { $owner = 'organization'; }
			if ( in_array( $code, array( 'missing_current_evidence', 'missing_source_evidence', 'source_evidence_not_current', 'not_eligible' ), true ) ) { $owner = 'source'; }
			$blockers[] = array( 'field' => is_array( $field ) ? $field['label'] : '', 'owner' => $owner, 'message' => $this->blocker_label( $code ) );
		}
		$available = null !== $row['recipient_label'];
		$label = ! $terminal && 'local' !== $row['restore_state'] ? __( 'Held after restore', 'certloom' ) : $this->status_label( $row['status'] );
		return array( 'id' => $row['id'], 'revision' => $row['revision'], 'recipient' => $available ? $row['recipient_label'] : __( 'Recipient unavailable', 'certloom' ), 'program' => $row['program_label'] ?: __( 'Program unavailable', 'certloom' ), 'status' => $row['status'], 'status_label' => $label, 'notice' => $this->notice( $row ), 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'blockers' => $blockers, 'can_recheck' => $available && $this->executable( $row ) && user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ), 'can_cancel' => $available && ! $terminal );
	}
	private function executable( array $row ) { return 'local' === $row['restore_state'] && PendingRequestContext::supports_source( $row ) && in_array( $row['status'], array( 'received', 'waiting', 'ready', 'processing', 'blocked', 'failed' ), true ); }
	private function notice( array $row ) {
		if ( 'issued' === $row['status'] ) { return __( 'Certificate issued. Use Credentials for later corrections or lifecycle changes.', 'certloom' ); }
		if ( 'cancelled' === $row['status'] && false !== strpos( (string) $row['blockers_json'], '"request_expired"' ) ) { return __( 'The request deadline passed. Recipient details remain saved; a new reviewed request is needed to continue.', 'certloom' ); }
		if ( 'cancelled' === $row['status'] ) { return __( 'This request is closed. It will not be assessed again.', 'certloom' ); }
		if ( 'local' !== $row['restore_state'] ) { return __( 'Restored request held for source and ownership review. It will not resume automatically.', 'certloom' ); }
		if ( ! PendingRequestContext::supports_source( $row ) ) { return __( 'This source needs an accepted current mapping and qualification check before it can run.', 'certloom' ); }
		if ( 'not_eligible' === $row['status'] ) { return __( 'New trusted qualification is required. Completing customer details or rechecking cannot grant eligibility.', 'certloom' ); }
		if ( null !== $row['next_attempt_at'] ) { return __( 'A fresh assessment is queued. Certloom will recheck current qualification, details, approvals and design before issuing.', 'certloom' ); }
		return __( 'Resolve the items below, then request a fresh assessment. Customer details and trusted results keep their own sources.', 'certloom' );
	}
	public function status_label( $status ) {
		$labels = array( 'received' => __( 'New requests', 'certloom' ), 'waiting' => __( 'Waiting for requirements', 'certloom' ), 'ready' => __( 'Ready for final checks', 'certloom' ), 'processing' => __( 'Issuance in progress', 'certloom' ), 'issued' => __( 'Issued', 'certloom' ), 'blocked' => __( 'Needs organization review', 'certloom' ), 'failed' => __( 'Assessment failed', 'certloom' ), 'cancelled' => __( 'Cancelled', 'certloom' ), 'not_eligible' => __( 'Waiting for qualification', 'certloom' ) );
		return is_string( $status ) ? ( $labels[ $status ] ?? '' ) : '';
	}
	private function blocker_label( $code ) {
		$labels = array( 'missing' => __( 'Required information is missing.', 'certloom' ), 'needs_resubmission' => __( 'The recipient needs to update this detail.', 'certloom' ), 'awaiting_approval' => __( 'A submitted detail needs organization approval.', 'certloom' ), 'missing_current_evidence' => __( 'Current trusted qualification is required.', 'certloom' ), 'not_eligible' => __( 'The trusted source has not confirmed qualification.', 'certloom' ), 'missing_source_evidence' => __( 'A required result must come from the trusted source.', 'certloom' ), 'source_evidence_not_current' => __( 'The trusted source must refresh this result.', 'certloom' ), 'design_data_missing' => __( 'Required certificate design information is missing.', 'certloom' ), 'registration_required' => __( 'Check the recipient’s Program registration.', 'certloom' ), 'reassessment_required' => __( 'A fresh assessment is required.', 'certloom' ) );
		$labels['account_connection_unavailable'] = __( 'The recipient account is unavailable. Restore access or review and unlink the connection.', 'certloom' );
		$labels['source_mapping_changed'] = __( 'The app connection was paused, removed or changed. Review the original request and current mapping in Integrations.', 'certloom' );
		$labels['source_authority_closed'] = __( 'Automatic app issuance is paused until the current issuing authority is available.', 'certloom' );
		return $labels[ $code ] ?? __( 'Review the Program, field sources and certificate settings.', 'certloom' );
	}
	private function event_label( $reason ) {
		$notice_labels = array( 'request_expired' => __( 'Unissued request closed after its deadline', 'certloom' ), 'notice_queued' => __( 'Optional recipient notice queued', 'certloom' ), 'notice_throttle' => __( 'Daily recipient notice limit reserved', 'certloom' ), 'notice_sending' => __( 'Recipient notice attempt started', 'certloom' ), 'notice_sent' => __( 'Recipient notice accepted by mail transport', 'certloom' ), 'notice_failed' => __( 'Recipient notice not accepted by mail transport', 'certloom' ), 'notice_uncertain' => __( 'Recipient notice outcome uncertain; automatic resend held', 'certloom' ), 'notice_skipped' => __( 'Recipient notice skipped after requirements or access changed', 'certloom' ) );
		if ( isset( $notice_labels[ $reason ] ) ) { return $notice_labels[ $reason ]; }
		$labels = array( 'intake_received' => __( 'Request received', 'certloom' ), 'new_source_evidence' => __( 'Trusted qualification or results updated', 'certloom' ), 'readiness_blocked' => __( 'Requirements checked; further action needed', 'certloom' ), 'readiness_confirmed' => __( 'Requirements ready for final checks', 'certloom' ), 'issuance_started' => __( 'Canonical issuance started', 'certloom' ), 'issuance_completed' => __( 'Certificate issued', 'certloom' ), 'canonical_receipt_reconciled' => __( 'Existing issued certificate reconciled', 'certloom' ), 'organization_recheck_requested' => __( 'Organization requested a fresh assessment', 'certloom' ), 'organization_cancelled' => __( 'Organization cancelled the unissued request', 'certloom' ), 'account_connection_changed' => __( 'Request closed after the recipient account was disconnected', 'certloom' ) );
		return $labels[ $reason ] ?? __( 'Request activity recorded', 'certloom' );
	}
	private function receipt( array $row, $outcome ) { return array( 'id' => $row['id'], 'revision' => $row['revision'], 'status' => $row['status'], 'outcome' => $outcome ); }
	private function allowed( $actor ) { return $this->id( $actor ) && user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ); }
	private function id( $value, $minimum = 1 ) { return is_int( $value ) && $value >= $minimum; }
	private function date( $value ) { if ( null === $value ) { return true; } if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) { return false; } $date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) ); return $date && $date->format( 'Y-m-d H:i:s' ) === $value; }
	private function forbidden() { return new \WP_Error( 'certloom_request_admin_forbidden', __( 'You cannot manage these certificate requests.', 'certloom' ), array( 'status' => 403 ) ); }
	private function invalid() { return new \WP_Error( 'certloom_request_admin_invalid', __( 'Refresh the request list and try again.', 'certloom' ), array( 'status' => 400 ) ); }
	private function conflict() { return new \WP_Error( 'certloom_request_admin_conflict', __( 'This request changed or is already closed. Refresh its status before acting again.', 'certloom' ), array( 'status' => 409 ) ); }
	private function missing() { return new \WP_Error( 'certloom_request_admin_missing', __( 'This certificate request could not be found.', 'certloom' ), array( 'status' => 404 ) ); }
}
