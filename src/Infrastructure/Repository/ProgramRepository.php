<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Data\SafeJson;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Standalone program persistence independent of LMS products. */
final class ProgramRepository {
	const HISTORY_LIMIT_MAX = 100;

	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data ) {
		global $wpdb;
		$row = $this->normalize_patch( $data, true );
		if ( is_wp_error( $row ) ) { return $row; }
		$now = current_time( 'mysql', true );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'programs' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_program_code_duplicate', __( 'That program code is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_program_create_failed', __( 'Certloom could not create the program.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/** Applies a whitelisted field patch without clearing omitted data. @return true|\WP_Error */
	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) { return new \WP_Error( 'certloom_program_id_required', __( 'A valid program is required.', 'certloom' ) ); }
		$row = $this->normalize_patch( $data, false );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( empty( $row ) ) { return true; }
		$row['updated_at'] = current_time( 'mysql', true );
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'programs' ), $row, array( 'id' => $id ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) { return new \WP_Error( 'certloom_program_code_duplicate', __( 'That program code is already in use.', 'certloom' ) ); }
			return new \WP_Error( 'certloom_program_update_failed', __( 'Certloom could not update the program.', 'certloom' ) );
		}
		return true;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 20, $status = 'all' ) {
		global $wpdb;
		$page = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		$search = trim( (string) $search );
		$status = in_array( $status, array( 'all', 'active', 'inactive' ), true ) ? $status : 'all';
		$where_parts = array();
		$args = array();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_parts[] = '(p.title LIKE %s OR p.code LIKE %s OR p.description LIKE %s)';
			$args = array( $like, $like, $like );
		}
		if ( 'active' === $status ) { $where_parts[] = 'p.active = 1'; }
		if ( 'inactive' === $status ) { $where_parts[] = 'p.active = 0'; }
		$where = $where_parts ? ' WHERE ' . implode( ' AND ', $where_parts ) : '';
		$programs = SchemaManager::table( 'programs' );
		$issuers = SchemaManager::table( 'issuers' );
		$series = SchemaManager::table( 'credential_series' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p' . $where, array_merge( array( $programs ), $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_program_list_failed', __( 'Certloom could not load programs.', 'certloom' ) );
		}
		$sql = 'SELECT p.id, p.issuer_id, p.code, p.title, p.validity_days, p.active, p.created_at, p.updated_at, i.name AS issuer_name, (SELECT COUNT(*) FROM %i s WHERE s.program_id = p.id) AS credential_count FROM %i p LEFT JOIN %i i ON i.id = p.issuer_id' . $where . ' ORDER BY p.active DESC, p.title ASC, p.id DESC LIMIT %d OFFSET %d';
		$wpdb->last_error = '';
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $series, $programs, $issuers ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) { return new \WP_Error( 'certloom_program_list_failed', __( 'Certloom could not load programs.', 'certloom' ) ); }
		foreach ( $items as &$item ) {
			$item['id'] = absint( $item['id'] ?? 0 );
			$item['issuer_id'] = ! empty( $item['issuer_id'] ) ? absint( $item['issuer_id'] ) : null;
			$item['validity_days'] = null !== ( $item['validity_days'] ?? null ) ? absint( $item['validity_days'] ) : null;
			$item['active'] = ! empty( $item['active'] );
			$item['credential_count'] = absint( $item['credential_count'] ?? 0 );
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array{total:int,active:int,inactive:int,with_credentials:int}|\WP_Error */
	public function summary() {
		global $wpdb;
		$programs = SchemaManager::table( 'programs' );
		$series = SchemaManager::table( 'credential_series' );
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) AS inactive, (SELECT COUNT(DISTINCT program_id) FROM %i WHERE program_id IS NOT NULL) AS with_credentials FROM %i', $series, $programs ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) { return new \WP_Error( 'certloom_program_summary_failed', __( 'Certloom could not load program totals.', 'certloom' ) ); }
		return array( 'total' => absint( $row['total'] ?? 0 ), 'active' => absint( $row['active'] ?? 0 ), 'inactive' => absint( $row['inactive'] ?? 0 ), 'with_credentials' => absint( $row['with_credentials'] ?? 0 ) );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function credential_history( $program_id, $limit = 20 ) {
		global $wpdb;
		$program_id = absint( $program_id );
		if ( ! $program_id ) { return array(); }
		$limit = max( 1, min( self::HISTORY_LIMIT_MAX, absint( $limit ) ) );
		$series = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$recipients = SchemaManager::table( 'recipients' );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.id AS series_id, s.public_id, s.administrative_state, s.compliance_state, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at, r.display_name FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id INNER JOIN %i r ON r.id = s.recipient_id WHERE s.program_id = %d ORDER BY s.updated_at DESC, s.id DESC LIMIT %d', $series, $versions, $recipients, $program_id, $limit ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_program_history_failed', __( 'Certloom could not load this program’s credential history.', 'certloom' ) ); }
		foreach ( $rows as &$row ) { $row['series_id'] = absint( $row['series_id'] ?? 0 ); $row['legacy_expired'] = ! empty( $row['legacy_expired'] ); }
		unset( $row );
		return $rows;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'programs' ), absint( $id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_program_read_failed', __( 'Certloom could not read the program.', 'certloom' ) ); }
		if ( ! is_array( $row ) ) { return null; }
		$row['id'] = absint( $row['id'] );
		$row['issuer_id'] = ! empty( $row['issuer_id'] ) ? absint( $row['issuer_id'] ) : null;
		$row['validity_days'] = null !== $row['validity_days'] ? absint( $row['validity_days'] ) : null;
		$row['active'] = ! empty( $row['active'] );
		$row['metadata'] = ( new SafeJson() )->decode_array( $row['metadata_json'] ?? null, 'certloom_program_metadata_corrupt', __( 'Stored program metadata is invalid JSON.', 'certloom' ) );
		unset( $row['metadata_json'] );
		return is_wp_error( $row['metadata'] ) ? $row['metadata'] : $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_patch( array $data, $creating ) {
		$row = array();
		if ( $creating || array_key_exists( 'title', $data ) ) {
			$title = trim( (string) ( $data['title'] ?? '' ) );
			if ( '' === $title ) { return new \WP_Error( 'certloom_program_title_required', __( 'A program title is required.', 'certloom' ) ); }
			if ( self::text_length( $title ) > 255 ) { return new \WP_Error( 'certloom_program_title_too_long', __( 'Program title must be 255 characters or fewer.', 'certloom' ) ); }
			$row['title'] = $title;
		}
		if ( $creating || array_key_exists( 'code', $data ) ) {
			$code = self::nullable_string( $data['code'] ?? null );
			if ( null !== $code && self::text_length( $code ) > 100 ) { return new \WP_Error( 'certloom_program_code_too_long', __( 'Program code must be 100 characters or fewer.', 'certloom' ) ); }
			$row['code'] = $code;
		}
		if ( $creating || array_key_exists( 'description', $data ) ) { $row['description'] = self::nullable_string( $data['description'] ?? null ); }
		if ( $creating || array_key_exists( 'issuer_id', $data ) ) {
			$issuer_id = ! empty( $data['issuer_id'] ) ? absint( $data['issuer_id'] ) : null;
			if ( $issuer_id ) {
				global $wpdb;
				$wpdb->last_error = '';
				$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( 'issuers' ), $issuer_id ) );
				if ( '' !== (string) $wpdb->last_error ) {
					return new \WP_Error( 'certloom_program_issuer_check_failed', __( 'Certloom could not verify the selected issuer.', 'certloom' ) );
				}
				if ( absint( $found ) !== $issuer_id ) {
					return new \WP_Error( 'certloom_program_issuer_missing', __( 'Choose an issuer that still exists.', 'certloom' ) );
				}
			}
			$row['issuer_id'] = $issuer_id;
		}
		if ( $creating || array_key_exists( 'validity_days', $data ) ) { $row['validity_days'] = null === ( $data['validity_days'] ?? null ) || '' === (string) ( $data['validity_days'] ?? '' ) ? null : max( 0, absint( $data['validity_days'] ) ); }
		if ( $creating || array_key_exists( 'active', $data ) ) { $row['active'] = isset( $data['active'] ) ? (int) (bool) $data['active'] : 1; }
		if ( array_key_exists( 'metadata', $data ) ) {
			$encoded = ( new SafeJson() )->encode_array( $data['metadata'], 'certloom_program_metadata_invalid', __( 'Program metadata could not be encoded safely.', 'certloom' ) );
			if ( is_wp_error( $encoded ) ) { return $encoded; }
			$row['metadata_json'] = $encoded;
		} elseif ( $creating ) { $row['metadata_json'] = null; }
		return $row;
	}

	/** @return string|null */
	private static function nullable_string( $value ) { $value = null === $value ? '' : trim( (string) $value ); return '' === $value ? null : $value; }
	/** @return int */
	private static function text_length( $value ) { $count = preg_match_all( '/./us', (string) $value, $matches ); return false === $count ? strlen( (string) $value ) : (int) $count; }
}
