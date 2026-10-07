<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Application\Data\SafeJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Participation is distinct from achievement and never creates a Credential. */
final class ProgramRegistrationRepository {
	private $store;
	public function __construct( ?RecipientWorkflowStore $store = null ) { $this->store = $store ?: new RecipientWorkflowStore(); }
	public function find( $id, $lock = false ) { return $this->decode( $this->store->find( 'program_registrations', $id, $lock ) ); }
	/** Never choose an arbitrary enrollment when multiple attempts share a Program/Batch. */
	public function for_issuance( $recipient_id, $program_id, $batch_id = 0, $registration_id = 0, $lock = false ) {
		if ( $registration_id ) {
			$row = $this->find( $registration_id, $lock );
			if ( is_wp_error( $row ) ) { return $row; }
			return is_array( $row ) && (int) $row['recipient_id'] === (int) $recipient_id && (int) $row['program_id'] === (int) $program_id && (int) $row['batch_id'] === (int) $batch_id && in_array( $row['status'], array( 'active', 'completed' ), true ) ? $row : $this->invalid();
		}
		global $wpdb;
		$args = array( \Certloom\Infrastructure\Database\SchemaManager::table( 'program_registrations' ), absint( $recipient_id ), absint( $program_id ) );
		$batch = $batch_id ? 'batch_id=%d' : 'batch_id IS NULL';
		if ( $batch_id ) { $args[] = absint( $batch_id ); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE recipient_id=%d AND program_id=%d AND $batch AND status IN ('active','completed') ORDER BY id LIMIT 2" . ( $lock ? ' FOR UPDATE' : '' ), $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
		if ( count( $rows ) > 1 ) { return new \WP_Error( 'certloom_registration_selection_required', __( 'This recipient has multiple enrollments in this Program. Choose the exact enrollment before issuing.', 'certloom' ) ); }
		if ( ! $rows ) { return null; }
		$row = $this->store->checked_row( 'program_registrations', $rows[0] );
		return $this->decode( $row );
	}
	public function summaries_for_recipient( $recipient_id, $after_id = 0 ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.id, a.status, p.title FROM %i a INNER JOIN %i p ON p.id=a.program_id WHERE a.recipient_id=%d AND a.id>%d AND a.status<>%s AND p.active=1 ORDER BY a.id LIMIT 51', \Certloom\Infrastructure\Database\SchemaManager::table( 'program_registrations' ), \Certloom\Infrastructure\Database\SchemaManager::table( 'programs' ), absint( $recipient_id ), absint( $after_id ), 'cancelled' ), ARRAY_A );
		return '' !== (string) $wpdb->last_error || ! is_array( $rows ) ? $this->invalid() : $rows;
	}
	public function for_recipient( $recipient_id, $after_id = 0, $limit = 50 ) {
		$rows = $this->store->select( 'program_registrations', array( 'recipient_id' => absint( $recipient_id ) ), $limit, $after_id );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$out = array(); foreach ( $rows as $row ) { $decoded = $this->decode( $row ); if ( is_wp_error( $decoded ) ) { return $decoded; } $out[] = $decoded; }
		return $out;
	}

	public function ensure( $recipient_id, $program_id, $batch_id, $source_type, $instance_ref ) {
		if ( ! is_string( $source_type ) || '' === $source_type || sanitize_key( $source_type ) !== $source_type || strlen( $source_type ) > 64 || ! is_string( $instance_ref ) || '' === trim( $instance_ref ) || strlen( $instance_ref ) > 191 ) { return $this->invalid(); }
		return ( new Transaction() )->run( function () use ( $recipient_id, $program_id, $batch_id, $source_type, $instance_ref ) {
			$recipient = ( new RecipientRepository() )->find_for_update( $recipient_id );
			$program = ( new ProgramRepository() )->find( $program_id );
			if ( is_wp_error( $recipient ) || is_wp_error( $program ) ) { return is_wp_error( $recipient ) ? $recipient : $program; }
			if ( ! is_array( $recipient ) || ! is_array( $program ) || empty( $program['active'] ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->invalid(); }
			$batch_id = absint( $batch_id );
			if ( $batch_id ) {
				$batch = ( new BatchRepository() )->find( $batch_id );
				if ( is_wp_error( $batch ) ) { return $batch; }
				if ( ! is_array( $batch ) || empty( $batch['active'] ) || (int) $batch['program_id'] !== (int) $program['id'] ) { return $this->invalid(); }
			}
			$key = hash( 'sha256', 'certloom-registration-v1:' . $source_type . ':' . $instance_ref );
			$existing = $this->store->select( 'program_registrations', array( 'recipient_id' => (int) $recipient['id'], 'program_id' => (int) $program['id'], 'instance_key' => $key ), 1, 0, true );
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( isset( $existing[0] ) ) {
				return (int) $existing[0]['batch_id'] === $batch_id && $existing[0]['source_type'] === $source_type && $existing[0]['source_ref'] === $instance_ref ? $this->decode( $existing[0] ) : $this->invalid();
			}
			$now = current_time( 'mysql', true );
			$row = array( 'recipient_id' => (int) $recipient['id'], 'program_id' => (int) $program['id'], 'batch_id' => $batch_id ?: null, 'instance_key' => $key, 'source_type' => $source_type, 'source_ref' => $instance_ref, 'status' => 'active', 'fields_json' => '{}', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now );
			$id = $this->store->insert( 'program_registrations', $row );
			return is_wp_error( $id ) ? $id : $this->find( $id );
		} );
	}

	public function save_fields( $id, $revision, array $values ) {
		return ( new Transaction() )->run( function () use ( $id, $revision, $values ) {
			$registration = $this->find( $id );
			if ( is_wp_error( $registration ) ) { return $registration; }
			if ( ! is_array( $registration ) ) { return $this->invalid(); }
			$recipient = ( new RecipientRepository() )->find_for_update( $registration['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->invalid(); }
			$json = ( new SafeJson() )->encode_array( $values, 'certloom_registration_fields_invalid', __( 'Program details could not be saved safely.', 'certloom' ) );
			if ( is_wp_error( $json ) ) { return $json; }
			return $this->decode( $this->store->patch( 'program_registrations', $id, $revision, array( 'fields_json' => $json ) ) );
		} );
	}

	private function decode( $row ) {
		if ( is_wp_error( $row ) || ! is_array( $row ) ) { return $row; }
		$row['fields'] = ( new SafeJson() )->decode_array( $row['fields_json'], 'certloom_registration_fields_invalid', __( 'Stored Program details are invalid.', 'certloom' ) );
		return is_wp_error( $row['fields'] ) ? $row['fields'] : $row;
	}
	private function invalid() { return new \WP_Error( 'certloom_registration_context_invalid', __( 'This registration needs an active Program and a matching recipient and Batch.', 'certloom' ) ); }
}
