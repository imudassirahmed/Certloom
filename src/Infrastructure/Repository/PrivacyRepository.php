<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Contract\PrivacyRepositoryInterface;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Application\Data\SafeJson;
use Certloom\Application\Privacy\IdentityDigest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, fail-closed persistence for WordPress privacy workflows. */
final class PrivacyRepository implements PrivacyRepositoryInterface {
	/** @var bool|null */ private $storage_exists = null;
	/** @var \WP_Error|null */ private $storage_error = null;

	public function domain_storage_exists() {
		if ( null !== $this->storage_exists ) { return $this->storage_exists; }
		global $wpdb;
		$table = SchemaManager::table( 'recipients' );
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			$this->storage_error = new \WP_Error( 'certloom_privacy_storage_probe_failed', __( 'Certloom could not determine whether normalized privacy data is available.', 'certloom' ) );
			$this->storage_exists = false;
			return false;
		}
		$this->storage_exists = (string) $found === (string) $table;
		return $this->storage_exists;
	}

	/** @return \WP_Error|null */
	public function storage_error() { return $this->storage_error; }

	public function find_recipients_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 ) {
		global $wpdb;
		if ( ! $this->domain_storage_exists() ) { return array(); }
		$email = sanitize_email( (string) $email );
		$wp_user_id = absint( $wp_user_id );
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$where = '(';
		$args = array( SchemaManager::table( 'recipients' ) );
		$parts = array();
		$digest = ( new IdentityDigest() )->email( $email );
		if ( '' !== $email ) { $parts[] = 'email = %s'; $args[] = $email; }
		if ( null !== $digest ) { $parts[] = 'privacy_identity_hashes LIKE %s'; $args[] = '%' . $wpdb->esc_like( $digest ) . '%'; }
		if ( $wp_user_id ) { $parts[] = 'wp_user_id = %d'; $args[] = $wp_user_id; }
		if ( empty( $parts ) ) { return array(); }
		$sql = 'SELECT * FROM %i WHERE ' . $where . implode( ' OR ', $parts ) . ') ORDER BY id ASC LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = absint( $offset );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_privacy_recipient_lookup_failed', __( 'Certloom could not read recipient data for the privacy request.', 'certloom' ) );
		}
		$matched = array();
		foreach ( $rows as $row ) {
			$hashes = ( new SafeJson() )->decode_array( $row['privacy_identity_hashes'] ?? null, 'certloom_recipient_privacy_identity_corrupt', __( 'Stored recipient privacy identity data is invalid JSON.', 'certloom' ) );
			if ( is_wp_error( $hashes ) ) { return $hashes; }
			if ( ! ( new IdentityDigest() )->is_valid_history( $hashes ) ) { return new \WP_Error( 'certloom_recipient_privacy_identity_corrupt', __( 'Stored recipient privacy identity history is invalid or exceeds its safe bounded limit.', 'certloom' ) ); }
			$email_match = '' !== $email && 0 === strcasecmp( trim( (string) ( $row['email'] ?? '' ) ), $email );
			$hash_match = null !== $digest && in_array( $digest, $hashes, true );
			$user_match = $wp_user_id && absint( $row['wp_user_id'] ?? 0 ) === $wp_user_id;
			if ( ! $email_match && ! $hash_match && ! $user_match ) { continue; }
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['wp_user_id'] = ! empty( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : null;
			$row['privacy_identity_hashes'] = $hashes;
			$matched[] = $row;
		}
		return $matched;
	}

	public function find_recipient_for_update( $recipient_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', SchemaManager::table( 'recipients' ), absint( $recipient_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_privacy_recipient_lock_failed', __( 'Certloom could not lock the recipient safely for anonymization.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) { return null; }
		$hashes = ( new SafeJson() )->decode_array( $row['privacy_identity_hashes'] ?? null, 'certloom_recipient_privacy_identity_corrupt', __( 'Stored recipient privacy identity data is invalid JSON.', 'certloom' ) );
		if ( is_wp_error( $hashes ) ) { return $hashes; }
		if ( ! ( new IdentityDigest() )->is_valid_history( $hashes ) ) { return new \WP_Error( 'certloom_recipient_privacy_identity_corrupt', __( 'Stored recipient privacy identity history is invalid or exceeds its safe bounded limit.', 'certloom' ) ); }
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['wp_user_id'] = ! empty( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : null;
		$row['privacy_identity_hashes'] = $hashes;
		return $row;
	}

	public function series_ids_for_recipient( $recipient_id, $limit = 101 ) {
		global $wpdb;
		$limit = max( 1, min( 1001, absint( $limit ) ) );
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE recipient_id = %d ORDER BY id ASC LIMIT %d', SchemaManager::table( 'credential_series' ), absint( $recipient_id ), $limit ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $ids ) ) {
			return new \WP_Error( 'certloom_privacy_series_lookup_failed', __( 'Certloom could not read credential relationships for the privacy request.', 'certloom' ) );
		}
		return array_values( array_map( 'absint', $ids ) );
	}

	public function anonymize_recipient( $recipient_id, $replacement_name, $expected_updated_at, array $identity_hashes = array() ) {
		global $wpdb;
		$recipient_id = absint( $recipient_id );
		$replacement_name = trim( (string) $replacement_name );
		if ( ! $recipient_id || '' === $replacement_name ) {
			return new \WP_Error( 'certloom_privacy_anonymize_invalid', __( 'Certloom could not prepare the recipient anonymization safely.', 'certloom' ) );
		}
		$identity_hashes = ( new IdentityDigest() )->merge_email( $identity_hashes, null );
		if ( ! ( new IdentityDigest() )->is_valid_history( $identity_hashes ) ) { return new \WP_Error( 'certloom_recipient_privacy_identity_limit', __( 'Recipient privacy identity history exceeds its safe bounded limit.', 'certloom' ) ); }
		$encoded_hashes = ( new SafeJson() )->encode_array( $identity_hashes, 'certloom_recipient_privacy_identity_invalid', __( 'Recipient privacy identity data could not be encoded safely.', 'certloom' ) );
		if ( is_wp_error( $encoded_hashes ) ) { return $encoded_hashes; }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET display_name = %s, first_name = NULL, middle_name = NULL, last_name = NULL, email = NULL, privacy_identity_hashes = %s, privacy_anonymized_at = %s, wp_user_id = NULL, external_id = NULL, custom_fields = NULL, updated_at = %s WHERE id = %d AND updated_at = %s',
				SchemaManager::table( 'recipients' ),
				$replacement_name,
				$encoded_hashes,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$recipient_id,
				(string) $expected_updated_at
			)
		);
		if ( false === $changed || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_privacy_anonymize_failed', __( 'Certloom could not anonymize the recipient safely.', 'certloom' ) );
		}
		if ( 1 !== (int) $changed ) {
			return new \WP_Error( 'certloom_privacy_anonymize_stale', __( 'The recipient changed while the privacy request was being processed. No anonymization was committed.', 'certloom' ) );
		}
		return true;
	}

	public function export_series_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 ) {
		return $this->export_joined( 'series', $email, $wp_user_id, $offset, $limit );
	}
	public function export_versions_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 ) {
		return $this->export_joined( 'versions', $email, $wp_user_id, $offset, $limit );
	}
	public function export_events_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 ) {
		return $this->export_joined( 'events', $email, $wp_user_id, $offset, $limit );
	}

	public function export_signers_for_email( $email, $offset = 0, $limit = 50 ) {
		global $wpdb;
		if ( ! $this->domain_storage_exists() || '' === sanitize_email( (string) $email ) ) { return array(); }
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, display_name, slug, title, email, active, metadata_json, created_at, updated_at FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d', SchemaManager::table( 'signers' ), sanitize_email( (string) $email ), $limit, absint( $offset ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_privacy_signer_export_failed', __( 'Certloom could not export signer data for the privacy request.', 'certloom' ) ); }
		return $rows;
	}

	public function export_issuers_for_email( $email, $offset = 0, $limit = 50 ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! $this->domain_storage_exists() || '' === $email ) { return array(); }
		$like = '%' . $wpdb->esc_like( $email ) . '%';
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, slug, settings_json, created_at, updated_at FROM %i WHERE settings_json LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d', SchemaManager::table( 'issuers' ), $like, $limit, absint( $offset ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_privacy_issuer_export_failed', __( 'Certloom could not export issuer data for the privacy request.', 'certloom' ) ); }
		return $rows;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function export_joined( $kind, $email, $wp_user_id, $offset, $limit ) {
		global $wpdb;
		if ( ! $this->domain_storage_exists() ) { return array(); }
		$email = sanitize_email( (string) $email );
		$wp_user_id = absint( $wp_user_id );
		if ( '' === $email && ! $wp_user_id ) { return array(); }
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$offset = absint( $offset );
		$identity = array(); $identity_args = array();
		$digest = ( new IdentityDigest() )->email( $email );
		if ( '' !== $email ) { $identity[] = 'r.email = %s'; $identity_args[] = $email; }
		if ( null !== $digest ) { $identity[] = 'r.privacy_identity_hashes LIKE %s'; $identity_args[] = '%' . $wpdb->esc_like( $digest ) . '%'; }
		if ( $wp_user_id ) { $identity[] = 'r.wp_user_id = %d'; $identity_args[] = $wp_user_id; }
		$where = '(' . implode( ' OR ', $identity ) . ')';
		if ( 'series' === $kind ) {
			$sql = 'SELECT s.* FROM %i s INNER JOIN %i r ON r.id = s.recipient_id WHERE ' . $where . ' ORDER BY s.id ASC LIMIT %d OFFSET %d';
			$args = array_merge( array( SchemaManager::table( 'credential_series' ), SchemaManager::table( 'recipients' ) ), $identity_args, array( $limit, $offset ) );
		} elseif ( 'versions' === $kind ) {
			$sql = 'SELECT v.* FROM %i v INNER JOIN %i s ON s.id = v.series_id INNER JOIN %i r ON r.id = s.recipient_id WHERE ' . $where . ' ORDER BY v.id ASC LIMIT %d OFFSET %d';
			$args = array_merge( array( SchemaManager::table( 'credential_versions' ), SchemaManager::table( 'credential_series' ), SchemaManager::table( 'recipients' ) ), $identity_args, array( $limit, $offset ) );
		} else {
			$sql = 'SELECT e.* FROM %i e INNER JOIN %i s ON s.id = e.series_id INNER JOIN %i r ON r.id = s.recipient_id WHERE ' . $where . ' ORDER BY e.id ASC LIMIT %d OFFSET %d';
			$args = array_merge( array( SchemaManager::table( 'credential_events' ), SchemaManager::table( 'credential_series' ), SchemaManager::table( 'recipients' ) ), $identity_args, array( $limit, $offset ) );
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_privacy_domain_export_failed', __( 'Certloom could not export credential history for the privacy request.', 'certloom' ) ); }
		return $rows;
	}
}
