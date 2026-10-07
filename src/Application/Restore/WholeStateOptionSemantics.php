<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Complete local policy dispatch. Valid source semantics grant no target or execution authority. */
final class WholeStateOptionSemantics {
	public function validate_set( array $records ) {
		$records = ( new WholeStateOptionRecord() )->validate_set( $records, WholeStateOptionInventory::keys() ); if ( is_wp_error( $records ) ) { return $records; }
		$values = array();
		foreach ( $records as $key => $record ) { $value = ( new WholeStateOptionValue() )->decode( $record ); if ( is_wp_error( $value ) ) { return $value; } $values[$key] = $value; }
		$catalog = array();
		if ( $values['certverify_retired_fields']['exists'] ) { $retired = WholeStateOptionRows::guarded( static function () use ( $values ) { return \Ecapla_CertVerify_Fields::validate_recovery_value( $values['certverify_retired_fields']['value'], true ); } ); if ( is_wp_error( $retired ) ) { return $retired; } $catalog = $retired; }
		if ( $values['certverify_fields']['exists'] ) { $fields = WholeStateOptionRows::guarded( static function () use ( $values ) { return \Ecapla_CertVerify_Fields::validate_recovery_value( $values['certverify_fields']['value'] ); } ); if ( is_wp_error( $fields ) ) { return $fields; } foreach ( $fields as $field ) { $catalog[$field['key']] = $field; } }
		else { foreach ( \Ecapla_CertVerify_Fields::get_default_fields() as $field ) { $catalog[$field['key']] = $field; } }
		foreach ( $values as $key => $record ) { if ( ! $record['exists'] ) { continue; } $valid = WholeStateOptionRows::guarded( function () use ( $key, $record, $catalog ) { return $this->validate_value( $key, $record['value'], $catalog ); } ); if ( is_wp_error( $valid ) ) { return new \WP_Error( 'certloom_whole_option_preflight_failed', sprintf( __( 'The saved setting %s needs review before recovery. No data was imported.', 'certloom' ), $key ), array( 'option' => $key, 'reason' => $valid->get_error_code() ) ); } }
		return $values;
	}

