<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Data\SafeJson;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Recipient persistence for the normalized credential domain. */
final class RecipientRepository {
	const HISTORY_LIMIT_MAX = 100;

	/** @param array<string,mixed> $data Recipient fields. @return int|\WP_Error */
	public function create( array $data ) {
		global $wpdb;
		$validated = $this->normalize_patch( $data, true );
		if ( is_wp_error( $validated ) ) { return $validated; }

		$now = current_time( 'mysql', true );
		$row = array_merge( $validated, array( 'created_at' => $now, 'updated_at' => $now ) );
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'recipients' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'wp_user_id' ) || false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_recipient_wp_user_duplicate', __( 'That WordPress user is already linked to another Certloom recipient.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_recipient_create_failed', __( 'Certloom could not create the recipient.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/** Updates only explicitly supplied recipient fields. @param int $id @param array<string,mixed> $data @return true|\WP_Error */
	public function update( $id, array $data ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'certloom_recipient_id_required', __( 'A valid recipient is required.', 'certloom' ) );
		}
		$row = $this->normalize_patch( $data, false );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( empty( $row ) ) { return true; }
		$row['updated_at'] = current_time( 'mysql', true );
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'recipients' ), $row, array( 'id' => $id ) );
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'wp_user_id' ) || false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_recipient_wp_user_duplicate', __( 'That WordPress user is already linked to another Certloom recipient.', 'certloom' ) );
			}
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
			$where = ' WHERE r.display_name LIKE %s OR r.email LIKE %s OR r.external_id LIKE %s';
			$args = array( $like, $like, $like );
		}
		$table = SchemaManager::table( 'recipients' );
		$series = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$count_sql = 'SELECT COUNT(*) FROM %i r' . $where;
		$list_sql = 'SELECT r.id, r.display_name, r.first_name, r.last_name, r.email, r.external_id, r.wp_user_id, r.created_at, r.updated_at, '
			. '(SELECT COUNT(*) FROM %i s WHERE s.recipient_id = r.id) AS credential_count, '
			. '(SELECT MAX(v.issued_at) FROM %i s2 INNER JOIN %i v ON v.id = s2.current_version_id WHERE s2.recipient_id = r.id) AS latest_issued_at '
			. 'FROM %i r' . $where . ' ORDER BY r.id DESC LIMIT %d OFFSET %d';
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $table ), $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_recipient_list_failed', __( 'Certloom could not load recipients.', 'certloom' ) );
		}
		$list_args = array_merge( array( $series, $series, $versions, $table ), $args, array( $per_page, $offset ) );
		$wpdb->last_error = '';
		$items = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_recipient_list_failed', __( 'Certloom could not load recipients.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			$item['id'] = absint( $item['id'] ?? 0 );
			$item['wp_user_id'] = ! empty( $item['wp_user_id'] ) ? absint( $item['wp_user_id'] ) : null;
			$item['credential_count'] = absint( $item['credential_count'] ?? 0 );
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array{total:int,with_email:int,linked_users:int,with_credentials:int}|\WP_Error */
	public function summary() {
		global $wpdb;
		$recipients = SchemaManager::table( 'recipients' );
		$series = SchemaManager::table( 'credential_series' );
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(CASE WHEN email IS NOT NULL AND email <> %s THEN 1 ELSE 0 END) AS with_email, SUM(CASE WHEN wp_user_id IS NOT NULL THEN 1 ELSE 0 END) AS linked_users, (SELECT COUNT(DISTINCT recipient_id) FROM %i) AS with_credentials FROM %i',
				'', $series, $recipients
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return new \WP_Error( 'certloom_recipient_summary_failed', __( 'Certloom could not load recipient totals.', 'certloom' ) );
		}
		return array(
			'total' => absint( $row['total'] ?? 0 ),
			'with_email' => absint( $row['with_email'] ?? 0 ),
			'linked_users' => absint( $row['linked_users'] ?? 0 ),
			'with_credentials' => absint( $row['with_credentials'] ?? 0 ),
		);
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function credential_history( $recipient_id, $limit = 20 ) {
		global $wpdb;
		$recipient_id = absint( $recipient_id );
		if ( ! $recipient_id ) { return array(); }
		$limit = max( 1, min( self::HISTORY_LIMIT_MAX, absint( $limit ) ) );
		$series = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$programs = SchemaManager::table( 'programs' );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.id AS series_id, s.public_id, s.administrative_state, s.compliance_state, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at, p.title AS program_title FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id LEFT JOIN %i p ON p.id = s.program_id WHERE s.recipient_id = %d ORDER BY s.updated_at DESC, s.id DESC LIMIT %d',
				$series, $versions, $programs, $recipient_id, $limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_recipient_history_failed', __( 'Certloom could not load this recipient’s credential history.', 'certloom' ) );
		}
		foreach ( $rows as &$row ) {
			$row['series_id'] = absint( $row['series_id'] ?? 0 );
			$row['legacy_expired'] = ! empty( $row['legacy_expired'] );
		}
		unset( $row );
		return $rows;
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

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_patch( array $data, $creating ) {
		$row = array();
		if ( $creating || array_key_exists( 'display_name', $data ) ) {
			$name = trim( (string) ( $data['display_name'] ?? '' ) );
			if ( '' === $name ) { return new \WP_Error( 'certloom_recipient_name_required', __( 'A recipient display name is required.', 'certloom' ) ); }
			if ( self::text_length( $name ) > 255 ) { return new \WP_Error( 'certloom_recipient_name_too_long', __( 'Recipient display name must be 255 characters or fewer.', 'certloom' ) ); }
			$row['display_name'] = $name;
		}
		foreach ( array( 'first_name', 'middle_name', 'last_name', 'external_id' ) as $key ) {
			if ( $creating || array_key_exists( $key, $data ) ) {
				$value = self::nullable_string( $data[ $key ] ?? null );
				if ( null !== $value && self::text_length( $value ) > 191 ) { return new \WP_Error( 'certloom_recipient_field_too_long', __( 'Recipient names and external IDs must be 191 characters or fewer.', 'certloom' ) ); }
				$row[ $key ] = $value;
			}
		}
		if ( $creating || array_key_exists( 'email', $data ) ) {
			$email = self::nullable_string( $data['email'] ?? null );
			if ( null !== $email && self::text_length( $email ) > 320 ) { return new \WP_Error( 'certloom_recipient_email_too_long', __( 'Recipient email must be 320 characters or fewer.', 'certloom' ) ); }
			$row['email'] = $email;
		}
		if ( $creating || array_key_exists( 'wp_user_id', $data ) ) {
			$row['wp_user_id'] = ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null;
		}
		if ( array_key_exists( 'custom_fields', $data ) ) {
			$encoded = ( new SafeJson() )->encode_array( $data['custom_fields'], 'certloom_recipient_custom_fields_invalid', __( 'Recipient custom fields could not be encoded safely.', 'certloom' ) );
			if ( is_wp_error( $encoded ) ) { return $encoded; }
			$row['custom_fields'] = $encoded;
		} elseif ( $creating ) {
			$row['custom_fields'] = null;
		}
		return $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode( array $row ) {
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['wp_user_id'] = ! empty( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : null;
		$row['custom_fields'] = ( new SafeJson() )->decode_array( $row['custom_fields'] ?? null, 'certloom_recipient_custom_fields_corrupt', __( 'Stored recipient custom fields are invalid JSON.', 'certloom' ) );
		if ( is_wp_error( $row['custom_fields'] ) ) { return $row['custom_fields']; }
		return $row;
	}

	/** @return string|null */
	private static function nullable_string( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/** UTF-8 code-point count without requiring mbstring. @return int */
	private static function text_length( $value ) {
		$count = preg_match_all( '/./us', (string) $value, $matches );
		return false === $count ? strlen( (string) $value ) : (int) $count;
	}
}
