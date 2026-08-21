<?php
namespace Certloom\Infrastructure\Repository;

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
				'administrative_state' => States::ADMIN_ACTIVE,
				'visibility' => self::visibility( $data['visibility'] ?? 'verification_summary' ),
				'created_at' => $now,
				'updated_at' => $now,
				'closed_at' => null,
			);
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
			return new \WP_Error( 'certloom_version_create_failed', __( 'Certloom could not create the credential version.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
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
				'SELECT s.id AS series_id, s.public_id, s.recipient_id, s.program_id, s.issuer_id, s.current_version_id, s.administrative_state, s.visibility, v.id AS version_id, v.version_number, v.display_number, v.issuance_state, v.legacy_expired, v.valid_from, v.issued_at, v.expires_at FROM %i s LEFT JOIN %i v ON v.id = s.current_version_id AND v.series_id = s.id WHERE s.public_id = %s LIMIT 1',
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
