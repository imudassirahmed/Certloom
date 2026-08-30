<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Premium, non-blocking first-run setup and WIZ-01 workflow state. */
class Ecapla_CertVerify_Onboarding {
	const OPTION_KEY = 'certverify_onboarding';
	const VERSION    = 3;

	/** @return array<int,string> */
	public static function wizard_steps() {
		return array( 'purpose', 'organization', 'program', 'design', 'delivery', 'backup', 'review' );
	}

	/** @return array<string,string> */
	public static function wizard_step_labels() {
		return array(
			'purpose'      => __( 'Purpose', 'certloom' ),
			'organization' => __( 'Organization', 'certloom' ),
			'program'      => __( 'First Program', 'certloom' ),
			'design'       => __( 'Design', 'certloom' ),
			'delivery'     => __( 'Delivery & Verification', 'certloom' ),
			'backup'       => __( 'Backup & Finish', 'certloom' ),
			'review'       => __( 'Review configuration', 'certloom' ),
		);
	}

	public static function defaults() {
		return array(
			'organization_name'      => '',
			'verification_page_id'   => 0,
			'appearance_reviewed'    => false,
			'verification_tested'    => false,
			'completed_at'           => '', // Legacy checklist completion; retained for compatibility.
			'wizard_step'            => 'purpose',
			'wizard_completed_steps' => array(),
			'wizard_skipped_steps'   => array(),
			'wizard_started_at'      => '',
			'wizard_last_seen_at'    => '',
			'wizard_completed_at'    => '',
			'wizard_rerun_count'     => 0,
			'wizard_draft'           => array(), // Workflow intent only; never runtime configuration authority.
			'version'                => self::VERSION,
		);
	}

