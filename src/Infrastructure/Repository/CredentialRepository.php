<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\Contract\CredentialRepositoryInterface;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\States;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence for Credential Series and immutable issuance-version payloads.
 *
 * No general-purpose "update version" API is exposed. Later lifecycle command
 * services may update the narrowly controlled issuance disposition/state while
 * immutable snapshot/content fields remain write-once after issue.
 */
final class CredentialRepository implements CredentialRepositoryInterface, CredentialQueryRepositoryInterface {
	/** @var PublicCredentialId */
	private $public_ids;

	public function __construct( PublicCredentialId $public_ids = null ) {
		$this->public_ids = $public_ids ?: new PublicCredentialId();
	}

	/**
	 * Creates a Series, retrying random public-ID collisions against the DB.
	 *
	 * @param array<string,mixed> $data Series data.
	 * @return int|\WP_Error
	 */
	public function create_series( array $data ) {
		global $wpdb;
		$recipient_id = absint( $data['recipient_id'] ?? 0 );
		if ( ! $recipient_id ) {
			return new \WP_Error( 'certloom_series_recipient_required', __( 'A credential recipient is required.', 'certloom' ) );
		}

		$administrative_state = isset( $data['administrative_state'] ) ? (string) $data['administrative_state'] : States::ADMIN_ACTIVE;
		if ( ! in_array( $administrative_state, States::administrative_values(), true ) ) {
			return new \WP_Error( 'certloom_series_administrative_state_invalid', __( 'The credential administrative state is invalid.', 'certloom' ) );
		}

		$compliance_state = isset( $data['compliance_state'] ) ? (string) $data['compliance_state'] : States::COMPLIANCE_NOT_APPLICABLE;
		if ( ! in_array( $compliance_state, States::compliance_values(), true ) ) {
			return new \WP_Error( 'certloom_series_compliance_state_invalid', __( 'The credential compliance state is invalid.', 'certloom' ) );
		}

		$requested_public_id = isset( $data['public_id'] ) ? $this->public_ids->normalize( $data['public_id'] ) : '';
		if ( '' !== $requested_public_id && ! $this->public_ids->is_valid( $requested_public_id ) ) {
			return new \WP_Error( 'certloom_series_public_id_invalid', __( 'The public credential ID is invalid.', 'certloom' ) );
		}

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			try {
				$public_id = '' !== $requested_public_id ? $requested_public_id : $this->public_ids->generate();
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'certloom_series_random_id_failed', __( 'Certloom could not generate a secure public credential ID.', 'certloom' ) );
			}
			$now = current_time( 'mysql', true );
			$row = array(
				'public_id' => $public_id,
				'recipient_id' => $recipient_id,
				'program_id' => ! empty( $data['program_id'] ) ? absint( $data['program_id'] ) : null,
				'issuer_id' => ! empty( $data['issuer_id'] ) ? absint( $data['issuer_id'] ) : null,
				'current_version_id' => null,
				'administrative_state' => $administrative_state,
				'compliance_state' => $compliance_state,
				'visibility' => self::visibility( $data['visibility'] ?? 'verification_summary' ),
				'created_at' => $now,
				'updated_at' => $now,
				'closed_at' => null,
			);
			$generation = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $generation ) ) { return $generation; }
			$wpdb->last_error = '';
			$inserted = $wpdb->insert( SchemaManager::table( 'credential_series' ), $row );
			if ( false !== $inserted && '' === (string) $wpdb->last_error ) {
				return (int) $wpdb->insert_id;
			}
			if ( '' !== $requested_public_id || false === stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				break;
			}
		}
		return new \WP_Error( 'certloom_series_create_failed', __( 'Certloom could not create a unique credential identity.', 'certloom' ) );
	}

	/**
	 * Creates a version. Snapshot payload is encoded once at persistence time.
	 *
	 * @param int                 $series_id Series ID.
	 * @param array<string,mixed> $data      Version data.
	 * @return int|\WP_Error
	 */
	public function create_version( $series_id, array $data ) {
		global $wpdb;
		$series_id = absint( $series_id );
		$display_number = trim( (string) ( $data['display_number'] ?? '' ) );
		if ( ! $series_id || '' === $display_number ) {
			return new \WP_Error( 'certloom_version_required_fields', __( 'A credential series and display certificate number are required.', 'certloom' ) );
		}

		$version_number = isset( $data['version_number'] ) ? max( 1, absint( $data['version_number'] ) ) : $this->next_version_number( $series_id );
		if ( is_wp_error( $version_number ) ) {
			return $version_number;
		}
		$previous_version_id = ! empty( $data['previous_version_id'] ) ? absint( $data['previous_version_id'] ) : null;
		if ( $previous_version_id ) {
			$wpdb->last_error = '';
			$previous_belongs = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND series_id = %d', SchemaManager::table( 'credential_versions' ), $previous_version_id, $series_id ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_previous_version_read_failed', __( 'Certloom could not verify the previous credential Version.', 'certloom' ) );
			}
			if ( ! $previous_belongs ) {
				return new \WP_Error( 'certloom_previous_version_wrong_series', __( 'The previous credential Version does not belong to this Credential Series.', 'certloom' ) );
			}
		}
		$issuance_state = (string) ( $data['issuance_state'] ?? States::ISSUANCE_DRAFT );
		if ( ! in_array( $issuance_state, States::issuance_values(), true ) ) {
			return new \WP_Error( 'certloom_version_state_invalid', __( 'The credential issuance state is invalid.', 'certloom' ) );
		}
		$valid_from = self::canonical_date( $data['valid_from'] ?? null, 'valid_from' );
		$expires_at = self::canonical_date( $data['expires_at'] ?? null, 'expires_at' );
		$issued_at = self::canonical_datetime( $data['issued_at'] ?? null, 'issued_at' );
		foreach ( array( $valid_from, $expires_at, $issued_at ) as $validated ) {
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}

		$idempotency_key = self::nullable_string( $data['source_idempotency_key'] ?? null );
		if ( null !== $idempotency_key && ! preg_match( '/^[a-f0-9]{64}$/', strtolower( $idempotency_key ) ) ) {
			return new \WP_Error( 'certloom_version_idempotency_invalid', __( 'The source idempotency key must be a SHA-256 hexadecimal value.', 'certloom' ) );
		}
		$idempotency_key = null === $idempotency_key ? null : strtolower( $idempotency_key );

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$reservation = $this->reserve_display_number( $series_id, $display_number );
		if ( is_wp_error( $reservation ) ) { return $reservation; }

		$snapshot_json = null;
		if ( isset( $data['snapshot'] ) ) {
			$snapshot_json = wp_json_encode( $data['snapshot'] );
			if ( false === $snapshot_json ) {
				return new \WP_Error( 'certloom_version_snapshot_invalid', __( 'Certloom could not encode the credential snapshot.', 'certloom' ) );
			}
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'series_id' => $series_id,
			'version_number' => $version_number,
			'previous_version_id' => $previous_version_id,
			'display_number' => $display_number,
			'issuance_state' => $issuance_state,
			'legacy_expired' => ! empty( $data['legacy_expired'] ) ? 1 : 0,
			'valid_from' => $valid_from,
			'issued_at' => $issued_at,
			'expires_at' => $expires_at,
			'grade' => self::nullable_string( $data['grade'] ?? null ),
			'source_type' => self::nullable_string( $data['source_type'] ?? null ),
			'source_ref' => self::nullable_string( $data['source_ref'] ?? null ),
			'source_idempotency_key' => $idempotency_key,
			'snapshot_json' => $snapshot_json,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'credential_versions' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			$this->release_new_number_reservation( $reservation );
			return new \WP_Error( 'certloom_version_create_failed', __( 'Certloom could not create the credential version.', 'certloom' ) );
		}
		$version_id = (int) $wpdb->insert_id;
		$linked = $this->link_number_first_version( $reservation, $version_id );
		if ( is_wp_error( $linked ) ) {
			$wpdb->delete( SchemaManager::table( 'credential_versions' ), array( 'id' => $version_id ) );
			$this->release_new_number_reservation( $reservation );
			return $linked;
		}
		return $version_id;
	}

	/** @return true|\WP_Error */
	public function set_current_version( $series_id, $version_id ) {
		global $wpdb;
		$series_id = absint( $series_id );
		$version_id = absint( $version_id );
		if ( ! $series_id || ! $version_id ) {
			return new \WP_Error( 'certloom_current_version_invalid', __( 'A valid credential series and version are required.', 'certloom' ) );
		}
		$wpdb->last_error = '';
		$belongs = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND series_id = %d', SchemaManager::table( 'credential_versions' ), $version_id, $series_id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_current_version_read_failed', __( 'Certloom could not verify the credential version relationship.', 'certloom' ) );
		}
		if ( ! $belongs ) {
			return new \WP_Error( 'certloom_current_version_wrong_series', __( 'The credential version does not belong to this credential series.', 'certloom' ) );
		}
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$updated = $wpdb->update(
			SchemaManager::table( 'credential_series' ),
			array( 'current_version_id' => $version_id, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $series_id )
		);
		return false === $updated ? new \WP_Error( 'certloom_current_version_update_failed', __( 'Certloom could not select the current credential version.', 'certloom' ) ) : true;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series( $series_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'credential_series' ), absint( $series_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_series_read_failed', __( 'Certloom could not read the credential series.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode_series( $row ) : null;
	}

	/**
	 * Locks the Series row for a short transaction-backed lifecycle command.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function find_series_for_update( $series_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', SchemaManager::table( 'credential_series' ), absint( $series_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_series_read_failed', __( 'Certloom could not read the credential series.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode_series( $row ) : null;
	}

	/**
	 * Compare-and-swap administrative state so concurrent lifecycle commands do
	 * not overwrite a newer state transition.
	 *
	 * @return true|\WP_Error
	 */
	public function set_administrative_state( $series_id, $expected_state, $new_state ) {
		global $wpdb;
		if ( ! in_array( $expected_state, States::administrative_values(), true ) || ! in_array( $new_state, States::administrative_values(), true ) ) {
			return new \WP_Error( 'certloom_series_state_invalid', __( 'The credential administrative state is invalid.', 'certloom' ) );
		}
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET administrative_state = %s, updated_at = %s WHERE id = %d AND administrative_state = %s',
				SchemaManager::table( 'credential_series' ),
				$new_state,
				current_time( 'mysql', true ),
				absint( $series_id ),
				$expected_state
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_series_state_update_failed', __( 'Certloom could not update the credential administrative state.', 'certloom' ) );
		}
		return 1 === (int) $updated ? true : new \WP_Error( 'certloom_series_state_conflict', __( 'The credential changed while this action was running. Reload and try again.', 'certloom' ) );
	}

	/**
	 * Compare-and-swap the narrow issuance disposition/state only. Snapshot and
	 * identity payloads remain write-once once the version is issued.
	 *
	 * @return true|\WP_Error
	 */
	public function set_version_issuance_state( $version_id, $expected_state, $new_state ) {
		global $wpdb;
		if ( ! in_array( $expected_state, States::issuance_values(), true ) || ! in_array( $new_state, States::issuance_values(), true ) ) {
			return new \WP_Error( 'certloom_version_state_invalid', __( 'The credential issuance state is invalid.', 'certloom' ) );
		}
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$now = current_time( 'mysql', true );
		$wpdb->last_error = '';
		if ( States::ISSUANCE_ISSUED === $new_state ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET issuance_state = %s, issued_at = COALESCE(issued_at,%s), updated_at = %s WHERE id = %d AND issuance_state = %s',
					SchemaManager::table( 'credential_versions' ),
					$new_state,
					$now,
					$now,
					absint( $version_id ),
					$expected_state
				)
			);
		} else {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET issuance_state = %s, updated_at = %s WHERE id = %d AND issuance_state = %s',
					SchemaManager::table( 'credential_versions' ),
					$new_state,
					$now,
					absint( $version_id ),
					$expected_state
				)
			);
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_version_state_update_failed', __( 'Certloom could not update the credential issuance state.', 'certloom' ) );
		}
		return 1 === (int) $updated ? true : new \WP_Error( 'certloom_version_state_conflict', __( 'The credential version changed while this action was running. Reload and try again.', 'certloom' ) );
	}

	/**
	 * Public verification read model: direct public ID -> Series + current Version.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function find_current_by_public_id( $public_id ) {
		global $wpdb;
		$public_id = $this->public_ids->normalize( $public_id );
		if ( ! $this->public_ids->is_valid( $public_id ) ) {
			return null;
		}

		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.id AS series_id, s.public_id, s.recipient_id, s.program_id, s.issuer_id, s.current_version_id, s.administrative_state, s.compliance_state, s.visibility, v.id AS version_id, v.version_number, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id AND v.series_id = s.id WHERE s.public_id = %s LIMIT 1',
				SchemaManager::table( 'credential_series' ),
				SchemaManager::table( 'credential_versions' ),
				$public_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_credential_verification_read_failed', __( 'Certloom could not read the credential for verification.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		foreach ( array( 'series_id', 'recipient_id', 'program_id', 'issuer_id', 'current_version_id', 'version_id', 'version_number' ) as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) ? absint( $row[ $key ] ) : null;
		}
		return $row;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version_by_source_idempotency( $source_type, $idempotency_key ) {
		global $wpdb;
		$source_type = trim( (string) $source_type );
		$idempotency_key = strtolower( trim( (string) $idempotency_key ) );
		if ( '' === $source_type || ! preg_match( '/^[a-f0-9]{64}$/', $idempotency_key ) ) {
			return null;
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_type = %s AND source_idempotency_key = %s LIMIT 1',
				SchemaManager::table( 'credential_versions' ),
				$source_type,
				$idempotency_key
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_version_idempotency_read_failed', __( 'Certloom could not check whether the legacy certificate was already migrated.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode_version( $row ) : null;
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
			$where = ' WHERE s.public_id LIKE %s OR v.display_number LIKE %s OR r.display_name LIKE %s OR p.title LIKE %s';
			$args = array( $like, $like, $like, $like );
		}
		$series = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$recipients = SchemaManager::table( 'recipients' );
		$programs = SchemaManager::table( 'programs' );
		$joins = ' FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id AND v.series_id = s.id LEFT JOIN %i r ON r.id = s.recipient_id LEFT JOIN %i p ON p.id = s.program_id';
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $joins . $where, array_merge( array( $series, $versions, $recipients, $programs ), $args ) ) );
		$sql = 'SELECT s.id AS series_id, s.public_id, s.administrative_state, s.compliance_state, s.visibility, r.display_name, p.title AS program_title, v.id AS version_id, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at' . $joins . $where . ' ORDER BY s.id DESC LIMIT %d OFFSET %d';
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $series, $versions, $recipients, $programs ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_credential_list_failed', __( 'Certloom could not load the credential registry.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			$item['series_id'] = absint( $item['series_id'] ?? 0 );
			$item['version_id'] = ! empty( $item['version_id'] ) ? absint( $item['version_id'] ) : null;
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series_by_public_id( $public_id ) {
		global $wpdb;
		$public_id = $this->public_ids->normalize( $public_id );
		if ( ! $this->public_ids->is_valid( $public_id ) ) {
			return null;
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s', SchemaManager::table( 'credential_series' ), $public_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_series_read_failed', __( 'Certloom could not read the credential series.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode_series( $row ) : null;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version( $version_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'credential_versions' ), absint( $version_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_version_read_failed', __( 'Certloom could not read the credential version.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode_version( $row ) : null;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version_for_update( $version_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', SchemaManager::table( 'credential_versions' ), absint( $version_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_version_read_failed', __( 'Certloom could not read the credential version.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode_version( $row ) : null;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series_by_display_number( $display_number ) {
		global $wpdb;
		$display_number = trim( (string) $display_number );
		if ( '' === $display_number ) { return null; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT n.display_number, n.series_id, n.first_version_id, s.public_id, s.current_version_id, s.administrative_state, s.compliance_state, s.visibility FROM %i n INNER JOIN %i s ON s.id = n.series_id WHERE n.display_number = %s LIMIT 1',
				SchemaManager::table( 'credential_numbers' ),
				SchemaManager::table( 'credential_series' ),
				$display_number
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_display_number_read_failed', __( 'Certloom could not read the certificate-number assignment.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) { return null; }
		foreach ( array( 'series_id', 'first_version_id', 'current_version_id' ) as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) ? absint( $row[ $key ] ) : null;
		}
		return $row;
	}

	/**
	 * Reserves a globally unique human certificate number for one Credential Series.
	 * Existing assignment to the same Series is reusable by immutable correction Versions.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function reserve_display_number( $series_id, $display_number ) {
		global $wpdb;
		$table = SchemaManager::table( 'credential_numbers' );
		$wpdb->last_error = '';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, series_id, first_version_id FROM %i WHERE display_number = %s LIMIT 1', $table, $display_number ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_display_number_read_failed', __( 'Certloom could not check the certificate-number assignment.', 'certloom' ) );
		}
		if ( is_array( $existing ) ) {
			if ( absint( $existing['series_id'] ?? 0 ) !== absint( $series_id ) ) {
				return new \WP_Error( 'certloom_display_number_duplicate', __( 'That certificate number is already assigned to another credential.', 'certloom' ) );
			}
			return array( 'id' => absint( $existing['id'] ), 'new' => false, 'first_version_id' => ! empty( $existing['first_version_id'] ) ? absint( $existing['first_version_id'] ) : null );
		}
		$wpdb->last_error = '';
		$inserted = $wpdb->insert(
			$table,
			array(
				'display_number' => $display_number,
				'series_id' => absint( $series_id ),
				'first_version_id' => null,
				'created_at' => current_time( 'mysql', true ),
				'retired_at' => null,
			)
		);
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			/* A concurrent Series may have won the unique insert; re-read for a precise result. */
			$wpdb->last_error = '';
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, series_id, first_version_id FROM %i WHERE display_number = %s LIMIT 1', $table, $display_number ), ARRAY_A );
			if ( is_array( $existing ) && absint( $existing['series_id'] ?? 0 ) === absint( $series_id ) ) {
				return array( 'id' => absint( $existing['id'] ), 'new' => false, 'first_version_id' => ! empty( $existing['first_version_id'] ) ? absint( $existing['first_version_id'] ) : null );
			}
			return new \WP_Error( 'certloom_display_number_duplicate', __( 'That certificate number is already assigned to another credential.', 'certloom' ) );
		}
		return array( 'id' => (int) $wpdb->insert_id, 'new' => true, 'first_version_id' => null );
	}

	/** @return true|\WP_Error */
	private function link_number_first_version( array $reservation, $version_id ) {
		if ( ! empty( $reservation['first_version_id'] ) ) { return true; }
		global $wpdb;
		$wpdb->last_error = '';
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET first_version_id = %d WHERE id = %d AND first_version_id IS NULL',
				SchemaManager::table( 'credential_numbers' ),
				absint( $version_id ),
				absint( $reservation['id'] ?? 0 )
			)
		);
		return false === $updated || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_display_number_link_failed', __( 'Certloom could not link the certificate number to its first issuance version.', 'certloom' ) )
			: true;
	}

	/** @return void */
	private function release_new_number_reservation( array $reservation ) {
		if ( empty( $reservation['new'] ) || empty( $reservation['id'] ) ) { return; }
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id = %d AND first_version_id IS NULL',
				SchemaManager::table( 'credential_numbers' ),
				absint( $reservation['id'] )
			)
		);
	}

	/** @return int|\WP_Error */
	private function next_version_number( $series_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(version_number) FROM %i WHERE series_id = %d', SchemaManager::table( 'credential_versions' ), absint( $series_id ) ) );
		if ( null === $value && '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_version_number_failed', __( 'Certloom could not calculate the next credential version number.', 'certloom' ) );
		}
		return max( 1, absint( $value ) + 1 );
	}

	/** @return array<string,mixed> */
	private static function decode_series( array $row ) {
		foreach ( array( 'id', 'recipient_id', 'program_id', 'issuer_id', 'current_version_id' ) as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) ? absint( $row[ $key ] ) : null;
		}
		return $row;
	}

	/** @return array<string,mixed> */
	private static function decode_version( array $row ) {
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['series_id'] = absint( $row['series_id'] ?? 0 );
		$row['version_number'] = absint( $row['version_number'] ?? 0 );
		$row['legacy_expired'] = ! empty( $row['legacy_expired'] );
		$row['snapshot'] = ! empty( $row['snapshot_json'] ) ? json_decode( (string) $row['snapshot_json'], true ) : array();
		if ( ! is_array( $row['snapshot'] ) ) {
			$row['snapshot'] = array();
		}
		unset( $row['snapshot_json'] );
		return $row;
	}

	/** @return string */
	private static function visibility( $value ) {
		$value = sanitize_key( (string) $value );
		$allowed = array( 'public', 'verification_summary', 'unlisted', 'recipient_only', 'organization_member', 'private' );
		return in_array( $value, $allowed, true ) ? $value : 'verification_summary';
	}

	/** @return string|null */
	private static function nullable_string( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/** @return string|null|\WP_Error */
	private static function canonical_date( $value, $field ) {
		$value = self::nullable_string( $value );
		if ( null === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return new \WP_Error( 'certloom_version_date_invalid', sprintf( __( '%s must use the YYYY-MM-DD date format.', 'certloom' ), $field ) );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			return new \WP_Error( 'certloom_version_date_invalid', sprintf( __( '%s is not a valid calendar date.', 'certloom' ), $field ) );
		}
		return $value;
	}

	/** @return string|null|\WP_Error */
	private static function canonical_datetime( $value, $field ) {
		$value = self::nullable_string( $value );
		if ( null === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'certloom_version_datetime_invalid', sprintf( __( '%s must be a valid UTC YYYY-MM-DD HH:MM:SS value.', 'certloom' ), $field ) );
		}
		return $value;
	}
}
