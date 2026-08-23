<?php
namespace Certloom\Application\Restore;

use Certloom\Application\VisibilityPolicy;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\States;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\SchemaManager;

/** Validates one backup row's scalar/schema semantics before it enters staging. */
final class DomainRestoreRowValidator {
	/** @var PublicCredentialId */
	private $public_ids;

	public function __construct( PublicCredentialId $public_ids = null ) {
		$this->public_ids = $public_ids ?: new PublicCredentialId();
	}

	/** @return true|\WP_Error */
	public function validate( $entity, array $row ) {
		$entity = sanitize_key( (string) $entity );
		if ( ! in_array( $entity, DomainBackupRepository::entity_types(), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_entity_invalid', __( 'The backup contains an unsupported Certloom entity.', 'certloom' ) );
		}
		$expected = SchemaManager::column_names( $entity );
		$actual   = array_keys( $row );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual || absint( $row['id'] ?? 0 ) < 1 ) {
			return new \WP_Error( 'certloom_domain_restore_row_schema_invalid', __( 'A backup record does not match the expected Certloom table schema.', 'certloom' ) );
		}

		$dates = array();
		$json  = array();
		switch ( $entity ) {
			case 'issuers':
				$issuer_name = trim( (string) $row['name'] );
				$issuer_slug = (string) $row['slug'];
				if ( '' === $issuer_name || $this->length( $issuer_name ) > 255 || '' === $issuer_slug || ! $this->valid_issuer_slug( $issuer_slug ) || $this->length( $issuer_slug ) > 191 ) {
					return $this->invalid( 'issuer' );
				}
				$json  = array( 'settings_json' );
				$dates = array( 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'programs':
				$program_title = trim( (string) $row['title'] );
				$program_code  = null === $row['code'] ? null : trim( (string) $row['code'] );
				if ( '' === $program_title || $this->length( $program_title ) > 255 || ( null !== $program_code && $this->length( $program_code ) > 100 ) || ! in_array( (string) $row['active'], array( '0', '1' ), true ) ) {
					return $this->invalid( 'program' );
				}
				$json  = array( 'metadata_json' );
				$dates = array( 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'recipients':
				$recipient_name = trim( (string) $row['display_name'] );
				if ( '' === $recipient_name || $this->length( $recipient_name ) > 255 ) {
					return $this->invalid( 'recipient' );
				}
				foreach ( array( 'first_name', 'middle_name', 'last_name', 'external_id' ) as $bounded_recipient_field ) {
					if ( null !== $row[ $bounded_recipient_field ] && $this->length( (string) $row[ $bounded_recipient_field ] ) > 191 ) {
						return $this->invalid( 'recipient' );
					}
				}
				if ( null !== $row['email'] && '' !== (string) $row['email'] && ( $this->length( (string) $row['email'] ) > 320 || false === filter_var( (string) $row['email'], FILTER_VALIDATE_EMAIL ) ) ) {
					return new \WP_Error( 'certloom_domain_restore_recipient_email_invalid', __( 'A recipient in the backup contains an invalid email address.', 'certloom' ) );
				}
				$json  = array( 'custom_fields' );
				$dates = array( 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'signers':
				$signer_name = trim( (string) $row['display_name'] );
				$signer_slug = (string) $row['slug'];
				if ( '' === $signer_name || $this->length( $signer_name ) > 255 || '' === $signer_slug || sanitize_key( $signer_slug ) !== $signer_slug || $this->length( $signer_slug ) > 191 || ( null !== $row['title'] && $this->length( (string) $row['title'] ) > 191 ) || ! in_array( (string) $row['active'], array( '0', '1' ), true ) ) {
					return $this->invalid( 'signer' );
				}
				if ( null !== $row['email'] && '' !== (string) $row['email'] && ( $this->length( (string) $row['email'] ) > 320 || false === filter_var( (string) $row['email'], FILTER_VALIDATE_EMAIL ) ) ) {
					return new \WP_Error( 'certloom_domain_restore_signer_email_invalid', __( 'A signer in the backup contains an invalid email address.', 'certloom' ) );
				}
				$json = array( 'metadata_json' );
				$dates = array( 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'batches':
				$batch_name = trim( (string) $row['name'] );
				$batch_code = trim( (string) $row['code'] );
				if ( '' === $batch_name || $this->length( $batch_name ) > 255 || '' === $batch_code || $this->length( $batch_code ) > 100 || absint( $row['program_id'] ) < 1 || ( null !== $row['instructor_name'] && $this->length( (string) $row['instructor_name'] ) > 255 ) || ! in_array( (string) $row['active'], array( '0', '1' ), true ) ) {
					return $this->invalid( 'batch' );
				}
				$json = array( 'metadata_json' );
				$dates = array( 'start_date' => 'date', 'end_date' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'batch_recipients':
				if ( absint( $row['batch_id'] ) < 1 || absint( $row['recipient_id'] ) < 1 ) { return $this->invalid( 'batch_recipient' ); }
				$dates = array( 'created_at' => 'datetime' );
				break;
			case 'signer_assignments':
				if ( absint( $row['signer_id'] ) < 1 || ! in_array( (string) $row['scope_type'], array( 'global', 'issuer', 'program', 'batch' ), true ) || ! in_array( (string) $row['role_type'], array( 'signer', 'instructor' ), true ) || absint( $row['priority'] ) > 1000 || ! in_array( (string) $row['active'], array( '0', '1' ), true ) ) {
					return $this->invalid( 'signer_assignment' );
				}
				if ( 'global' === (string) $row['scope_type'] && 0 !== absint( $row['scope_id'] ) ) { return $this->invalid( 'signer_assignment' ); }
				if ( 'global' !== (string) $row['scope_type'] && absint( $row['scope_id'] ) < 1 ) { return $this->invalid( 'signer_assignment' ); }
				$dates = array( 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'credential_series':
				if ( ! $this->public_ids->is_valid( (string) $row['public_id'] ) || ! in_array( (string) $row['administrative_state'], States::administrative_values(), true ) || ! in_array( (string) $row['compliance_state'], States::compliance_values(), true ) || ! in_array( (string) $row['visibility'], VisibilityPolicy::persisted_values(), true ) ) {
					return $this->invalid( 'credential_series' );
				}
				$dates = array( 'created_at' => 'datetime', 'updated_at' => 'datetime', 'closed_at' => 'datetime' );
				break;
			case 'credential_numbers':
				if ( '' === trim( (string) $row['display_number'] ) ) {
					return $this->invalid( 'credential_number' );
				}
				$dates = array( 'created_at' => 'datetime', 'retired_at' => 'datetime' );
				break;
			case 'credential_versions':
				if ( absint( $row['version_number'] ) < 1 || '' === trim( (string) $row['display_number'] ) || ! in_array( (string) $row['issuance_state'], States::issuance_values(), true ) || ! in_array( (string) $row['legacy_expired'], array( '0', '1' ), true ) ) {
					return $this->invalid( 'credential_version' );
				}
				if ( null !== $row['source_idempotency_key'] && '' !== (string) $row['source_idempotency_key'] && ! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $row['source_idempotency_key'] ) ) ) {
					return new \WP_Error( 'certloom_domain_restore_idempotency_invalid', __( 'A credential Version contains an invalid source idempotency key.', 'certloom' ) );
				}
				$json  = array( 'snapshot_json' );
				$dates = array( 'valid_from' => 'date', 'issued_at' => 'datetime', 'expires_at' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime' );
				break;
			case 'credential_events':
				if ( '' === sanitize_key( (string) $row['event_type'] ) ) {
					return $this->invalid( 'credential_event' );
				}
				$json  = array( 'old_state_json', 'new_state_json', 'meta_json' );
				$dates = array( 'created_at' => 'datetime' );
				break;
		}

		foreach ( $json as $field ) {
			if ( null === $row[ $field ] || '' === (string) $row[ $field ] ) {
				continue;
			}
			$decoded = json_decode( (string) $row[ $field ], true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return new \WP_Error( 'certloom_domain_restore_json_field_invalid', __( 'A backup record contains malformed or structurally invalid JSON data.', 'certloom' ), array( 'entity' => $entity, 'field' => $field ) );
			}
		}
		foreach ( $dates as $field => $kind ) {
			if ( null === $row[ $field ] || '' === (string) $row[ $field ] ) {
				continue;
			}
			if ( ! $this->valid_date( (string) $row[ $field ], $kind ) ) {
				return new \WP_Error( 'certloom_domain_restore_date_invalid', __( 'A backup record contains an invalid canonical date or timestamp.', 'certloom' ), array( 'entity' => $entity, 'field' => $field ) );
			}
		}
		return true;
	}

	/** @return \WP_Error */
	private function invalid( $type ) {
		return new \WP_Error( 'certloom_domain_restore_' . sanitize_key( $type ) . '_invalid', __( 'A backup record contains invalid Certloom domain data.', 'certloom' ) );
	}

	private function length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value ); }

	/** Accepts canonical WordPress slug bytes, including percent-encoded UTF-8 produced by sanitize_title(). */
	private function valid_issuer_slug( $value ) {
		return 1 === preg_match( '/^(?:[a-z0-9_-]|%[a-f0-9]{2})+$/', (string) $value );
	}

	/** @return bool */
	private function valid_date( $value, $kind ) {
		$format = 'date' === $kind ? '!Y-m-d' : '!Y-m-d H:i:s';
		$date = \DateTimeImmutable::createFromFormat( $format, $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date ) {
			return false;
		}
		if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
			return false;
		}
		return $date->format( 'date' === $kind ? 'Y-m-d' : 'Y-m-d H:i:s' ) === $value;
	}
}
