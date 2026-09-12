<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Premium, non-blocking first-run setup and WIZ-01 workflow state. */
class Ecapla_CertVerify_Onboarding {
	const OPTION_KEY = 'certverify_onboarding';
	const VERSION    = 5;

	/** @return array<int,string> */
	public static function wizard_steps() {
		return array( 'welcome', 'profile', 'organization', 'fields', 'appearance', 'issue', 'finish' );
	}

	/** @return array<string,string> */
	public static function wizard_step_labels() {
		return array(
			'welcome'      => __( 'Welcome', 'certloom' ),
			'profile'      => __( 'Profile', 'certloom' ),
			'organization' => __( 'Organization', 'certloom' ),
			'fields'       => __( 'Credential fields', 'certloom' ),
			'appearance'   => __( 'Appearance', 'certloom' ),
			'issue'        => __( 'First credential', 'certloom' ),
			'finish'       => __( 'Finish', 'certloom' ),
		);
	}

	public static function defaults() {
		return array(
			'organization_name'      => '',
			'verification_page_id'   => 0,
			'appearance_reviewed'    => false,
			'verification_tested'    => false,
			'completed_at'           => '', // Legacy checklist completion; retained for compatibility.
			'wizard_step'            => 'welcome',
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
		$saved_version = absint( $saved['version'] ?? 0 );
		$state = wp_parse_args( $saved, self::defaults() );
		$state['organization_name']       = self::limit_text( sanitize_text_field( (string) $state['organization_name'] ), 200 );
		$state['verification_page_id']    = absint( $state['verification_page_id'] );
		$state['appearance_reviewed']     = ! empty( $state['appearance_reviewed'] );
		$state['verification_tested']     = ! empty( $state['verification_tested'] );
		$state['completed_at']            = sanitize_text_field( (string) $state['completed_at'] );
		$state['wizard_step']             = self::migrate_legacy_step( $state['wizard_step'] );
		$state['wizard_completed_steps']  = array_map( array( __CLASS__, 'migrate_legacy_step' ), is_array( $state['wizard_completed_steps'] ) ? $state['wizard_completed_steps'] : array() );
		$state['wizard_skipped_steps']    = array_map( array( __CLASS__, 'migrate_legacy_step' ), is_array( $state['wizard_skipped_steps'] ) ? $state['wizard_skipped_steps'] : array() );
		$state['wizard_draft']            = self::migrate_legacy_draft( is_array( $state['wizard_draft'] ) ? $state['wizard_draft'] : array() );
		$state['wizard_step']             = self::normalize_step( $state['wizard_step'] );
		$state['wizard_completed_steps']  = self::normalize_step_list( $state['wizard_completed_steps'] );
		$state['wizard_skipped_steps']    = self::normalize_step_list( $state['wizard_skipped_steps'] );
		$state['wizard_started_at']       = sanitize_text_field( (string) $state['wizard_started_at'] );
		$state['wizard_last_seen_at']     = sanitize_text_field( (string) $state['wizard_last_seen_at'] );
		$state['wizard_completed_at']     = sanitize_text_field( (string) $state['wizard_completed_at'] );
		$state['wizard_rerun_count']      = min( 999, absint( $state['wizard_rerun_count'] ) );
		$state['wizard_draft']            = self::sanitize_wizard_draft( $state['wizard_draft'] );
		if ( $saved_version > 0 && $saved_version < 4 ) {
			// Retired pre-v4 walkthrough completion did not represent the current
			// profile/appearance/first-credential Wizard contract.
			$state['wizard_completed_at'] = '';
		}
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
		$valid = self::validate_step_draft( $step, $draft, $skipped );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( 'profile' === $step && ! $skipped ) {
			$profile = isset( $draft['usage_profile'] ) ? $draft['usage_profile'] : '';
			$preset = ( new \Certloom\Application\Setup\PurposePresetEngine() )->recommend( $profile );
			if ( is_wp_error( $preset ) ) { return $preset; }
			// Seed recommended field keys only when the administrator has not already
			// edited the fields step. A later profile change never overwrites choices.
			if ( ! array_key_exists( 'selected_fields', $state['wizard_draft'] ) ) {
				$draft['selected_fields'] = array_values( array_map( static function ( $field ) { return sanitize_key( (string) ( $field['key'] ?? '' ) ); }, (array) ( $preset['recommended_fields'] ?? array() ) ) );
			}
			$draft = self::sanitize_wizard_draft( $draft );
		}
		$state['wizard_draft'] = $draft;
		$now = current_time( 'mysql' );
		if ( '' === $state['wizard_started_at'] ) { $state['wizard_started_at'] = $now; }
		$state['wizard_last_seen_at'] = $now;
		if ( 'finish' !== $step ) {
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
		if ( 'issue' === $step && $skipped && 'finish' === $state['wizard_step'] ) {
			$state['wizard_completed_at'] = $now;
		}
		return self::persist( $state );
	}

	/** Mutation-free validation for a Wizard step against the current resumable draft. @return true|WP_Error */
	public static function validate_wizard_draft( $step, array $draft_patch, $skipped = false ) {
		$step = sanitize_key( (string) $step );
		if ( ! in_array( $step, self::wizard_steps(), true ) ) {
			return new WP_Error( 'certloom_setup_step_invalid', __( 'Certloom could not identify that setup step.', 'certloom' ) );
		}
		$state = self::get_state();
		$draft = self::sanitize_wizard_draft( array_merge( $state['wizard_draft'], $draft_patch ) );
		return self::validate_step_draft( $step, $draft, (bool) $skipped );
	}

	/**
	 * Saves resumable Wizard draft values without completing or advancing a step.
	 * Used by Save & finish later on the first-credential screen so saving a
	 * draft can never accidentally issue a credential.
	 *
	 * @param string $step
	 * @param array<string,mixed> $draft_patch
	 * @return true|WP_Error
	 */
	public static function save_wizard_draft_only( $step, array $draft_patch ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$step = self::normalize_step( sanitize_key( (string) $step ) );
		$state = self::get_state();
		$state['wizard_draft'] = self::sanitize_wizard_draft( array_merge( $state['wizard_draft'], $draft_patch ) );
		$now = current_time( 'mysql' );
		if ( '' === $state['wizard_started_at'] ) { $state['wizard_started_at'] = $now; }
		$state['wizard_last_seen_at'] = $now;
		$state['wizard_step'] = $step;
		return self::persist( $state );
	}

	/** @return true|WP_Error */
	public static function restart_wizard() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$state = self::get_state();
		$state['wizard_step'] = 'welcome';
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
		$state['wizard_step'] = 'finish';
		$state['wizard_last_seen_at'] = current_time( 'mysql' );
		$state['wizard_completed_at'] = $state['wizard_last_seen_at'];
		return self::persist( $state );
	}

	/**
	 * Records the real credential issued from Setup and completes the Wizard.
	 * The issuance itself is committed by ManualIssuanceService before this
	 * resumable onboarding state is written. Retrying the same reviewed request
	 * is idempotent, so a failed onboarding write cannot manufacture a duplicate.
	 *
	 * @param array<string,mixed> $draft_patch
	 * @param array<string,mixed> $receipt
	 * @return true|WP_Error
	 */
	public static function record_first_credential( array $draft_patch, array $receipt ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$version_id = absint( $receipt['version_id'] ?? 0 );
		$series_id = absint( $receipt['series_id'] ?? 0 );
		$url = trim( (string) ( $receipt['verification_url'] ?? '' ) );
		if ( ! $version_id || ! $series_id || '' === $url ) {
			return new WP_Error( 'certloom_setup_first_credential_receipt_invalid', __( 'The first credential was issued, but Certloom could not record its Setup receipt safely.', 'certloom' ) );
		}

		$state = self::get_state();
		$draft = self::sanitize_wizard_draft( array_merge( $state['wizard_draft'], $draft_patch ) );
		$valid = self::validate_step_draft( 'issue', $draft, false );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$draft['first_credential_id'] = $version_id;
		$draft['first_credential_series_id'] = $series_id;
		$draft['first_credential_url'] = self::limit_text( sanitize_text_field( $url ), 500 );
		$state['wizard_draft'] = self::sanitize_wizard_draft( $draft );
		$state['wizard_completed_steps'][] = 'issue';
		$state['wizard_completed_steps'] = self::normalize_step_list( $state['wizard_completed_steps'] );
		$state['wizard_skipped_steps'] = array_values( array_diff( $state['wizard_skipped_steps'], array( 'issue' ) ) );
		$state['wizard_skipped_steps'] = self::normalize_step_list( $state['wizard_skipped_steps'] );
		$state['wizard_step'] = 'finish';
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
		$wizard_completed = '' !== (string) $state['wizard_completed_at'];
		return array(
			'state'                => $state,
			'organization_ready'   => $progress_flags[0],
			'fields_ready'         => $progress_flags[1],
			'page_ready'           => $progress_flags[2],
			'registry_ready'       => $progress_flags[3],
			'verification_tested'  => $progress_flags[4],
			'appearance_ready'     => $progress_flags[5],
			'completed'            => '' !== (string) $state['completed_at'],
			'wizard_completed'     => $wizard_completed,
			'certificate_count'    => absint( $count ),
			'field_count'          => count( $fields ),
			'sample_cert_number'   => $sample_cert_number,
			'progress_completed'   => $wizard_completed ? count( $progress_flags ) : count( array_filter( $progress_flags ) ),
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
		return in_array( $step, self::wizard_steps(), true ) ? $step : 'welcome';
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
		return false !== $index && isset( $steps[ $index + 1 ] ) ? $steps[ $index + 1 ] : 'finish';
	}

	/** @return true|WP_Error */
	private static function validate_step_draft( $step, array $draft, $skipped ) {
		if ( $skipped ) { return true; }
		switch ( $step ) {
			case 'profile':
				$preset = ( new \Certloom\Application\Setup\PurposePresetEngine() )->recommend( $draft['usage_profile'] ?? '' );
				return is_wp_error( $preset ) ? $preset : true;
			case 'organization':
				return '' === trim( (string) ( $draft['organization_name'] ?? '' ) ) ? new WP_Error( 'certloom_setup_organization_required', __( 'Enter your organization name to continue.', 'certloom' ) ) : true;
			case 'appearance':
				$mode = sanitize_key( (string) ( $draft['presentation_mode'] ?? 'details_only' ) );
				if ( 'image' === $mode && empty( $draft['certificate_image_id'] ) ) { return new WP_Error( 'certloom_setup_image_required', __( 'Choose a PNG or JPEG certificate image, or select another appearance.', 'certloom' ) ); }
				if ( 'design' === $mode && empty( $draft['design_template_id'] ) ) { return new WP_Error( 'certloom_setup_design_required', __( 'Choose a published Certloom design, or select another appearance.', 'certloom' ) ); }
				return true;
			case 'issue':
				if ( '' === trim( (string) ( $draft['first_recipient_name'] ?? '' ) ) ) { return new WP_Error( 'certloom_setup_recipient_required', __( 'Enter the recipient name to continue.', 'certloom' ) ); }
				if ( '' === trim( (string) ( $draft['credential_title'] ?? '' ) ) ) { return new WP_Error( 'certloom_setup_title_required', __( 'Enter the credential title to continue.', 'certloom' ) ); }
				if ( '' === trim( (string) ( $draft['issue_date'] ?? '' ) ) ) { return new WP_Error( 'certloom_setup_issue_date_required', __( 'Choose the issue date to continue.', 'certloom' ) ); }
				$email = trim( (string) ( $draft['first_recipient_email'] ?? '' ) );
				if ( '' !== $email && function_exists( 'is_email' ) && ! is_email( $email ) ) { return new WP_Error( 'certloom_setup_email_invalid', __( 'Enter a valid recipient email address or leave it blank.', 'certloom' ) ); }
				return true;
		}
		return true;
	}

	private static function migrate_legacy_step( $step ) {
		$step = sanitize_key( (string) $step );
		$map = array(
			'purpose' => 'profile',
			'program' => 'fields',
			'design' => 'appearance',
			'delivery' => 'issue',
			'backup' => 'finish',
			'review' => 'finish',
		);
		return isset( $map[ $step ] ) ? $map[ $step ] : $step;
	}

	/** @return array<string,mixed> */
	private static function migrate_legacy_draft( array $draft ) {
		if ( ! isset( $draft['usage_profile'] ) && isset( $draft['purpose'] ) ) {
			$draft['usage_profile'] = ( new \Certloom\Application\Setup\PurposePresetEngine() )->normalize_profile( $draft['purpose'] );
		}
		if ( ! isset( $draft['presentation_mode'] ) && ! empty( $draft['design_template_id'] ) ) {
			$draft['presentation_mode'] = 'design';
		}
		unset( $draft['purpose'], $draft['subcategory'], $draft['scheme_reference'], $draft['program_title'], $draft['design_note'] );
		return $draft;
	}

	/** @return array<string,mixed> */
	private static function sanitize_wizard_draft( array $draft ) {
		$out = array();
		if ( array_key_exists( 'usage_profile', $draft ) ) {
			$out['usage_profile'] = ( new \Certloom\Application\Setup\PurposePresetEngine() )->normalize_profile( $draft['usage_profile'] );
		}
		if ( array_key_exists( 'organization_name', $draft ) ) { $out['organization_name'] = self::limit_text( sanitize_text_field( (string) $draft['organization_name'] ), 200 ); }
		if ( array_key_exists( 'organization_logo_id', $draft ) ) { $out['organization_logo_id'] = absint( $draft['organization_logo_id'] ); }
		if ( array_key_exists( 'organization_website', $draft ) ) { $out['organization_website'] = self::limit_text( sanitize_text_field( (string) $draft['organization_website'] ), 240 ); }
		if ( array_key_exists( 'organization_contact', $draft ) ) { $out['organization_contact'] = self::limit_text( sanitize_text_field( (string) $draft['organization_contact'] ), 240 ); }

		if ( array_key_exists( 'selected_fields', $draft ) ) {
			$values = is_array( $draft['selected_fields'] ) ? $draft['selected_fields'] : array();
			$out['selected_fields'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $values ) ) ) );
		}
		if ( array_key_exists( 'custom_fields', $draft ) ) {
			$values = is_array( $draft['custom_fields'] ) ? $draft['custom_fields'] : array();
			$clean = array();
			foreach ( array_slice( $values, 0, 20 ) as $label ) {
				$label = self::limit_text( sanitize_text_field( (string) $label ), 100 );
				if ( '' !== $label && ! in_array( $label, $clean, true ) ) { $clean[] = $label; }
			}
			$out['custom_fields'] = $clean;
		}

