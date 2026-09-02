<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Data\SafeJson;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persistence for normalized multi-brand issuer identities and local brand settings. */
final class IssuerRepository {
	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data ) {
		global $wpdb;

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name || $this->length( $name ) > 255 ) {
			return new \WP_Error( 'certloom_issuer_name_invalid', __( 'Issuer name is required and must be 255 characters or fewer.', 'certloom' ) );
		}

		$slug = sanitize_title( (string) ( $data['slug'] ?? $name ) );
		if ( '' === $slug || $this->length( $slug ) > 191 ) {
			return new \WP_Error( 'certloom_issuer_slug_invalid', __( 'Issuer slug is required and must be 191 characters or fewer.', 'certloom' ) );
		}

		$settings_json = null;
		if ( array_key_exists( 'settings', $data ) ) {
			$settings_json = ( new SafeJson() )->encode_array( $data['settings'], 'certloom_issuer_settings_invalid', __( 'Issuer settings could not be encoded safely.', 'certloom' ) );
			if ( is_wp_error( $settings_json ) ) {
				return $settings_json;
			}
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'name'               => $name,
			'slug'               => $slug,
			'logo_attachment_id' => ! empty( $data['logo_attachment_id'] ) ? absint( $data['logo_attachment_id'] ) : null,
			'seal_attachment_id' => ! empty( $data['seal_attachment_id'] ) ? absint( $data['seal_attachment_id'] ) : null,
			'settings_json'      => $settings_json,
			'created_at'         => $now,
			'updated_at'         => $now,
		);

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		$wpdb->last_error = '';
		$inserted         = $wpdb->insert( SchemaManager::table( 'issuers' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_issuer_slug_duplicate', __( 'That issuer slug is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_issuer_create_failed', __( 'Certloom could not create the issuer.', 'certloom' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Applies a whitelisted issuer patch without clearing omitted branding/settings.
	 *
	 * @param int                 $id Issuer ID.
	 * @param array<string,mixed> $data Submitted patch.
	 * @return true|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'certloom_issuer_id_required', __( 'A valid issuer is required.', 'certloom' ) );
		}

		$row = array();
		if ( array_key_exists( 'name', $data ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name || $this->length( $name ) > 255 ) {
				return new \WP_Error( 'certloom_issuer_name_invalid', __( 'Issuer name is required and must be 255 characters or fewer.', 'certloom' ) );
			}
			$row['name'] = $name;
		}

		if ( array_key_exists( 'slug', $data ) ) {
			$slug = sanitize_title( (string) $data['slug'] );
			if ( '' === $slug || $this->length( $slug ) > 191 ) {
				return new \WP_Error( 'certloom_issuer_slug_invalid', __( 'Issuer slug is required and must be 191 characters or fewer.', 'certloom' ) );
			}
			$row['slug'] = $slug;
		}

		foreach ( array( 'logo_attachment_id', 'seal_attachment_id' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = ! empty( $data[ $key ] ) ? absint( $data[ $key ] ) : null;
			}
		}

		if ( array_key_exists( 'settings', $data ) ) {
			$encoded = ( new SafeJson() )->encode_array( $data['settings'], 'certloom_issuer_settings_invalid', __( 'Issuer settings could not be encoded safely.', 'certloom' ) );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$row['settings_json'] = $encoded;
		}

		if ( empty( $row ) ) {
			return true;
		}
		$row['updated_at'] = current_time( 'mysql', true );

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		$wpdb->last_error = '';
		$updated          = $wpdb->update( SchemaManager::table( 'issuers' ), $row, array( 'id' => $id ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_issuer_slug_duplicate', __( 'That issuer slug is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_issuer_update_failed', __( 'Certloom could not update the issuer.', 'certloom' ) );
		}
		return true;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 20 ) {
		global $wpdb;
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = trim( (string) $search );
		$where    = '';
		$args     = array();
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = ' WHERE name LIKE %s OR slug LIKE %s';
			$args  = array( $like, $like );
		}

		$table = SchemaManager::table( 'issuers' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, array_merge( array( $table ), $args ) )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_issuer_list_failed', __( 'Certloom could not load issuers.', 'certloom' ) );
		}

		$programs    = SchemaManager::table( 'programs' );
		$assignments = SchemaManager::table( 'signer_assignments' );
		$row_where   = str_replace( 'name LIKE', 'i.name LIKE', str_replace( 'slug LIKE', 'i.slug LIKE', $where ) );
		$wpdb->last_error = '';
		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.id, i.name, i.slug, i.logo_attachment_id, i.seal_attachment_id, i.created_at, (SELECT COUNT(*) FROM %i p WHERE p.issuer_id=i.id) AS program_count, (SELECT COUNT(*) FROM %i a WHERE a.scope_type=\'issuer\' AND a.scope_id=i.id AND a.active=1) AS signer_count FROM %i i' . $row_where . ' ORDER BY i.id DESC LIMIT %d OFFSET %d',
				array_merge( array( $programs, $assignments, $table ), $args, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_issuer_list_failed', __( 'Certloom could not load issuers.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			$item['id']                 = absint( $item['id'] ?? 0 );
			$item['logo_attachment_id'] = ! empty( $item['logo_attachment_id'] ) ? absint( $item['logo_attachment_id'] ) : null;
			$item['seal_attachment_id'] = ! empty( $item['seal_attachment_id'] ) ? absint( $item['seal_attachment_id'] ) : null;
			$item['program_count']       = absint( $item['program_count'] ?? 0 );
			$item['signer_count']        = absint( $item['signer_count'] ?? 0 );
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}


	/**
	 * Bounded issuer labels for workflow summaries without N+1 reads.
	 *
	 * @param array<int,int|string> $ids
	 * @return array<int,string>|\WP_Error
	 */
	public function labels_by_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) { return array(); }
		if ( count( $ids ) > 2500 ) { return new \WP_Error( 'certloom_issuer_label_limit', __( 'Too many Issuer labels were requested at once.', 'certloom' ) ); }
		global $wpdb;
		$out = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args = array_merge( array( SchemaManager::table( 'issuers' ) ), $chunk );
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,name FROM %i WHERE id IN (' . $placeholders . ')', $args ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_issuer_label_read_failed', __( 'Certloom could not load Issuer labels.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$id = absint( $row['id'] ?? 0 );
				if ( $id ) { $out[ $id ] = (string) ( $row['name'] ?? '' ); }
			}
		}
		return $out;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'issuers' ), absint( $id ) ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_issuer_read_failed', __( 'Certloom could not read the issuer.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']                 = absint( $row['id'] );
		$row['logo_attachment_id'] = ! empty( $row['logo_attachment_id'] ) ? absint( $row['logo_attachment_id'] ) : null;
		$row['seal_attachment_id'] = ! empty( $row['seal_attachment_id'] ) ? absint( $row['seal_attachment_id'] ) : null;
		$row['settings']           = ( new SafeJson() )->decode_array( $row['settings_json'] ?? null, 'certloom_issuer_settings_corrupt', __( 'Stored issuer settings are invalid JSON.', 'certloom' ) );
		unset( $row['settings_json'] );
		return is_wp_error( $row['settings'] ) ? $row['settings'] : $row;
	}

	/** @return int */
	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
