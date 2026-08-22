<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal recipient persistence for the new domain model. */
final class RecipientRepository {
	/**
	 * @param array<string,mixed> $data Recipient fields.
	 * @return int|\WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;
		$display_name = trim( (string) ( $data['display_name'] ?? '' ) );
		if ( '' === $display_name ) {
			return new \WP_Error( 'certloom_recipient_name_required', __( 'A recipient display name is required.', 'certloom' ) );
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'display_name' => $display_name,
			'first_name' => self::nullable_string( $data['first_name'] ?? null ),
			'middle_name' => self::nullable_string( $data['middle_name'] ?? null ),
			'last_name' => self::nullable_string( $data['last_name'] ?? null ),
			'email' => self::nullable_string( $data['email'] ?? null ),
			'wp_user_id' => ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null,
			'external_id' => self::nullable_string( $data['external_id'] ?? null ),
			'custom_fields' => isset( $data['custom_fields'] ) ? wp_json_encode( $data['custom_fields'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'recipients' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_recipient_create_failed', __( 'Certloom could not create the recipient.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates only explicitly supplied recipient fields. Missing keys are never
	 * interpreted as clears, which is the repository-level basis for LOCKDATA-01.
	 *
	 * @param int $id Recipient ID.
	 * @param array<string,mixed> $data Submitted patch.
	 * @return true|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'certloom_recipient_id_required', __( 'A valid recipient is required.', 'certloom' ) );
		}
		$row = array();
		if ( array_key_exists( 'display_name', $data ) ) {
			$name = trim( (string) $data['display_name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'certloom_recipient_name_required', __( 'A recipient display name is required.', 'certloom' ) );
			}
			$row['display_name'] = $name;
		}
		foreach ( array( 'first_name', 'middle_name', 'last_name', 'email', 'external_id' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = self::nullable_string( $data[ $key ] );
			}
		}
		if ( array_key_exists( 'wp_user_id', $data ) ) {
			$row['wp_user_id'] = ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null;
		}
		if ( array_key_exists( 'custom_fields', $data ) ) {
			$row['custom_fields'] = wp_json_encode( is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array() );
		}
		if ( empty( $row ) ) {
			return true;
		}
		$row['updated_at'] = current_time( 'mysql', true );
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'recipients' ), $row, array( 'id' => $id ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_recipient_update_failed', __( 'Certloom could not update the recipient.', 'certloom' ) );
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
			$where = ' WHERE display_name LIKE %s OR email LIKE %s OR external_id LIKE %s';
			$args = array( $like, $like, $like );
		}
		$table = SchemaManager::table( 'recipients' );
		$count_sql = 'SELECT COUNT(*) FROM %i' . $where;
		$list_sql = 'SELECT id, display_name, email, external_id, wp_user_id, created_at FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $table ), $args ) ) );
		$items = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( array( $table ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_recipient_list_failed', __( 'Certloom could not load recipients.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			$item['id'] = absint( $item['id'] ?? 0 );
			$item['wp_user_id'] = ! empty( $item['wp_user_id'] ) ? absint( $item['wp_user_id'] ) : null;
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'recipients' ), absint( $id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_recipient_read_failed', __( 'Certloom could not read the recipient.', 'certloom' ) );
		}
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/** @return array<string,mixed> */
	private function decode( array $row ) {
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['wp_user_id'] = ! empty( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : null;
		$row['custom_fields'] = ! empty( $row['custom_fields'] ) ? json_decode( (string) $row['custom_fields'], true ) : array();
		if ( ! is_array( $row['custom_fields'] ) ) {
			$row['custom_fields'] = array();
		}
		return $row;
	}

	/** @return string|null */
	private static function nullable_string( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