	public static function get_state() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$state = wp_parse_args( $saved, self::defaults() );
		$state['organization_name']       = self::limit_text( sanitize_text_field( (string) $state['organization_name'] ), 200 );
		$state['verification_page_id']    = absint( $state['verification_page_id'] );
		$state['appearance_reviewed']     = ! empty( $state['appearance_reviewed'] );
		$state['verification_tested']     = ! empty( $state['verification_tested'] );
		$state['completed_at']            = sanitize_text_field( (string) $state['completed_at'] );
		$state['wizard_step']             = self::normalize_step( $state['wizard_step'] );
		$state['wizard_completed_steps']  = self::normalize_step_list( $state['wizard_completed_steps'] );
		$state['wizard_skipped_steps']    = self::normalize_step_list( $state['wizard_skipped_steps'] );
		$state['wizard_started_at']       = sanitize_text_field( (string) $state['wizard_started_at'] );
		$state['wizard_last_seen_at']     = sanitize_text_field( (string) $state['wizard_last_seen_at'] );
		$state['wizard_completed_at']     = sanitize_text_field( (string) $state['wizard_completed_at'] );
		$state['wizard_rerun_count']      = min( 999, absint( $state['wizard_rerun_count'] ) );
		$state['wizard_draft']            = self::sanitize_wizard_draft( is_array( $state['wizard_draft'] ) ? $state['wizard_draft'] : array() );
		$state['version']                 = self::VERSION;
		return $state;
	}

	/** @return true|WP_Error */
	public static function save_profile( $organization_name, $verification_page_id, $appearance_reviewed = null, $verification_tested = null ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$name = self::limit_text( sanitize_text_field( (string) $organization_name ), 200 );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_org_required', __( 'Enter the organization name shown in your setup profile.', 'certloom' ) );
		}
		$page_id = absint( $verification_page_id );
		$page = $page_id ? get_post( $page_id ) : null;
		if ( $page_id && ( ! $page || 'page' !== get_post_type( $page_id ) ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_page_invalid', __( 'Choose a valid WordPress page for certificate verification.', 'certloom' ) );
		}
		$state                          = self::get_state();
		$state['organization_name']     = $name;
		$state['verification_page_id']  = $page_id;
		if ( null !== $appearance_reviewed ) {
			$state['appearance_reviewed'] = (bool) $appearance_reviewed;
		}
		if ( null !== $verification_tested ) {
			$state['verification_tested'] = (bool) $verification_tested;
		}
		return self::persist( $state );
	}

	/**
	 * Saves only resumable Wizard intent. Product code must never read this
	 * draft as runtime configuration; canonical settings are applied explicitly
	 * at Review configuration.
	 *
	 * @return true|WP_Error
	 */
	public static function save_wizard_step( $step, array $draft_patch, $next_step = '', $skipped = false ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$step = sanitize_key( (string) $step );
		if ( ! in_array( $step, self::wizard_steps(), true ) ) {
			return new WP_Error( 'certloom_setup_step_invalid', __( 'Certloom could not identify that setup step.', 'certloom' ) );
		}
		$next_step = sanitize_key( (string) $next_step );
		if ( '' !== $next_step && ! in_array( $next_step, self::wizard_steps(), true ) ) {
			return new WP_Error( 'certloom_setup_next_step_invalid', __( 'Certloom could not identify the next setup step.', 'certloom' ) );
		}
		$state = self::get_state();
		$merged = array_merge( $state['wizard_draft'], $draft_patch );
		$draft = self::sanitize_wizard_draft( $merged );
		if ( 'purpose' === $step && ! $skipped ) {
			$purpose = isset( $draft['purpose'] ) ? $draft['purpose'] : '';
			$subcategory = isset( $draft['subcategory'] ) ? $draft['subcategory'] : '';
			$preset = ( new \Certloom\Application\Setup\PurposePresetEngine() )->recommend( $purpose, $subcategory );
			if ( is_wp_error( $preset ) ) { return $preset; }
			// Seed only untouched review fields. A later purpose change must never
			// overwrite an administrator's explicit Wizard choices.
			if ( ! array_key_exists( 'numbering_mode', $state['wizard_draft'] ) && isset( $preset['numbering_mode'] ) ) {
				$draft['numbering_mode'] = sanitize_key( (string) $preset['numbering_mode'] );
			}
			if ( ! array_key_exists( 'backup_schedule', $state['wizard_draft'] ) && isset( $preset['backup_schedule'] ) ) {
				$draft['backup_schedule'] = sanitize_key( (string) $preset['backup_schedule'] );
			}
			$draft = self::sanitize_wizard_draft( $draft );
		}
		$state['wizard_draft'] = $draft;
		$now = current_time( 'mysql' );
		if ( '' === $state['wizard_started_at'] ) { $state['wizard_started_at'] = $now; }
		$state['wizard_last_seen_at'] = $now;
		if ( 'review' !== $step ) {
			$state['wizard_completed_steps'][] = $step;
			$state['wizard_completed_steps'] = self::normalize_step_list( $state['wizard_completed_steps'] );
			if ( $skipped ) {
				$state['wizard_skipped_steps'][] = $step;
			} else {
				$state['wizard_skipped_steps'] = array_values( array_diff( $state['wizard_skipped_steps'], array( $step ) ) );
			}
			$state['wizard_skipped_steps'] = self::normalize_step_list( $state['wizard_skipped_steps'] );
		}
		$state['wizard_step'] = '' !== $next_step ? self::normalize_step( $next_step ) : self::next_step( $step );
		return self::persist( $state );
	}

	/** @return true|WP_Error */
	public static function restart_wizard() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$state = self::get_state();
		$state['wizard_step'] = 'purpose';
		$state['wizard_completed_steps'] = array();
		$state['wizard_skipped_steps'] = array();
		$state['wizard_started_at'] = current_time( 'mysql' );
		$state['wizard_last_seen_at'] = $state['wizard_started_at'];
		$state['wizard_completed_at'] = '';
		$state['wizard_rerun_count'] = min( 999, absint( $state['wizard_rerun_count'] ) + 1 );
		$state['wizard_draft'] = array();
		return self::persist( $state );
	}

	/** @return true|WP_Error */
	public static function complete_wizard() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$state = self::get_state();
		$state['wizard_step'] = 'review';
		$state['wizard_last_seen_at'] = current_time( 'mysql' );
		$state['wizard_completed_at'] = $state['wizard_last_seen_at'];
		return self::persist( $state );
	}

	/** Exact sanitized Wizard/onboarding snapshot used only for transactional compensation. */
	public static function snapshot() { return self::get_state(); }

	/** Restores an exact prior sanitized snapshot after a multi-authority setup apply fails. @return true|WP_Error */
	public static function restore_snapshot( array $snapshot ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		return self::persist( $snapshot );
	}

	/** @return int|WP_Error */
	public static function create_verification_page() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$state = self::get_state();
		if ( $state['verification_page_id'] && get_post( $state['verification_page_id'] ) ) { return $state['verification_page_id']; }
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Verify a Certificate', 'certloom' ),
				'post_content' => '[certloom]',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) { return $page_id; }
		$state['verification_page_id'] = absint( $page_id );
		$saved = self::persist( $state );
		if ( is_wp_error( $saved ) ) {
			$removed = wp_delete_post( absint( $page_id ), true );
			if ( ! $removed ) {
				return new WP_Error( 'ecapla_certverify_onboarding_page_cleanup_failed', __( 'The verification page was created, but Certloom could not save its setup link or remove the unlinked page. Review Pages before continuing.', 'certloom' ) );
			}
			return $saved;
		}
		return absint( $page_id );
	}

	/** @return true|WP_Error */
	public static function complete() {
		$state = self::get_state();
		if ( '' === trim( $state['organization_name'] ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_incomplete', __( 'Add your organization name before completing setup.', 'certloom' ) );
		}
		if ( ! $state['verification_page_id'] || ! get_post( $state['verification_page_id'] ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_incomplete', __( 'Choose or create a verification page before completing setup.', 'certloom' ) );
		}
		$state['completed_at'] = current_time( 'mysql' );
		return self::persist( $state );
	}

	public static function is_complete() { return '' !== (string) self::get_state()['completed_at']; }
	public static function is_wizard_complete() { return '' !== (string) self::get_state()['wizard_completed_at']; }

	/** @return array|WP_Error */
	public static function get_summary() {
		$state = self::get_state();
		$count = Ecapla_CertVerify_Database::get_registry_count();
		if ( is_wp_error( $count ) ) { return $count; }
		$fields = Ecapla_CertVerify_Fields::get_manageable_fields();
		$page   = $state['verification_page_id'] ? get_post( $state['verification_page_id'] ) : null;
		$sample_cert_number = '';
		if ( absint( $count ) > 0 ) {
			$sample = Ecapla_CertVerify_Database::get_list( array( 'per_page' => 1, 'page' => 1 ) );
			if ( is_wp_error( $sample ) ) { return $sample; }
			if ( ! empty( $sample['items'][0]['cert_number'] ) ) { $sample_cert_number = (string) $sample['items'][0]['cert_number']; }
		}
		$progress_flags = array(
			'' !== trim( $state['organization_name'] ),
			count( $fields ) > 0,
			(bool) $page,
			absint( $count ) > 0,
			! empty( $state['verification_tested'] ),
			! empty( $state['appearance_reviewed'] ),
		);
		return array(
			'state'                => $state,
			'organization_ready'   => $progress_flags[0],
			'fields_ready'         => $progress_flags[1],
			'page_ready'           => $progress_flags[2],
			'registry_ready'       => $progress_flags[3],
			'verification_tested'  => $progress_flags[4],
			'appearance_ready'     => $progress_flags[5],
			'completed'            => '' !== (string) $state['completed_at'],
			'wizard_completed'     => '' !== (string) $state['wizard_completed_at'],
			'certificate_count'    => absint( $count ),
			'field_count'          => count( $fields ),
			'sample_cert_number'   => $sample_cert_number,
			'progress_completed'   => count( array_filter( $progress_flags ) ),
			'progress_total'       => count( $progress_flags ),
		);
	}

	/** @return true|WP_Error */
	private static function persist( $state ) {
		$state = wp_parse_args( is_array( $state ) ? $state : array(), self::defaults() );
		$state['version'] = self::VERSION;
		$state['wizard_step'] = self::normalize_step( $state['wizard_step'] );
		$state['wizard_completed_steps'] = self::normalize_step_list( $state['wizard_completed_steps'] );
		$state['wizard_skipped_steps'] = self::normalize_step_list( $state['wizard_skipped_steps'] );
		$state['wizard_draft'] = self::sanitize_wizard_draft( is_array( $state['wizard_draft'] ) ? $state['wizard_draft'] : array() );
		$stored = get_option( self::OPTION_KEY, array() );
		if ( $state === $stored ) { return true; }
		$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$saved = update_option( self::OPTION_KEY, $state, false );
		if ( ! $saved && $state !== get_option( self::OPTION_KEY, array() ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_save_failed', __( 'Certloom could not save the setup progress.', 'certloom' ) );
		}
		return true;
	}

	private static function normalize_step( $step ) {
		$step = sanitize_key( (string) $step );
		return in_array( $step, self::wizard_steps(), true ) ? $step : 'purpose';
	}

	/** @return array<int,string> */
	private static function normalize_step_list( $steps ) {
		$steps = is_array( $steps ) ? $steps : array();
		$allowed = self::wizard_steps();
		$out = array();
		foreach ( $steps as $step ) {
			$step = sanitize_key( (string) $step );
			if ( in_array( $step, $allowed, true ) && ! in_array( $step, $out, true ) ) { $out[] = $step; }
		}
		return $out;
	}

	private static function next_step( $step ) {
		$steps = self::wizard_steps();
		$index = array_search( $step, $steps, true );
		return false !== $index && isset( $steps[ $index + 1 ] ) ? $steps[ $index + 1 ] : 'review';
	}

	/** @return array<string,mixed> */
	private static function sanitize_wizard_draft( array $draft ) {
		$out = array();
		$text_limits = array(
			'program_title' => 191,
			'scheme_reference' => 120,
			'design_note' => 240,
		);
		foreach ( $text_limits as $key => $limit ) {
			if ( array_key_exists( $key, $draft ) ) { $out[ $key ] = self::limit_text( sanitize_text_field( (string) $draft[ $key ] ), $limit ); }
		}
		if ( array_key_exists( 'organization_name', $draft ) ) { $out['organization_name'] = self::limit_text( sanitize_text_field( (string) $draft['organization_name'] ), 200 ); }
		if ( array_key_exists( 'verification_page_id', $draft ) ) { $out['verification_page_id'] = absint( $draft['verification_page_id'] ); }
		if ( array_key_exists( 'purpose', $draft ) ) { $out['purpose'] = sanitize_key( (string) $draft['purpose'] ); }
		if ( array_key_exists( 'subcategory', $draft ) ) { $out['subcategory'] = sanitize_key( (string) $draft['subcategory'] ); }
		if ( array_key_exists( 'design_template_id', $draft ) ) { $out['design_template_id'] = absint( $draft['design_template_id'] ); }
		if ( array_key_exists( 'numbering_mode', $draft ) ) {
			$value = sanitize_key( (string) $draft['numbering_mode'] );
			$out['numbering_mode'] = in_array( $value, array( 'manual', 'sequential', 'random', 'uuid' ), true ) ? $value : 'manual';
		}
		if ( array_key_exists( 'numbering_prefix', $draft ) ) { $out['numbering_prefix'] = self::limit_text( sanitize_text_field( (string) $draft['numbering_prefix'] ), 32 ); }
		if ( array_key_exists( 'numbering_suffix', $draft ) ) { $out['numbering_suffix'] = self::limit_text( sanitize_text_field( (string) $draft['numbering_suffix'] ), 32 ); }
		if ( array_key_exists( 'numbering_start', $draft ) ) { $out['numbering_start'] = min( 999999999999, max( 1, absint( $draft['numbering_start'] ) ) ); }
		if ( array_key_exists( 'numbering_padding', $draft ) ) { $out['numbering_padding'] = min( 12, max( 1, absint( $draft['numbering_padding'] ) ) ); }
		if ( array_key_exists( 'backup_schedule', $draft ) ) {
			$value = sanitize_key( (string) $draft['backup_schedule'] );
			$out['backup_schedule'] = in_array( $value, array( 'off', 'daily', 'weekly' ), true ) ? $value : 'off';
		}
		if ( array_key_exists( 'backup_retention', $draft ) ) { $out['backup_retention'] = min( 60, max( 1, absint( $draft['backup_retention'] ) ) ); }
		if ( array_key_exists( 'google_drive_retention', $draft ) ) { $out['google_drive_retention'] = min( 60, max( 1, absint( $draft['google_drive_retention'] ) ) ); }
		foreach ( array( 'verification_qr_enabled', 'verification_barcode_enabled', 'appearance_reviewed', 'verification_tested', 'google_drive_enabled' ) as $key ) {
			if ( array_key_exists( $key, $draft ) ) { $out[ $key ] = ! empty( $draft[ $key ] ); }
		}
		return $out;
	}

	private static function limit_text( $value, $limit ) { return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit ); }
}
