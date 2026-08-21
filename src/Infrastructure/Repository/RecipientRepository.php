<?php
namespace Certloom\Infrastructure\Repository;

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

		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'recipients' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_recipient_create_failed', __( 'Certloom could not create the recipient.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
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
