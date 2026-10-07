<?php
namespace Certloom\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Additive recipient-workflow table definitions and verification contracts.
 * One descriptor supplies DDL and structural expectations, avoiding drift
 * between migrations, restore column whitelists and schema health checks.
 */
final class RecipientWorkflowSchema {

	/** @return array<string,array<string,mixed>> */
	private static function descriptors() {
		$id = array( 'BIGINT UNSIGNED', false, null, true );
		$reference = array( 'BIGINT UNSIGNED', false );
		$optional_id = array( 'BIGINT UNSIGNED', true );
		$datetime = array( 'DATETIME', false );
		$optional_date = array( 'DATETIME', true );
		$json = array( 'LONGTEXT', true );
		$revision = array( 'BIGINT UNSIGNED', false, '1' );
		return array(
			'program_registrations' => array(
				'columns' => array(
					'id' => $id,
					'recipient_id' => $reference,
					'program_id' => $reference,
					'batch_id' => $optional_id,
					'instance_key' => array( 'CHAR(64)', false ),
					'source_type' => array( 'VARCHAR(64)', false ),
					'source_ref' => array( 'VARCHAR(191)', true ),
					'status' => array( 'VARCHAR(20)', false, 'active' ),
					'fields_json' => $json,
					'revision' => $revision,
					'created_at' => $datetime,
					'updated_at' => $datetime,
				),
				'indexes' => array(
					'PRIMARY' => array( array( 'id' ), true ),
					'participation' => array( array( 'recipient_id', 'program_id', 'instance_key' ), true ),
					'recipient_status' => array( array( 'recipient_id', 'status', 'id' ), false ),
					'program_status' => array( array( 'program_id', 'status', 'id' ), false ),
					'batch_id' => array( array( 'batch_id' ), false ),
				),
			),
			'field_submissions' => array(
				'columns' => array(
					'id' => $id,
					'public_ref' => array( 'CHAR(32)', false ),
					'recipient_id' => $reference,
					'scope_kind' => array( 'VARCHAR(20)', false ),
					'scope_id' => $reference,
					'field_key' => array( 'VARCHAR(64)', false ),
					'scope_key' => array( 'CHAR(64)', false ),
					'open_slot' => $optional_id,
					'candidate_value' => array( 'LONGTEXT', false ),
					'policy_sha256' => array( 'CHAR(64)', false ),
					'status' => array( 'VARCHAR(20)', false, 'pending' ),
					'submitted_by' => $optional_id,
					'decided_by' => $optional_id,
					'decided_at' => $optional_date,
					'revision' => $revision,
					'created_at' => $datetime,
					'updated_at' => $datetime,
				),
				'indexes' => array(
					'PRIMARY' => array( array( 'id' ), true ),
					'public_ref' => array( array( 'public_ref' ), true ),
					'open_submission' => array( array( 'scope_key', 'open_slot' ), true ),
					'scope_history' => array( array( 'scope_key', 'id' ), false ),
					'recipient_scope' => array( array( 'recipient_id', 'scope_kind', 'scope_id', 'field_key', 'id' ), false ),
					'recipient_status' => array( array( 'recipient_id', 'status', 'id' ), false ),
					'pending_age' => array( array( 'status', 'created_at', 'id' ), false ),
				),
			),
			'issuance_requests' => array(
				'columns' => array(
					'id' => $id,
					'public_ref' => array( 'CHAR(32)', false ),
					'intake_key' => array( 'CHAR(64)', false ),
					'payload_sha256' => array( 'CHAR(64)', false ),
					'recipient_id' => $reference,
					'program_id' => $reference,
					'registration_id' => $optional_id,
					'batch_id' => $optional_id,
					'source_type' => array( 'VARCHAR(64)', false ),
					'source_ref' => array( 'VARCHAR(191)', true ),
					'intake_json' => array( 'LONGTEXT', false ),
					'evidence_json' => $json,
					'evidence_revision' => $revision,
					'mapping_sha256' => array( 'CHAR(64)', true ),
					'restore_state' => array( 'VARCHAR(20)', false, 'local' ),
					'blockers_json' => $json,
					'status' => array( 'VARCHAR(20)', false, 'received' ),
					'review_sha256' => array( 'CHAR(64)', true ),
					'series_id' => $optional_id,
					'version_id' => $optional_id,
					'attempt_count' => array( 'BIGINT UNSIGNED', false, '0' ),
					'next_attempt_at' => $optional_date,
					'revision' => $revision,
					'created_at' => $datetime,
					'updated_at' => $datetime,
				),
				'indexes' => array(
					'PRIMARY' => array( array( 'id' ), true ),
					'public_ref' => array( array( 'public_ref' ), true ),
					'intake_key' => array( array( 'intake_key' ), true ),
					'recipient_status' => array( array( 'recipient_id', 'status', 'id' ), false ),
					'program_status' => array( array( 'program_id', 'status', 'id' ), false ),
					'registration_id' => array( array( 'registration_id' ), false ),
					'work_due' => array( array( 'status', 'next_attempt_at', 'id' ), false ),
				),
			),
			'issuance_request_events' => array(
				'columns' => array(
					'id' => $id,
					'public_ref' => array( 'CHAR(32)', false ),
					'request_id' => $reference,
					'event_type' => array( 'VARCHAR(64)', false ),
					'event_key' => array( 'CHAR(64)', true ),
					'actor_user_id' => $optional_id,
					'reason_code' => array( 'VARCHAR(64)', false ),
					'request_revision' => $reference,
					'meta_json' => $json,
					'created_at' => $datetime,
				),
				'indexes' => array(
					'PRIMARY' => array( array( 'id' ), true ),
					'public_ref' => array( array( 'public_ref' ), true ),
					'request_history' => array( array( 'request_id', 'id' ), false ),
					'source_event' => array( array( 'request_id', 'event_key' ), true ),
					'event_created' => array( array( 'event_type', 'created_at' ), false ),
				),
			),
		);
	}

