<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Data\SafeJson;
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
		$metadata_json = null;
		if ( array_key_exists( 'metadata', $data ) ) {
			$metadata_json = ( new SafeJson() )->encode_array( $data['metadata'], 'certloom_program_metadata_invalid', __( 'Program metadata could not be encoded safely.', 'certloom' ) );
			if ( is_wp_error( $metadata_json ) ) { return $metadata_json; }
		}
		$now = current_time( 'mysql', true );
		$row = array(
			'issuer_id' => ! empty( $data['issuer_id'] ) ? absint( $data['issuer_id'] ) : null,
			'code' => self::nullable_string( $data['code'] ?? null ),
			'title' => $title,
			'description' => self::nullable_string( $data['description'] ?? null ),
			'validity_days' => isset( $data['validity_days'] ) && '' !== (string) $data['validity_days'] ? max( 0, absint( $data['validity_days'] ) ) : null,
			'active' => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'metadata_json' => $metadata_json,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'programs' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_program_create_failed', __( 'Certloom could not create the program.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Applies a whitelisted field patch without clearing omitted data.
	 *
	 * @param int $id Program ID.
	 * @param array<string,mixed> $data Submitted patch.
	 * @return true|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'certloom_program_id_required', __( 'A valid program is required.', 'certloom' ) );
		}
		$row = array();
		if ( array_key_exists( 'title', $data ) ) {
			$title = trim( (string) $data['title'] );
			if ( '' === $title ) {
				return new \WP_Error( 'certloom_program_title_required', __( 'A program title is required.', 'certloom' ) );
			}
			$row['title'] = $title;
		}
		foreach ( array( 'code', 'description' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = self::nullable_string( $data[ $key ] );
			}
		}
		if ( array_key_exists( 'issuer_id', $data ) ) {
			$row['issuer_id'] = ! empty( $data['issuer_id'] ) ? absint( $data['issuer_id'] ) : null;
		}
		if ( array_key_exists( 'validity_days', $data ) ) {
			$row['validity_days'] = null === $data['validity_days'] || '' === (string) $data['validity_days'] ? null : max( 0, absint( $data['validity_days'] ) );
		}
		if ( array_key_exists( 'active', $data ) ) {
			$row['active'] = (int) (bool) $data['active'];
		}
		if ( array_key_exists( 'metadata', $data ) ) {
			$encoded = ( new SafeJson() )->encode_array( $data['metadata'], 'certloom_program_metadata_invalid', __( 'Program metadata could not be encoded safely.', 'certloom' ) );
			if ( is_wp_error( $encoded ) ) { return $encoded; }
			$row['metadata_json'] = $encoded;
		}
		if ( empty( $row ) ) {
			return true;
		}
		$row['updated_at'] = current_time( 'mysql', true );
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'programs' ), $row, array( 'id' => $id ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_program_code_duplicate', __( 'That program code is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_program_update_failed', __( 'Certloom could not update the program.', 'certloom' ) );
		}
		return true;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 20 ) {
		global $wpdb;
		$page = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		$search = trim( (string) $search );
		$where = '';
		$args = array();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where = ' WHERE title LIKE %s OR code LIKE %s';
			$args = array( $like, $like );
		}
		$table = SchemaManager::table( 'programs' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, array_merge( array( $table ), $args ) ) );
		$items = $wpdb->get_results( $wpdb->prepare( 'SELECT id, issuer_id, code, title, validity_days, active, created_at FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( array( $table ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_program_list_failed', __( 'Certloom could not load programs.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			$item['id'] = absint( $item['id'] ?? 0 );
			$item['issuer_id'] = ! empty( $item['issuer_id'] ) ? absint( $item['issuer_id'] ) : null;
			$item['validity_days'] = null !== ( $item['validity_days'] ?? null ) ? absint( $item['validity_days'] ) : null;
			$item['active'] = ! empty( $item['active'] );
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
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
		$row['metadata'] = ( new SafeJson() )->decode_array( $row['metadata_json'] ?? null, 'certloom_program_metadata_corrupt', __( 'Stored program metadata is invalid JSON.', 'certloom' ) );
		unset( $row['metadata_json'] );
		return is_wp_error( $row['metadata'] ) ? $row['metadata'] : $row;
	}

	/** @return string|null */
	private static function nullable_string( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
