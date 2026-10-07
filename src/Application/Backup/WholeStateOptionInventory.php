<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit durable option ownership. This is not an import allowlist or execution authority. */
final class WholeStateOptionInventory {
	const VERSION = 1;
	const MAX_OPTION_BYTES = 2097152;
	const MAX_TOTAL_OPTION_BYTES = 4194304;

	/** Policies come from local code, never from an archive's claimed metadata. */
	public static function definitions() {
		$rows = array(
			'certverify_fields' => array( 'domain', 'field_catalog_review' ),
			'certverify_retired_fields' => array( 'domain', 'field_catalog_review' ),
			'certverify_settings' => array( 'domain', 'settings_review' ),
			'certverify_number_sequence_next' => array( 'legacy', 'numbering_reconcile' ),
			'certverify_onboarding' => array( 'configuration', 'onboarding_review' ),
			'certloom_global_scoped_fields_v1' => array( 'configuration', 'field_policy_review' ),
			'certloom_tutor_lms_mappings' => array( 'configuration', 'source_review' ),
			'certloom_tutor_pending_mappings_v1' => array( 'configuration', 'source_review' ),
			'certloom_woocommerce_mappings' => array( 'configuration', 'source_review' ),
			'certloom_woocommerce_pending_mappings_v1' => array( 'configuration', 'source_review' ),
			'certloom_learndash_mappings' => array( 'configuration', 'source_review' ),
			'certloom_coursera_mappings_v1' => array( 'configuration', 'source_review' ),
			'certloom_coursera_xapi_credentials_v1' => array( 'configuration', 'connection_review' ),
			'certloom_credly_mappings_v1' => array( 'configuration', 'source_review' ),
			'certloom_credly_connection_v1' => array( 'configuration', 'connection_review' ),
			'certloom_webhook_sources_v1' => array( 'configuration', 'source_review' ),
			'certloom_webhook_sources_v2' => array( 'configuration', 'source_review' ),
			'certloom_email_design_assignments_v1' => array( 'configuration', 'object_review' ),
			'certloom_email_automation_rules_v1' => array( 'configuration', 'automation_hold' ),
			'certloom_backup_schedule' => array( 'configuration', 'execution_hold' ),
			'certloom_backup_retention' => array( 'configuration', 'retention_review' ),
			'certloom_safety_backup_retention' => array( 'configuration', 'retention_review' ),
			'certloom_google_drive_config' => array( 'configuration', 'connection_review' ),
			'certloom_google_drive_secrets' => array( 'configuration', 'connection_review' ),
			'certloom_google_drive_retention' => array( 'configuration', 'retention_review' ),
			'certloom_email_template_catalog_v1' => array( 'authoring', 'content_review' ),
			'certloom_email_template_issued_v1' => array( 'authoring', 'content_review' ),
			'certloom_email_automation_runs_v1' => array( 'deliveries', 'replay_evidence' ),
			'certloom_custom_font_library_v1' => array( 'references', 'asset_review' ),
			'certloom_local_backup_catalog' => array( 'references', 'availability_review' ),
			'certloom_remote_backup_catalog' => array( 'references', 'availability_review' ),
		);
		ksort( $rows, SORT_STRING );
		$out = array();
		foreach ( $rows as $key => $row ) { $out[ $key ] = array( 'component' => $row[0], 'reconciliation' => $row[1] ); }
		return $out;
	}

	public static function keys( $component = null ) {
		$definitions = self::definitions();
		if ( null === $component ) { return array_keys( $definitions ); }
		return array_keys( array_filter( $definitions, static function ( $row ) use ( $component ) { return $component === $row['component']; } ) );
	}
}