	/** @return array<int,string> */
	public static function entity_types() {
		return array_keys( self::descriptors() );
	}

	/** @return array<string,string> */
	public static function sql( $charset_collate ) {
		$out = array();
		foreach ( self::descriptors() as $name => $descriptor ) {
			$lines = array();
			foreach ( $descriptor['columns'] as $column => $definition ) {
				$sql = $column . ' ' . $definition[0] . ( $definition[1] ? ' NULL' : ' NOT NULL' );
				if ( isset( $definition[2] ) ) { $sql .= " DEFAULT '" . $definition[2] . "'"; }
				if ( ! empty( $definition[3] ) ) { $sql .= ' AUTO_INCREMENT'; }
				$lines[] = $sql;
			}
			foreach ( $descriptor['indexes'] as $index => $definition ) {
				$lines[] = ( 'PRIMARY' === $index ? 'PRIMARY KEY ' : ( $definition[1] ? 'UNIQUE KEY ' : 'KEY ' ) . $index ) . ' (' . implode( ',', $definition[0] ) . ')';
			}
			$out[ $name ] = 'CREATE TABLE ' . SchemaManager::table( $name ) . " (\n\t" . implode( ",\n\t", $lines ) . "\n) ENGINE=InnoDB " . $charset_collate . ';';
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	public static function contracts() {
		$out = array();
		foreach ( self::descriptors() as $name => $descriptor ) {
			$columns = array();
			foreach ( $descriptor['columns'] as $column => $definition ) {
				$type = strtolower( $definition[0] );
				$columns[ $column ] = array(
					'type' => 'bigint unsigned' === $type ? '/^bigint(?:\(20\))? unsigned$/' : '/^' . preg_quote( $type, '/' ) . '$/',
					'null' => $definition[1] ? 'YES' : 'NO',
				);
				if ( isset( $definition[2] ) ) { $columns[ $column ]['default'] = $definition[2]; }
				if ( ! empty( $definition[3] ) ) { $columns[ $column ]['extra'] = 'auto_increment'; }
			}
			$indexes = array();
			foreach ( $descriptor['indexes'] as $index => $definition ) {
				$indexes[ $index ] = array( 'columns' => $definition[0], 'unique' => $definition[1] );
			}
			$out[ $name ] = array( 'columns' => $columns, 'indexes' => $indexes );
		}
		return $out;
	}
}
