<?php
namespace Certloom\Application\Restore;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Backup\DomainBackupReader;
use Certloom\Application\Backup\DomainBackupFormat;
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
	private $format;

	public function __construct( ?DomainBackupReader $reader = null, ?PublicCredentialId $public_ids = null, ?DomainRestoreRowValidator $row_validator = null, ?DomainBackupFormat $format = null ) {
		$this->reader = $reader ?: new DomainBackupReader();
		$this->public_ids = $public_ids ?: new PublicCredentialId();
		$this->row_validator = $row_validator ?: new DomainRestoreRowValidator( $this->public_ids );
		$this->format = $format ?: new DomainBackupFormat();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function analyze( $path, $max_records = 500000 ) {
		$integrity = $this->reader->analyze( $path, $max_records );
		if ( is_wp_error( $integrity ) ) { return $integrity; }
		$size = @filesize( $path );
		if ( false !== $size && $size > DomainBackupFormat::MAX_FILE_BYTES ) {
			return new \WP_Error( 'certloom_domain_backup_file_too_large', __( 'The Certloom domain backup exceeds the supported restore size limit.', 'certloom' ) );
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only bounded streaming restore preflight.
		if ( false === $handle ) { return new \WP_Error( 'certloom_domain_restore_read_failed', __( 'Certloom could not reopen the validated backup for restore review.', 'certloom' ) ); }

		$order = array( 'issuers', 'programs', 'recipients', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events' );
		$order_map = array_flip( $order );
		$current_order = -1;
		$ids = array_fill_keys( $order, array() );
		$issuer_slugs = array(); $program_codes = array(); $program_issuers = array(); $recipient_wp_users = array(); $signer_slugs = array(); $batch_codes = array(); $batch_members = array(); $signer_assignments = array();
		$template_keys = array(); $template_revision_keys = array(); $template_assignment_keys = array(); $template_revision_owner = array(); $template_current = array(); $template_published = array(); $template_revision_parent = array();
		$public_ids = array(); $display_numbers = array(); $series_versions = array(); $source_keys = array();
		$version_series = array(); $series_current = array(); $number_first_versions = array(); $version_previous = array();
		$counts = array_fill_keys( $order, 0 ); $stream_counts = array_fill_keys( $order, 0 ); $errors = array(); $line_no = 0;
		$manifest = null; $config = null; $hash = ''; $records = 0; $after_integrity = false; $format_version = 0;

		while ( true ) {
			$line_no++;
			$line = $this->format->read_line( $handle, $line_no );
			if ( is_wp_error( $line ) ) { fclose( $handle ); return $line; }
			if ( null === $line ) { $line_no--; break; }
			$position = ftell( $handle );
			if ( false !== $position && $position > DomainBackupFormat::MAX_FILE_BYTES ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_file_too_large', __( 'The Certloom domain backup exceeds the supported restore size limit.', 'certloom' ) ); }
			if ( $after_integrity ) {
				if ( '' !== trim( $line ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_trailing_data', __( 'The Certloom domain backup contains unexpected data after its integrity record.', 'certloom' ) ); }
				continue;
			}
			$item = json_decode( trim( $line ), true );
			if ( ! is_array( $item ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_json_invalid', sprintf( /* translators: %d: line number. */ __( 'The Certloom domain backup contains invalid JSON on line %d.', 'certloom' ), $line_no ) ); }
			$item_type = (string) ( $item['type'] ?? '' );
			if ( 1 === $line_no ) {
				$normalized = $this->format->normalize_manifest( $item );
				if ( is_wp_error( $normalized ) ) { fclose( $handle ); return $normalized; }
				$format_version = absint( $item['format_version'] ?? 0 );
				$config = $this->validate_configuration( (array) ( $item['configuration'] ?? array() ) );
				if ( is_wp_error( $config ) ) { fclose( $handle ); return $config; }
				$config_json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$config_hash = strtolower( (string) ( $item['configuration_hash'] ?? '' ) );
				if ( false === $config_json || ! preg_match( '/^[a-f0-9]{64}$/', $config_hash ) || ! hash_equals( $config_hash, hash( 'sha256', $config_json ) ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_configuration_hash_invalid', __( 'The Certloom domain backup configuration metadata is inconsistent.', 'certloom' ) ); }
				$manifest = $normalized;
				$hash = $this->format->next_hash( '', $line, $format_version );
				continue;
			}
			if ( 'integrity' === $item_type ) {
				if ( $records !== absint( $item['records'] ?? -1 ) || ! hash_equals( $hash, (string) ( $item['chain_sha256'] ?? '' ) ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_integrity_failed', __( 'The Certloom domain backup failed its integrity check during semantic restore review.', 'certloom' ) ); }
				$after_integrity = true;
				continue;
			}
			if ( 'entity' !== $item_type || empty( $item['entity'] ) || ! is_array( $item['data'] ?? null ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_record_invalid', sprintf( /* translators: %d: line number. */ __( 'The Certloom domain backup contains an unsupported record on line %d.', 'certloom' ), $line_no ) ); }
			$records++;
			if ( $records > max( 1, absint( $max_records ) ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_limit', __( 'The Certloom domain backup exceeds the configured record safety limit.', 'certloom' ) ); }
			$type = sanitize_key( (string) ( $item['entity'] ?? '' ) );
			if ( isset( $stream_counts[ $type ] ) ) { $stream_counts[ $type ]++; }
			$hash = $this->format->next_hash( $hash, $line, $format_version );
			$row = $this->format->normalize_entity_row( $format_version, $type, is_array( $item['data'] ?? null ) ? $item['data'] : array() );
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
					$program_issuers[ $id ] = $issuer_id;
					$code = trim( (string) ( $row['code'] ?? '' ) ); if ( '' !== $code ) { if ( isset( $program_codes[ $code ] ) ) { $this->error( $errors, $line_no, 'program_code_duplicate', 'Duplicate program code inside backup.' ); } else { $program_codes[ $code ] = $id; } }
					break;
				case 'recipients':
					if ( '' === trim( (string) ( $row['display_name'] ?? '' ) ) ) { $this->error( $errors, $line_no, 'recipient_invalid', 'Recipient display name is required.' ); }
					$wp_user_id = absint( $row['wp_user_id'] ?? 0 ); if ( $wp_user_id ) { if ( isset( $recipient_wp_users[ $wp_user_id ] ) ) { $this->error( $errors, $line_no, 'recipient_wp_user_duplicate', 'Multiple recipients refer to the same WordPress user.' ); } else { $recipient_wp_users[ $wp_user_id ] = $id; } }
					break;
				case 'signers':
					if ( '' === trim( (string) ( $row['display_name'] ?? '' ) ) ) { $this->error( $errors, $line_no, 'signer_invalid', 'Signer display name is required.' ); }
					$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) ); if ( '' === $slug ) { $this->error( $errors, $line_no, 'signer_slug_invalid', 'Signer key is required.' ); } elseif ( isset( $signer_slugs[ $slug ] ) ) { $this->error( $errors, $line_no, 'signer_slug_duplicate', 'Duplicate signer key inside backup.' ); } else { $signer_slugs[ $slug ] = $id; }
					break;
				case 'batches':
					if ( '' === trim( (string) ( $row['name'] ?? '' ) ) ) { $this->error( $errors, $line_no, 'batch_invalid', 'Batch name is required.' ); }
					$program_id = absint( $row['program_id'] ?? 0 ); if ( ! isset( $ids['programs'][ $program_id ] ) ) { $this->error( $errors, $line_no, 'batch_program_missing', 'Batch refers to a missing program.' ); }
					$instructor_signer_id = absint( $row['instructor_signer_id'] ?? 0 ); if ( $instructor_signer_id && ! isset( $ids['signers'][ $instructor_signer_id ] ) ) { $this->error( $errors, $line_no, 'batch_signer_missing', 'Batch refers to a missing instructor signer.' ); }
					$code = trim( (string) ( $row['code'] ?? '' ) ); if ( '' === $code ) { $this->error( $errors, $line_no, 'batch_code_invalid', 'Batch code is required.' ); } elseif ( isset( $batch_codes[ $code ] ) ) { $this->error( $errors, $line_no, 'batch_code_duplicate', 'Duplicate batch code inside backup.' ); } else { $batch_codes[ $code ] = $id; }
					break;
				case 'batch_recipients':
					$batch_id = absint( $row['batch_id'] ?? 0 ); $recipient_id = absint( $row['recipient_id'] ?? 0 );
					if ( ! isset( $ids['batches'][ $batch_id ] ) ) { $this->error( $errors, $line_no, 'batch_member_batch_missing', 'Batch membership refers to a missing batch.' ); }
					if ( ! isset( $ids['recipients'][ $recipient_id ] ) ) { $this->error( $errors, $line_no, 'batch_member_recipient_missing', 'Batch membership refers to a missing recipient.' ); }
					$member_key = $batch_id . ':' . $recipient_id; if ( isset( $batch_members[ $member_key ] ) ) { $this->error( $errors, $line_no, 'batch_member_duplicate', 'Duplicate batch membership inside backup.' ); } else { $batch_members[ $member_key ] = true; }
					break;
				case 'signer_assignments':
					$signer_id = absint( $row['signer_id'] ?? 0 ); $scope_type = sanitize_key( (string) ( $row['scope_type'] ?? '' ) ); $scope_id = absint( $row['scope_id'] ?? 0 ); $role_type = sanitize_key( (string) ( $row['role_type'] ?? '' ) );
					if ( ! isset( $ids['signers'][ $signer_id ] ) ) { $this->error( $errors, $line_no, 'signer_assignment_signer_missing', 'Signer assignment refers to a missing signer.' ); }
					$scope_entity = array( 'issuer' => 'issuers', 'program' => 'programs', 'batch' => 'batches' );
					if ( 'global' === $scope_type ) { if ( 0 !== $scope_id ) { $this->error( $errors, $line_no, 'signer_assignment_global_scope_invalid', 'Global signer assignments must use scope ID zero.' ); } }
					elseif ( ! isset( $scope_entity[ $scope_type ] ) || ! isset( $ids[ $scope_entity[ $scope_type ] ][ $scope_id ] ) ) { $this->error( $errors, $line_no, 'signer_assignment_scope_missing', 'Signer assignment refers to a missing scope.' ); }
					$assignment_key = $signer_id . ':' . $scope_type . ':' . $scope_id . ':' . $role_type; if ( isset( $signer_assignments[ $assignment_key ] ) ) { $this->error( $errors, $line_no, 'signer_assignment_duplicate', 'Duplicate signer assignment inside backup.' ); } else { $signer_assignments[ $assignment_key ] = true; }
					break;
				case 'templates':
					$template_key = (string) ( $row['machine_key'] ?? '' );
					if ( isset( $template_keys[ $template_key ] ) ) { $this->error( $errors, $line_no, 'template_key_duplicate', 'Duplicate template machine key inside backup.' ); } else { $template_keys[ $template_key ] = $id; }
					$current_revision = absint( $row['current_revision_id'] ?? 0 ); if ( $current_revision ) { $template_current[ $id ] = array( 'revision_id' => $current_revision, 'line' => $line_no ); }
					$published_revision = absint( $row['published_revision_id'] ?? 0 ); if ( $published_revision ) { $template_published[ $id ] = array( 'revision_id' => $published_revision, 'line' => $line_no ); }
					break;
				case 'template_revisions':
					$template_id = absint( $row['template_id'] ?? 0 ); $revision_number = absint( $row['revision_number'] ?? 0 );
					if ( ! isset( $ids['templates'][ $template_id ] ) ) { $this->error( $errors, $line_no, 'template_revision_template_missing', 'Template revision refers to a missing template.' ); }
					$revision_key = $template_id . ':' . $revision_number; if ( isset( $template_revision_keys[ $revision_key ] ) ) { $this->error( $errors, $line_no, 'template_revision_duplicate', 'Duplicate revision number within one template.' ); } else { $template_revision_keys[ $revision_key ] = $id; }
					$template_revision_owner[ $id ] = $template_id;
					$parent_revision_id = absint( $row['parent_revision_id'] ?? 0 ); if ( $parent_revision_id ) { $template_revision_parent[] = array( 'template_id' => $template_id, 'revision_id' => $id, 'parent_revision_id' => $parent_revision_id, 'line' => $line_no ); }
					break;
				case 'template_assignments':
					$template_id = absint( $row['template_id'] ?? 0 ); $scope_type = sanitize_key( (string) ( $row['scope_type'] ?? '' ) ); $scope_id = absint( $row['scope_id'] ?? 0 );
					if ( ! isset( $ids['templates'][ $template_id ] ) ) { $this->error( $errors, $line_no, 'template_assignment_template_missing', 'Template assignment refers to a missing template.' ); }
					$scope_entity = array( 'issuer' => 'issuers', 'program' => 'programs', 'batch' => 'batches' );
					if ( 'global' === $scope_type ) { if ( 0 !== $scope_id ) { $this->error( $errors, $line_no, 'template_assignment_global_scope_invalid', 'Global template assignments must use scope ID zero.' ); } }
					elseif ( ! isset( $scope_entity[ $scope_type ] ) || ! isset( $ids[ $scope_entity[ $scope_type ] ][ $scope_id ] ) ) { $this->error( $errors, $line_no, 'template_assignment_scope_missing', 'Template assignment refers to a missing scope.' ); }
					$assignment_key = $scope_type . ':' . $scope_id; if ( isset( $template_assignment_keys[ $assignment_key ] ) ) { $this->error( $errors, $line_no, 'template_assignment_duplicate', 'Duplicate template assignment scope inside backup.' ); } else { $template_assignment_keys[ $assignment_key ] = $id; }
					break;
				case 'credential_series':
					$public_id = (string) ( $row['public_id'] ?? '' );
					if ( ! $this->public_ids->is_valid( $public_id ) ) { $this->error( $errors, $line_no, 'public_id_invalid', 'Credential Series contains an invalid public credential ID.' ); }
					elseif ( isset( $public_ids[ $public_id ] ) ) { $this->error( $errors, $line_no, 'public_id_duplicate', 'Duplicate public credential ID inside backup.' ); } else { $public_ids[ $public_id ] = $id; }
					$recipient_id = absint( $row['recipient_id'] ?? 0 ); if ( ! isset( $ids['recipients'][ $recipient_id ] ) ) { $this->error( $errors, $line_no, 'series_recipient_missing', 'Credential Series refers to a missing recipient.' ); }
					$program_id = absint( $row['program_id'] ?? 0 ); if ( $program_id && ! isset( $ids['programs'][ $program_id ] ) ) { $this->error( $errors, $line_no, 'series_program_missing', 'Credential Series refers to a missing program.' ); }
					$batch_id = absint( $row['batch_id'] ?? 0 ); if ( $batch_id && ! isset( $ids['batches'][ $batch_id ] ) ) { $this->error( $errors, $line_no, 'series_batch_missing', 'Credential Series refers to a missing batch.' ); }
					$issuer_id = absint( $row['issuer_id'] ?? 0 ); if ( $issuer_id && ! isset( $ids['issuers'][ $issuer_id ] ) ) { $this->error( $errors, $line_no, 'series_issuer_missing', 'Credential Series refers to a missing issuer.' ); }
					if ( $program_id && ! empty( $program_issuers[ $program_id ] ) && $issuer_id !== absint( $program_issuers[ $program_id ] ) ) { $this->error( $errors, $line_no, 'series_program_issuer_mismatch', 'Credential Series issuer disagrees with its Program issuer.' ); }
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
		if ( ! $after_integrity || ! is_array( $manifest ) ) {
			return new \WP_Error( 'certloom_domain_backup_integrity_failed', __( 'The Certloom domain backup ended before a valid integrity record was confirmed during restore review.', 'certloom' ) );
		}
		foreach ( $order as $entity ) {
			if ( absint( $manifest['entities'][ $entity ]['count'] ?? -1 ) !== absint( $stream_counts[ $entity ] ?? 0 ) ) {
				return new \WP_Error( 'certloom_domain_backup_count_mismatch', __( 'The Certloom domain backup record counts do not match its manifest during restore review.', 'certloom' ) );
			}
		}
		if ( absint( $integrity['records'] ?? -1 ) !== $records || empty( $integrity['chain_sha256'] ) || ! hash_equals( (string) $integrity['chain_sha256'], $hash ) ) {
			return new \WP_Error( 'certloom_domain_restore_preflight_changed', __( 'The Certloom backup changed between integrity validation and semantic restore review. Run the restore review again.', 'certloom' ) );
		}

		if ( count( $errors ) < self::MAX_ERRORS ) {
			foreach ( $template_current as $template_id => $ref ) {
				$revision_id = absint( $ref['revision_id'] );
				if ( ! isset( $template_revision_owner[ $revision_id ] ) || (int) $template_revision_owner[ $revision_id ] !== (int) $template_id ) { $this->error( $errors, $ref['line'], 'template_current_revision_invalid', 'Template current revision is missing or belongs to another template.' ); }
			}
			foreach ( $template_published as $template_id => $ref ) {
				$revision_id = absint( $ref['revision_id'] );
				if ( ! isset( $template_revision_owner[ $revision_id ] ) || (int) $template_revision_owner[ $revision_id ] !== (int) $template_id ) { $this->error( $errors, $ref['line'], 'template_published_revision_invalid', 'Template published revision is missing or belongs to another template.' ); }
			}
			foreach ( $template_revision_parent as $ref ) {
				if ( ! isset( $template_revision_owner[ $ref['parent_revision_id'] ] ) || (int) $template_revision_owner[ $ref['parent_revision_id'] ] !== (int) $ref['template_id'] || (int) $ref['parent_revision_id'] >= (int) $ref['revision_id'] ) { $this->error( $errors, $ref['line'], 'template_parent_revision_invalid', 'Template revision parent link is missing, not older than the child, or belongs to another template.' ); }
			}
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
			return new \WP_Error( 'certloom_domain_restore_semantic_invalid', sprintf( /* translators: %d: errors. */ __( 'The Certloom backup passed file integrity checks but contains %d semantic relationship error(s).', 'certloom' ), count( $errors ) ), $errors );
		}
		return array( 'manifest' => $manifest, 'records' => $records, 'counts' => $counts, 'configuration' => $config, 'chain_sha256' => $hash, 'ready' => true );
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
