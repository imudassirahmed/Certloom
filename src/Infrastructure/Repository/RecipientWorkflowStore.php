<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Restore\DomainRestoreRowValidator;
use Certloom\Domain\Recipient\RecipientWorkflowEntities;
use Certloom\Domain\Issuance\IssuanceRequestState;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DomainGeneration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checked private row operations; application services authorize ownership and policy. */
final class RecipientWorkflowStore {
	public function find( $entity, $id, $lock = false ) {
		$rows = $this->select( $entity, array( 'id' => $id ), 1, 0, $lock );
		return is_wp_error( $rows ) ? $rows : ( $rows[0] ?? null );
	}

	public function select( $entity, array $equals, $limit = 50, $after_id = 0, $lock = false, $descending = false ) {
		$lock = $lock || \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::active();
		global $wpdb;
		if ( ! in_array( $entity, RecipientWorkflowEntities::all(), true ) ) { return $this->error(); }
		$columns = SchemaManager::column_names( $entity );
		$where = array(); $args = array( SchemaManager::table( $entity ) );
		foreach ( $equals as $column => $value ) {
			if ( ! in_array( $column, $columns, true ) || ( null !== $value && ! is_scalar( $value ) ) ) { return $this->error(); }
			$where[] = $column . ( null === $value ? ' IS NULL' : ' = %s' );
			if ( null !== $value ) { $args[] = (string) $value; }
		}
		if ( $after_id ) { $where[] = 'id > %d'; $args[] = absint( $after_id ); }
		$args[] = max( 1, min( 101, absint( $limit ) ) );
		$sql = 'SELECT * FROM %i' . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY id ' . ( $descending ? 'DESC' : 'ASC' ) . ' LIMIT %d' . ( $lock ? ' FOR UPDATE' : '' );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->error(); }
		foreach ( $rows as $index => $row ) {
			$checked = $this->checked_row( $entity, $row );
			if ( is_wp_error( $checked ) ) { return $checked; }
			$rows[ $index ] = $checked;
		}
		return $rows;
	}

	/** Domain callers receive the same integer types after a read or a mutation. */
	public function checked_row( $entity, array $row ) {
		if ( ! in_array( $entity, RecipientWorkflowEntities::all(), true ) ) { return $this->error(); }
		$valid = ( new DomainRestoreRowValidator() )->validate( $entity, $row );
		if ( is_wp_error( $valid ) ) { return $valid; }
		foreach ( array( 'id', 'recipient_id', 'program_id', 'batch_id', 'registration_id', 'scope_id', 'open_slot', 'submitted_by', 'decided_by', 'actor_user_id', 'request_id', 'request_revision', 'evidence_revision', 'series_id', 'version_id', 'attempt_count', 'revision' ) as $key ) {
			if ( isset( $row[ $key ] ) ) { $row[ $key ] = (int) $row[ $key ]; }
		}
		return $row;
	}

	public function insert( $entity, array $row ) {
		return ( new Transaction() )->run( function () use ( $entity, $row ) {
			global $wpdb;
			if ( ! in_array( $entity, RecipientWorkflowEntities::all(), true ) || isset( $row['id'] ) ) { return $this->error(); }
			$valid = ( new DomainRestoreRowValidator() )->validate( $entity, array_merge( array( 'id' => 1 ), $row ) );
			if ( is_wp_error( $valid ) ) { return $valid; }
			$generation = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $generation ) ) { return $generation; }
			$wpdb->last_error = '';
			$result = $wpdb->insert( SchemaManager::table( $entity ), $row );
			return false === $result || '' !== (string) $wpdb->last_error || ! $wpdb->insert_id ? $this->error() : (int) $wpdb->insert_id;
		} );
	}

	/** Changes never replace identity, immutable intake or candidate history. */
	public function patch( $entity, $id, $expected_revision, array $patch ) {
		return ( new Transaction() )->run( function () use ( $entity, $id, $expected_revision, $patch ) {
			global $wpdb;
			$allowed = array(
				'program_registrations' => array( 'fields_json', 'status' ),
				'field_submissions' => array( 'status', 'open_slot', 'decided_by', 'decided_at' ),
				'issuance_requests' => array( 'evidence_json', 'evidence_revision', 'mapping_sha256', 'restore_state', 'blockers_json', 'status', 'review_sha256', 'series_id', 'version_id', 'attempt_count', 'next_attempt_at' ),
			);
			if ( ! isset( $allowed[ $entity ] ) || array_diff( array_keys( $patch ), $allowed[ $entity ] ) ) { return $this->error(); }
			$row = $this->find( $entity, $id, true );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $row ) || (int) $row['revision'] !== (int) $expected_revision ) { return $this->stale(); }
			$canonical_receipt = false;
			if ( 'issuance_requests' === $entity && 'issued' === ( $patch['status'] ?? null ) ) {
				if ( 'paused' === $row['restore_state'] ) { return $this->error(); }
				$wpdb->last_error = '';
				$receipt = $wpdb->get_var( $wpdb->prepare( 'SELECT v.id FROM %i v INNER JOIN %i s ON s.id=v.series_id WHERE v.id=%d AND s.id=%d AND s.recipient_id=%d AND s.program_id=%d AND COALESCE(s.batch_id,0)=%d AND v.source_type=%s AND v.source_ref=%s AND v.source_idempotency_key=%s', SchemaManager::table( 'credential_versions' ), SchemaManager::table( 'credential_series' ), absint( $patch['version_id'] ?? 0 ), absint( $patch['series_id'] ?? 0 ), (int) $row['recipient_id'], (int) $row['program_id'], (int) $row['batch_id'], IssuanceRequestRepository::SOURCE_TYPE, 'request:' . $row['public_ref'], IssuanceRequestRepository::issuance_key( $row ) ) );
				if ( '' !== (string) $wpdb->last_error || ! $receipt ) { return $this->error(); }
				$canonical_receipt = true;
			}
			if ( 'issuance_requests' === $entity && isset( $patch['status'] ) && $patch['status'] !== $row['status'] && ! IssuanceRequestState::can_transition( $row['status'], $patch['status'], isset( $patch['evidence_revision'] ) && (int) $patch['evidence_revision'] > (int) $row['evidence_revision'], $canonical_receipt ) ) { return $this->error(); }
			if ( 'field_submissions' === $entity && ( 'pending' !== $row['status'] || ! in_array( $patch['status'] ?? '', array( 'approved', 'rejected', 'superseded', 'cancelled' ), true ) ) ) { return $this->error(); }
			$patch['revision'] = (int) $row['revision'] + 1;
			$patch['updated_at'] = current_time( 'mysql', true );
			$valid = ( new DomainRestoreRowValidator() )->validate( $entity, array_merge( $row, $patch ) );
			if ( is_wp_error( $valid ) ) { return $valid; }
			$generation = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $generation ) ) { return $generation; }
			$wpdb->last_error = '';
			$result = $wpdb->update( SchemaManager::table( $entity ), $patch, array( 'id' => absint( $id ), 'revision' => (int) $row['revision'] ) );
			if ( false === $result || '' !== (string) $wpdb->last_error ) { return $this->error(); }
			return 1 === (int) $result ? array_merge( $row, $patch ) : $this->stale();
		} );
	}

	private function error() { return new \WP_Error( 'certloom_workflow_storage_failed', __( 'Certloom could not safely read or save recipient workflow data.', 'certloom' ) ); }
	private function stale() { return new \WP_Error( 'certloom_workflow_revision_conflict', __( 'These details changed in another session. Refresh them before saving again.', 'certloom' ), array( 'status' => 409 ) ); }
}
