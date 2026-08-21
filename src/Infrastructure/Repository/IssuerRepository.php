<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal issuer persistence; multi-brand UI comes later. */
final class IssuerRepository {
	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data ) {
		global $wpdb;
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'certloom_issuer_name_required', __( 'An issuer name is required.', 'certloom' ) );
		}
		$slug = sanitize_title( (string) ( $data['slug'] ?? $name ) );
		if ( '' === $slug ) {
			return new \WP_Error( 'certloom_issuer_slug_required', __( 'A valid issuer slug is required.', 'certloom' ) );
		}
		$now = current_time( 'mysql', true );
		$row = array(
			'name' => $name,
			'slug' => $slug,
			'logo_attachment_id' => ! empty( $data['logo_attachment_id'] ) ? absint( $data['logo_attachment_id'] ) : null,
			'seal_attachment_id' => ! empty( $data['seal_attachment_id'] ) ? absint( $data['seal_attachment_id'] ) : null,
			'settings_json' => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'issuers' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_issuer_create_failed', __( 'Certloom could not create the issuer.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'issuers' ), absint( $id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_issuer_read_failed', __( 'Certloom could not read the issuer.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id'] = absint( $row['id'] );
		$row['logo_attachment_id'] = ! empty( $row['logo_attachment_id'] ) ? absint( $row['logo_attachment_id'] ) : null;
		$row['seal_attachment_id'] = ! empty( $row['seal_attachment_id'] ) ? absint( $row['seal_attachment_id'] ) : null;
		$row['settings'] = ! empty( $row['settings_json'] ) ? json_decode( (string) $row['settings_json'], true ) : array();
		unset( $row['settings_json'] );
		return $row;
	}
}
