<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\DomainBackupReader;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\States;

/**
 * Semantic restore preflight for a cryptographically/integrity-valid domain backup.
 * Performs no live writes. It verifies relationship closure and stable unique keys
 * before a staging workspace is allowed to be built.
 */
final class DomainRestorePreflightService {
	const MAX_ERRORS = 100;
	private $reader;
	private $public_ids;
	private $row_validator;

	public function __construct( DomainBackupReader $reader = null, PublicCredentialId $public_ids = null, DomainRestoreRowValidator $row_validator = null ) {
		$this->reader = $reader ?: new DomainBackupReader();
		$this->public_ids = $public_ids ?: new PublicCredentialId();
		$this->row_validator = $row_validator ?: new DomainRestoreRowValidator( $this->public_ids );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function analyze( $path, $max_records = 500000 ) {
		$integrity = $this->reader->analyze( $path, $max_records );
		if ( is_wp_error( $integrity ) ) { return $integrity; }
		$config = $this->validate_configuration( (array) ( $integrity['manifest']['configuration'] ?? array() ) );
		if ( is_wp_error( $config ) ) { return $config; }

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only streaming restore preflight.
		if ( false === $handle ) { return new \WP_Error( 'certloom_domain_restore_read_failed', __( 'Certloom could not reopen the validated backup for restore review.', 'certloom' ) ); }

		$order = array( 'issuers', 'programs', 'recipients', 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events' );
		$order_map = array_flip( $order );
		$current_order = -1;
		$ids = array_fill_keys( $order, array() );
		$issuer_slugs = array(); $program_codes = array(); $recipient_wp_users = array();
		$public_ids = array(); $display_numbers = array(); $series_versions = array(); $source_keys = array();
		$version_series = array(); $series_current = array(); $number_first_versions = array(); $version_previous = array();
		$counts = array_fill_keys( $order, 0 ); $errors = array(); $line_no = 0;

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line_no++;
			$item = json_decode( trim( $line ), true );
			if ( ! is_array( $item ) ) { continue; } // Integrity reader already reports malformed JSON.
			if ( 'entity' !== (string) ( $item['type'] ?? '' ) ) { continue; }
			$type = sanitize_key( (string) ( $item['entity'] ?? '' ) );
			$row = is_array( $item['data'] ?? null ) ? $item['data'] : array();
			if ( ! isset( $order_map[ $type ] ) ) { $this->error( $errors, $line_no, 'unsupported_entity', 'Unsupported restore entity.' ); continue; }
			if ( $order_map[ $type ] < $current_order ) { $this->error( $errors, $line_no, 'entity_order', 'Backup entities are not in the canonical dependency order.' ); continue; }
			$current_order = $order_map[ $type ];
			$scalar = $this->row_validator->validate( $type, $row );
			if ( is_wp_error( $scalar ) ) { $this->error( $errors, $line_no, $scalar->get_error_code(), $scalar->get_error_message() ); continue; }
			$id = absint( $row['id'] ?? 0 );
			if ( $id < 1 ) { $this->error( $errors, $line_no, 'invalid_id', 'Restore entity ID must be a positive integer.' ); continue; }
			if ( isset( $ids[ $type ][ $id ] ) ) { $this->error( $errors, $line_no, 'duplicate_id', 'Duplicate entity ID inside backup.' ); continue; }
			$ids[ $type ][ $id ] = true; $counts[ $type ]++;

			switch ( $type ) {
				case 'issuers':
					$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
					if ( '' === trim( (string) ( $row['name'] ?? '' ) ) || '' === $slug ) { $this->error( $errors, $line_no, 'issuer_invalid', 'Issuer name and slug are required.' ); break; }
					if ( isset( $issuer_slugs[ $slug ] ) ) { $this->error( $errors, $line_no, 'issuer_slug_duplicate', 'Duplicate issuer slug inside backup.' ); } else { $issuer_slugs[ $slug ] = $id; }
					break;
				case 'programs':
					if ( '' === trim( (string) ( $row['title'] ?? '' ) ) ) { $this->error( $errors, $line_no, 'program_invalid', 'Program title is required.' ); }
					$issuer_id = absint( $row['issuer_id'] ?? 0 ); if ( $issuer_id && ! isset( $ids['issuers'][ $issuer_id ] ) ) { $this->error( $errors, $line_no, 'program_issuer_missing', 'Program refers to a missing issuer.' ); }
					$code = trim( (string) ( $row['code'] ?? '' ) ); if ( '' !== $code ) { if ( isset( $program_codes[ $code ] ) ) { $this->error( $errors, $line_no, 'program_code_duplicate', 'Duplicate program code inside backup.' ); } else { $program_codes[ $code ] = $id; } }
					break;
				case 'recipients':
					if ( '' === trim( (string) ( $row['display_name'] ?? '' ) ) ) { $this->error( $errors, $line_no, 'recipient_invalid', 'Recipient display name is required.' ); }
					$wp_user_id = absint( $row['wp_user_id'] ?? 0 ); if ( $wp_user_id ) { if ( isset( $recipient_wp_users[ $wp_user_id ] ) ) { $this->error( $errors, $line_no, 'recipient_wp_user_duplicate', 'Multiple recipients refer to the same WordPress user.' ); } else { $recipient_wp_users[ $wp_user_id ] = $id; } }
					break;
				case 'credential_series':
					$public_id = (string) ( $row['public_id'] ?? '' );
					if ( ! $this->public_ids->is_valid( $public_id ) ) { $this->error( $errors, $line_no, 'public_id_invalid', 'Credential Series contains an invalid public credential ID.' ); }
					elseif ( isset( $public_ids[ $public_id ] ) ) { $this->error( $errors, $line_no, 'public_id_duplicate', 'Duplicate public credential ID inside backup.' ); } else { $public_ids[ $public_id ] = $id; }
					$recipient_id = absint( $row['recipient_id'] ?? 0 ); if ( ! isset( $ids['recipients'][ $recipient_id ] ) ) { $this->error( $errors, $line_no, 'series_recipient_missing', 'Credential Series refers to a missing recipient.' ); }
					$program_id = absint( $row['program_id'] ?? 0 ); if ( $program_id && ! isset( $ids['programs'][ $program_id ] ) ) { $this->error( $errors, $line_no, 'series_program_missing', 'Credential Series refers to a missing program.' ); }
					$issuer_id = absint( $row['issuer_id'] ?? 0 ); if ( $issuer_id && ! isset( $ids['issuers'][ $issuer_id ] ) ) { $this->error( $errors, $line_no, 'series_issuer_missing', 'Credential Series refers to a missing issuer.' ); }
					$admin = (string) ( $row['administrative_state'] ?? '' ); if ( ! in_array( $admin, States::administrative_values(), true ) ) { $this->error( $errors, $line_no, 'series_state_invalid', 'Credential Series contains an unsupported administrative state.' ); }
					$compliance = (string) ( $row['compliance_state'] ?? '' ); if ( ! in_array( $compliance, States::compliance_values(), true ) ) { $this->error( $errors, $line_no, 'series_compliance_state_invalid', 'Credential Series contains an unsupported compliance state.' ); }
					$current = absint( $row['current_version_id'] ?? 0 ); if ( $current ) { $series_current[ $id ] = $current; }
					break;
				case 'credential_numbers':
					$number = trim( (string) ( $row['display_number'] ?? '' ) ); $series_id = absint( $row['series_id'] ?? 0 );
					if ( '' === $number || ! isset( $ids['credential_series'][ $series_id ] ) ) { $this->error( $errors, $line_no, 'number_invalid', 'Credential number is empty or refers to a missing Series.' ); break; }
					if ( isset( $display_numbers[ $number ] ) ) { $this->error( $errors, $line_no, 'number_duplicate', 'Duplicate human certificate number inside backup.' ); } else { $display_numbers[ $number ] = $series_id; }
					$first = absint( $row['first_version_id'] ?? 0 ); if ( $first ) { $number_first_versions[] = array( 'series_id' => $series_id, 'version_id' => $first, 'line' => $line_no ); }
					break;
				case 'credential_versions':
					$series_id = absint( $row['series_id'] ?? 0 ); $version_number = absint( $row['version_number'] ?? 0 );
					if ( ! isset( $ids['credential_series'][ $series_id ] ) || $version_number < 1 ) { $this->error( $errors, $line_no, 'version_invalid', 'Credential Version refers to a missing Series or invalid version number.' ); break; }
					$key = $series_id . ':' . $version_number; if ( isset( $series_versions[ $key ] ) ) { $this->error( $errors, $line_no, 'series_version_duplicate', 'Duplicate Version number within one Credential Series.' ); } else { $series_versions[ $key ] = $id; }
					$issuance = (string) ( $row['issuance_state'] ?? '' ); if ( ! in_array( $issuance, States::issuance_values(), true ) ) { $this->error( $errors, $line_no, 'version_state_invalid', 'Credential Version contains an unsupported issuance state.' ); }
					$number = trim( (string) ( $row['display_number'] ?? '' ) ); if ( '' === $number || ! isset( $display_numbers[ $number ] ) || (int) $display_numbers[ $number ] !== $series_id ) { $this->error( $errors, $line_no, 'version_number_assignment_missing', 'Credential Version number is not assigned to its Series.' ); }
					$source_type = trim( (string) ( $row['source_type'] ?? '' ) ); $idem = trim( (string) ( $row['source_idempotency_key'] ?? '' ) ); if ( '' !== $source_type && '' !== $idem ) { $source_key = $source_type . ':' . $idem; if ( isset( $source_keys[ $source_key ] ) ) { $this->error( $errors, $line_no, 'source_idempotency_duplicate', 'Duplicate source idempotency key inside backup.' ); } else { $source_keys[ $source_key ] = $id; } }
										$previous = absint( $row['previous_version_id'] ?? 0 ); if ( $previous ) { $version_previous[] = array( 'series_id' => $series_id, 'version_id' => $id, 'previous_version_id' => $previous, 'line' => $line_no ); }
					$version_series[ $id ] = $series_id;
					break;
				case 'credential_events':
					$series_id = absint( $row['series_id'] ?? 0 ); if ( ! isset( $ids['credential_series'][ $series_id ] ) ) { $this->error( $errors, $line_no, 'event_series_missing', 'Credential event refers to a missing Series.' ); }
					$version_id = absint( $row['version_id'] ?? 0 ); if ( $version_id && ( ! isset( $version_series[ $version_id ] ) || (int) $version_series[ $version_id ] !== $series_id ) ) { $this->error( $errors, $line_no, 'event_version_missing', 'Credential event refers to a missing or different-Series Version.' ); }
					if ( '' === sanitize_key( (string) ( $row['event_type'] ?? '' ) ) ) { $this->error( $errors, $line_no, 'event_type_invalid', 'Credential event type is required.' ); }
					break;
			}
			if ( count( $errors ) >= self::MAX_ERRORS ) { break; }
		}
		fclose( $handle );

		if ( count( $errors ) < self::MAX_ERRORS ) {
			foreach ( $series_current as $series_id => $version_id ) {
				if ( ! isset( $version_series[ $version_id ] ) || (int) $version_series[ $version_id ] !== (int) $series_id ) { $this->error( $errors, 0, 'current_version_invalid', 'Credential Series current Version is missing or belongs to another Series.' ); }
			}
			foreach ( $number_first_versions as $ref ) {
				if ( ! isset( $version_series[ $ref['version_id'] ] ) || (int) $version_series[ $ref['version_id'] ] !== (int) $ref['series_id'] ) { $this->error( $errors, $ref['line'], 'number_first_version_invalid', 'Credential-number assignment points to a missing or different-Series first Version.' ); }
			}
			foreach ( $version_previous as $ref ) {
				if ( ! isset( $version_series[ $ref['previous_version_id'] ] ) || (int) $version_series[ $ref['previous_version_id'] ] !== (int) $ref['series_id'] || (int) $ref['previous_version_id'] === (int) $ref['version_id'] ) { $this->error( $errors, $ref['line'], 'previous_version_invalid', 'Credential Version previous-version link is missing, self-referential, or belongs to another Series.' ); }
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'certloom_domain_restore_semantic_invalid', sprintf( __( 'The Certloom backup passed file integrity checks but contains %d semantic relationship error(s).', 'certloom' ), count( $errors ) ), $errors );
		}
		return array( 'manifest' => $integrity['manifest'], 'records' => absint( $integrity['records'] ), 'counts' => $counts, 'configuration' => $config, 'ready' => true );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validate_configuration( array $config ) {
		$required = array( 'certverify_fields', 'certverify_retired_fields', 'certverify_settings' );
		foreach ( $required as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) || ! array_key_exists( 'exists', $config[ $key ] ) || ! array_key_exists( 'value', $config[ $key ] ) ) {
				return new \WP_Error( 'certloom_domain_restore_configuration_invalid', __( 'The Certloom backup is missing required configuration metadata.', 'certloom' ) );
			}
		}
		return $config;
	}

	private function error( array &$errors, $line, $code, $message ) {
		if ( count( $errors ) < self::MAX_ERRORS ) { $errors[] = array( 'line' => absint( $line ), 'code' => (string) $code, 'message' => (string) $message ); }
	}
}