	public function validate_value( $key, $value, array $catalog = array() ) {
		return WholeStateOptionRows::guarded( function () use ( $key, $value, $catalog ) { return $this->validate_value_unchecked( $key, $value, $catalog ); } );
	}
	private function validate_value_unchecked( $key, $value, array $catalog ) {
		$stores = array(
			'certloom_tutor_lms_mappings' => \Certloom\Infrastructure\Integration\TutorLmsMappingStore::class,
			'certloom_tutor_pending_mappings_v1' => \Certloom\Infrastructure\Integration\TutorPendingMappingRepository::class,
			'certloom_woocommerce_mappings' => \Certloom\Infrastructure\Integration\WooCommerceMappingStore::class,
			'certloom_woocommerce_pending_mappings_v1' => \Certloom\Infrastructure\Integration\WooCommercePendingMappingRepository::class,
			'certloom_learndash_mappings' => \Certloom\Infrastructure\Integration\LearnDashMappingStore::class,
			'certloom_coursera_mappings_v1' => \Certloom\Infrastructure\Integration\CourseraMappingStore::class,
			'certloom_credly_mappings_v1' => \Certloom\Infrastructure\Integration\CredlyMappingStore::class,
			'certloom_webhook_sources_v1' => \Certloom\Infrastructure\Integration\WebhookSourceRepository::class,
			'certloom_webhook_sources_v2' => \Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository::class,
			'certloom_email_design_assignments_v1' => \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository::class,
			'certloom_email_automation_rules_v1' => \Certloom\Infrastructure\Email\EmailAutomationRuleRepository::class,
			'certloom_email_template_catalog_v1' => \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository::class,
			'certloom_custom_font_library_v1' => \Certloom\Application\Rendering\CustomFontLibrary::class,
			'certloom_local_backup_catalog' => \Certloom\Infrastructure\Backup\LocalBackupStore::class,
		);
		if ( isset( $stores[$key] ) ) { return ( new $stores[$key]() )->validate_recovery_value( $value ); }
		switch ( $key ) {
			case 'certverify_fields': return \Ecapla_CertVerify_Fields::validate_recovery_value( $value );
			case 'certverify_retired_fields': return \Ecapla_CertVerify_Fields::validate_recovery_value( $value, true );
			case 'certverify_settings': return \Ecapla_CertVerify_Settings::validate_recovery_value( $value, $catalog );
			case 'certverify_onboarding': return \Ecapla_CertVerify_Onboarding::validate_recovery_value( $value );
			case 'certverify_number_sequence_next': return $this->integer( $value, 1, \Ecapla_CertVerify_Numbering::EXHAUSTED_STATE ) ? $value : $this->invalid();
			case 'certloom_backup_schedule': return is_string( $value ) && in_array( $value, array( 'off', 'daily', 'weekly', 'daily_whole', 'weekly_whole' ), true ) ? $value : $this->invalid();
			case 'certloom_backup_retention': case 'certloom_google_drive_retention': return $this->integer( $value, 1, 60 ) ? $value : $this->invalid();
			case 'certloom_safety_backup_retention': return $this->integer( $value, 3, 30 ) ? $value : $this->invalid();
			case 'certloom_global_scoped_fields_v1':
				$contract = new ScopedFieldContract(); $valid = $contract->validate_stored_envelope( $value, 'global' ); if ( is_wp_error( $valid ) ) { return $valid; }
				$next = $contract->normalize_scope( 'global', $valid['fields'], $catalog ); return is_wp_error( $next ) || ! WholeStateOptionRows::same( $value, $next ) ? $this->invalid() : $value;
			case 'certloom_coursera_xapi_credentials_v1':
				if ( ! $this->keys( $value, array( 'client_id', 'client_secret_sha256', 'token_sha256', 'token_expires_at', 'updated_at' ) ) || ! is_string( $value['client_id'] ) || ! preg_match( '/^clm_[a-f0-9]{24}$/D', $value['client_id'] ) || ! $this->sha( $value['client_secret_sha256'] ) || ( '' !== $value['token_sha256'] && ! $this->sha( $value['token_sha256'] ) ) || ! is_int( $value['token_expires_at'] ) || $value['token_expires_at'] < 0 || ! WholeStateOptionRows::date( $value['updated_at'], 'Y-m-d H:i:s' ) || ( '' === $value['token_sha256'] ) !== ( 0 === $value['token_expires_at'] ) ) { return $this->invalid(); } return $value;
			case 'certloom_credly_connection_v1':
				if ( ! $this->keys( $value, array( 'schema_version', 'organization_id', 'environment', 'sealed_token', 'token_sha256', 'updated_at' ) ) || 1 !== $value['schema_version'] || ! is_string( $value['organization_id'] ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value['organization_id'] ) || ! in_array( $value['environment'], array( 'production', 'sandbox' ), true ) || ! $this->sha( $value['token_sha256'] ) || ! WholeStateOptionRows::date( $value['updated_at'], 'Y-m-d H:i:s' ) || is_wp_error( WholeStateSealedConnectionFormat::validate( $value['sealed_token'] ) ) ) { return $this->invalid(); } return $value;
			case 'certloom_google_drive_secrets': return WholeStateSealedConnectionFormat::validate( $value );
			case 'certloom_google_drive_config': return $this->drive_config( $value );
			case 'certloom_email_template_issued_v1':
				if ( ! is_array( $value ) ) { return $this->invalid(); } $normalized = ( new \Certloom\Application\Email\EmailTemplateService() )->normalize_template( $value );
				if ( is_wp_error( $normalized ) || ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $value['html_body'] ?? null, $normalized['html_body'] ) ) { return $this->invalid(); }
				$normalized['html_body'] = $value['html_body']; return WholeStateOptionRows::same( $value, $normalized ) ? $value : $this->invalid();
			case 'certloom_email_automation_runs_v1': return $this->runs( $value );
			case 'certloom_remote_backup_catalog': return $this->remote_catalog( $value );
		}
		return $this->invalid();
	}

	public function validate_recommendations( array $records ) {
		$valid = ( new \Certloom\Application\Backup\WholeStateTemplateRecommendationInventory() )->validate_records( $records );
		if ( is_wp_error( $valid ) ) { return $valid; }
		foreach ( $records as $key => $record ) {
			$decoded = ( new WholeStateOptionValue() )->decode( $record ); if ( is_wp_error( $decoded ) || ! $decoded['exists'] || ! is_array( $decoded['value'] ) || count( $decoded['value'] ) > 10 ) { return $this->invalid(); }
			$normalized = ( new \Certloom\Infrastructure\Repository\TemplateRecommendationRepository() )->normalize( $decoded['value'] ); if ( ! WholeStateOptionRows::same( $decoded['value'], $normalized ) ) { return $this->invalid(); }
		}
		return $records;
	}
	private function runs( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 50 || array_values( $value ) !== $value ) { return $this->invalid(); }
		foreach ( $value as $row ) {
			if ( ! $this->keys( $row, array( 'rule_id', 'version_id', 'source_type', 'outcome', 'code', 'finished_at' ) ) || ! is_int( $row['version_id'] ) || $row['version_id'] < 0 || ! in_array( $row['outcome'], array( 'queued', 'skipped', 'failed' ), true ) || ! WholeStateOptionRows::date( $row['finished_at'], 'Y-m-d H:i:s' ) ) { return $this->invalid(); }
			foreach ( array( 'rule_id', 'source_type', 'outcome', 'code' ) as $key ) { if ( ! is_string( $row[$key] ) || strlen( $row[$key] ) > 191 || sanitize_key( $row[$key] ) !== $row[$key] ) { return $this->invalid(); } }
			if ( '' === $row['rule_id'] ) { return $this->invalid(); }
		}
		return $value;
	}
	private function drive_config( $value ) {
		$allowed = array( 'client_id', 'connection_mode', 'enabled', 'folder_id', 'connected_at', 'connected_ts' );
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), $allowed ) ) { return $this->invalid(); }
		foreach ( $value as $key => $item ) {
			if ( 'enabled' === $key ) { if ( ! in_array( $item, array( 0, 1 ), true ) ) { return $this->invalid(); } }
			elseif ( 'connected_ts' === $key ) { if ( ! is_int( $item ) || $item < 0 ) { return $this->invalid(); } }
			elseif ( ! is_string( $item ) || strlen( $item ) > 512 || sanitize_text_field( $item ) !== $item ) { return $this->invalid(); }
		}
		if ( isset( $value['connection_mode'] ) && ! in_array( $value['connection_mode'], array( 'relay', 'direct' ), true ) ) { return $this->invalid(); }
		if ( ! empty( $value['client_id'] ) && ! preg_match( '/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D', $value['client_id'] ) ) { return $this->invalid(); }
		if ( ! empty( $value['folder_id'] ) && \Certloom\Infrastructure\Backup\GoogleDriveOAuth::clean_file_id( $value['folder_id'] ) !== $value['folder_id'] ) { return $this->invalid(); }
		if ( ! empty( $value['connected_at'] ) && ! WholeStateOptionRows::iso_date( $value['connected_at'] ) ) { return $this->invalid(); }
		return $value;
	}
	private function remote_catalog( $value ) {
		$keys = array( 'id', 'provider', 'status', 'remote_file_id', 'local_backup_id', 'purpose', 'created_at', 'created_ts', 'updated_at', 'updated_ts', 'bytes', 'records', 'file_sha256', 'content_sha256', 'format_version', 'plugin_version', 'schema_version', 'source_authority', 'attempts', 'last_error', 'md5_checksum', 'recovered_local_backup_id' );
		if ( ! is_array( $value ) || count( $value ) > 100 || array_values( $value ) !== $value ) { return $this->invalid(); } $seen = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), $keys ) || array_diff( array_slice( $keys, 0, 20 ), array_keys( $row ) ) || 'google_drive' !== $row['provider'] || ! in_array( $row['status'], array( 'pending', 'failed', 'verified' ), true ) || ! in_array( $row['purpose'], array( 'manual', 'scheduled', 'safety' ), true ) || ! $this->sha( $row['file_sha256'] ) || ! $this->sha( $row['content_sha256'] ) || ! WholeStateOptionRows::iso_date( $row['created_at'] ) || ! WholeStateOptionRows::iso_date( $row['updated_at'] ) ) { return $this->invalid(); }
			foreach ( array( 'created_ts', 'updated_ts', 'bytes', 'records', 'format_version', 'attempts' ) as $key ) { if ( ! is_int( $row[$key] ) || $row[$key] < 0 ) { return $this->invalid(); } }
			foreach ( array( 'id', 'local_backup_id' ) as $key ) { if ( ! is_string( $row[$key] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row[$key] ) ) { return $this->invalid(); } }
			if ( isset( $seen[$row['id']] ) || ! is_string( $row['remote_file_id'] ) || '' === $row['remote_file_id'] || \Certloom\Infrastructure\Backup\GoogleDriveOAuth::clean_file_id( $row['remote_file_id'] ) !== $row['remote_file_id'] ) { return $this->invalid(); } $seen[$row['id']] = true;
			foreach ( array( 'plugin_version', 'schema_version', 'source_authority', 'last_error' ) as $key ) { if ( ! is_string( $row[$key] ) || strlen( $row[$key] ) > 2000 || sanitize_text_field( $row[$key] ) !== $row[$key] ) { return $this->invalid(); } }
			if ( isset( $row['recovered_local_backup_id'] ) && '' !== $row['recovered_local_backup_id'] && ( ! is_string( $row['recovered_local_backup_id'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row['recovered_local_backup_id'] ) ) ) { return $this->invalid(); }
			if ( isset( $row['md5_checksum'] ) && '' !== $row['md5_checksum'] && ( ! is_string( $row['md5_checksum'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row['md5_checksum'] ) ) ) { return $this->invalid(); }
		}
		return $value;
	}
	private function integer( $value, $minimum, $maximum ) { if ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,12}$/D', $value ) ) { $value = (int) $value; } return is_int( $value ) && $value >= $minimum && $value <= $maximum; }
	private function keys( $value, array $keys ) { return is_array( $value ) && count( $value ) === count( $keys ) && ! array_diff( array_keys( $value ), $keys ); }
	private function sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_option_semantics_invalid', __( 'A recovery setting has unsupported or invalid values. Review its source settings before continuing.', 'certloom' ) ); }
}
