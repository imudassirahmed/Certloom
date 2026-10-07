<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Domain\Recipient\FieldSubmissionScope;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable proposed values; decisions name the exact candidate revision. */
final class FieldSubmissionRepository {
	private $store;
	public function __construct( ?RecipientWorkflowStore $store = null ) { $this->store = $store ?: new RecipientWorkflowStore(); }
	public function find( $id, $lock = false ) { return $this->store->find( 'field_submissions', $id, $lock ); }
	public function latest( $recipient_id, $kind, $scope_id, $key ) {
		$rows = $this->store->select( 'field_submissions', array( 'scope_key' => FieldSubmissionScope::key( $recipient_id, $kind, $scope_id, $key ) ), 1, 0, false, true );
		return is_wp_error( $rows ) ? $rows : ( $rows[0] ?? null );
	}
	public function pending( $limit = 50, $after_id = 0 ) { return $this->store->select( 'field_submissions', array( 'status' => 'pending' ), $limit, $after_id ); }

	/** Bounded queue joined to owner/context labels without loading account identities. */
	public function pending_for_review( $after_id = 0 ) {
		global $wpdb;
		$schema = \Certloom\Infrastructure\Database\SchemaManager::class;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.*, r.display_name AS recipient_name, p.title AS program_title, g.program_id AS context_program_id, g.batch_id AS context_batch_id, g.status AS context_status FROM %i s INNER JOIN %i r ON r.id=s.recipient_id LEFT JOIN %i g ON s.scope_kind=%s AND g.id=s.scope_id LEFT JOIN %i p ON p.id=g.program_id WHERE s.status=%s AND r.privacy_anonymized_at IS NULL AND s.id>%d ORDER BY s.id LIMIT 51', $schema::table( 'field_submissions' ), $schema::table( 'recipients' ), $schema::table( 'program_registrations' ), 'registration', $schema::table( 'programs' ), 'pending', absint( $after_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_submission_read_failed', __( 'Certloom could not load submitted details safely.', 'certloom' ) ); }
		$out = array();
		foreach ( $rows as $row ) {
			$labels = array( 'recipient_name' => (string) $row['recipient_name'], 'program_title' => (string) ( $row['program_title'] ?? '' ), 'context_program_id' => (int) ( $row['context_program_id'] ?? 0 ), 'context_batch_id' => (int) ( $row['context_batch_id'] ?? 0 ), 'context_status' => (string) ( $row['context_status'] ?? '' ) );
			unset( $row['recipient_name'], $row['program_title'], $row['context_program_id'], $row['context_batch_id'], $row['context_status'] );
			$checked = $this->store->checked_row( 'field_submissions', $row );
			if ( is_wp_error( $checked ) ) { return $checked; }
			$out[] = $checked + $labels;
		}
		return $out;
	}

	/** One indexed query for an account form; historic revisions do not multiply reads. */
	public function latest_map( $recipient_id, $kind, $scope_id, $lock = false ) {
		global $wpdb;
		if ( ! in_array( $kind, array( 'recipient', 'registration' ), true ) ) { return new \WP_Error( 'certloom_submission_scope_invalid', __( 'The field submission scope is invalid.', 'certloom' ) ); }
		$table = \Certloom\Infrastructure\Database\SchemaManager::table( 'field_submissions' );
		$wpdb->last_error = '';
		$lock = $lock || \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::active();
		$current = $lock ? ' FOR UPDATE' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.* FROM %i s INNER JOIN (SELECT field_key, MAX(id) AS latest_id FROM %i WHERE recipient_id=%d AND scope_kind=%s AND scope_id=%d GROUP BY field_key' . $current . ') newest ON newest.latest_id=s.id ORDER BY s.field_key LIMIT 129' . $current, $table, $table, absint( $recipient_id ), $kind, absint( $scope_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 128 ) { return new \WP_Error( 'certloom_submission_read_failed', __( 'Certloom could not load submitted details safely.', 'certloom' ) ); }
		$out = array();
		foreach ( $rows as $row ) {
			$checked = $this->store->checked_row( 'field_submissions', $row );
			if ( is_wp_error( $checked ) ) { return $checked; }
			$out[ $checked['field_key'] ] = $checked;
		}
		return $out;
	}

	/** Caller validates its field policy and holds the canonical Recipient lock. */
	public function propose( $recipient_id, $kind, $scope_id, $key, $value, $proof, $actor_id ) {
		return ( new Transaction() )->run( function () use ( $recipient_id, $kind, $scope_id, $key, $value, $proof, $actor_id ) {
			$recipient = ( new RecipientRepository() )->find_for_update( $recipient_id );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return new \WP_Error( 'certloom_submission_recipient_invalid', __( 'This recipient cannot submit details.', 'certloom' ) ); }
			if ( 'registration' === $kind ) {
				$registration = ( new ProgramRegistrationRepository() )->find( $scope_id, true );
				if ( is_wp_error( $registration ) ) { return $registration; }
				if ( ! is_array( $registration ) || (int) $registration['recipient_id'] !== (int) $recipient['id'] || 'cancelled' === $registration['status'] ) { return new \WP_Error( 'certloom_submission_context_invalid', __( 'This Program registration cannot accept details.', 'certloom' ) ); }
			}
			$scope_key = FieldSubmissionScope::key( $recipient_id, $kind, $scope_id, $key );
			$pending = $this->store->select( 'field_submissions', array( 'scope_key' => $scope_key, 'open_slot' => 1 ), 1, 0, true );
			if ( is_wp_error( $pending ) ) { return $pending; }
			if ( isset( $pending[0] ) ) {
				if ( (string) $pending[0]['candidate_value'] === $value && hash_equals( $pending[0]['policy_sha256'], $proof ) ) { return $pending[0]; }
				$closed = $this->decide( $pending[0]['id'], $pending[0]['revision'], 'superseded', null );
				if ( is_wp_error( $closed ) ) { return $closed; }
			}
			$now = current_time( 'mysql', true );
			$row = array( 'public_ref' => bin2hex( random_bytes( 16 ) ), 'recipient_id' => (int) $recipient_id, 'scope_kind' => $kind, 'scope_id' => (int) $scope_id, 'field_key' => $key, 'scope_key' => $scope_key, 'open_slot' => 1, 'candidate_value' => $value, 'policy_sha256' => $proof, 'status' => 'pending', 'submitted_by' => (int) $actor_id, 'decided_by' => null, 'decided_at' => null, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now );
			$id = $this->store->insert( 'field_submissions', $row );
			return is_wp_error( $id ) ? $id : $this->find( $id );
		} );
	}

	public function decide( $id, $revision, $status, $actor_id ) {
		return $this->store->patch( 'field_submissions', $id, $revision, array( 'status' => $status, 'open_slot' => null, 'decided_by' => $actor_id ? (int) $actor_id : null, 'decided_at' => current_time( 'mysql', true ) ) );
	}
}
