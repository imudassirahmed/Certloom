<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Data\SafeJson;
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\Contract\CredentialRepositoryInterface;
use Certloom\Application\Contract\CredentialRelationshipRepositoryInterface;
use Certloom\Application\VisibilityPolicy;
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
final class CredentialRepository implements CredentialRepositoryInterface, CredentialRelationshipRepositoryInterface, CredentialQueryRepositoryInterface {
	/** @var PublicCredentialId */
	private $public_ids;

	public function __construct( ?PublicCredentialId $public_ids = null ) {
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
		$recipient_exists = $this->reference_exists( 'recipients', $recipient_id );
		if ( is_wp_error( $recipient_exists ) ) { return $recipient_exists; }
		if ( ! $recipient_exists ) { return new \WP_Error( 'certloom_series_recipient_missing', __( 'The selected credential recipient does not exist.', 'certloom' ) ); }

		$program_id = ! empty( $data['program_id'] ) ? absint( $data['program_id'] ) : null;
		$batch_id   = ! empty( $data['batch_id'] ) ? absint( $data['batch_id'] ) : null;
		$issuer_id  = ! empty( $data['issuer_id'] ) ? absint( $data['issuer_id'] ) : null;
		if ( $program_id ) {
			$program_exists = $this->reference_exists( 'programs', $program_id );
			if ( is_wp_error( $program_exists ) ) { return $program_exists; }
			if ( ! $program_exists ) { return new \WP_Error( 'certloom_series_program_missing', __( 'The selected credential program does not exist.', 'certloom' ) ); }
		}
		if ( $batch_id ) {
			$batch_program_id = $this->batch_program_id( $batch_id );
			if ( is_wp_error( $batch_program_id ) ) { return $batch_program_id; }
			if ( ! $batch_program_id ) { return new \WP_Error( 'certloom_series_batch_missing', __( 'The selected credential batch does not exist.', 'certloom' ) ); }
			if ( $program_id && $program_id !== $batch_program_id ) { return new \WP_Error( 'certloom_series_batch_program_mismatch', __( 'The selected batch belongs to a different credential program.', 'certloom' ) ); }
			$program_id = $batch_program_id;
		}
		if ( $issuer_id ) {
			$issuer_exists = $this->reference_exists( 'issuers', $issuer_id );
			if ( is_wp_error( $issuer_exists ) ) { return $issuer_exists; }
			if ( ! $issuer_exists ) { return new \WP_Error( 'certloom_series_issuer_missing', __( 'The selected credential issuer does not exist.', 'certloom' ) ); }
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
				'program_id' => $program_id,
				'batch_id' => $batch_id,
				'issuer_id' => $issuer_id,
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

		/*
		 * Encode every immutable payload before mutating generation state or reserving
		 * the globally unique human certificate number. A serialization failure is
		 * therefore side-effect free and cannot strand an unused number reservation.
		 */
		$snapshot_json = null;
		if ( array_key_exists( 'snapshot', $data ) ) {
			$snapshot_json = ( new SafeJson() )->encode_array( $data['snapshot'], 'certloom_version_snapshot_invalid', __( 'Certloom could not encode the credential snapshot.', 'certloom' ) );
			if ( is_wp_error( $snapshot_json ) ) { return $snapshot_json; }
		}

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$reservation = $this->reserve_display_number( $series_id, $display_number );
		if ( is_wp_error( $reservation ) ) { return $reservation; }

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
			$cleanup = $this->release_new_number_reservation( $reservation );
			if ( is_wp_error( $cleanup ) ) {
				return new \WP_Error( 'certloom_version_create_cleanup_failed', __( 'Certloom could not create the credential version and could not safely release its new certificate-number reservation. The number remains reserved to prevent duplicate ownership.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_version_create_failed', __( 'Certloom could not create the credential version.', 'certloom' ) );
		}
		$version_id = (int) $wpdb->insert_id;
		$linked = $this->link_number_first_version( $reservation, $version_id );
		if ( is_wp_error( $linked ) ) {
			$wpdb->last_error = '';
			$deleted = $wpdb->delete( SchemaManager::table( 'credential_versions' ), array( 'id' => $version_id ) );
			if ( 1 !== (int) $deleted || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_version_link_rollback_failed', __( 'Certloom could not finish linking the certificate number and could not safely remove the partially created Version. Its number remains reserved to prevent duplicate ownership.', 'certloom' ) );
			}
			$cleanup = $this->release_new_number_reservation( $reservation );
			if ( is_wp_error( $cleanup ) ) { return $cleanup; }
			return $linked;
		}
		return $version_id;
	}

	/**
	 * Validates a Program/Batch correction target without mutating credential state.
	 * A selected Batch is authoritative for its Program.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function validate_relationship_target( $program_id, $batch_id ) {
		$program_id = ! empty( $program_id ) ? absint( $program_id ) : null;
		$batch_id   = ! empty( $batch_id ) ? absint( $batch_id ) : null;
		if ( $batch_id ) {
			$batch_program_id = $this->batch_program_id( $batch_id );
			if ( is_wp_error( $batch_program_id ) ) { return $batch_program_id; }
			if ( ! $batch_program_id ) { return new \WP_Error( 'certloom_relationship_batch_missing', __( 'The selected credential batch does not exist.', 'certloom' ) ); }
			if ( $program_id && $program_id !== $batch_program_id ) { return new \WP_Error( 'certloom_relationship_batch_program_mismatch', __( 'The selected batch belongs to a different credential program.', 'certloom' ) ); }
			$program_id = $batch_program_id;
		}
		if ( $program_id ) {
			$exists = $this->reference_exists( 'programs', $program_id );
			if ( is_wp_error( $exists ) ) { return $exists; }
			if ( ! $exists ) { return new \WP_Error( 'certloom_relationship_program_missing', __( 'The selected credential program does not exist.', 'certloom' ) ); }
		}
		return array( 'program_id' => $program_id, 'batch_id' => $batch_id );
	}

	/**
	 * Compare-and-swap the continuing Series relationship after immutable correction evidence exists.
	 * This narrow method intentionally cannot alter Recipient, Issuer, lifecycle state, or snapshots.
	 *
	 * @return true|\WP_Error
	 */
	public function set_relationships( $series_id, $expected_program_id, $expected_batch_id, $program_id, $batch_id, $expected_updated_at = null ) {
		global $wpdb;
		$series_id           = absint( $series_id );
		$expected_program_id = ! empty( $expected_program_id ) ? absint( $expected_program_id ) : null;
		$expected_batch_id   = ! empty( $expected_batch_id ) ? absint( $expected_batch_id ) : null;
		$target              = $this->validate_relationship_target( $program_id, $batch_id );
		if ( is_wp_error( $target ) ) { return $target; }
		if ( ! $series_id ) { return new \WP_Error( 'certloom_relationship_series_required', __( 'A valid credential series is required.', 'certloom' ) ); }

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$now = current_time( 'mysql', true );
		$set_program = null === $target['program_id'] ? 'program_id=NULL' : 'program_id=%d';
		$set_batch   = null === $target['batch_id'] ? 'batch_id=NULL' : 'batch_id=%d';
		$where_program = null === $expected_program_id ? 'program_id IS NULL' : 'program_id=%d';
		$where_batch   = null === $expected_batch_id ? 'batch_id IS NULL' : 'batch_id=%d';
		$sql  = 'UPDATE %i SET ' . $set_program . ', ' . $set_batch . ', updated_at=%s WHERE id=%d AND ' . $where_program . ' AND ' . $where_batch;
		$args = array( SchemaManager::table( 'credential_series' ) );
		if ( null !== $target['program_id'] ) { $args[] = $target['program_id']; }
		if ( null !== $target['batch_id'] ) { $args[] = $target['batch_id']; }
		$args[] = $now;
		$args[] = $series_id;
		if ( null !== $expected_program_id ) { $args[] = $expected_program_id; }
		if ( null !== $expected_batch_id ) { $args[] = $expected_batch_id; }
		if ( null !== $expected_updated_at ) {
			$sql .= ' AND updated_at=%s';
			$args[] = (string) $expected_updated_at;
		}
		$wpdb->last_error = '';
		$updated = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_relationship_update_failed', __( 'Certloom could not update the credential relationship.', 'certloom' ) ); }
		return 1 === (int) $updated ? true : new \WP_Error( 'certloom_relationship_conflict', __( 'The credential relationship changed while this correction was running. Reload and review it again.', 'certloom' ) );
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
				'SELECT s.id AS series_id, s.public_id, s.recipient_id, s.program_id, s.batch_id, s.issuer_id, s.current_version_id, s.administrative_state, s.compliance_state, s.visibility, v.id AS version_id, v.version_number, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at, v.snapshot_json FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id AND v.series_id = s.id WHERE s.public_id = %s LIMIT 1',
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

		foreach ( array( 'series_id', 'recipient_id', 'program_id', 'batch_id', 'issuer_id', 'current_version_id', 'version_id', 'version_number' ) as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) ? absint( $row[ $key ] ) : null;
		}
		$row['snapshot'] = ( new SafeJson() )->decode_array( $row['snapshot_json'] ?? null, 'certloom_version_snapshot_corrupt', __( 'Stored credential snapshot is invalid JSON.', 'certloom' ) );
		unset( $row['snapshot_json'] );
		if ( is_wp_error( $row['snapshot'] ) ) { return $row['snapshot']; }
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
			return new \WP_Error( 'certloom_version_idempotency_read_failed', __( 'Certloom could not check whether this source operation was already committed.', 'certloom' ) );
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
			$where = ' WHERE s.public_id LIKE %s OR v.display_number LIKE %s OR r.display_name LIKE %s OR p.title LIKE %s OR b.name LIKE %s OR b.code LIKE %s';
			$args = array( $like, $like, $like, $like, $like, $like );
		}
		$series = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$recipients = SchemaManager::table( 'recipients' );
		$programs = SchemaManager::table( 'programs' );
		$batches = SchemaManager::table( 'batches' );
		$joins = ' FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id AND v.series_id = s.id LEFT JOIN %i r ON r.id = s.recipient_id LEFT JOIN %i p ON p.id = s.program_id LEFT JOIN %i b ON b.id = s.batch_id';
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $joins . $where, array_merge( array( $series, $versions, $recipients, $programs, $batches ), $args ) ) );
		$sql = 'SELECT s.id AS series_id, s.public_id, s.batch_id, s.administrative_state, s.compliance_state, s.visibility, r.display_name, p.title AS program_title, b.name AS batch_name, v.id AS version_id, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at' . $joins . $where . ' ORDER BY s.id DESC LIMIT %d OFFSET %d';
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $series, $versions, $recipients, $programs, $batches ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_credential_list_failed', __( 'Certloom could not load the credential registry.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			$item['series_id'] = absint( $item['series_id'] ?? 0 );
			$item['batch_id'] = ! empty( $item['batch_id'] ) ? absint( $item['batch_id'] ) : null;
			$item['version_id'] = ! empty( $item['version_id'] ) ? absint( $item['version_id'] ) : null;
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}


	/**
	 * Premium bounded credential registry query used by Credential Management UX.
	 *
	 * The existing list_page() contract remains available for older internal
	 * callers. This query adds exact effective-status and Program filters without
	 * changing public authority or mutating immutable Version history.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error
	 */
	public function list_management_page( $search = '', $page = 1, $per_page = 20, $status = 'all', $program_id = 0, $exclude_source_type = '', $orderby = 'recent', $order = 'DESC' ) {
		global $wpdb;
		$page       = max( 1, absint( $page ) );
		$per_page   = max( 1, min( 100, absint( $per_page ) ) );
		$offset     = ( $page - 1 ) * $per_page;
		$search     = trim( (string) $search );
		$program_id = absint( $program_id );
		$allowed    = array( 'all', 'valid', 'suspended', 'revoked', 'expired', 'not_yet_valid', 'pending_review', 'noncompliant', 'voided', 'not_issued' );
		$status     = in_array( $status, $allowed, true ) ? $status : 'all';
		$where      = array();
		$args       = array();

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(s.public_id LIKE %s OR v.display_number LIKE %s OR r.display_name LIKE %s OR r.email LIKE %s OR r.external_id LIKE %s OR p.title LIKE %s OR p.code LIKE %s OR b.name LIKE %s OR b.code LIKE %s)';
			$args    = array_merge( $args, array( $like, $like, $like, $like, $like, $like, $like, $like, $like ) );
		}
		if ( $program_id ) {
			$where[] = 's.program_id = %d';
			$args[]  = $program_id;
		}
		$exclude_source_type = trim( (string) $exclude_source_type );
		if ( '' !== $exclude_source_type ) {
			$where[] = '(v.source_type IS NULL OR v.source_type <> %s)';
			$args[]  = $exclude_source_type;
		}
		if ( 'all' !== $status ) {
			$predicate = self::management_status_predicate( $status, gmdate( 'Y-m-d' ) );
			if ( is_wp_error( $predicate ) ) { return $predicate; }
			$where[] = '(' . $predicate['sql'] . ')';
			$args     = array_merge( $args, $predicate['args'] );
		}

		$series     = SchemaManager::table( 'credential_series' );
		$versions   = SchemaManager::table( 'credential_versions' );
		$recipients = SchemaManager::table( 'recipients' );
		$programs   = SchemaManager::table( 'programs' );
		$batches    = SchemaManager::table( 'batches' );
		$issuers    = SchemaManager::table( 'issuers' );
		$joins      = ' FROM %i s LEFT JOIN %i v ON v.id=s.current_version_id AND v.series_id=s.id LEFT JOIN %i r ON r.id=s.recipient_id LEFT JOIN %i p ON p.id=s.program_id LEFT JOIN %i b ON b.id=s.batch_id LEFT JOIN %i i ON i.id=s.issuer_id';
		$where_sql  = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$base_args  = array( $series, $versions, $recipients, $programs, $batches, $issuers );

		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $joins . $where_sql, array_merge( $base_args, $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_credential_management_count_failed', __( 'Certloom could not count the credential registry safely.', 'certloom' ) );
		}

		$orderby = sanitize_key( (string) $orderby );
		$order   = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		$order_clause = 's.updated_at DESC, s.id DESC';
		switch ( $orderby ) {
			case 'credential':
				$order_clause = "COALESCE(NULLIF(v.display_number,''),s.public_id) {$order}, s.id {$order}";
				break;
			case 'recipient':
				$order_clause = "r.display_name {$order}, s.id {$order}";
				break;
			case 'program':
				$order_clause = "COALESCE(p.title,'') {$order}, COALESCE(b.name,'') {$order}, s.id {$order}";
				break;
			case 'issue_date':
				$order_clause = "v.issued_at {$order}, s.id {$order}";
				break;
			case 'expiry_date':
				$order_clause = "CASE WHEN v.expires_at IS NULL OR v.expires_at='' THEN 1 ELSE 0 END ASC, v.expires_at {$order}, s.id {$order}";
				break;
			case 'status':
				$today = esc_sql( gmdate( 'Y-m-d' ) );
				$order_clause = "CASE
					WHEN v.issuance_state='voided' THEN 'voided'
					WHEN v.issuance_state='draft' THEN 'not_issued'
					WHEN s.administrative_state='revoked' THEN 'revoked'
					WHEN s.administrative_state='suspended' THEN 'suspended'
					WHEN v.legacy_expired=1 THEN 'expired'
					WHEN v.valid_from IS NOT NULL AND v.valid_from>'{$today}' THEN 'not_yet_valid'
					WHEN v.expires_at IS NOT NULL AND v.expires_at<'{$today}' THEN 'expired'
					WHEN s.compliance_state='pending' THEN 'pending_review'
					WHEN s.compliance_state='noncompliant' THEN 'suspended_noncompliant'
					ELSE 'valid' END {$order}, s.id {$order}";
				break;
			case 'version':
				$order_clause = "COALESCE(v.version_number,0) {$order}, s.id {$order}";
				break;
			case 'added':
				$order_clause = "s.created_at {$order}, s.id {$order}";
				break;
		}

		$sql = 'SELECT s.id AS series_id, s.public_id, s.recipient_id, s.program_id, s.batch_id, s.issuer_id, s.current_version_id, s.administrative_state, s.compliance_state, s.visibility, s.created_at AS series_created_at, s.updated_at AS series_updated_at, r.display_name, r.email AS recipient_email, p.title AS program_title, p.code AS program_code, b.name AS batch_name, b.code AS batch_code, i.name AS issuer_name, v.id AS version_id, v.version_number, v.previous_version_id, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at, v.grade, v.source_type, v.source_idempotency_key, v.updated_at AS version_updated_at' . $joins . $where_sql . ' ORDER BY ' . $order_clause . ' LIMIT %d OFFSET %d';
		$wpdb->last_error = '';
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $base_args, $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY is assembled exclusively from a strict switch over internal column expressions and ASC/DESC.
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_credential_management_list_failed', __( 'Certloom could not load the credential registry safely.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			foreach ( array( 'series_id', 'recipient_id', 'program_id', 'batch_id', 'issuer_id', 'current_version_id', 'version_id', 'previous_version_id' ) as $key ) {
				$item[ $key ] = ! empty( $item[ $key ] ) ? absint( $item[ $key ] ) : null;
			}
			$item['version_number'] = ! empty( $item['version_number'] ) ? absint( $item['version_number'] ) : null;
			$item['legacy_expired'] = ! empty( $item['legacy_expired'] );
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Bounded authenticated Recipient collection used by POR-01 My Credentials.
	 *
	 * Ownership is supplied as one already-resolved Recipient ID; this query never
	 * attempts to infer identity from email/name input. Only current issued Versions
	 * are returned. Historical Versions remain available through their dedicated
	 * immutable-history surfaces rather than being duplicated as portal cards.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error
	 */
	public function list_recipient_portal_page( $recipient_id, $page = 1, $per_page = 12, $status = 'all', $sort = 'newest' ) {
		global $wpdb;
		$recipient_id = absint( $recipient_id );
		if ( ! $recipient_id ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 48, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$allowed_statuses = array( 'all', 'valid', 'suspended', 'revoked', 'expired', 'not_yet_valid', 'pending_review', 'noncompliant' );
		$status   = in_array( $status, $allowed_statuses, true ) ? $status : 'all';
		$allowed_sorts = array( 'newest', 'oldest', 'expires_soon', 'program' );
		$sort     = in_array( $sort, $allowed_sorts, true ) ? $sort : 'newest';

		$portal_visibility = VisibilityPolicy::recipient_portal_values();
		$where = array( 's.recipient_id=%d', 'v.issuance_state=%s', 's.visibility IN (%s,%s,%s,%s)' );
		$args  = array_merge( array( $recipient_id, States::ISSUANCE_ISSUED ), $portal_visibility );
		if ( 'all' !== $status ) {
			$predicate = self::management_status_predicate( $status, gmdate( 'Y-m-d' ) );
			if ( is_wp_error( $predicate ) ) { return $predicate; }
			$where[] = '(' . $predicate['sql'] . ')';
			$args = array_merge( $args, $predicate['args'] );
		}

		$order = 'v.issued_at DESC, s.id DESC';
		switch ( $sort ) {
			case 'oldest':
				$order = 'v.issued_at ASC, s.id ASC';
				break;
			case 'expires_soon':
				$order = 'CASE WHEN v.expires_at IS NULL THEN 1 ELSE 0 END ASC, v.expires_at ASC, v.issued_at DESC, s.id DESC';
				break;
			case 'program':
				$order = 'p.title ASC, v.issued_at DESC, s.id DESC';
				break;
		}

		$series   = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$programs = SchemaManager::table( 'programs' );
		$batches  = SchemaManager::table( 'batches' );
		$issuers  = SchemaManager::table( 'issuers' );
		$joins = ' FROM %i s INNER JOIN %i v ON v.id=s.current_version_id AND v.series_id=s.id LEFT JOIN %i p ON p.id=s.program_id LEFT JOIN %i b ON b.id=s.batch_id LEFT JOIN %i i ON i.id=s.issuer_id';
		$where_sql = ' WHERE ' . implode( ' AND ', $where );
		$base_args = array( $series, $versions, $programs, $batches, $issuers );

		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $joins . $where_sql, array_merge( $base_args, $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_recipient_portal_count_failed', __( 'Certloom could not count your credentials safely.', 'certloom' ) );
		}

		$sql = 'SELECT s.id AS series_id, s.public_id, s.visibility, s.administrative_state, s.compliance_state, s.program_id, s.batch_id, s.issuer_id, v.id AS version_id, v.version_number, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at, v.grade, v.snapshot_json, p.title AS program_title, p.code AS program_code, b.name AS batch_name, i.name AS issuer_name' . $joins . $where_sql . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d';
		$wpdb->last_error = '';
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $base_args, $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_recipient_portal_list_failed', __( 'Certloom could not load your credentials safely.', 'certloom' ) );
		}
		foreach ( $items as &$item ) {
			foreach ( array( 'series_id', 'program_id', 'batch_id', 'issuer_id', 'version_id', 'version_number' ) as $key ) {
				$item[ $key ] = ! empty( $item[ $key ] ) ? absint( $item[ $key ] ) : null;
			}
			$item['legacy_expired'] = ! empty( $item['legacy_expired'] );
			$item['snapshot'] = ( new SafeJson() )->decode_array( $item['snapshot_json'] ?? null, 'certloom_version_snapshot_corrupt', __( 'Stored credential snapshot is invalid JSON.', 'certloom' ) );
			unset( $item['snapshot_json'] );
			if ( is_wp_error( $item['snapshot'] ) ) { return $item['snapshot']; }
		}
		unset( $item );
		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array<string,int>|\WP_Error */
	public function management_summary( $exclude_source_type = '' ) {
		global $wpdb;
		$series   = SchemaManager::table( 'credential_series' );
		$versions = SchemaManager::table( 'credential_versions' );
		$today    = gmdate( 'Y-m-d' );
		$statuses = array( 'valid', 'suspended', 'revoked', 'expired', 'not_yet_valid', 'pending_review', 'noncompliant', 'voided', 'not_issued' );
		$parts          = array( 'COUNT(*) AS total' );
		$predicate_args = array();
		foreach ( $statuses as $status ) {
			$predicate = self::management_status_predicate( $status, $today );
			if ( is_wp_error( $predicate ) ) { return $predicate; }
			$parts[]          = 'SUM(CASE WHEN ' . $predicate['sql'] . ' THEN 1 ELSE 0 END) AS ' . $status;
			$predicate_args = array_merge( $predicate_args, $predicate['args'] );
		}
		$exclude_source_type = trim( (string) $exclude_source_type );
		$where = '' !== $exclude_source_type ? ' WHERE (v.source_type IS NULL OR v.source_type <> %s)' : '';
		$sql  = 'SELECT ' . implode( ', ', $parts ) . ' FROM %i s LEFT JOIN %i v ON v.id=s.current_version_id AND v.series_id=s.id' . $where;
		$args = array_merge( $predicate_args, array( $series, $versions ) );
		if ( '' !== $exclude_source_type ) { $args[] = $exclude_source_type; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return new \WP_Error( 'certloom_credential_management_summary_failed', __( 'Certloom could not load credential management totals.', 'certloom' ) );
		}
		$out = array();
		foreach ( array_merge( array( 'total' ), $statuses ) as $key ) { $out[ $key ] = absint( $row[ $key ] ?? 0 ); }
		return $out;
	}

	/** @return array{sql:string,args:array<int,mixed>}|\WP_Error */
	private static function management_status_predicate( $status, $today ) {
		$issued = States::ISSUANCE_ISSUED;
		$active = States::ADMIN_ACTIVE;
		$current_window = 'v.legacy_expired=0 AND (v.valid_from IS NULL OR v.valid_from <= %s) AND (v.expires_at IS NULL OR v.expires_at >= %s)';
		switch ( (string) $status ) {
			case 'valid':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s AND ' . $current_window . ' AND s.compliance_state IN (%s,%s)', 'args' => array( $issued, $active, $today, $today, States::COMPLIANCE_NOT_APPLICABLE, States::COMPLIANCE_COMPLIANT ) );
			case 'suspended':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s', 'args' => array( $issued, States::ADMIN_SUSPENDED ) );
			case 'revoked':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s', 'args' => array( $issued, States::ADMIN_REVOKED ) );
			case 'expired':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s AND (v.legacy_expired=1 OR (v.legacy_expired=0 AND (v.valid_from IS NULL OR v.valid_from <= %s) AND v.expires_at IS NOT NULL AND v.expires_at < %s))', 'args' => array( $issued, $active, $today, $today ) );
			case 'not_yet_valid':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s AND v.legacy_expired=0 AND v.valid_from IS NOT NULL AND v.valid_from > %s', 'args' => array( $issued, $active, $today ) );
			case 'pending_review':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s AND ' . $current_window . ' AND s.compliance_state=%s', 'args' => array( $issued, $active, $today, $today, States::COMPLIANCE_PENDING ) );
			case 'noncompliant':
				return array( 'sql' => 'v.issuance_state=%s AND s.administrative_state=%s AND ' . $current_window . ' AND s.compliance_state=%s', 'args' => array( $issued, $active, $today, $today, States::COMPLIANCE_NONCOMPLIANT ) );
			case 'voided':
				return array( 'sql' => 'v.issuance_state=%s', 'args' => array( States::ISSUANCE_VOIDED ) );
			case 'not_issued':
				return array( 'sql' => '(v.id IS NULL OR v.issuance_state=%s)', 'args' => array( States::ISSUANCE_DRAFT ) );
		}
		return new \WP_Error( 'certloom_credential_management_status_invalid', __( 'Choose a valid credential status filter.', 'certloom' ) );
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

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function list_versions_for_series( $series_id, $limit = 100 ) {
		global $wpdb;
		$series_id = absint( $series_id ); $limit = max( 1, min( 250, absint( $limit ) ) );
		if ( ! $series_id ) { return array(); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE series_id = %d ORDER BY version_number DESC, id DESC LIMIT %d', SchemaManager::table( 'credential_versions' ), $series_id, $limit ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_version_list_failed', __( 'Certloom could not load credential Version history.', 'certloom' ) ); }
		foreach ( $rows as &$row ) { $row = self::decode_version( $row ); if ( is_wp_error( $row ) ) { return $row; } } unset( $row );
		return $rows;
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


	/**
	 * Duplicate-prevention lookup for the one-Series-per-recipient/program
	 * Manual Issuance boundary. Re-certification belongs to a later immutable
	 * Version in the same Series rather than a second Series.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function find_series_for_recipient_program( $recipient_id, $program_id ) {
		global $wpdb;
		$recipient_id = absint( $recipient_id );
		$program_id = absint( $program_id );
		if ( ! $recipient_id || ! $program_id ) { return null; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE recipient_id=%d AND program_id=%d ORDER BY id ASC LIMIT 1',
				SchemaManager::table( 'credential_series' ),
				$recipient_id,
				$program_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_series_duplicate_check_failed', __( 'Certloom could not check for an existing credential for this recipient and program.', 'certloom' ) ); }
		return is_array( $row ) ? self::decode_series( $row ) : null;
	}


	/**
	 * Duplicate-prevention lookup for Programs configured as one credential per
	 * Batch/session. The same recipient may therefore hold separate Series for
	 * different Batches of the same Program, but never two for the same Batch.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function find_series_for_recipient_program_batch( $recipient_id, $program_id, $batch_id ) {
		global $wpdb;
		$recipient_id = absint( $recipient_id );
		$program_id = absint( $program_id );
		$batch_id = absint( $batch_id );
		if ( ! $recipient_id || ! $program_id || ! $batch_id ) { return null; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE recipient_id=%d AND program_id=%d AND batch_id=%d ORDER BY id ASC LIMIT 1',
				SchemaManager::table( 'credential_series' ),
				$recipient_id,
				$program_id,
				$batch_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_series_batch_duplicate_check_failed', __( 'Certloom could not check for an existing credential for this recipient, Program and Batch.', 'certloom' ) ); }
		return is_array( $row ) ? self::decode_series( $row ) : null;
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

	/** @return true|\WP_Error */
	private function release_new_number_reservation( array $reservation ) {
		if ( empty( $reservation['new'] ) || empty( $reservation['id'] ) ) { return true; }
		global $wpdb;
		$wpdb->last_error = '';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id = %d AND first_version_id IS NULL',
				SchemaManager::table( 'credential_numbers' ),
				absint( $reservation['id'] )
			)
		);
		return 1 === (int) $deleted && '' === (string) $wpdb->last_error
			? true
			: new \WP_Error( 'certloom_display_number_release_failed', __( 'Certloom could not safely release a failed certificate-number reservation. The number remains reserved to prevent duplicate ownership.', 'certloom' ) );
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
		foreach ( array( 'id', 'recipient_id', 'program_id', 'batch_id', 'issuer_id', 'current_version_id' ) as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) ? absint( $row[ $key ] ) : null;
		}
		return $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function decode_version( array $row ) {
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['series_id'] = absint( $row['series_id'] ?? 0 );
		$row['version_number'] = absint( $row['version_number'] ?? 0 );
		$row['legacy_expired'] = ! empty( $row['legacy_expired'] );
		$row['snapshot'] = ( new SafeJson() )->decode_array( $row['snapshot_json'] ?? null, 'certloom_version_snapshot_corrupt', __( 'Stored credential snapshot is invalid JSON.', 'certloom' ) );
		unset( $row['snapshot_json'] );
		return is_wp_error( $row['snapshot'] ) ? $row['snapshot'] : $row;
	}

	/** @return bool|\WP_Error */
	private function reference_exists( $entity, $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( $entity ), absint( $id ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_series_reference_read_failed', __( 'Certloom could not validate a related credential record.', 'certloom' ) ); }
		return ! empty( $found );
	}

	/** @return int|\WP_Error */
	private function batch_program_id( $batch_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$program_id = $wpdb->get_var( $wpdb->prepare( 'SELECT program_id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( 'batches' ), absint( $batch_id ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_series_batch_read_failed', __( 'Certloom could not validate the credential batch.', 'certloom' ) ); }
		return absint( $program_id );
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
			return new \WP_Error( 'certloom_version_date_invalid', sprintf( /* translators: %s: field label. */ __( '%s must use the YYYY-MM-DD date format.', 'certloom' ), $field ) );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			return new \WP_Error( 'certloom_version_date_invalid', sprintf( /* translators: %s: field label. */ __( '%s is not a valid calendar date.', 'certloom' ), $field ) );
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
			return new \WP_Error( 'certloom_version_datetime_invalid', sprintf( /* translators: %s: field label. */ __( '%s must be a valid UTC YYYY-MM-DD HH:MM:SS value.', 'certloom' ), $field ) );
		}
		return $value;
	}
}
