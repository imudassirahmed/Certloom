<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Issuance\IssuanceEvidence;
use Certloom\Application\Issuance\IssuanceIntake;
use Certloom\Application\Contract\PendingRequestSelectionInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable intent and append-only receipts; execution jobs are separate and disposable. */
final class IssuanceRequestRepository implements PendingRequestSelectionInterface {
	const SOURCE_TYPE = 'pending_request';
	private $store;
	public function __construct( ?RecipientWorkflowStore $store = null ) { $this->store = $store ?: new RecipientWorkflowStore(); }
	public function find( $id, $lock = false ) { return $this->store->find( 'issuance_requests', $id, $lock ); }
	public function find_by_public_ref( $ref ) {
		if ( ! is_string( $ref ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $ref ) ) { return $this->invalid(); }
		$rows = $this->store->select( 'issuance_requests', array( 'public_ref' => $ref ), 1 ); return is_wp_error( $rows ) ? $rows : ( $rows[0] ?? null );
	}
	public function find_by_intake_key( $key, $lock = false ) {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $key ) ) { return $this->invalid(); }
		global $wpdb; $wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE intake_key=%s' . ( $lock ? ' FOR UPDATE' : '' ), SchemaManager::table( 'issuance_requests' ), $key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		return is_array( $row ) ? $this->store->checked_row( 'issuance_requests', $row ) : null;
	}
	public function for_recipient( $recipient_id, $after_id = 0, $limit = 50 ) { return $this->store->select( 'issuance_requests', array( 'recipient_id' => absint( $recipient_id ) ), $limit, $after_id ); }

	/** Staff-only caller: bounded status/due or recent primary-key pages, never OFFSET. */
	public function staff_page( $status, $cursor_id = 0, $cursor_due = null, $program_id = 0 ) {
		if ( $program_id ) { return $this->staff_program_page( $program_id, $status, $cursor_id ); }
		global $wpdb;
		$args = array( SchemaManager::table( 'issuance_requests' ), SchemaManager::table( 'recipients' ), SchemaManager::table( 'programs' ) );
		$where = array();
		if ( 'all' === $status ) {
			if ( $cursor_id ) { $where[] = 'q.id < %d'; $args[] = (int) $cursor_id; }
			$order = 'q.id DESC';
		} else {
			if ( ! in_array( $status, \Certloom\Domain\Issuance\IssuanceRequestState::all(), true ) ) { return $this->invalid(); }
			$where[] = 'q.status = %s'; $args[] = $status;
			if ( $cursor_id ) {
				if ( null === $cursor_due ) { $where[] = '((q.next_attempt_at IS NULL AND q.id > %d) OR q.next_attempt_at IS NOT NULL)'; $args[] = (int) $cursor_id; }
				else { $where[] = '(q.next_attempt_at > %s OR (q.next_attempt_at = %s AND q.id > %d))'; $args[] = $cursor_due; $args[] = $cursor_due; $args[] = (int) $cursor_id; }
			}
			$order = 'q.next_attempt_at, q.id';
		}
		$index = 'all' === $status ? 'PRIMARY' : 'work_due';
		$sql = 'SELECT q.*, CASE WHEN r.privacy_anonymized_at IS NULL THEN r.display_name ELSE NULL END AS recipient_label, p.title AS program_label FROM %i q FORCE INDEX (' . $index . ') LEFT JOIN %i r ON r.id=q.recipient_id LEFT JOIN %i p ON p.id=q.program_id' . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY ' . $order . ' LIMIT 51';
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
		return $this->checked_staff_rows( $rows );
	}

	/** Each status branch is limited before the small merge; no full Program sort. */
	private function staff_program_page( $program_id, $status, $cursor_id ) {
		global $wpdb;
		$states = 'all' === $status ? \Certloom\Domain\Issuance\IssuanceRequestState::all() : array( $status );
		$branches = array();
		foreach ( $states as $state ) {
			if ( ! in_array( $state, \Certloom\Domain\Issuance\IssuanceRequestState::all(), true ) ) { return $this->invalid(); }
			$args = array( SchemaManager::table( 'issuance_requests' ), (int) $program_id, $state );
			$sql = 'SELECT * FROM %i FORCE INDEX (program_status) WHERE program_id=%d AND status=%s';
			if ( $cursor_id ) { $sql .= ' AND id<%d'; $args[] = (int) $cursor_id; }
			$branches[] = '(' . $wpdb->prepare( $sql . ' ORDER BY id DESC LIMIT 51', $args ) . ')';
		}
		$labels = $wpdb->prepare( ' LEFT JOIN %i r ON r.id=q.recipient_id LEFT JOIN %i p ON p.id=q.program_id', SchemaManager::table( 'recipients' ), SchemaManager::table( 'programs' ) );
		$sql = 'SELECT q.*, CASE WHEN r.privacy_anonymized_at IS NULL THEN r.display_name ELSE NULL END AS recipient_label, p.title AS program_label FROM (' . implode( ' UNION ALL ', $branches ) . ') q' . $labels . ' ORDER BY q.id DESC LIMIT 51';
		$wpdb->last_error = ''; $rows = $wpdb->get_results( $sql, ARRAY_A );
		return '' !== (string) $wpdb->last_error || ! is_array( $rows ) ? $this->invalid() : $this->checked_staff_rows( $rows );
	}

	private function checked_staff_rows( array $rows ) {
		foreach ( $rows as $index => $row ) {
			$labels = array( 'recipient_label' => $row['recipient_label'], 'program_label' => $row['program_label'] );
			unset( $row['recipient_label'], $row['program_label'] );
			$checked = $this->store->checked_row( 'issuance_requests', $row );
			if ( is_wp_error( $checked ) ) { return $checked; }
			$rows[ $index ] = array_merge( $checked, $labels );
		}
		return $rows;
	}
	public function history( $id, $after_id = 0 ) { return $this->store->select( 'issuance_request_events', array( 'request_id' => (int) $id ), 51, $after_id ); }
	/** Indexed bounded ownership-change preflight, independent of closed history size. */
	public function open_for_recipient( $recipient_id ) {
		global $wpdb;
		$branches = array();
		foreach ( array( 'received', 'waiting', 'ready', 'processing', 'blocked', 'failed', 'not_eligible' ) as $status ) {
			$branches[] = '(' . $wpdb->prepare( 'SELECT * FROM %i FORCE INDEX (recipient_status) WHERE recipient_id=%d AND status=%s ORDER BY id LIMIT 101', SchemaManager::table( 'issuance_requests' ), (int) $recipient_id, $status ) . ')';
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( implode( ' UNION ALL ', $branches ) . ' ORDER BY id LIMIT 101', ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
		foreach ( $rows as $key => $row ) { $rows[ $key ] = $this->store->checked_row( 'issuance_requests', $row ); if ( is_wp_error( $rows[ $key ] ) ) { return $rows[ $key ]; } }
		return $rows;
	}
	public function account_summaries( $recipient_id, $after_id = 0, $view = 'active' ) {
		global $wpdb;
		if ( ! in_array( $view, array( 'active', 'closed' ), true ) ) { return $this->invalid(); }
		$wpdb->last_error = '';
		$states = 'closed' === $view ? ' IN ' : ' NOT IN ';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT q.id, q.program_id, q.registration_id, q.status, q.restore_state, q.source_type, q.mapping_sha256, q.blockers_json, q.updated_at, p.title, q.batch_id, a.status AS registration_status FROM %i q INNER JOIN %i p ON p.id=q.program_id LEFT JOIN %i a ON a.id=q.registration_id AND a.recipient_id=q.recipient_id AND a.program_id=q.program_id WHERE q.recipient_id=%d AND q.id>%d AND q.status' . $states . '(%s,%s) ORDER BY q.id LIMIT 51', SchemaManager::table( 'issuance_requests' ), SchemaManager::table( 'programs' ), SchemaManager::table( 'program_registrations' ), absint( $recipient_id ), absint( $after_id ), 'issued', 'cancelled' ), ARRAY_A );
		return '' !== (string) $wpdb->last_error || ! is_array( $rows ) ? $this->invalid() : $rows;
	}

	/** Disposable scheduling hint; source evidence and field values stay unchanged. */
	public function mark_due_for_recipient( $recipient_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE recipient_id=%d AND restore_state=%s AND status IN (%s,%s,%s,%s,%s,%s) LIMIT 1', SchemaManager::table( 'issuance_requests' ), absint( $recipient_id ), 'local', 'received', 'waiting', 'ready', 'processing', 'blocked', 'failed' ) );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		if ( ! $exists ) { return 0; }
		return ( new Transaction() )->run( function () use ( $recipient_id ) {
			global $wpdb;
			$generation = ( new \Certloom\Infrastructure\Data\DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $generation ) ) { return $generation; }
			$wpdb->last_error = '';
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_attempt_at=%s WHERE recipient_id=%d AND restore_state=%s AND status IN (%s,%s,%s,%s,%s,%s)', SchemaManager::table( 'issuance_requests' ), current_time( 'mysql', true ), absint( $recipient_id ), 'local', 'received', 'waiting', 'ready', 'processing', 'blocked', 'failed' ) );
			return false === $changed || '' !== (string) $wpdb->last_error ? $this->invalid() : (int) $changed;
		} );
	}

	public function due_ids( $cutoff, $after_id = 0, $max_id = PHP_INT_MAX, $limit = 11 ) {
		global $wpdb;
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status IN (%s,%s,%s,%s,%s,%s) AND restore_state=%s AND next_attempt_at<=%s AND id>%d AND id<=%d ORDER BY id LIMIT %d', SchemaManager::table( 'issuance_requests' ), 'received', 'waiting', 'ready', 'processing', 'blocked', 'failed', 'local', $cutoff, absint( $after_id ), $max_id, max( 1, min( 51, (int) $limit ) ) ) );
		return '' !== (string) $wpdb->last_error || ! is_array( $ids ) ? $this->invalid() : array_map( 'intval', $ids );
	}

	/** A trusted intake adapter supplies source identity, never an account form. */
	public function receive( array $context, array $issuance, array $evidence ) {
		$allowed = array( 'recipient_id', 'program_id', 'registration_id', 'batch_id', 'source_type', 'source_ref', 'intake_key', 'mapping_sha256' );
		if ( array_diff( array_keys( $context ), $allowed ) || array_diff( array( 'recipient_id', 'program_id', 'source_type', 'intake_key' ), array_keys( $context ) ) || count( $issuance ) > 64 ) { return $this->invalid(); }
		foreach ( array( 'recipient_id', 'program_id' ) as $key ) { if ( ! is_int( $context[ $key ] ) || $context[ $key ] < 1 ) { return $this->invalid(); } }
		foreach ( array( 'registration_id', 'batch_id' ) as $key ) {
			if ( isset( $context[ $key ] ) && ( ! is_int( $context[ $key ] ) || $context[ $key ] < 0 ) ) { return $this->invalid(); }
			$context[ $key ] = empty( $context[ $key ] ) ? null : $context[ $key ];
		}
		if ( ! is_string( $context['source_type'] ) || '' === $context['source_type'] || strlen( $context['source_type'] ) > 64 || sanitize_key( $context['source_type'] ) !== $context['source_type'] || ! is_string( $context['intake_key'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $context['intake_key'] ) ) { return $this->invalid(); }
		$context['source_ref'] = $context['source_ref'] ?? null; $context['mapping_sha256'] = $context['mapping_sha256'] ?? null;
		if ( ( null !== $context['source_ref'] && ( ! is_string( $context['source_ref'] ) || '' === $context['source_ref'] || strlen( $context['source_ref'] ) > 191 ) ) || ( null !== $context['mapping_sha256'] && ( ! is_string( $context['mapping_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $context['mapping_sha256'] ) ) ) ) { return $this->invalid(); }
		$valid = ( new IssuanceIntake() )->validate_request( $issuance );
		if ( is_wp_error( $valid ) ) { return $valid; }
		foreach ( array( 'recipient_id', 'program_id', 'batch_id', 'registration_id' ) as $key ) { if ( isset( $issuance[ $key ] ) && (int) $issuance[ $key ] !== (int) ( $context[ $key ] ?? 0 ) ) { return $this->invalid(); } }
		$issuance = array_merge( $issuance, array( 'recipient_mode' => 'existing', 'recipient_id' => $context['recipient_id'], 'credential_basis' => 'program', 'program_id' => $context['program_id'], 'batch_id' => $context['batch_id'] ?: 0 ) );
		$evidence = ( new IssuanceEvidence() )->normalize( $evidence );
		if ( is_wp_error( $evidence ) ) { return $evidence; }
		try { $intake_json = ( new CanonicalJson() )->encode( array( '_meta' => array( 'schema' => 'certloom-issuance-intake', 'schema_version' => 1 ), 'request' => $issuance, 'initial_evidence' => $evidence ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
		$payload_sha256 = hash( 'sha256', $intake_json );
		$result = ( new Transaction() )->run( function () use ( $context, $intake_json, $payload_sha256, $evidence ) {
			$recipient = ( new RecipientRepository() )->find_for_update( $context['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->invalid(); }
			$existing = $this->store->select( 'issuance_requests', array( 'intake_key' => $context['intake_key'] ), 1, 0, true );
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( isset( $existing[0] ) ) { return $this->replay( $existing[0], $context, $payload_sha256 ); }
			$program = ( new ProgramRepository() )->find( $context['program_id'] );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) || empty( $program['active'] ) ) { return $this->invalid(); }
			$batch_id = $context['batch_id'] ?? null;
			if ( $batch_id ) {
				$batch = ( new BatchRepository() )->find( $batch_id );
				if ( is_wp_error( $batch ) ) { return $batch; }
				if ( ! is_array( $batch ) || empty( $batch['active'] ) || (int) $batch['program_id'] !== (int) $context['program_id'] ) { return $this->invalid(); }
			}
			$registration_id = $context['registration_id'] ?? null;
			if ( $registration_id ) {
				$registration = ( new ProgramRegistrationRepository() )->find( $registration_id, true );
				if ( is_wp_error( $registration ) ) { return $registration; }
				if ( ! is_array( $registration ) || 'cancelled' === $registration['status'] || (int) $registration['recipient_id'] !== (int) $recipient['id'] || (int) $registration['program_id'] !== (int) $program['id'] || (int) $registration['batch_id'] !== (int) $batch_id ) { return $this->invalid(); }
			}
			$now = current_time( 'mysql', true );
			$row = array_fill_keys( array_diff( SchemaManager::column_names( 'issuance_requests' ), array( 'id' ) ), null );
			$row = array_merge( $row, $context, array( 'public_ref' => bin2hex( random_bytes( 16 ) ), 'payload_sha256' => $payload_sha256, 'intake_json' => $intake_json, 'evidence_json' => ( new CanonicalJson() )->encode( $evidence ), 'evidence_revision' => 1, 'restore_state' => 'local', 'status' => 'received', 'attempt_count' => 0, 'revision' => 1, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
			$id = $this->store->insert( 'issuance_requests', $row );
			if ( is_wp_error( $id ) ) {
				$collision = $this->store->select( 'issuance_requests', array( 'intake_key' => $context['intake_key'] ), 1, 0, true );
				return ! is_wp_error( $collision ) && isset( $collision[0] ) ? $this->replay( $collision[0], $context, $payload_sha256 ) : $id;
			}
			$event = $this->append_event( $id, 1, 'request.received', 'intake_received', 0, array(), hash( 'sha256', 'received:' . $context['intake_key'] ) );
			return is_wp_error( $event ) ? $event : $this->find( $id );
		} );
		if ( ! is_wp_error( $result ) ) { do_action( 'certloom_issuance_request_changed' ); }
		return $result;
	}

	public function transition( $id, $expected_revision, array $patch, $reason_code, $actor_id = 0 ) {
		return ( new Transaction() )->run( function () use ( $id, $expected_revision, $patch, $reason_code, $actor_id ) {
			$request = $this->find( $id );
			if ( is_wp_error( $request ) ) { return $request; }
			if ( ! is_array( $request ) ) { return $this->invalid(); }
			$recipient = ( new RecipientRepository() )->find_for_update( $request['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->invalid(); }
			$request = $this->find( $id, true );
			if ( is_wp_error( $request ) ) { return $request; }
			if ( ! is_array( $request ) || in_array( $request['status'], array( 'issued', 'cancelled' ), true ) ) { return $this->invalid(); }
			if ( array_intersect( array_keys( $patch ), array( 'evidence_json', 'evidence_revision', 'restore_state', 'mapping_sha256' ) ) ) { return $this->invalid(); }
			if ( 'paused' === $request['restore_state'] && in_array( $patch['status'] ?? $request['status'], array( 'ready', 'processing', 'issued' ), true ) ) { return $this->invalid(); }
			if ( in_array( $patch['status'] ?? $request['status'], array( 'ready', 'processing' ), true ) && empty( $patch['review_sha256'] ?? $request['review_sha256'] ) ) { return $this->invalid(); }
			if ( 'issued' === ( $patch['status'] ?? '' ) ) {
				global $wpdb;
				$wpdb->last_error = '';
				$receipt = $wpdb->get_var( $wpdb->prepare( 'SELECT v.id FROM %i v INNER JOIN %i s ON s.id=v.series_id WHERE v.id=%d AND s.id=%d AND s.recipient_id=%d AND s.program_id=%d AND COALESCE(s.batch_id,0)=%d AND v.source_type=%s AND v.source_ref=%s AND v.source_idempotency_key=%s', SchemaManager::table( 'credential_versions' ), SchemaManager::table( 'credential_series' ), absint( $patch['version_id'] ?? 0 ), absint( $patch['series_id'] ?? 0 ), (int) $request['recipient_id'], (int) $request['program_id'], (int) $request['batch_id'], self::SOURCE_TYPE, 'request:' . $request['public_ref'], self::issuance_key( $request ) ) );
				if ( '' !== (string) $wpdb->last_error || ! $receipt ) { return $this->invalid(); }
			}
			$changed = $this->store->patch( 'issuance_requests', $id, $expected_revision, $patch );
			if ( is_wp_error( $changed ) ) { return $changed; }
			$event = $this->append_event( $id, (int) $changed['revision'], 'request.transition', $reason_code, $actor_id, array( 'from_status' => $request['status'], 'to_status' => $changed['status'] ) );
			return is_wp_error( $event ) ? $event : $changed;
		} );
	}

	/** New evidence is independently replay protected and cannot rewrite original intake. */
	public function update_evidence( $id, $expected_revision, $event_key, array $evidence, $actor_id = 0, $source_payload_sha256 = null ) {
		$evidence = ( new IssuanceEvidence() )->normalize( $evidence );
		if ( is_wp_error( $evidence ) ) { return $evidence; }
		if ( ! is_string( $event_key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $event_key ) ) { return $this->invalid(); }
		if ( null !== $source_payload_sha256 && ( ! is_string( $source_payload_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_payload_sha256 ) ) ) { return $this->invalid(); }
		$json = ( new CanonicalJson() )->encode( $evidence ); $digest = hash( 'sha256', $json );
		$replayed = false;
		$result = ( new Transaction() )->run( function () use ( $id, $expected_revision, $event_key, $json, $digest, $actor_id, $source_payload_sha256, &$replayed ) {
			$request = $this->find( $id );
			if ( is_wp_error( $request ) ) { return $request; }
			if ( ! is_array( $request ) ) { return $this->invalid(); }
			$recipient = ( new RecipientRepository() )->find_for_update( $request['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->invalid(); }
			$request = $this->find( $id, true );
			if ( is_wp_error( $request ) ) { return $request; }
			if ( ! is_array( $request ) ) { return $this->invalid(); }
			$events = $this->store->select( 'issuance_request_events', array( 'request_id' => (int) $id, 'event_key' => $event_key ), 1, 0, true );
			if ( is_wp_error( $events ) ) { return $events; }
			if ( isset( $events[0] ) ) {
				$meta = json_decode( (string) $events[0]['meta_json'], true );
				if ( ! is_array( $meta ) || ! hash_equals( $digest, (string) ( $meta['evidence_sha256'] ?? '' ) ) || $source_payload_sha256 !== ( $meta['source_payload_sha256'] ?? null ) ) { return $this->conflict(); }
				$replayed = true; return $request;
			}
			if ( in_array( $request['status'], array( 'issued', 'cancelled' ), true ) ) { return $this->invalid(); }
			$patch = array( 'evidence_json' => $json, 'evidence_revision' => (int) $request['evidence_revision'] + 1, 'review_sha256' => null );
			if ( 'not_eligible' === $request['status'] ) { $patch['status'] = 'received'; }
			if ( in_array( $request['status'], array( 'ready', 'processing' ), true ) ) { $patch['status'] = 'waiting'; }
			$patch['next_attempt_at'] = null;
			$changed = $this->store->patch( 'issuance_requests', $id, $expected_revision, $patch );
			if ( is_wp_error( $changed ) ) { return $changed; }
			$meta = array( 'evidence_revision' => (int) $changed['evidence_revision'], 'evidence_sha256' => $digest );
			if ( null !== $source_payload_sha256 ) { $meta['source_payload_sha256'] = $source_payload_sha256; }
			$event = $this->append_event( $id, (int) $changed['revision'], 'request.evidence_updated', 'new_source_evidence', $actor_id, $meta, $event_key );
			return is_wp_error( $event ) ? $event : $changed;
		} );
		if ( ! is_wp_error( $result ) && ! $replayed ) {
			$due = $this->mark_due_for_recipient( $result['recipient_id'] );
			if ( ! is_wp_error( $due ) ) { do_action( 'certloom_issuance_request_changed' ); }
		}
		return $result;
	}

	private function append_event( $id, $revision, $type, $reason, $actor, array $meta, $event_key = null ) {
		if ( array_diff( array_keys( $meta ), array( 'from_status', 'to_status', 'evidence_revision', 'evidence_sha256', 'source_payload_sha256' ) ) ) { return $this->invalid(); }
		return $this->store->insert( 'issuance_request_events', array( 'public_ref' => bin2hex( random_bytes( 16 ) ), 'request_id' => (int) $id, 'event_type' => $type, 'event_key' => $event_key, 'actor_user_id' => $actor ? (int) $actor : null, 'reason_code' => $reason, 'request_revision' => (int) $revision, 'meta_json' => ( new CanonicalJson() )->encode( $meta ), 'created_at' => current_time( 'mysql', true ) ) );
	}
	private function replay( array $row, array $context, $payload_sha256 ) {
		foreach ( array( 'recipient_id', 'program_id', 'registration_id', 'batch_id', 'source_type', 'source_ref', 'mapping_sha256' ) as $key ) { if ( (string) ( $row[ $key ] ?? '' ) !== (string) ( $context[ $key ] ?? '' ) ) { return $this->conflict(); } }
		return hash_equals( (string) $row['payload_sha256'], $payload_sha256 ) ? $row : $this->conflict();
	}
	public static function issuance_key( array $request ) { return hash( 'sha256', 'certloom-pending-issue-v1:' . $request['intake_key'] ); }
	private function invalid() { return new \WP_Error( 'certloom_issuance_request_invalid', __( 'This certificate request needs a valid recipient, Program, context and source.', 'certloom' ) ); }
	private function conflict() { return new \WP_Error( 'certloom_issuance_request_replay_conflict', __( 'This source event was already received with different data. Review the source mapping before retrying.', 'certloom' ), array( 'status' => 409 ) ); }
}