		if ( array_key_exists( 'presentation_mode', $draft ) ) {
			$mode = sanitize_key( (string) $draft['presentation_mode'] );
			$out['presentation_mode'] = in_array( $mode, array( 'details_only', 'image', 'design' ), true ) ? $mode : 'details_only';
		}
		if ( array_key_exists( 'certificate_image_id', $draft ) ) { $out['certificate_image_id'] = absint( $draft['certificate_image_id'] ); }
		if ( array_key_exists( 'design_template_id', $draft ) ) { $out['design_template_id'] = absint( $draft['design_template_id'] ); }
		if ( isset( $out['presentation_mode'] ) ) {
			if ( 'image' === $out['presentation_mode'] ) { $out['design_template_id'] = 0; }
			elseif ( 'design' === $out['presentation_mode'] ) { $out['certificate_image_id'] = 0; }
			else { $out['certificate_image_id'] = 0; $out['design_template_id'] = 0; }
		}

		foreach ( array( 'first_recipient_name' => 200, 'first_recipient_email' => 254, 'credential_title' => 191, 'issue_date' => 20 ) as $key => $limit ) {
			if ( array_key_exists( $key, $draft ) ) { $out[ $key ] = self::limit_text( sanitize_text_field( (string) $draft[ $key ] ), $limit ); }
		}
		if ( array_key_exists( 'first_credential_id', $draft ) ) { $out['first_credential_id'] = absint( $draft['first_credential_id'] ); }
		if ( array_key_exists( 'first_credential_series_id', $draft ) ) { $out['first_credential_series_id'] = absint( $draft['first_credential_series_id'] ); }
		if ( array_key_exists( 'first_credential_url', $draft ) ) { $out['first_credential_url'] = self::limit_text( sanitize_text_field( (string) $draft['first_credential_url'] ), 500 ); }
		return $out;
	}

	private static function limit_text( $value, $limit ) { return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit ); }
}
