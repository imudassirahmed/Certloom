<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal standalone program persistence. */
final class ProgramRepository {
	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data ) {
		global $wpdb;
		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'certloom_program_title_required', __( 'A program title is required.', 'certloom' ) );
		}
		$now = current_time( 'mysql', true );
		$row = array(
			'issuer_id' => ! empty( $data['issuer_id'] ) ? absint( $data['issuer_id'] ) : null,
			'code' => self::nullable_string( $data['code'] ?? null ),
			'title' => $title,
			'description' => self::nullable_string( $data['description'] ?? null ),
			'validity_days' => isset( $data['validity_days'] ) && '' !== (string) $data['validity_days'] ? max( 0, absint( $data['validity_days'] ) ) : null,
			'active' => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'metadata_json' => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'programs' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_program_create_failed', __( 'Certloom could not create the program.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'programs' ), absint( $id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_program_read_failed', __( 'Certloom could not read the program.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id'] = absint( $row['id'] );
		$row['issuer_id'] = ! empty( $row['issuer_id'] ) ? absint( $row['issuer_id'] ) : null;
		$row['validity_days'] = null !== $row['validity_days'] ? absint( $row['validity_days'] ) : null;
		$row['active'] = ! empty( $row['active'] );
		$row['metadata'] = ! empty( $row['metadata_json'] ) ? json_decode( (string) $row['metadata_json'], true ) : array();
		unset( $row['metadata_json'] );
		return $row;
	}

	/** @return string|null */
	private static function nullable_string( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
