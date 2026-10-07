<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certloom administrative screens and form handlers.
 */
class Ecapla_CertVerify_Admin {

	const DASHBOARD_PAGE    = 'certloom';
	const CERTIFICATES_PAGE = 'certloom-certificates';
	const ANALYTICS_PAGE    = 'certloom-analytics';

	public function __construct() {
		( new \Certloom\Infrastructure\Admin\AdminProductNavigation() )->register();
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'set_hidden_page_title' ), 1 );
		add_action( 'admin_init', array( $this, 'redirect_hidden_compatibility_routes' ), 2 );
		add_action( 'admin_post_certloom_set_ui_mode', array( $this, 'handle_ui_mode_switch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_delete' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_preview' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_commit' ) );
		add_action( 'admin_init', array( $this, 'handle_onboarding_save' ) );
		add_action( 'admin_init', array( $this, 'handle_onboarding_create_page' ) );
		add_action( 'admin_init', array( $this, 'handle_onboarding_complete' ) );
		add_action( 'admin_init', array( $this, 'handle_setup_wizard_action' ) );
		add_action( 'admin_init', array( $this, 'handle_registry_columns_save' ) );
		add_action( 'admin_init', array( $this, 'handle_fields_save' ) );
		add_action( 'admin_init', array( $this, 'handle_field_add' ) );
		add_action( 'admin_init', array( $this, 'handle_import_analyze' ) );
		add_action( 'admin_init', array( $this, 'handle_import_process' ) );
		add_action( 'admin_init', array( $this, 'handle_import_continue' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_database_proof' ) );
		add_action( 'admin_init', array( $this, 'handle_domain_acceptance_proof' ) );
		add_action( 'admin_init', array( $this, 'handle_legacy_migration_preflight_proof' ) );
		add_action( 'admin_init', array( $this, 'handle_staging_migration_rehearsal' ) );
		add_action( 'admin_init', array( $this, 'handle_staging_restore_rehearsal' ) );
		add_action( 'admin_init', array( $this, 'handle_staging_cutover_rehearsal' ) );
		add_action( 'admin_init', array( $this, 'handle_create_manager_role' ) );
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'handle_template_download' ) );
		add_action( 'admin_post_ecapla_certverify_backup_download', array( $this, 'handle_backup_download' ) );
		add_action( 'admin_post_ecapla_certverify_backup_analyze', array( $this, 'handle_backup_analyze' ) );
		add_action( 'admin_post_ecapla_certverify_backup_apply', array( $this, 'handle_backup_apply' ) );
		add_action( 'admin_post_ecapla_certverify_backup_continue', array( $this, 'handle_backup_continue' ) );
		add_action( 'admin_post_certloom_backup_settings', array( $this, 'handle_backup_settings' ) );
		add_action( 'admin_post_certloom_backup_test_storage', array( $this, 'handle_backup_test_storage' ) );
		add_action( 'admin_post_certloom_backup_run_now', array( $this, 'handle_backup_run_now' ) );
		add_action( 'admin_post_certloom_backup_saved_download', array( $this, 'handle_backup_saved_download' ) );
		add_action( 'admin_post_certloom_backup_saved_review', array( $this, 'handle_backup_saved_review' ) );
		add_action( 'admin_post_certloom_backup_saved_test_restore', array( $this, 'handle_backup_saved_test_restore' ) );
		add_action( 'admin_post_certloom_backup_saved_delete', array( $this, 'handle_backup_saved_delete' ) );
		add_action( 'admin_post_certloom_google_drive_connect', array( $this, 'handle_google_drive_connect' ) );
		add_action( 'admin_post_certloom_google_drive_oauth_callback', array( $this, 'handle_google_drive_oauth_callback' ) );
		add_action( 'admin_post_certloom_google_drive_relay_callback', array( $this, 'handle_google_drive_relay_callback' ) );
		add_action( 'admin_post_certloom_google_drive_settings', array( $this, 'handle_google_drive_settings' ) );
		add_action( 'admin_post_certloom_google_drive_test', array( $this, 'handle_google_drive_test' ) );
		add_action( 'admin_post_certloom_google_drive_disconnect', array( $this, 'handle_google_drive_disconnect' ) );
		add_action( 'admin_post_certloom_google_drive_upload', array( $this, 'handle_google_drive_upload' ) );
		add_action( 'admin_post_certloom_google_drive_download_local', array( $this, 'handle_google_drive_download_local' ) );
		add_action( 'admin_post_certloom_google_drive_delete', array( $this, 'handle_google_drive_delete' ) );
		add_action( 'admin_post_certloom_dismiss_setup_reminder', array( $this, 'handle_dismiss_setup_reminder' ) );
		add_action( 'wp_ajax_certloom_setup_draft_autosave', array( $this, 'handle_setup_wizard_draft_autosave' ) );
		add_action( 'wp_ajax_certloom_wizard_template_preview', array( $this, 'handle_setup_wizard_template_preview' ) );
		add_action( 'wp_ajax_certloom_guided_tour_state', array( 'Ecapla_CertVerify_Guided_Tour', 'handle_ajax' ) );
		add_action( 'admin_notices', array( $this, 'render_active_setup_draft_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_status_review_acknowledgement' ) );
		add_action( 'admin_post_ecapla_certverify_delete_certificate', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ecapla_certverify_delete_field', array( $this, 'handle_field_delete' ) );
		add_action( 'admin_post_ecapla_certverify_restore_field', array( $this, 'handle_field_restore' ) );
		add_action( 'admin_post_ecapla_certverify_purge_field', array( $this, 'handle_field_purge' ) );
		add_action( 'admin_post_ecapla_certverify_retranslate_fields', array( $this, 'handle_field_retranslate' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
		add_action( 'admin_notices', array( $this, 'status_review_notices' ) );
		add_action( 'admin_footer-plugins.php', array( $this, 'render_plugin_lifecycle_dialog' ) );
		add_action( 'admin_post_certloom_prepare_deactivation', array( $this, 'handle_prepare_deactivation' ) );
	}

	/** Give hidden Certloom routes a title before WordPress builds admin-header.php. */
	public function set_hidden_page_title() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selection.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$labels = array(
			'certloom-domain-recipients' => __( 'Recipients', 'certloom' ),
			'certloom-domain-issuers' => __( 'Issuers', 'certloom' ),
			'certloom-domain-signers' => __( 'Signers', 'certloom' ),
			'certloom-domain-batches' => __( 'Batches', 'certloom' ),
			'certloom-domain-data-tools' => __( 'Credential data tools', 'certloom' ),
			'certloom-issuance' => __( 'Issue credential', 'certloom' ),
			'certloom-bulk-issuance' => __( 'Bulk issuance', 'certloom' ),
			'certloom-email-delivery' => __( 'Email delivery', 'certloom' ),
			'certloom-file-intake' => __( 'File intake', 'certloom' ),
			'certloom-email-designs' => __( 'Email designs', 'certloom' ),
			'certloom-design-rules' => __( 'Design Rules', 'certloom' ),
			'certloom-analytics' => __( 'Analytics', 'certloom' ),
			'certloom-integrations' => __( 'Integrations', 'certloom' ),
			'certloom-import' => __( 'Backup & Restore', 'certloom' ),
			'certloom-certificates' => __( 'Certificates', 'certloom' ),
			'certloom-add' => __( 'Add Certificate', 'certloom' ),
			'certloom-fields' => __( 'Field Manager', 'certloom' ),
			'certloom-frontend' => __( 'Appearance & Embed', 'certloom' ),
			'certloom-setup' => __( 'Setup', 'certloom' ),
			'certloom-verification-sample' => __( 'Verification sample', 'certloom' ),
			'certloom-bulk-review' => __( 'Review bulk change', 'certloom' ),
		);
		if ( ! isset( $labels[ $page ] ) ) { return; }
		global $title;
		if ( empty( $title ) ) { $title = $labels[ $page ]; }
	}

	public function add_menu() {
		/*
		 * WordPress evaluates capabilities while routes are registered. Refresh the
		 * versioned Certloom role/current-user capability state before registering
		 * any menu route so upgraded roles cannot receive a generic admin.php 403.
		 */
		Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		$home_label = __( 'Home', 'certloom' );

		$dashboard_hook = add_menu_page(
			__( 'Certloom', 'certloom' ),
			__( 'Certloom', 'certloom' ),
			ECAPLA_CERTVERIFY_CAP,
			self::DASHBOARD_PAGE,
			array( $this, 'render_dashboard_screen' ),
			'none',
			30
		);
		if ( $dashboard_hook ) {
			add_action( 'load-' . $dashboard_hook, array( $this, 'register_dashboard_help' ) );
		}
		add_submenu_page( self::DASHBOARD_PAGE, $home_label, $home_label, ECAPLA_CERTVERIFY_CAP, self::DASHBOARD_PAGE, array( $this, 'render_dashboard_screen' ) );
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			// The historical list slug remains a compatibility route while the unified
			// Credentials workspace is authoritative in the QA domain preview. Register
			// it as a hidden route so stale links/redirects reach our redirect callback
			// instead of failing WordPress' page-access check before Certloom can act.
			add_submenu_page( null, __( 'Credentials', 'certloom' ), __( 'Credentials', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::CERTIFICATES_PAGE, array( $this, 'render_list_screen' ) );
		} else {
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Credentials', 'certloom' ), __( 'Credentials', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::CERTIFICATES_PAGE, array( $this, 'render_list_screen' ) );
		}

		/*
		 * WordPress treats submenu registration as route registration too. The
		 * consolidated Certloom shell intentionally shows only six primary rows,
		 * so contextual/deep-link screens are registered with a null parent. This
		 * keeps them routable without letting the late menu-consolidation pass
		 * accidentally turn a valid Certloom subtab into a generic WordPress 403.
		 */
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			add_submenu_page( null, __( 'Recipients', 'certloom' ), __( 'Recipients', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-recipients', array( $this, 'render_roadmap_placeholder_screen' ) );
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Programs', 'certloom' ), __( 'Programs', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-programs', array( $this, 'render_roadmap_placeholder_screen' ) );
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Design', 'certloom' ), __( 'Design', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-templates', array( $this, 'render_roadmap_placeholder_screen' ) );
			add_submenu_page( null, __( 'Issuance', 'certloom' ), __( 'Issuance', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-issuance', array( $this, 'render_roadmap_placeholder_screen' ) );
		}

		/*
		 * Analytics is contextual rather than a primary WordPress submenu row.
		 * Register it parentless from the outset so its hook remains admin_page_*
		 * after the unified-menu consolidation removes contextual rows. The screen
		 * callback still enforces CAP_VIEW_ANALYTICS (with the established Certloom
		 * management fallback) before rendering anything.
		 */
		Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		add_submenu_page( null, __( 'Analytics', 'certloom' ), __( 'Analytics', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::ANALYTICS_PAGE, array( $this, 'render_analytics_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Settings', 'certloom' ), __( 'Settings', 'certloom' ), 'manage_options', 'certloom-settings', array( $this, 'render_settings_screen' ) );

		// Automations is a first-class task in the unified Certloom workspace.
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Automations', 'certloom' ), __( 'Automations', 'certloom' ), Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, 'certloom-automations', array( $this, 'render_automations_screen' ) );

		// Contextual utility screens stay routable while remaining out of the six-section primary menu.
		add_submenu_page( null, __( 'Integrations', 'certloom' ), __( 'Integrations', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-integrations', array( $this, 'render_integrations_screen' ) );
		add_submenu_page( null, __( 'Backup & Restore', 'certloom' ), __( 'Backup & Restore', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-import', array( $this, 'render_import_screen' ) );
		add_submenu_page( null, __( 'Add Certificate', 'certloom' ), __( 'Add Certificate', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-add', array( $this, 'render_edit_screen' ) );
		add_submenu_page( null, __( 'Field Manager', 'certloom' ), __( 'Field Manager', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-fields', array( $this, 'render_fields_screen' ) );
		add_submenu_page( null, __( 'Appearance & Embed', 'certloom' ), __( 'Appearance & Embed', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-frontend', array( $this, 'render_frontend_screen' ) );
		add_submenu_page( null, __( 'Setup', 'certloom' ), __( 'Setup', 'certloom' ), 'manage_options', 'certloom-setup', array( $this, 'render_onboarding_screen' ) );
		add_submenu_page( null, __( 'Verification sample', 'certloom' ), __( 'Verification sample', 'certloom' ), 'manage_options', 'certloom-verification-sample', array( $this, 'render_verification_sample_screen' ) );
		add_submenu_page( null, __( 'Review bulk change', 'certloom' ), __( 'Review bulk change', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-bulk-review', array( $this, 'render_bulk_review_screen' ) );
	}



	/** Adds a replay/resume entry to WordPress Help without blocking the Dashboard. */
	public function register_dashboard_help() {
		if ( ! Ecapla_CertVerify_Permissions::current_user_can_manage() || ! function_exists( 'get_current_screen' ) ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || ! method_exists( $screen, 'add_help_tab' ) ) { return; }
		$state = Ecapla_CertVerify_Guided_Tour::get_state();
		$status = (string) ( $state['status'] ?? 'new' );
		$resume_label = 'in_progress' === $status ? __( 'Resume product tour', 'certloom' ) : __( 'Start product tour', 'certloom' );
		if ( in_array( $status, array( 'completed', 'dismissed' ), true ) ) { $resume_label = __( 'Replay product tour', 'certloom' ); }
		$resume_mode = in_array( $status, array( 'completed', 'dismissed' ), true ) ? 'restart' : 'resume';
		$resume_url = add_query_arg( array( 'page' => self::DASHBOARD_PAGE, 'certloom_tour' => $resume_mode ), admin_url( 'admin.php' ) );
		$restart_url = add_query_arg( array( 'page' => self::DASHBOARD_PAGE, 'certloom_tour' => 'restart' ), admin_url( 'admin.php' ) );
		$content = '<p>' . esc_html__( 'The six-step Certloom tour points to real Dashboard actions. It never blocks normal navigation and stores progress only for your administrator account.', 'certloom' ) . '</p>';
		$content .= '<p><a class="button button-primary" href="' . esc_url( $resume_url ) . '">' . esc_html( $resume_label ) . '</a> <a class="button" href="' . esc_url( $restart_url ) . '">' . esc_html__( 'Restart from step 1', 'certloom' ) . '</a></p>';
		$screen->add_help_tab( array( 'id' => 'certloom-guided-tour', 'title' => __( 'Product tour', 'certloom' ), 'content' => $content ) );
	}


	/** Compatibility endpoint for stale pre-unification workspace preference forms. */
	public function handle_ui_mode_switch() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be signed in to change the Certloom workspace mode.', 'certloom' ) );
		}
		check_admin_referer( 'certloom_set_ui_mode' );
		$mode = isset( $_POST['certloom_ui_mode'] ) ? sanitize_key( wp_unslash( $_POST['certloom_ui_mode'] ) ) : '';
		$result = \Certloom\Infrastructure\Admin\AdminUiMode::save_for_current_user( $mode );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Retired compatibility method. Certloom now has one workspace. */
	public function render_ui_mode_switch() { return; }

	/** Sends a newly activated single site into Setup exactly once. */
	public function maybe_redirect_after_activation() {
		$key = 'certloom_activation_redirect_' . get_current_user_id();
		if ( ! get_transient( $key ) ) { return; }
		delete_transient( $key );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bulk activation detection.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) { return; }
		$state = Ecapla_CertVerify_Onboarding::get_state();
		if ( is_array( $state ) && ! empty( $state['wizard_completed_at'] ) ) { return; }
		wp_safe_redirect( admin_url( 'admin.php?page=certloom-setup' ) );
		exit;
	}

	public function enqueue_assets( $hook ) {
		// The monochrome Certloom mark belongs to the WordPress menu on every admin page.
		wp_enqueue_style( 'certloom-admin-menu-icon', ECAPLA_CERTVERIFY_URL . 'admin/assets/menu-icon.css', array(), ECAPLA_CERTVERIFY_VERSION );

		if ( false === strpos( (string) $hook, 'certloom' ) && 'plugins.php' !== (string) $hook ) {
			return;
		}
		wp_enqueue_style( 'certloom-tokens', ECAPLA_CERTVERIFY_URL . 'assets/css/certloom-tokens.css', array(), ECAPLA_CERTVERIFY_VERSION );
		$ui_version = ECAPLA_CERTVERIFY_VERSION;
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) { $ui_version .= '.' . max( filemtime( ECAPLA_CERTVERIFY_PATH . 'admin/assets/admin.css' ), filemtime( ECAPLA_CERTVERIFY_PATH . 'admin/assets/certloom-foundation.css' ), filemtime( ECAPLA_CERTVERIFY_PATH . 'admin/assets/admin.js' ) ); }
		wp_enqueue_style( 'ecapla-certverify-admin', ECAPLA_CERTVERIFY_URL . 'admin/assets/admin.css', array( 'certloom-tokens' ), $ui_version );
		wp_enqueue_style( 'certloom-admin-foundation', ECAPLA_CERTVERIFY_URL . 'admin/assets/certloom-foundation.css', array( 'ecapla-certverify-admin' ), $ui_version );
		wp_enqueue_script( 'certloom-template-text-fit', ECAPLA_CERTVERIFY_URL . 'admin/assets/template-text-fit.js', array(), ECAPLA_CERTVERIFY_VERSION, true );
		wp_enqueue_script( 'ecapla-certverify-admin', ECAPLA_CERTVERIFY_URL . 'admin/assets/admin.js', array( 'certloom-template-text-fit' ), $ui_version, true );
		$usage_profile = \Certloom\Infrastructure\Admin\UsageProfilePresentation::active_profile();
		$usage_terms = \Certloom\Infrastructure\Admin\UsageProfilePresentation::terms( $usage_profile );
		$usage_issuance = \Certloom\Infrastructure\Admin\UsageProfilePresentation::issuance_contract( $usage_profile );
		$usage_program = (string) ( $usage_terms['program']['singular'] ?? __( 'Program', 'certloom' ) );
		$usage_batch = (string) ( $usage_terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) );
		$usage_issuer = (string) ( $usage_terms['issuer']['singular'] ?? __( 'Issuer', 'certloom' ) );
		wp_localize_script( 'ecapla-certverify-admin', 'certloomAdminL10n', array(
			'usageProfile' => $usage_profile,
			'usageProfileLabel' => \Certloom\Infrastructure\Admin\UsageProfilePresentation::profile_label(),
			'activeProfileLabel' => __( 'Active profile', 'certloom' ),
			'usageTerms' => $usage_terms,
			'usageIssuance' => $usage_issuance,
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'restContextUrl' => rest_url( 'certloom/v1/admin/context' ),
			'restSetupCheckUrl' => rest_url( 'certloom/v1/admin/setup/field-check' ),
			'restSetupReadinessUrl' => rest_url( 'certloom/v1/admin/setup/readiness-check' ),
			'setupCheckingReadiness' => __( 'Checking setup and preparing an example...', 'certloom' ),
			'setupReviewStep' => __( 'Review this step', 'certloom' ),
			'setupStatusReady' => __( 'Checked', 'certloom' ),
			'setupStatusBlocked' => __( 'Needs attention', 'certloom' ),
			'setupStatusNext' => __( 'Next step', 'certloom' ),
			'setupCheckChanged' => __( 'Setup changed during the check. Run it again to review your saved choices.', 'certloom' ),
			'restCredentialsUrl' => rest_url( 'certloom/v1/admin/credentials' ),
			'restTemplatePreviewUrl' => rest_url( 'certloom/v1/admin/template-preview' ),
			'restIssuancePreviewUrl' => rest_url( 'certloom/v1/admin/issuance-preview' ),
			'recipientEditBase' => admin_url( 'admin.php?page=certloom-domain-recipients&recipient_id=' ),
			'liveSearchUpdating' => __( 'Updating results…', 'certloom' ),
			'liveSearchUpdated' => __( 'Results updated.', 'certloom' ),
			'liveSearchFailed' => __( 'Live search was unavailable. Use Filter to reload the page.', 'certloom' ),
			'copy' => __( 'Copy', 'certloom' ),
			'copied' => __( 'Copied', 'certloom' ),
			'copyFailed' => __( 'Press Ctrl+C or Cmd+C to copy', 'certloom' ),
			'contextSearchBatches' => (string) ( $usage_terms['batch']['search'] ?? __( 'Search Batches…', 'certloom' ) ),
			'contextSearchTemplates' => __( 'Search published Templates…', 'certloom' ),
			'contextSearchChoices' => __( 'Search choices…', 'certloom' ),
			'contextManageDesigns' => __( 'Manage published designs', 'certloom' ),
			'contextChooseProgramFirst' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Choose a %s first.', 'certloom' ), $usage_program ),
			'contextSearching' => __( 'Searching…', 'certloom' ),
			'contextSearchFailed' => __( 'Search failed.', 'certloom' ),
			'contextOneMatch' => __( '1 match.', 'certloom' ),
			'contextMatches' => __( '%d matches.', 'certloom' ),
			'contextNoPublishedDesigns' => __( 'No published designs match. Publish a design in Design first.', 'certloom' ),
			'contextNoChoices' => __( 'No matching choices.', 'certloom' ),
			'contextNoBatch' => sprintf( /* translators: %s: profile-aware Batch term. */ __( 'No %s', 'certloom' ), $usage_batch ),
			'contextChooseProgram' => (string) ( $usage_terms['program']['choose'] ?? __( 'Choose Program…', 'certloom' ) ),
			'programDefaultDesign' => sprintf( /* translators: %s: profile-aware Program term. */ __( '%s default design', 'certloom' ), $usage_program ),
			'recoveryChooseBackup' => __( 'Step 2: choose the Certloom backup you want to review.', 'certloom' ),
			'recoveryChooseCsv' => __( 'Step 2: choose the CSV file you want to review.', 'certloom' ),
			'legacyProgramFieldMode' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Legacy %s field mode — optional free custom keys remain available until this context adopts Credential fields.', 'certloom' ), $usage_program ),
			'programNoCredentialFields' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'This %s uses Credential fields but currently assigns no additional fields.', 'certloom' ), $usage_program ),
			'credentialFieldsLoadedProgram' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Credential fields loaded from the selected %s.', 'certloom' ), $usage_program ),
			'credentialFieldsLoadedBatch' => sprintf( /* translators: 1: Program term, 2: Batch term. */ __( 'Credential fields loaded from the selected %1$s + %2$s.', 'certloom' ), $usage_program, $usage_batch ),
			'scopeBatch' => $usage_batch,
			'scopeProgram' => $usage_program,
			'fieldRequired' => __( 'Required', 'certloom' ),
			'fieldOptional' => __( 'Optional', 'certloom' ),
			'recipientValueFrozen' => __( 'Value comes from the Recipient profile and is frozen into the issued credential.', 'certloom' ),
			'useGradeField' => __( 'Use the Grade / result field above.', 'certloom' ),
			'privacyPrefix' => __( 'Privacy:', 'certloom' ),
			'privacyPrivate' => __( 'Private', 'certloom' ),
			'privacyVerification' => __( 'Verification only', 'certloom' ),
			'privacyProfile' => __( 'Profile only', 'certloom' ),
			'privacyBoth' => __( 'Verification + profile', 'certloom' ),
			'sensitivityPublicSafe' => __( 'Public-safe', 'certloom' ),
			'sensitivityPersonal' => __( 'Personal', 'certloom' ),
			'sensitivitySensitive' => __( 'Sensitive', 'certloom' ),
			'sensitivityInternal' => __( 'Internal', 'certloom' ),
			'bulkOr' => __( 'or', 'certloom' ),
			'bulkRequired' => __( 'required', 'certloom' ),
			'credentialFieldsLoadFailed' => __( 'Credential fields could not be loaded. The server will still validate before issuance.', 'certloom' ),
			'chooseProgramLoadFields' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Choose a %s to load its credential fields.', 'certloom' ), $usage_program ),
			'loadingCredentialFields' => __( 'Loading credential fields…', 'certloom' ),
			'invalidResponse' => __( 'Invalid response', 'certloom' ),
			'defaultCredentialTerm' => __( 'Credential', 'certloom' ),
			'defaultDesignDescription' => __( 'Clear and professional', 'certloom' ),
			'enteredWhenIssuing' => __( 'Entered when issuing', 'certloom' ),
			'loadingPreview' => __( 'Loading preview…', 'certloom' ),
			'previewLoadFailed' => __( 'Template preview could not be loaded.', 'certloom' ),
			'previewSessionExpired' => __( 'The preview session expired. Refresh this page and try again.', 'certloom' ),
			'noDefaultDesign' => __( 'No default design selected', 'certloom' ),
			'previewUnavailable' => __( 'Preview unavailable', 'certloom' ),
			'inheritedDesign' => __( 'Inherited design', 'certloom' ),
			'assignedDesign' => __( 'Assigned design', 'certloom' ),
			'chooseAssignedDesignPreview' => __( 'Choose a published design to preview it here.', 'certloom' ),
			'choosePublishedDesignPreview' => __( 'Choose a published design to preview a one-off override.', 'certloom' ),
			'previewFitHint' => __( 'Preview fits automatically in this window.', 'certloom' ),
			'issuancePreviewCompleteCore' => __( 'Choose a recipient and complete the credential basics to preview real issuance data.', 'certloom' ),
			'designDataCheck' => __( 'Design data check', 'certloom' ),
			'designDataChecking' => __( 'Checking design data…', 'certloom' ),
			'designDataReady' => __( 'All design fields are ready.', 'certloom' ),
			'designDataMissingOne' => __( '1 design field still needs a value.', 'certloom' ),
			'designDataMissingMany' => __( '%d design fields still need values.', 'certloom' ),
			'designDataGenerated' => __( 'Created when issued', 'certloom' ),
			'designDataReadyBadge' => __( 'Ready', 'certloom' ),
			'designDataMissingBadge' => __( 'Needs value', 'certloom' ),
			'designDataEditRecipient' => __( 'Edit recipient', 'certloom' ),
			'designDataOpenDetails' => __( 'Open details', 'certloom' ),
			'designDataChooseProgram' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Choose %s', 'certloom' ), $usage_program ),
			'designDataAddValue' => __( 'Add value', 'certloom' ),
			'designDataCredentialDetails' => __( 'Credential details', 'certloom' ),
			'credentialDetailsIndependent' => __( 'Add the credential-specific facts you want to store with this independent credential.', 'certloom' ),
			'credentialDetailsProgram' => (string) ( $usage_issuance['credential_details_program'] ?? __( 'Program and Batch field rules appear here. Add one-off credential details below when this issuance or its design needs them.', 'certloom' ) ),
			'addCredentialDetail' => __( 'Add detail', 'certloom' ),
			'chooseCredentialDetail' => __( 'Choose a detail…', 'certloom' ),
			'noIndependentDetails' => __( 'No extra credential details added yet.', 'certloom' ),
			'designSuggestedDetails' => __( 'Needed by the selected design', 'certloom' ),
			'designSuggestedAdd' => __( 'Add %s', 'certloom' ),
			'designSuggestedMissing' => __( 'Required by the selected design and not filled yet.', 'certloom' ),
			'designSuggestedMap' => __( 'Assign existing detail', 'certloom' ),
			'designSuggestedChooseExisting' => __( 'Choose an existing credential detail…', 'certloom' ),
			'designAutoApplied' => __( 'The selected Program has a published inherited design, so Certloom selected it automatically.', 'certloom' ),
			'googleWaiting' => __( 'Waiting for Google…', 'certloom' ),
			'googleOpening' => __( 'Opening Google…', 'certloom' ),
			'googleCompletePermission' => __( 'Complete permission in the Google window.', 'certloom' ),
			'googlePopupBlocked' => __( 'Your browser blocked the popup, so Google will open in this tab.', 'certloom' ),
			'googleConnected' => __( 'Google Drive connected.', 'certloom' ),
			'googleNotConnected' => __( 'Google Drive was not connected.', 'certloom' ),
			'connected' => __( 'Connected', 'certloom' ),
			'requiredField' => __( 'Please fill in this required field.', 'certloom' ),
			'newRecipientProfile' => __( 'New Recipient profile', 'certloom' ),
			'quickInvalid' => __( 'Complete the highlighted required field.', 'certloom' ),
			'adding' => __( 'Adding…', 'certloom' ),
			'itemAddFailed' => __( 'This item could not be added.', 'certloom' ),
			'added' => __( 'Added.', 'certloom' ),
			'saving' => __( 'Saving…', 'certloom' ),
			'setupDraftSaving' => __( 'Saving draft…', 'certloom' ),
			'setupDraftSaved' => __( 'Draft saved', 'certloom' ),
			'setupDraftFailed' => __( 'Draft could not be saved. Keep this page open and try again.', 'certloom' ),
			'defaultLabel' => __( 'Default', 'certloom' ),
			'enterFieldLabel' => __( 'Enter a field label.', 'certloom' ),
			'creatingField' => __( 'Creating field…', 'certloom' ),
			'fieldCreateFailed' => __( 'Field could not be created.', 'certloom' ),
			'fieldCreated' => __( 'Field created.', 'certloom' ),
			'scopeNotUsed' => __( 'Not used', 'certloom' ),
			'scopeInheritProgram' => __( 'Inherit Program', 'certloom' ),
			'scopeExclude' => __( 'Exclude', 'certloom' ),
			'fieldRule' => __( 'Field rule', 'certloom' ),
			'remove' => __( 'Remove', 'certloom' ),
			'designPublishFailed' => __( 'This design could not be published.', 'certloom' ),
			'designDraftFailed' => __( 'This design could not be moved to Draft.', 'certloom' ),
			'designPublished' => __( 'Design published.', 'certloom' ),
			'designMovedDraft' => __( 'Design moved to Draft.', 'certloom' ),
			'published' => __( 'Published', 'certloom' ),
			'draft' => __( 'Draft', 'certloom' ),
			'movePublishedDraft' => __( 'Move published design back to Draft', 'certloom' ),
			'publishDraftDesign' => __( 'Publish this draft design', 'certloom' ),
			'certificateImageTypeInvalid' => __( 'Choose a PNG or JPEG image.', 'certloom' ),
			'selectedFile' => __( 'Selected file', 'certloom' ),
			'noFileSelected' => __( 'No file selected', 'certloom' ),
			'noCertificateImage' => __( 'No certificate image selected', 'certloom' ),
			'phase2ChooseProgram' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Choose a %s, or switch to Independent credential.', 'certloom' ), $usage_program ),
			'phase2ChooseBatchForRepeat' => sprintf( /* translators: 1: Program term, 2: Batch term. */ __( 'This %1$s issues one credential per %2$s. Choose a %2$s in the profile context section.', 'certloom' ), $usage_program, $usage_batch ),
			'chooseProgramForTitle' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Choose a %s to inherit its title.', 'certloom' ), $usage_program ),
			'chooseProgramValidity' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Choose a %s to use its validity default.', 'certloom' ), $usage_program ),
			'programDefaultPrefix' => sprintf( /* translators: %s: profile-aware Program term. */ __( '%s default:', 'certloom' ), $usage_program ),
			'programValidityAtIssuance' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'This %s decides validity at issuance. Choose No expiry or a specific expiry date.', 'certloom' ), $usage_program ),
			'issuerInheritedFallback' => sprintf( /* translators: 1: Program term, 2: Issuer term. */ __( '%1$s %2$s', 'certloom' ), $usage_program, $usage_issuer ),
			'issuerInheritedPrefix' => sprintf( /* translators: %s: profile-aware Program term. */ __( 'Inherited from the selected %s:', 'certloom' ), $usage_program ),
			'issuerInheritedChangeProgram' => sprintf( /* translators: 1: Program term, 2: Issuer term. */ __( 'Change the %1$s to change this %2$s.', 'certloom' ), $usage_program, $usage_issuer ),
			'issuerOptionalHelp' => sprintf( /* translators: 1: Program term, 2: Issuer term. */ __( 'Optional for an Independent credential or a %1$s without an assigned %2$s.', 'certloom' ), $usage_program, $usage_issuer ),
			'phase2DetailsReady' => __( 'Ready to review without certificate artwork.', 'certloom' ),
			'phase2ChooseImage' => __( 'Choose a PNG or JPEG certificate image to continue.', 'certloom' ),
			'phase2ImageReady' => __( 'Ready to review with the selected certificate image.', 'certloom' ),
			'phase2StandalonePending' => sprintf( /* translators: %s: profile-aware Program term. */ __( '%s is optional for standalone credentials.', 'certloom' ), $usage_program ),
			'phase2ChooseDesign' => __( 'Choose a published assigned design, or use the inherited design.', 'certloom' ),
			'phase2CompleteCore' => __( 'Choose a recipient and enter a credential title to continue to review.', 'certloom' ),
			'phase2Compatible' => __( 'Ready to review with the selected Certloom design.', 'certloom' ),
			'bulkAssignedDesignHint' => __( 'The selected published design overrides inheritance only for this bulk job.', 'certloom' ),
			'bulkInheritedDesignHint' => __( 'Inherited design follows Global → Issuer → Program → Batch. Choose a Program to see the effective source.', 'certloom' ),
			'bulkChooseProgramLifecycle' => __( 'Choose a Program to confirm its default validity and repeat-issuance policy.', 'certloom' ),
			'bulkProgramValidity' => __( 'Program default: %s.', 'certloom' ),
			'bulkDecideValidity' => __( 'This Program decides validity at issuance. Choose Permanent or a specific expiry date for this bulk job.', 'certloom' ),
			'bulkRenewalRequired' => __( ' Renewal is required after expiry.', 'certloom' ),
			'bulkBatchRequired' => __( 'Required: this Program issues one credential per Batch or session.', 'certloom' ),
			'bulkBatchOptional' => __( 'Optional. Search is limited to the selected Program; Batch field and design overrides are revalidated for every row.', 'certloom' ),
			'manualBatchRequired' => sprintf( /* translators: 1: Program term, 2: Batch term. */ __( 'Required: this %1$s issues one credential per %2$s.', 'certloom' ), $usage_program, $usage_batch ),
			'manualBatchOptional' => sprintf( /* translators: 1: Program term, 2: Batch term. */ __( 'Optional unless the selected %1$s issues one credential per %2$s. When selected, the %2$s must belong to the %1$s.', 'certloom' ), $usage_program, $usage_batch ),
			'resolvingInheritedDesign' => __( 'Resolving inherited design…', 'certloom' ),
			'deactivateVerificationWarning' => __( 'Deactivating Certloom stops public credential verification. Continue?', 'certloom' ),
		) );
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Asset routing only.
		if ( in_array( $page, array( 'certloom-domain-issuers', 'certloom-domain-signers', 'certloom-issuance', 'certloom-setup' ), true ) ) { wp_enqueue_media(); }
	}


	public function admin_body_class( $classes ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin presentation routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 'certloom-setup' === $page ? trim( (string) $classes . ' certloom-setup-v2-body' ) : $classes;
	}


	public function render_plugin_lifecycle_dialog() {
		if ( ! current_user_can( 'activate_plugins' ) ) { return; }
		$plugin_file = plugin_basename( ECAPLA_CERTVERIFY_PATH . 'certloom.php' );
		$delete_data = ! empty( Ecapla_CertVerify_Settings::get( 'delete_data_on_uninstall', false ) );
		$network_wide = is_multisite() && is_network_admin();
		if ( $network_wide ) {
			$network_choice = get_site_option( 'certloom_uninstall_delete_data_network_override', null );
			if ( is_array( $network_choice ) && array_key_exists( 'delete_data', $network_choice ) ) { $delete_data = ! empty( $network_choice['delete_data'] ); }
		}
		?>
		<dialog id="certloom-plugin-lifecycle-dialog" class="certloom-plugin-lifecycle-dialog" aria-labelledby="certloom-plugin-lifecycle-title" aria-describedby="certloom-plugin-lifecycle-description" data-certloom-plugin-file="<?php echo esc_attr( $plugin_file ); ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-certloom-lifecycle-form>
				<input type="hidden" name="action" value="certloom_prepare_deactivation"><input type="hidden" name="redirect_to" value="" data-certloom-deactivate-redirect><input type="hidden" name="certloom_network_wide" value="<?php echo $network_wide ? '1' : '0'; ?>"><?php wp_nonce_field( 'certloom_prepare_deactivation' ); ?>
				<div class="certloom-lifecycle-dialog-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Data & verification', 'certloom' ); ?></span><h2 id="certloom-plugin-lifecycle-title"><?php esc_html_e( 'Before deactivating Certloom', 'certloom' ); ?></h2></div><button type="button" class="button-link certloom-dialog-close" data-certloom-dialog-close aria-label="<?php esc_attr_e( 'Close', 'certloom' ); ?>">×</button></div>
				<p id="certloom-plugin-lifecycle-description"><?php esc_html_e( 'Public verification pages, credential QR destinations and the recipient portal stop working while Certloom is inactive.', 'certloom' ); ?></p>
				<p class="description"><?php esc_html_e( 'WordPress can only delete a plugin after it is inactive, when Certloom code is no longer running. Choose now what should happen if you later delete the plugin.', 'certloom' ); ?></p>
				<div class="certloom-lifecycle-options" role="radiogroup" aria-label="<?php esc_attr_e( 'If the plugin is deleted', 'certloom' ); ?>">
					<label><input type="radio" name="delete_data_on_uninstall" value="0" <?php checked( ! $delete_data ); ?>><span><strong><?php esc_html_e( 'Keep certificate data', 'certloom' ); ?></strong><small><?php esc_html_e( 'Recommended. Saved credentials, certificate and email designs stay in WordPress for reinstalling Certloom. Verification remains unavailable while the plugin is inactive.', 'certloom' ); ?></small></span></label>
					<label class="is-destructive"><input type="radio" name="delete_data_on_uninstall" value="1" <?php checked( $delete_data ); ?>><span><strong><?php esc_html_e( 'Delete Certloom data if the plugin is deleted', 'certloom' ); ?></strong><small><?php esc_html_e( 'Permanently removes Certloom certificate data and settings during WordPress plugin deletion. This cannot be undone without a backup.', 'certloom' ); ?></small></span></label>
				</div>
				<p class="description"><?php esc_html_e( 'If you later delete the plugin, connections, automation rules, pending jobs and verification analytics are cleared for either choice. Reconnect integrations after reinstalling. Deactivation alone keeps these settings.', 'certloom' ); ?></p>
				<div class="certloom-lifecycle-dialog-actions"><button type="button" class="button" data-certloom-dialog-close><?php esc_html_e( 'Cancel', 'certloom' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Save choice & deactivate', 'certloom' ); ?></button></div>
			</form>
		</dialog>
		<?php
	}

	public function handle_prepare_deactivation() {
		if ( ! current_user_can( 'activate_plugins' ) ) { wp_die( esc_html__( 'You do not have permission to deactivate plugins.', 'certloom' ) ); }
		check_admin_referer( 'certloom_prepare_deactivation' );
		$delete_data = isset( $_POST['delete_data_on_uninstall'] ) && '1' === (string) wp_unslash( $_POST['delete_data_on_uninstall'] );
		$network_wide = is_multisite() && ! empty( $_POST['certloom_network_wide'] );
		if ( $network_wide ) {
			if ( ! current_user_can( 'manage_network_plugins' ) ) { wp_die( esc_html__( 'You do not have permission to change a network-wide Certloom deletion policy.', 'certloom' ) ); }
			$choice = array( 'delete_data' => $delete_data ? 1 : 0, 'recorded_at' => time() );
			$saved = update_site_option( 'certloom_uninstall_delete_data_network_override', $choice );
			if ( ! $saved && $choice !== get_site_option( 'certloom_uninstall_delete_data_network_override', array() ) ) { wp_die( esc_html__( 'Certloom could not save the network-wide uninstall data choice. The plugin was not deactivated.', 'certloom' ) ); }
		} else {
			$settings = Ecapla_CertVerify_Settings::get_all();
			$settings['delete_data_on_uninstall'] = $delete_data;
			if ( ! Ecapla_CertVerify_Settings::save( $settings ) ) { wp_die( esc_html__( 'Certloom could not save the uninstall data choice. The plugin was not deactivated.', 'certloom' ) ); }
		}
		$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
		$redirect = wp_validate_redirect( $redirect, '' );
		$admin_base = untrailingslashit( admin_url() );
		if ( '' === $redirect || 0 !== strpos( $redirect, $admin_base ) ) { wp_die( esc_html__( 'Certloom refused an invalid plugin deactivation destination.', 'certloom' ) ); }
		wp_safe_redirect( $redirect ); exit;
	}

	public function handle_form_submission() {
		if ( ! isset( $_POST['certverify_save'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_save_certificate' );

		$id       = isset( $_POST['cert_id'] ) ? absint( $_POST['cert_id'] ) : 0;
		$back_url = $id ? admin_url( 'admin.php?page=certloom-add&id=' . $id ) : admin_url( 'admin.php?page=certloom-add' );
		if ( ! $id && \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			// Once the Recipient-aware issuance workflow is available, never create a
			// second-class legacy record that cannot be linked to a Recipient/Program.
			// Existing legacy records remain editable through this controller.
			wp_safe_redirect( admin_url( 'admin.php?page=certloom-issuance' ) );
			exit;
		}
		if ( $id ) {
			$existing = Ecapla_CertVerify_Database::get_by_id( $id );
			if ( is_wp_error( $existing ) ) {
				$this->redirect_error( $existing->get_error_message(), $back_url );
			}
			if ( ! $existing ) {
				$this->redirect_error( __( 'The certificate you tried to update no longer exists.', 'certloom' ), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
			}
		}

		$data   = array();
		$custom = array();
		foreach ( Ecapla_CertVerify_Fields::get_fields() as $field ) {
			if ( ! empty( $field['system_only'] ) ) {
				continue;
			}
			if ( ! empty( $field['column'] ) ) {
				if ( isset( $_POST[ $field['key'] ] ) ) {
					$data[ $field['key'] ] = sanitize_text_field( wp_unslash( $_POST[ $field['key'] ] ) );
				}
			} else {
				$post_key = 'cf_' . $field['key'];
				if ( isset( $_POST[ $post_key ] ) ) {
					$custom[ $field['key'] ] = 'textarea' === $field['type']
						? sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) )
						: sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				}
			}
		}
		$secondary_code = isset( $_POST['certloom_secondary_code'] ) ? wp_unslash( $_POST['certloom_secondary_code'] ) : '';
		$clear_secondary = ! empty( $_POST['certloom_secondary_code_clear'] );
		if ( $clear_secondary ) {
			$custom[ Ecapla_CertVerify_Secondary_Code::FIELD_KEY ] = '';
		} elseif ( '' !== trim( (string) $secondary_code ) ) {
			$hash = Ecapla_CertVerify_Secondary_Code::hash_from_input( $secondary_code );
			if ( is_wp_error( $hash ) ) {
				$this->redirect_error( $hash->get_error_message(), $back_url );
			}
			$custom[ Ecapla_CertVerify_Secondary_Code::FIELD_KEY ] = $hash;
		}
		if ( $custom ) {
			$data['custom_fields'] = $custom;
		}

		$use_generated_number = ! $id
			&& Ecapla_CertVerify_Numbering::is_enabled()
			&& ( ! array_key_exists( 'cert_number', $data ) || '' === trim( (string) $data['cert_number'] ) );
		if ( $use_generated_number ) {
			unset( $data['cert_number'] );
			$result = Ecapla_CertVerify_Numbering::insert_generated( $data );
		} else {
			$result = $id ? Ecapla_CertVerify_Database::update( $id, $data ) : Ecapla_CertVerify_Database::insert( $data );
		}
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), $back_url );
		}

		// Existing Legacy records stay in their edit workspace after save. This
		// avoids bouncing through the retired compatibility-list slug and preserves
		// the administrator's context while showing an explicit success state.
		$success_url = $id ? $back_url : admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE );
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_saved', '1', $success_url ) );
		exit;
	}

	public function handle_delete() {
		$this->require_manage_permission();
		check_admin_referer( 'ecapla_certverify_delete_certificate' );
		$id         = isset( $_POST['certificate_id'] ) ? absint( $_POST['certificate_id'] ) : 0;
		$return_url = $this->list_return_url();
		$result     = Ecapla_CertVerify_Database::delete( $id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), $return_url );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_deleted', '1', $return_url ) );
		exit;
	}

	/**
	 * Handles the WP_List_Table bulk action.
	 *
	 * The nonce checked here is the one WP_List_Table::display_tablenav() prints
	 * automatically ("bulk-{plural}"). The surrounding form must not print a
	 * second wp_nonce_field(), because duplicate _wpnonce inputs overwrite each
	 * other and the verification would always fail.
	 */
	public function handle_bulk_delete() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}
		if ( ! isset( $_POST['page'] ) || ! in_array( sanitize_key( wp_unslash( $_POST['page'] ) ), array( self::DASHBOARD_PAGE, self::CERTIFICATES_PAGE ), true ) ) {
			return;
		}
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}
		if ( ! in_array( $action, array( 'delete', 'bulk_edit' ), true ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'bulk-certificates' );

		$ids = isset( $_POST['cert_ids'] ) && is_array( $_POST['cert_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['cert_ids'] ) ) : array();
		if ( 'bulk_edit' === $action ) {
			$token = Ecapla_CertVerify_Bulk_Manager::create_session( $ids, $this->bulk_return_state_from_request( $_POST ) );
			if ( is_wp_error( $token ) ) {
				$this->redirect_error( $token->get_error_message(), $this->list_return_url() );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-bulk-review', 'token' => $token ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! empty( $ids ) ) {
			$this->require_safety_snapshot( 'bulk_delete', implode( ',', array_values( array_unique( $ids ) ) ), $this->list_return_url() );
		}
		$count = Ecapla_CertVerify_Database::delete_many( $ids );
		if ( is_wp_error( $count ) ) {
			$this->redirect_error( $count->get_error_message(), $this->list_return_url() );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_bulk_deleted', $count, $this->list_return_url() ) );
		exit;
	}

	/** Validates and freezes the exact bulk operation before any mutation. */
	public function handle_bulk_preview() {
		if ( empty( $_POST['certverify_bulk_preview'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_bulk_preview' );
		$token = isset( $_POST['bulk_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_token'] ) ) : '';
		$input = array(
			'bulk_operation'         => isset( $_POST['bulk_operation'] ) ? wp_unslash( $_POST['bulk_operation'] ) : '',
			'bulk_status'            => isset( $_POST['bulk_status'] ) ? wp_unslash( $_POST['bulk_status'] ) : '',
			'bulk_status_reason_set' => ! empty( $_POST['bulk_status_reason_set'] ),
			'bulk_status_reason'     => isset( $_POST['bulk_status_reason'] ) ? wp_unslash( $_POST['bulk_status_reason'] ) : '',
			'bulk_expiry_date'       => isset( $_POST['bulk_expiry_date'] ) ? wp_unslash( $_POST['bulk_expiry_date'] ) : '',
			'bulk_field_key'         => isset( $_POST['bulk_field_key'] ) ? wp_unslash( $_POST['bulk_field_key'] ) : '',
			'bulk_field_value'       => isset( $_POST['bulk_field_value'] ) ? wp_unslash( $_POST['bulk_field_value'] ) : '',
		);
		$preview = Ecapla_CertVerify_Bulk_Manager::prepare_preview( $token, $input );
		if ( is_wp_error( $preview ) ) {
			$this->redirect_error( $preview->get_error_message(), add_query_arg( array( 'page' => 'certloom-bulk-review', 'token' => $token ), admin_url( 'admin.php' ) ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-bulk-review', 'token' => $token, 'reviewed' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Commits only the already-reviewed frozen bulk operation. */
	public function handle_bulk_commit() {
		if ( empty( $_POST['certverify_bulk_commit'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_bulk_commit' );
		$this->require_writes_available( admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		$token   = isset( $_POST['bulk_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_token'] ) ) : '';
		$session = Ecapla_CertVerify_Bulk_Manager::get_session( $token );
		if ( is_wp_error( $session ) ) {
			$this->redirect_error( $session->get_error_message(), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		}
		$this->require_safety_snapshot( 'bulk_change', $token, add_query_arg( array( 'page' => 'certloom-bulk-review', 'token' => $token ), admin_url( 'admin.php' ) ) );
		$result = Ecapla_CertVerify_Bulk_Manager::commit( $token );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), add_query_arg( array( 'page' => 'certloom-bulk-review', 'token' => $token ), admin_url( 'admin.php' ) ) );
		}
		$args = array_merge(
			array( 'page' => self::CERTIFICATES_PAGE ),
			(array) ( $session['return_state'] ?? array() ),
			array(
				'ecapla_certverify_bulk_changed' => absint( $result['changed'] ?? 0 ),
				'ecapla_certverify_bulk_failed'  => count( (array) ( $result['failed'] ?? array() ) ),
				'ecapla_certverify_bulk_skipped' => count( (array) ( $result['skipped'] ?? array() ) ),
			)
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_onboarding_save() {
		if ( empty( $_POST['certverify_onboarding_save'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_onboarding_save' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-setup' ) );
		$name    = isset( $_POST['organization_name'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_name'] ) ) : '';
		$page_id = isset( $_POST['verification_page_id'] ) ? absint( $_POST['verification_page_id'] ) : 0;
		$result  = Ecapla_CertVerify_Onboarding::save_profile( $name, $page_id, ! empty( $_POST['appearance_reviewed'] ), ! empty( $_POST['verification_tested'] ) );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-setup' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_setup_saved', '1', admin_url( 'admin.php?page=certloom-setup' ) ) );
		exit;
	}

	public function handle_onboarding_create_page() {
		if ( empty( $_POST['certverify_onboarding_create_page'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_onboarding_create_page' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-setup' ) );
		$result = Ecapla_CertVerify_Onboarding::create_verification_page();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-setup' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_setup_page_created', '1', admin_url( 'admin.php?page=certloom-setup' ) ) );
		exit;
	}

	public function handle_onboarding_complete() {
		if ( empty( $_POST['certverify_onboarding_complete'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_onboarding_complete' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-setup' ) );
		$result = Ecapla_CertVerify_Onboarding::complete();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-setup' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_setup_complete', '1', admin_url( 'admin.php?page=certloom-setup' ) ) );
		exit;
	}


	/** Handles the resumable WIZ-01 workflow without creating a second configuration authority. */
	public function handle_setup_wizard_action() {
		// admin_init also runs for admin-ajax.php. The draft autosave carries the
		// wizard form fields, but only the dedicated AJAX handler may process it.
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( empty( $_POST['certloom_setup_wizard_action'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certloom_setup_wizard' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-setup' ) );
		$action = sanitize_key( wp_unslash( $_POST['certloom_setup_wizard_action'] ) );
		$base = admin_url( 'admin.php?page=certloom-setup' );
		$run_id = isset( $_POST['certloom_draft_run'] ) && is_string( $_POST['certloom_draft_run'] ) ? wp_unslash( $_POST['certloom_draft_run'] ) : '';
		$revision = isset( $_POST['certloom_draft_base_revision'] ) && is_string( $_POST['certloom_draft_base_revision'] ) ? filter_var( wp_unslash( $_POST['certloom_draft_base_revision'] ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) ) : false;
		if ( false === $revision || '' === $run_id ) { $this->redirect_error( __( 'Reload Setup to continue with the current saved draft.', 'certloom' ), $base ); }
		$result = \Certloom\Application\Admin\DraftWorkspace::run_if_current( 'setup', $run_id, $revision, function () use ( $action, $base ) { return $this->handle_setup_wizard_action_owned( $action, $base ); } );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
	}

	private function handle_setup_wizard_action_owned( $action, $base ) {

		if ( 'restart' === $action ) {
			$result = Ecapla_CertVerify_Onboarding::restart_wizard();
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
			wp_safe_redirect( add_query_arg( 'certloom_wizard_restarted', '1', $base ) );
			exit;
		}

		if ( in_array( $action, array( 'save_step', 'skip_step' ), true ) ) {
			$step = isset( $_POST['wizard_step'] ) ? sanitize_key( wp_unslash( $_POST['wizard_step'] ) ) : '';
			$next = isset( $_POST['wizard_next_step'] ) ? sanitize_key( wp_unslash( $_POST['wizard_next_step'] ) ) : '';
			if ( 'save_step' === $action && 'context' === $step ) {
				$patch = $this->setup_wizard_draft_request( $step );
				if ( ! empty( $_POST['wizard_finish_later'] ) ) {
					$result = Ecapla_CertVerify_Onboarding::save_wizard_draft_only( 'context', $patch );
					if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
					wp_safe_redirect( add_query_arg( 'certloom_wizard_saved', '1', admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ) );
					exit;
				}
				$state = Ecapla_CertVerify_Onboarding::get_state();
				$merged = array_merge( is_array( $state['wizard_draft'] ?? null ) ? $state['wizard_draft'] : array(), $patch );
				$required = $this->validate_setup_wizard_context_requirements( $merged, false );
				if ( is_wp_error( $required ) ) { $this->redirect_error( $required->get_error_message(), add_query_arg( 'step', 'context', $base ) ); }
			}
			if ( 'save_step' === $action && 'issue' === $step ) {
				$patch = $this->setup_wizard_draft_request( $step );
				if ( ! empty( $_POST['wizard_finish_later'] ) ) {
					$result = Ecapla_CertVerify_Onboarding::save_wizard_draft_only( 'issue', $patch );
					if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
					wp_safe_redirect( add_query_arg( 'certloom_wizard_saved', '1', admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ) );
					exit;
				}
				$this->issue_setup_wizard_first_credential( $base );
			}
			$patch = 'skip_step' === $action ? ( 'email' === $step ? array( 'setup_email_mode' => 'inherit', 'setup_email_design_id' => '' ) : array() ) : $this->setup_wizard_draft_request( $step );
			if ( is_wp_error( $patch ) ) { $this->redirect_error( $patch->get_error_message(), $base ); }
			if ( 'fields' === $step && 'skip_step' !== $action && isset( $patch['setup_field_plan'] ) ) {
				$program_id = absint( Ecapla_CertVerify_Onboarding::get_state()['wizard_draft']['setup_program_id'] ?? 0 );
				$program = $program_id ? ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->find( $program_id ) : null;
				$valid = is_wp_error( $program ) ? $program : ( new \Certloom\Application\Setup\WizardFieldSetup() )->build( $patch['setup_field_plan'], Ecapla_CertVerify_Fields::get_field_map(), Ecapla_CertVerify_Fields::get_retired_fields(), $program );
				if ( is_wp_error( $valid ) ) { $this->redirect_error( $valid->get_error_message(), $base ); }
			}
			$onboarding_before = ( 'profile' === $step && 'skip_step' !== $action ) ? Ecapla_CertVerify_Onboarding::snapshot() : null;
			$result = Ecapla_CertVerify_Onboarding::save_wizard_step( $step, $patch, $next, 'skip_step' === $action );
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
			if ( 'profile' === $step && 'skip_step' !== $action ) {
				$engine = new \Certloom\Application\Setup\PurposePresetEngine();
				$profile = $engine->normalize_profile( (string) ( $patch['usage_profile'] ?? '' ) );
				$profiles = $engine->profiles();
				if ( ! isset( $profiles[ $profile ] ) ) {
					if ( is_array( $onboarding_before ) ) { Ecapla_CertVerify_Onboarding::restore_snapshot( $onboarding_before ); }
					$this->redirect_error( __( 'Choose a valid Certloom usage profile.', 'certloom' ), $base );
				}
				$settings = Ecapla_CertVerify_Settings::get_all();
				$settings['usage_profile'] = $profile;
				if ( ! Ecapla_CertVerify_Settings::save( $settings ) ) {
					if ( is_array( $onboarding_before ) ) { Ecapla_CertVerify_Onboarding::restore_snapshot( $onboarding_before ); }
					$this->redirect_error( __( 'Certloom could not activate that usage profile, so the Setup step was not kept.', 'certloom' ), $base );
				}
			}
			$state = Ecapla_CertVerify_Onboarding::get_state();
			if ( ! empty( $_POST['wizard_finish_later'] ) && 'skip_step' !== $action ) {
				wp_safe_redirect( add_query_arg( 'certloom_wizard_saved', '1', admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ) );
				exit;
			}
			wp_safe_redirect( add_query_arg( array( 'certloom_wizard_saved' => '1', 'step' => $state['wizard_step'] ), $base ) );
			exit;
		}

		if ( 'apply_guided_setup' === $action ) {
			if ( ! empty( $_POST['wizard_finish_later'] ) ) {
				$result = Ecapla_CertVerify_Onboarding::save_wizard_draft_only( 'configuration', array() );
			} else {
				$result = $this->apply_guided_setup_configuration();
			}
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), add_query_arg( 'step', 'configuration', $base ) ); }
			wp_safe_redirect( ! empty( $_POST['wizard_finish_later'] ) ? admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) : add_query_arg( 'step', 'issue', $base ) );
			exit;
		}

		if ( 'apply_review' === $action ) {
			$result = $this->apply_setup_wizard_review();
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), add_query_arg( 'step', 'review', $base ) ); }
			wp_safe_redirect( add_query_arg( 'certloom_wizard_applied', '1', admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ) );
			exit;
		}

		$this->redirect_error( __( 'Certloom could not identify that setup action.', 'certloom' ), $base );
	}

	/** @return array<string,mixed> */
	private function setup_wizard_first_credential_plan( array $draft ) {
		$template = null;
		if ( 'design' === sanitize_key( (string) ( $draft['presentation_mode'] ?? '' ) ) && ! empty( $draft['design_template_id'] ) ) {
			$template = ( new \Certloom\Infrastructure\Repository\TemplateRepository() )->find_published( absint( $draft['design_template_id'] ) );
			if ( is_wp_error( $template ) || ! is_array( $template ) ) { $template = null; }
		}
		return ( new \Certloom\Application\Setup\FirstCredentialSetupPlan() )->build(
			$template,
			is_array( $draft['selected_fields'] ?? null ) ? $draft['selected_fields'] : array(),
			Ecapla_CertVerify_Fields::get_field_map(),
			is_array( $draft['custom_fields'] ?? null ) ? $draft['custom_fields'] : array()
		);
	}

	/** Read-only artwork guidance for the current administrator's staged field choices. */
	private function setup_wizard_design_coverage( ?array $template, array $draft ) {
		$catalog = Ecapla_CertVerify_Fields::get_field_map();
		$keys = $draft['selected_fields'] ?? array();
		if ( isset( $draft['setup_field_plan'] ) ) {
			$id = absint( $draft['setup_program_id'] ?? 0 );
			$program = $id ? ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->find( $id ) : null;
			$plan = is_wp_error( $program ) ? $program : ( new \Certloom\Application\Setup\WizardFieldSetup() )->build( $draft['setup_field_plan'], $catalog, Ecapla_CertVerify_Fields::get_retired_fields(), $program );
			if ( ! is_wp_error( $plan ) ) { $catalog = $plan['catalog']; $keys = array(); foreach ( $plan['review'] as $row ) { if ( 'exclude' !== $row['requirement'] ) { $keys[] = $row['key']; } } }
		}
		return ( new \Certloom\Application\Setup\WizardDesignCoverage() )->inspect( $template, $keys, $catalog );
	}

	/** @return true|\WP_Error */
	private function validate_setup_wizard_context_requirements( array $draft, $require_issuance_values = true ) {
		return ( new \Certloom\Infrastructure\Admin\WizardContextRequirementsCheck() )->validate( $draft, $this->setup_wizard_first_credential_plan( $draft ), $require_issuance_values );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function materialize_setup_wizard_context( array $draft, ?array $field_plan = null ) {
		$issuer_id = absint( $draft['setup_issuer_id'] ?? 0 );
		$program_id = absint( $draft['setup_program_id'] ?? 0 );
		$batch_id = absint( $draft['setup_batch_id'] ?? 0 );
		$signer_id = absint( $draft['setup_signer_id'] ?? 0 );

		$issuer_repo = new \Certloom\Infrastructure\Repository\IssuerRepository();
		$program_repo = new \Certloom\Infrastructure\Repository\ProgramRepository();
		$batch_repo = new \Certloom\Infrastructure\Repository\BatchRepository();
		$signer_repo = new \Certloom\Infrastructure\Repository\SignerRepository();

		if ( ! $issuer_id && '' !== trim( (string) ( $draft['setup_new_issuer_name'] ?? '' ) ) ) {
			$name = trim( (string) $draft['setup_new_issuer_name'] );
			$base_slug = sanitize_title( $name );
			$created = null;
			for ( $attempt = 0; $attempt < 10; $attempt++ ) {
				$slug = 0 === $attempt ? $base_slug : $base_slug . '-' . ( $attempt + 1 );
				$issuer_settings = array();
				$issuer_title = trim( (string) ( $draft['setup_new_issuer_title'] ?? '' ) );
				if ( '' !== $issuer_title ) { $issuer_settings['title'] = $issuer_title; }
				$is_organization = $name === trim( (string) ( $draft['organization_name'] ?? '' ) );
				if ( $is_organization ) {
					$issuer_settings['website'] = (string) ( $draft['organization_website'] ?? '' );
					$issuer_settings['contact'] = (string) ( $draft['organization_contact'] ?? '' );
				}
				$created = $issuer_repo->create( array( 'name' => $name, 'slug' => $slug, 'settings' => $issuer_settings, 'logo_attachment_id' => $is_organization ? absint( $draft['organization_logo_id'] ?? 0 ) : 0 ) );
				if ( ! is_wp_error( $created ) || 'certloom_issuer_slug_duplicate' !== $created->get_error_code() ) { break; }
			}
			if ( is_wp_error( $created ) ) { return $created; }
			$issuer_id = absint( $created );
		}

		$program = null;
		if ( $program_id ) {
			$program = null !== $field_plan ? $program_repo->find_for_update( $program_id ) : $program_repo->find( $program_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_setup_program_missing', __( 'The selected Program is no longer available.', 'certloom' ) ); }
			if ( ! empty( $program['issuer_id'] ) ) { $issuer_id = absint( $program['issuer_id'] ); }
			if ( null !== $field_plan ) {
				$valid = ( new \Certloom\Infrastructure\Admin\ScopedFieldConfigurationGuard() )->validate_program_change( $program_id, $field_plan['contract'], $program['profile'], $issuer_id );
				if ( is_wp_error( $valid ) ) { return $valid; }
				$saved = $program_repo->update( $program_id, array( 'metadata' => $field_plan['metadata'] ) );
				if ( is_wp_error( $saved ) ) { return $saved; }
			}
		} elseif ( '' !== trim( (string) ( $draft['setup_new_program_title'] ?? '' ) ) ) {
			$program_id = $program_repo->create( array(
				'title' => trim( (string) $draft['setup_new_program_title'] ),
				'code' => trim( (string) ( $draft['setup_new_program_code'] ?? '' ) ),
				'issuer_id' => $issuer_id ?: null,
				'validity_days' => null,
				'active' => true,
				'metadata' => null !== $field_plan ? $field_plan['metadata'] : array(),
			) );
			if ( is_wp_error( $program_id ) ) { return $program_id; }
			$program = $program_repo->find( $program_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( null !== $field_plan ) {
				$valid = ( new \Certloom\Infrastructure\Admin\ScopedFieldConfigurationGuard() )->validate_program_change( $program_id, $field_plan['contract'], $program['profile'], $issuer_id );
				if ( is_wp_error( $valid ) ) { return $valid; }
			}
		}

		if ( $batch_id ) {
			$batch = $batch_repo->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || ! $program_id || absint( $batch['program_id'] ?? 0 ) !== $program_id ) { return new \WP_Error( 'certloom_setup_batch_program_mismatch', __( 'Choose a Batch or cycle that belongs to the selected Program context.', 'certloom' ) ); }
		} elseif ( '' !== trim( (string) ( $draft['setup_new_batch_name'] ?? '' ) ) ) {
			if ( ! $program_id ) { return new \WP_Error( 'certloom_setup_batch_program_required', __( 'Choose or add the Program context before adding a Batch or cycle.', 'certloom' ) ); }
			$batch_id = $batch_repo->create( array(
				'program_id' => $program_id,
				'name' => trim( (string) $draft['setup_new_batch_name'] ),
				'code' => trim( (string) $draft['setup_new_batch_code'] ),
				'active' => true,
			) );
			if ( is_wp_error( $batch_id ) ) { return $batch_id; }
		}

		$new_signer = false;
		if ( ! $signer_id && '' !== trim( (string) ( $draft['setup_new_signer_name'] ?? '' ) ) ) {
			$name = trim( (string) $draft['setup_new_signer_name'] );
			$base_slug = sanitize_key( sanitize_title( $name ) );
			if ( '' === $base_slug ) { $base_slug = 'signer'; }
			$created = null;
			for ( $attempt = 0; $attempt < 10; $attempt++ ) {
				$slug = 0 === $attempt ? $base_slug : $base_slug . '-' . ( $attempt + 1 );
				$created = $signer_repo->create( array(
					'display_name' => $name,
					'slug' => $slug,
					'title' => trim( (string) ( $draft['setup_new_signer_title'] ?? '' ) ),
					'email' => trim( (string) ( $draft['setup_new_signer_email'] ?? '' ) ),
					'signature_attachment_id' => absint( $draft['setup_new_signer_signature_id'] ?? 0 ),
					'active' => true,
				) );
				if ( ! is_wp_error( $created ) || 'certloom_signer_slug_duplicate' !== $created->get_error_code() ) { break; }
			}
			if ( is_wp_error( $created ) ) { return $created; }
			$signer_id = absint( $created );
			$new_signer = true;
		}
		if ( $new_signer && $signer_id ) {
			$scope = $batch_id ? 'batch' : ( $program_id ? 'program' : ( $issuer_id ? 'issuer' : 'global' ) );
			$scope_id = $batch_id ?: ( $program_id ?: ( $issuer_id ?: 0 ) );
			$assignment = $signer_repo->add_assignment( $signer_id, $scope, $scope_id, 'signer', 100 );
			if ( is_wp_error( $assignment ) && 'certloom_signer_assignment_duplicate' !== $assignment->get_error_code() ) { return $assignment; }
		}

		return array(
			'setup_issuer_id' => $issuer_id,
			'setup_program_id' => $program_id,
			'setup_batch_id' => $batch_id,
			'setup_signer_id' => $signer_id,
		);
	}

	/**
	 * Issues the Wizard's first credential through the same canonical service as
	 * the full Issue Credential workspace. The quick Setup path remains
	 * Independent when no Program context is chosen, or becomes Program-backed
	 * when the administrator deliberately selected/created Program context.
	 *
	 * @return void
	 */
	private function issue_setup_wizard_first_credential( $base ) {
		$issue_url = add_query_arg( 'step', 'issue', $base );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready() ) {
			$this->redirect_error( __( 'Credential issuance is temporarily unavailable because the credential store is not ready.', 'certloom' ), $issue_url );
		}

		$patch = $this->setup_wizard_draft_request( 'issue' );
		$valid = Ecapla_CertVerify_Onboarding::validate_wizard_draft( 'issue', $patch, false );
		if ( is_wp_error( $valid ) ) { $this->redirect_error( $valid->get_error_message(), $issue_url ); }
		$state = Ecapla_CertVerify_Onboarding::get_state();
		$draft = is_array( $state['wizard_draft'] ?? null ) ? array_merge( $state['wizard_draft'], $patch ) : $patch;
		$context_valid = $this->validate_setup_wizard_context_requirements( $draft );
		if ( is_wp_error( $context_valid ) ) {
			$value_error = in_array( $context_valid->get_error_code(), array( 'certloom_setup_design_field_required', 'certloom_setup_design_grade_required', 'certloom_setup_design_repeating_required' ), true );
			$this->redirect_error( $context_valid->get_error_message(), $value_error ? $issue_url : add_query_arg( 'step', 'context', $base ) );
		}

		$materialized = $this->materialize_setup_wizard_context( $draft );
		if ( is_wp_error( $materialized ) ) { $this->redirect_error( $materialized->get_error_message(), add_query_arg( 'step', 'context', $base ) ); }
		$draft = array_merge( $draft, $materialized );
		$saved_context = Ecapla_CertVerify_Onboarding::save_wizard_draft_only( 'issue', $materialized );
		if ( is_wp_error( $saved_context ) ) { $this->redirect_error( $saved_context->get_error_message(), $issue_url ); }

		$presentation = isset( $draft['presentation_mode'] ) ? sanitize_key( (string) $draft['presentation_mode'] ) : 'details_only';
		if ( ! in_array( $presentation, array( 'details_only', 'image', 'design' ), true ) ) { $presentation = 'details_only'; }
		$template_id = 'design' === $presentation ? absint( $draft['design_template_id'] ?? 0 ) : 0;
		$image_id = 'image' === $presentation ? absint( $draft['certificate_image_id'] ?? 0 ) : 0;
		$program_id = absint( $draft['setup_program_id'] ?? 0 );
		$program = null;
		$expiry_mode = 'permanent';
		if ( $program_id ) {
			$program = ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->find( $program_id );
			if ( is_wp_error( $program ) ) { $this->redirect_error( $program->get_error_message(), $issue_url ); }
			if ( is_array( $program ) && ! empty( $program['program_default_available'] ) ) { $expiry_mode = 'program'; }
		}

		$recipient_custom = is_array( $draft['first_recipient_custom'] ?? null ) ? $draft['first_recipient_custom'] : array();
		$new_recipient = array(
			'display_name' => sanitize_text_field( (string) ( $patch['first_recipient_name'] ?? '' ) ),
			'email' => sanitize_email( (string) ( $patch['first_recipient_email'] ?? '' ) ),
			'first_name' => sanitize_text_field( (string) ( $patch['first_recipient_first_name'] ?? '' ) ),
			'middle_name' => sanitize_text_field( (string) ( $patch['first_recipient_middle_name'] ?? '' ) ),
			'last_name' => sanitize_text_field( (string) ( $patch['first_recipient_last_name'] ?? '' ) ),
			'external_id' => sanitize_text_field( (string) ( $patch['first_recipient_external_id'] ?? '' ) ),
			'custom_fields' => $recipient_custom,
		);
		$request = array(
			'recipient_mode' => 'new',
			'recipient_id' => 0,
			'new_recipient' => $new_recipient,
			'credential_basis' => $program_id ? 'program' : 'independent',
			'title_source' => $program_id ? 'program' : 'custom',
			'credential_title' => $program_id ? '' : sanitize_text_field( (string) ( $patch['credential_title'] ?? '' ) ),
			'presentation_mode' => $presentation,
			'certificate_image_id' => $image_id,
			'issuer_id' => absint( $draft['setup_issuer_id'] ?? 0 ),
			'signer_id' => absint( $draft['setup_signer_id'] ?? 0 ),
			'program_id' => $program_id,
			'batch_id' => absint( $draft['setup_batch_id'] ?? 0 ),
			'template_id' => $template_id,
			'design_source' => $template_id ? 'assigned' : 'inherited',
			'valid_from' => sanitize_text_field( (string) ( $patch['issue_date'] ?? '' ) ),
			'expiry_mode' => $expiry_mode,
			'expires_at' => '',
			'grade' => sanitize_text_field( (string) ( $draft['first_grade'] ?? '' ) ),
			'display_number' => Ecapla_CertVerify_Numbering::is_enabled() ? '' : sanitize_text_field( (string) ( $patch['display_number'] ?? '' ) ),
			'custom_merge_fields' => is_array( $draft['setup_credential_values'] ?? null ) ? $draft['setup_credential_values'] : array(),
			'design_field_aliases' => array(),
			'repeating_data' => is_array( $draft['setup_repeating_data'] ?? null ) ? $draft['setup_repeating_data'] : array(),
		);

		$service = new \Certloom\Application\Issuance\ManualIssuanceService();
		$review = $service->review( $request );
		if ( is_wp_error( $review ) ) { $this->redirect_error( $review->get_error_message(), $issue_url ); }
		$receipt = $service->issue( $request, (string) ( $review['review_fingerprint'] ?? '' ), get_current_user_id() );
		if ( is_wp_error( $receipt ) ) { $this->redirect_error( $receipt->get_error_message(), $issue_url ); }

		$recorded = Ecapla_CertVerify_Onboarding::record_first_credential( array_merge( $patch, $materialized ), $receipt );
		if ( is_wp_error( $recorded ) ) {
			$this->redirect_error( sprintf( /* translators: %s: Setup state error after a credential was issued. */ __( 'The credential was issued, but Setup could not save its completion state: %s. Re-submit the same first credential to recover without issuing a duplicate.', 'certloom' ), $recorded->get_error_message() ), $issue_url );
		}

		wp_safe_redirect( add_query_arg( array( 'certloom_wizard_issued' => '1', 'step' => 'finish' ), $base ) );
		exit;
	}

	/** @return array<string,mixed> */
	private function setup_wizard_draft_request( $step ) {
		$step = sanitize_key( (string) $step );
		switch ( $step ) {
			case 'welcome':
				return array( 'quick_setup' => ! empty( $_POST['quick_setup'] ) ? 1 : 0 );
			case 'profile':
				return array(
					'usage_profile' => isset( $_POST['usage_profile'] ) ? sanitize_key( wp_unslash( $_POST['usage_profile'] ) ) : '',
				);
			case 'organization':
				return array(
					'organization_name' => isset( $_POST['organization_name'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_name'] ) ) : '',
					'organization_logo_id' => isset( $_POST['organization_logo_id'] ) ? absint( $_POST['organization_logo_id'] ) : 0,
					'organization_website' => isset( $_POST['organization_website'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_website'] ) ) : '',
					'organization_contact' => isset( $_POST['organization_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_contact'] ) ) : '',
				);
			case 'fields':
				if ( array_key_exists( 'setup_fields_version', $_POST ) || array_key_exists( 'setup_field', $_POST ) ) {
					$intent = ( new \Certloom\Application\Setup\WizardFieldSetup() )->from_form( wp_unslash( $_POST ) );
					if ( is_wp_error( $intent ) ) { return $intent; }
					return array( 'setup_field_plan' => $intent, 'selected_fields' => array_column( $intent['rows'], 'key' ), 'custom_fields' => array() );
				}
				$selected = isset( $_POST['selected_fields'] ) && is_array( $_POST['selected_fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['selected_fields'] ) ) : array();
				$custom = isset( $_POST['custom_fields'] ) && is_array( $_POST['custom_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['custom_fields'] ) ) : array();
				return array( 'selected_fields' => $selected, 'custom_fields' => $custom );
			case 'appearance':
				$mode = isset( $_POST['presentation_mode'] ) ? sanitize_key( wp_unslash( $_POST['presentation_mode'] ) ) : 'details_only';
				$image_id = isset( $_POST['certificate_image_id'] ) ? absint( $_POST['certificate_image_id'] ) : 0;
				$template_id = isset( $_POST['design_template_id'] ) ? absint( $_POST['design_template_id'] ) : 0;
				if ( 'image' === $mode ) { $template_id = 0; }
				elseif ( 'design' === $mode ) { $image_id = 0; }
				else { $image_id = 0; $template_id = 0; }
				return array( 'presentation_mode' => $mode, 'certificate_image_id' => $image_id, 'design_template_id' => $template_id, 'setup_design_default' => ! empty( $_POST['setup_design_default'] ) ? 1 : 0 );
			case 'email':
				return array(
					'setup_email_mode' => isset( $_POST['setup_email_mode'] ) ? sanitize_key( wp_unslash( $_POST['setup_email_mode'] ) ) : 'inherit',
					'setup_email_design_id' => isset( $_POST['setup_email_design_id'] ) ? sanitize_key( wp_unslash( $_POST['setup_email_design_id'] ) ) : '',
				);
			case 'context':
				return array_merge( array(
					'setup_issuer_id' => isset( $_POST['setup_issuer_id'] ) ? absint( $_POST['setup_issuer_id'] ) : 0,
					'setup_program_id' => isset( $_POST['setup_program_id'] ) ? absint( $_POST['setup_program_id'] ) : 0,
					'setup_batch_id' => isset( $_POST['setup_batch_id'] ) ? absint( $_POST['setup_batch_id'] ) : 0,
					'setup_signer_id' => isset( $_POST['setup_signer_id'] ) ? absint( $_POST['setup_signer_id'] ) : 0,
					'setup_new_issuer_name' => isset( $_POST['setup_new_issuer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_issuer_name'] ) ) : '',
					'setup_new_issuer_title' => isset( $_POST['setup_new_issuer_title'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_issuer_title'] ) ) : '',
					'setup_new_program_title' => isset( $_POST['setup_new_program_title'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_program_title'] ) ) : '',
					'setup_new_program_code' => isset( $_POST['setup_new_program_code'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_program_code'] ) ) : '',
					'setup_new_batch_name' => isset( $_POST['setup_new_batch_name'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_batch_name'] ) ) : '',
					'setup_new_batch_code' => isset( $_POST['setup_new_batch_code'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_batch_code'] ) ) : '',
					'setup_new_signer_name' => isset( $_POST['setup_new_signer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_signer_name'] ) ) : '',
					'setup_new_signer_title' => isset( $_POST['setup_new_signer_title'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_signer_title'] ) ) : '',
					'setup_new_signer_email' => isset( $_POST['setup_new_signer_email'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_new_signer_email'] ) ) : '',
					'setup_new_signer_signature_id' => isset( $_POST['setup_new_signer_signature_id'] ) ? absint( $_POST['setup_new_signer_signature_id'] ) : 0,
				), $this->setup_wizard_credential_values_request() );
			case 'issue':
				$recipient_custom = isset( $_POST['first_recipient_custom'] ) && is_array( $_POST['first_recipient_custom'] ) ? wp_unslash( $_POST['first_recipient_custom'] ) : array();
				return array_merge( array(
					'first_recipient_name' => isset( $_POST['first_recipient_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_recipient_name'] ) ) : '',
					'first_recipient_email' => isset( $_POST['first_recipient_email'] ) ? sanitize_text_field( wp_unslash( $_POST['first_recipient_email'] ) ) : '',
					'first_recipient_first_name' => isset( $_POST['first_recipient_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_recipient_first_name'] ) ) : '',
					'first_recipient_middle_name' => isset( $_POST['first_recipient_middle_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_recipient_middle_name'] ) ) : '',
					'first_recipient_last_name' => isset( $_POST['first_recipient_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_recipient_last_name'] ) ) : '',
					'first_recipient_external_id' => isset( $_POST['first_recipient_external_id'] ) ? sanitize_text_field( wp_unslash( $_POST['first_recipient_external_id'] ) ) : '',
					'first_recipient_custom' => $recipient_custom,
					'credential_title' => isset( $_POST['credential_title'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_title'] ) ) : '',
					'display_number' => isset( $_POST['display_number'] ) ? sanitize_text_field( wp_unslash( $_POST['display_number'] ) ) : '',
					'issue_date' => isset( $_POST['issue_date'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_date'] ) ) : '',
				), $this->setup_wizard_credential_values_request() );
		}
		return array();
	}

	/** Preserve old draft values when a reduced or older form omits their controls. */
	private function setup_wizard_credential_values_request() {
		$patch = array();
		$present = isset( $_POST['setup_values_present'] ) && '1' === $_POST['setup_values_present'];
		foreach ( array( 'setup_credential_values', 'setup_repeating_data' ) as $key ) {
			if ( $present || array_key_exists( $key, $_POST ) ) { $patch[ $key ] = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); }
		}
		if ( $present || array_key_exists( 'first_grade', $_POST ) ) { $patch['first_grade'] = isset( $_POST['first_grade'] ) ? sanitize_text_field( wp_unslash( $_POST['first_grade'] ) ) : ''; }
		return $patch;
	}

	/**
	 * Applies only current-authority settings supported by the Wizard.
	 * Program/Template intent stays a recommendation while normalized admin
	 * authority is closed; the Wizard must never bypass those gates.
	 *
	 * @return true|\WP_Error
	 */
	/** Apply only the choices shown in guided setup; record IDs atomically for safe retries. */
	private function apply_guided_setup_configuration() {
		$option_keys = array( Ecapla_CertVerify_Settings::OPTION_KEY, Ecapla_CertVerify_Onboarding::OPTION_KEY, \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository::OPTION );
		$result = ( new \Certloom\Infrastructure\Database\Transaction() )->run( function () {
			$draft = Ecapla_CertVerify_Onboarding::get_state()['wizard_draft'];
			if ( empty( $draft['organization_name'] ) ) { return new \WP_Error( 'certloom_setup_organization_required', __( 'Enter an issuer name before saving setup.', 'certloom' ) ); }
			$valid = $this->validate_setup_wizard_context_requirements( $draft, false );
			if ( is_wp_error( $valid ) ) { return $valid; }
			if ( isset( $draft['setup_field_plan'] ) ) {
				$valid = ( new \Certloom\Infrastructure\Admin\WizardFieldSetupCheck() )->inspect( $draft );
				if ( is_wp_error( $valid ) ) { return $valid; }
			}
			$field_plan = ( new \Certloom\Infrastructure\Admin\WizardFieldSetupCommand() )->prepare( $draft );
			if ( is_wp_error( $field_plan ) ) { return $field_plan; }
			$email_id = 'selected' === ( $draft['setup_email_mode'] ?? 'inherit' ) ? (string) ( $draft['setup_email_design_id'] ?? '' ) : '';
			if ( $email_id ) {
				$email = ( new \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository() )->get( $email_id );
				if ( ! is_array( $email ) || empty( $email['active'] ) || \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED !== ( $email['purpose'] ?? '' ) ) { return new \WP_Error( 'certloom_setup_email_design_unavailable', __( 'Choose a published credential-issued email design before saving setup.', 'certloom' ) ); }
			} elseif ( 'selected' === ( $draft['setup_email_mode'] ?? 'inherit' ) ) {
				return new \WP_Error( 'certloom_setup_email_design_required', __( 'Choose an email design or keep the current email design.', 'certloom' ) );
			}
			$template_id = 'design' === ( $draft['presentation_mode'] ?? '' ) && ! empty( $draft['setup_design_default'] ) ? absint( $draft['design_template_id'] ?? 0 ) : 0;
			if ( $template_id ) {
				$valid = ( new \Certloom\Infrastructure\Admin\DesignHierarchyConfigurationGuard() )->validate_global( $template_id );
				if ( is_wp_error( $valid ) ) { return $valid; }
			}
			// The organization is the issuer when no existing context supplies one.
			if ( empty( $draft['setup_issuer_id'] ) && empty( $draft['setup_program_id'] ) && empty( $draft['setup_new_issuer_name'] ) ) { $draft['setup_new_issuer_name'] = $draft['organization_name']; }
			$ids = $this->materialize_setup_wizard_context( $draft, $field_plan );
			if ( is_wp_error( $ids ) ) { return $ids; }
			if ( $template_id ) {
				$repo = new \Certloom\Infrastructure\Repository\TemplateRepository();
				$current = $repo->get_assignment( 'global', 0 );
				if ( is_wp_error( $current ) ) { return $current; }
				$assigned = $repo->assign( $template_id, 'global', 0, 100, is_array( $current ) ? absint( $current['id'] ) : 0, is_array( $current ) ? (string) $current['updated_at'] : '' );
				if ( is_wp_error( $assigned ) ) { return $assigned; }
			}
			if ( $email_id ) {
				$saved = ( new \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository() )->save( 'global', 0, \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED, $email_id );
				if ( is_wp_error( $saved ) ) { return $saved; }
			}
			$saved = Ecapla_CertVerify_Onboarding::save_profile( $draft['organization_name'], absint( $draft['verification_page_id'] ?? 0 ) );
			if ( is_wp_error( $saved ) ) { return $saved; }
			$ids['setup_configuration_applied'] = 1;
			if ( null !== $field_plan ) {
				$planner = new \Certloom\Application\Setup\WizardFieldSetup();
				$intent = $draft['setup_field_plan'];
				$intent['program_id'] = (string) $ids['setup_program_id'];
				$intent['catalog_revision'] = $planner->catalog_revision( Ecapla_CertVerify_Fields::get_field_map(), Ecapla_CertVerify_Fields::get_retired_fields() );
				$applied_program = ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->find( $ids['setup_program_id'] );
				if ( is_wp_error( $applied_program ) || ! is_array( $applied_program ) ) { return is_wp_error( $applied_program ) ? $applied_program : new \WP_Error( 'certloom_setup_program_missing', __( 'The saved Program could not be confirmed.', 'certloom' ) ); }
				$intent['program_revision'] = $planner->program_revision( $applied_program );
				$ids['setup_field_plan'] = $intent;
			}
			// Clear materialized names so revisiting Program cannot duplicate them.
			foreach ( array_keys( $draft ) as $key ) { if ( 0 === strpos( $key, 'setup_new_' ) ) { $ids[ $key ] = 'setup_new_signer_signature_id' === $key ? 0 : ''; } }
			return Ecapla_CertVerify_Onboarding::save_wizard_step( 'configuration', $ids, 'issue' );
		} );
		if ( is_wp_error( $result ) ) {
			\Certloom\Infrastructure\Data\DatabaseOptions::invalidate( $option_keys );
			wp_cache_delete( get_current_user_id(), 'user_meta' );
		}
		return $result;
	}

	private function apply_setup_wizard_review() {
		$state = Ecapla_CertVerify_Onboarding::get_state();
		$draft = isset( $state['wizard_draft'] ) && is_array( $state['wizard_draft'] ) ? $state['wizard_draft'] : array();
		$current_settings = Ecapla_CertVerify_Settings::get_all();
		$next_settings = $current_settings;
		if ( array_key_exists( 'usage_profile', $draft ) ) {
			$engine = new \Certloom\Application\Setup\PurposePresetEngine();
			$profile = $engine->normalize_profile( $draft['usage_profile'] );
			if ( isset( $engine->profiles()[ $profile ] ) ) { $next_settings['usage_profile'] = $profile; }
		}
		foreach ( array( 'numbering_mode', 'numbering_prefix', 'numbering_suffix', 'numbering_start', 'numbering_padding' ) as $key ) {
			if ( array_key_exists( $key, $draft ) ) { $next_settings[ $key ] = $draft[ $key ]; }
		}
		if ( array_key_exists( 'verification_qr_enabled', $draft ) ) { $next_settings['verification_qr_enabled'] = ! empty( $draft['verification_qr_enabled'] ); }
		if ( array_key_exists( 'verification_barcode_enabled', $draft ) ) { $next_settings['verification_barcode_enabled'] = ! empty( $draft['verification_barcode_enabled'] ); }
		$numbering = Ecapla_CertVerify_Numbering::normalize_policy( $next_settings );
		if ( is_wp_error( $numbering ) ) { return $numbering; }
		foreach ( $numbering as $key => $value ) { $next_settings[ $key ] = $value; }

		$current_backup = \Certloom\Infrastructure\Backup\ScheduledBackup::settings();
		$next_schedule = isset( $draft['backup_schedule'] ) ? sanitize_key( (string) $draft['backup_schedule'] ) : (string) $current_backup['schedule'];
		$next_retention = isset( $draft['backup_retention'] ) ? absint( $draft['backup_retention'] ) : absint( $current_backup['retention'] );
		if ( 'off' !== $next_schedule ) {
			$storage = ( new \Certloom\Infrastructure\Backup\LocalBackupStore() )->test_storage();
			if ( is_wp_error( $storage ) ) { return $storage; }
		}

		$current_google = \Certloom\Infrastructure\Backup\GoogleDriveBackup::state();
		$current_google_settings = \Certloom\Infrastructure\Backup\GoogleDriveBackup::settings_snapshot();
		$next_google_enabled = array_key_exists( 'google_drive_enabled', $draft ) ? ! empty( $draft['google_drive_enabled'] ) : ! empty( $current_google['enabled'] );
		$next_google_retention = isset( $draft['google_drive_retention'] ) ? absint( $draft['google_drive_retention'] ) : absint( $current_google['retention'] ?? 14 );
		if ( $next_google_enabled && empty( $current_google['connected'] ) ) {
			return new \WP_Error( 'certloom_setup_google_not_connected', __( 'Connect Google Drive before enabling automatic Drive copies in Setup.', 'certloom' ) );
		}

		$organization = isset( $draft['organization_name'] ) ? trim( (string) $draft['organization_name'] ) : '';
		$page_id = isset( $draft['verification_page_id'] ) ? absint( $draft['verification_page_id'] ) : 0;
		if ( $page_id && ( ! get_post( $page_id ) || 'page' !== get_post_type( $page_id ) ) ) {
			return new \WP_Error( 'certloom_setup_review_page_invalid', __( 'The selected verification page is no longer available.', 'certloom' ) );
		}

		$template_repo = null;
		$template_before = null;
		$template_id = array_key_exists( 'design_template_id', $draft ) ? absint( $draft['design_template_id'] ) : null;
		if ( null !== $template_id ) {
			if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
				return new \WP_Error( 'certloom_setup_template_authority_closed', __( 'The selected default design cannot be applied until Template administration is available.', 'certloom' ) );
			}
			$template_repo = new \Certloom\Infrastructure\Repository\TemplateRepository();
			$template_before = $template_repo->get_assignment( 'global', 0 );
			if ( is_wp_error( $template_before ) ) { return $template_before; }
			if ( $template_id ) {
				$template = $template_repo->find_published( $template_id );
				if ( is_wp_error( $template ) ) { return $template; }
				if ( ! is_array( $template ) ) { return new \WP_Error( 'certloom_setup_template_missing', __( 'The selected default design is no longer available.', 'certloom' ) ); }
			}
			$design_preflight = ( new \Certloom\Infrastructure\Admin\DesignHierarchyConfigurationGuard() )->validate_global( $template_id );
			if ( is_wp_error( $design_preflight ) ) { return $design_preflight; }
		}

		$onboarding_before = Ecapla_CertVerify_Onboarding::snapshot();
		$rollback = function () use ( $current_settings, $current_backup, $current_google_settings, $onboarding_before, $template_repo, $template_before ) {
			$failures = array();
			if ( $template_repo ) {
				$current = $template_repo->get_assignment( 'global', 0 );
				if ( ! is_wp_error( $current ) ) {
					if ( is_array( $template_before ) ) {
						$r = $template_repo->assign( absint( $template_before['template_id'] ), 'global', 0, absint( $template_before['priority'] ?? 100 ), is_array( $current ) ? absint( $current['id'] ?? 0 ) : 0, is_array( $current ) ? (string) ( $current['updated_at'] ?? '' ) : '' );
					} else {
						$r = $template_repo->clear_assignment( 'global', 0, is_array( $current ) ? absint( $current['id'] ?? 0 ) : 0, is_array( $current ) ? (string) ( $current['updated_at'] ?? '' ) : '' );
					}
					if ( is_wp_error( $r ) ) { $failures[] = $r->get_error_message(); }
				} else { $failures[] = $current->get_error_message(); }
			}
			$r = Ecapla_CertVerify_Onboarding::restore_snapshot( $onboarding_before ); if ( is_wp_error( $r ) ) { $failures[] = $r->get_error_message(); }
			$r = \Certloom\Infrastructure\Backup\GoogleDriveBackup::restore_settings_snapshot( $current_google_settings ); if ( is_wp_error( $r ) ) { $failures[] = $r->get_error_message(); }
			$r = \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( $current_backup['schedule'], $current_backup['retention'] ); if ( is_wp_error( $r ) ) { $failures[] = $r->get_error_message(); }
			if ( ! Ecapla_CertVerify_Settings::save( $current_settings ) ) { $failures[] = __( 'Previous Certloom settings could not be restored.', 'certloom' ); }
			return $failures;
		};

		if ( ! Ecapla_CertVerify_Settings::save( $next_settings ) ) {
			return new \WP_Error( 'certloom_setup_settings_save_failed', __( 'Certloom could not apply the reviewed delivery and verification settings.', 'certloom' ) );
		}
		$backup = \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( $next_schedule, $next_retention );
		if ( is_wp_error( $backup ) ) { $rollback(); return $backup; }
		$google = \Certloom\Infrastructure\Backup\GoogleDriveBackup::save_settings( $next_google_enabled, $next_google_retention );
		if ( is_wp_error( $google ) ) { $rollback(); return $google; }
		if ( '' !== $organization ) {
			$profile = Ecapla_CertVerify_Onboarding::save_profile( $organization, $page_id, array_key_exists( 'appearance_reviewed', $draft ) ? ! empty( $draft['appearance_reviewed'] ) : null, array_key_exists( 'verification_tested', $draft ) ? ! empty( $draft['verification_tested'] ) : null );
			if ( is_wp_error( $profile ) ) { $rollback(); return $profile; }
		}
		if ( $template_repo ) {
			$current_assignment = $template_repo->get_assignment( 'global', 0 );
			if ( is_wp_error( $current_assignment ) ) { $rollback(); return $current_assignment; }
			if ( $template_id ) {
				$template_result = $template_repo->assign( $template_id, 'global', 0, 100, is_array( $current_assignment ) ? absint( $current_assignment['id'] ?? 0 ) : 0, is_array( $current_assignment ) ? (string) ( $current_assignment['updated_at'] ?? '' ) : '' );
			} else {
				$template_result = $template_repo->clear_assignment( 'global', 0, is_array( $current_assignment ) ? absint( $current_assignment['id'] ?? 0 ) : 0, is_array( $current_assignment ) ? (string) ( $current_assignment['updated_at'] ?? '' ) : '' );
			}
			if ( is_wp_error( $template_result ) ) { $rollback(); return $template_result; }
		}
		$completed = Ecapla_CertVerify_Onboarding::complete_wizard();
		if ( is_wp_error( $completed ) ) {
			$failures = $rollback();
			$message = __( 'Certloom could not complete the reviewed setup, so it restored the previous configuration.', 'certloom' );
			if ( $failures ) { $message .= ' ' . __( 'Some rollback checks also need administrator review.', 'certloom' ); }
			return new \WP_Error( 'certloom_setup_progress_finalize_failed', $message );
		}
		\Certloom\Infrastructure\Admin\SetupReadinessReminder::clear();
		return true;
	}

	public function handle_dismiss_setup_reminder() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_dismiss_setup_reminder' );
		$mode = isset( $_POST['reminder_mode'] ) ? sanitize_key( wp_unslash( $_POST['reminder_mode'] ) ) : 'snooze';
		$result = \Certloom\Infrastructure\Admin\SetupReadinessReminder::dismiss( 0, 'dismiss' === $mode );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ); exit;
	}

	/** Autosaves unfinished Setup intent without advancing or applying configuration. */
	public function handle_setup_wizard_draft_autosave() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to save this Setup draft.', 'certloom' ) ), 403 );
		}
		check_ajax_referer( 'certloom_setup_wizard' );
		foreach ( array( 'wizard_step', 'certloom_draft_revision', 'certloom_draft_run', 'certloom_draft_base_revision' ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
				wp_send_json_error( array( 'message' => __( 'The draft request is incomplete. Keep your edits on this page and reload Setup in another tab.', 'certloom' ) ), 400 );
			}
		}
		$base_revision = filter_var( wp_unslash( $_POST['certloom_draft_base_revision'] ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $base_revision ) {
			wp_send_json_error( array( 'message' => __( 'The draft revision is invalid. Keep your edits on this page and reload Setup in another tab.', 'certloom' ) ), 400 );
		}
		$step = isset( $_POST['wizard_step'] ) ? sanitize_key( wp_unslash( $_POST['wizard_step'] ) ) : '';
		$client_revision = isset( $_POST['certloom_draft_revision'] ) ? max( 0, (int) wp_unslash( $_POST['certloom_draft_revision'] ) ) : 0;
		$patch = $this->setup_wizard_draft_request( $step );
		if ( is_wp_error( $patch ) ) { wp_send_json_error( array( 'message' => $patch->get_error_message(), 'code' => $patch->get_error_code() ), 400 ); }
		$result = Ecapla_CertVerify_Onboarding::autosave_wizard_draft( $step, $patch, $client_revision, array( 'expected_revision' => $base_revision, 'run_id' => wp_unslash( $_POST['certloom_draft_run'] ) ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 409 );
		}
		wp_send_json_success( array(
			'run_id'     => (string) ( $result['run_id'] ?? '' ),
			'step'       => (string) ( $result['step'] ?? $step ),
			'updated_at' => (string) ( $result['updated_at'] ?? '' ),
			'client_revision' => (int) ( $result['client_revision'] ?? 0 ),
			'revision' => (int) ( $result['revision'] ?? 0 ),
			'message'    => __( 'Draft saved', 'certloom' ),
		) );
	}

	/** Keeps an in-progress Setup draft reachable after visiting another Certloom workspace. */
	public function render_active_setup_draft_notice() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'certloom-setup' === $page || 0 !== strpos( $page, 'certloom' ) ) { return; }
		$workspace = Ecapla_CertVerify_Onboarding::get_wizard_workspace();
		if ( empty( $workspace ) || 'active' !== (string) ( $workspace['status'] ?? '' ) ) { return; }
		$payload = isset( $workspace['payload'] ) && is_array( $workspace['payload'] ) ? $workspace['payload'] : array();
		$draft = isset( $payload['wizard_draft'] ) && is_array( $payload['wizard_draft'] ) ? $payload['wizard_draft'] : array();
		if ( empty( $draft ) && 'welcome' === (string) ( $workspace['step'] ?? 'welcome' ) ) { return; }
		$step = sanitize_key( (string) ( $workspace['step'] ?? 'welcome' ) );
		$return_url = add_query_arg( array( 'page' => 'certloom-setup', 'step' => $step ), admin_url( 'admin.php' ) );
		echo '<div class="notice notice-info certloom-active-draft-notice"><p><strong>' . esc_html__( 'Setup draft active.', 'certloom' ) . '</strong> ' . esc_html__( 'Your unfinished Setup choices are saved to your administrator account.', 'certloom' ) . ' <a class="button button-small" href="' . esc_url( $return_url ) . '">' . esc_html__( 'Return to Setup', 'certloom' ) . '</a></p></div>';
	}

	public function handle_setup_wizard_template_preview() {
		$this->require_options_permission();
		check_ajax_referer( 'certloom_setup_wizard_preview', 'nonce' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { wp_send_json_error( array( 'message' => __( 'Template Preview is not available while Template administration is protected.', 'certloom' ) ), 403 ); }
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$draft = Ecapla_CertVerify_Onboarding::get_state()['wizard_draft'];
		if ( ! $template_id ) { wp_send_json_success( array( 'template_id' => 0, 'name' => __( 'None', 'certloom' ), 'description' => __( 'Choose designs per Program, Batch or Issuer later.', 'certloom' ), 'html' => '', 'coverage' => $this->setup_wizard_design_coverage( null, Ecapla_CertVerify_Onboarding::get_state()['wizard_draft'] ) ) ); }
		$template = ( new \Certloom\Infrastructure\Repository\TemplateRepository() )->find_published( $template_id );
		if ( is_wp_error( $template ) ) { wp_send_json_error( array( 'message' => $template->get_error_message() ), 400 ); }
		if ( ! is_array( $template ) ) { wp_send_json_error( array( 'message' => __( 'That published Template is no longer available.', 'certloom' ) ), 404 ); }
		$plan = ( new \Certloom\Infrastructure\Rendering\TemplatePreviewService() )->build( $template, 'published', 'standard', (string) ( $draft['usage_profile'] ?? '' ) );
		if ( is_wp_error( $plan ) ) { wp_send_json_error( array( 'message' => $plan->get_error_message() ), 400 ); }
		try { $preview = ( new \Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview() )->present( $plan['render_plan'] ); }
		catch ( \Throwable $error ) { wp_send_json_error( array( 'message' => __( 'Certloom could not build that Template preview.', 'certloom' ) ), 400 ); }
		wp_send_json_success( array( 'template_id' => $template_id, 'name' => (string) ( $template['name'] ?? '' ), 'description' => (string) ( $template['description'] ?? '' ), 'revision' => absint( $plan['revision_number'] ?? 0 ), 'html' => (string) ( $preview['html'] ?? '' ), 'coverage' => $this->setup_wizard_design_coverage( $template, Ecapla_CertVerify_Onboarding::get_state()['wizard_draft'] ) ) );
	}

	public function handle_registry_columns_save() {
		if ( empty( $_POST['certverify_registry_columns_save'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_registry_columns_save' );
		$allowed = array( 'holder_name', 'status', 'issue_date', 'expiry_date' );
		$visible = isset( $_POST['visible_columns'] ) && is_array( $_POST['visible_columns'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['visible_columns'] ) ) ) : array();
		$hidden  = array_values( array_diff( $allowed, array_intersect( $allowed, $visible ) ) );
		$stored  = update_user_meta( get_current_user_id(), 'certloom_registry_hidden_columns', $hidden );
		if ( false === $stored && $hidden !== get_user_meta( get_current_user_id(), 'certloom_registry_hidden_columns', true ) ) {
			$this->redirect_error( __( 'Registry column preferences could not be saved.', 'certloom' ), $this->list_return_url() );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_columns_saved', '1', $this->list_return_url() ) );
		exit;
	}

	public function handle_fields_save() {
		if ( isset( $_POST['certloom_save_global_field_scope'] ) ) {
			$this->require_manage_permission();
			check_admin_referer( 'certloom_save_global_field_scope' );
			$this->require_writes_available( admin_url( 'admin.php?page=certloom-fields' ) );
			$raw = isset( $_POST['global_field_requirement'] ) && is_array( $_POST['global_field_requirement'] ) ? wp_unslash( $_POST['global_field_requirement'] ) : array();
			$rules = array();
			foreach ( $raw as $raw_key => $raw_rule ) {
				$key = sanitize_key( (string) $raw_key );
				$rule = sanitize_key( (string) $raw_rule );
				if ( '' !== $key && in_array( $rule, array( 'optional', 'required' ), true ) ) { $rules[ $key ] = $rule; }
			}
			$saved = ( new \Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository() )->save( $rules, Ecapla_CertVerify_Fields::get_field_map() );
			if ( is_wp_error( $saved ) ) { $this->redirect_error( $saved->get_error_message(), admin_url( 'admin.php?page=certloom-fields' ) ); }
			wp_safe_redirect( add_query_arg( 'certloom_global_field_scope_saved', '1', admin_url( 'admin.php?page=certloom-fields' ) ) );
			exit;
		}
		if ( ! isset( $_POST['certverify_save_fields'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_save_fields' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-fields' ) );

		$existing = Ecapla_CertVerify_Fields::get_field_map();
		$submitted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each nested field key and value is allow-listed and sanitized below.
		foreach ( $submitted as $row ) {
			if ( is_array( $row ) && array_key_exists( 'collection', $row ) && '1' !== ( $_POST['certloom_fields_collection_form_complete'] ?? null ) ) {
				$this->redirect_error( __( 'The server received an incomplete field settings form. No settings were saved. Increase the server’s form input limit and try again.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
			}
		}
		$new_fields = array();
		$order = 0;
		foreach ( $submitted as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $key || ! isset( $existing[ $key ] ) ) {
				continue;
			}
			$field                     = $existing[ $key ];
			$label                     = isset( $row['label'] ) && '' !== trim( (string) $row['label'] ) ? sanitize_text_field( $row['label'] ) : $field['label'];
			$field['label']            = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 100 ) : substr( $label, 0, 100 );
			$sensitivity_options = array_keys( Ecapla_CertVerify_Fields::get_sensitivity_options() );
			$exposure_options = array_keys( Ecapla_CertVerify_Fields::get_public_exposure_options() );
			$sensitivity = isset( $row['sensitivity'] ) ? sanitize_key( (string) $row['sensitivity'] ) : ( $field['sensitivity'] ?? 'personal' );
			$exposure = isset( $row['public_exposure'] ) ? sanitize_key( (string) $row['public_exposure'] ) : ( ! empty( $row['show_on_frontend'] ) ? 'both' : 'private' );
			$field['sensitivity'] = in_array( $sensitivity, $sensitivity_options, true ) ? $sensitivity : ( $field['sensitivity'] ?? 'personal' );
			if ( Ecapla_CertVerify_Fields::is_required_core_field( $key ) ) { $field['sensitivity'] = 'public_safe'; }
			$field['public_exposure'] = in_array( $exposure, $exposure_options, true ) ? $exposure : 'private';
			if ( ! empty( $field['locked_visibility'] ) || 'internal' === $field['sensitivity'] ) { $field['public_exposure'] = 'private'; }
			if ( isset( $field['collection_policy'] ) && is_array( $field['collection_policy'] ) && ! in_array( $field['public_exposure'], array( 'profile', 'both' ), true ) ) {
				$field['collection_policy']['show_on_certificate'] = false;
			}
			if ( array_key_exists( 'collection', $row ) ) {
				$policy = ( new \Certloom\Application\Fields\FieldCollectionForm() )->normalize( $row['collection'], $field );
				if ( is_wp_error( $policy ) ) { $this->redirect_error( $field['label'] . ': ' . $policy->get_error_message(), admin_url( 'admin.php?page=certloom-fields' ) ); }
				if ( null === $policy ) { unset( $field['collection_policy'] ); } else { $field['collection_policy'] = $policy; }
			}
			$field['show_on_frontend'] = ! empty( $field['locked_visibility'] ) ? false : 'private' !== $field['public_exposure'];
			$field['order']            = $order++;
			$new_fields[]              = $field;
			unset( $existing[ $key ] );
		}
		foreach ( $existing as $field ) {
			$field['order'] = $order++;
			$new_fields[]   = $field;
		}
		if ( ! Ecapla_CertVerify_Fields::save_fields( $new_fields ) ) {
			$this->redirect_error( __( 'The field order, labels, and visibility could not be saved.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_fields_saved', '1', admin_url( 'admin.php?page=certloom-fields' ) ) );
		exit;
	}

	public function handle_field_add() {
		if ( ! isset( $_POST['certverify_add_field'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_add_field' );
		$label = isset( $_POST['new_field_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_field_label'] ) ) : '';
		$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 100 ) : substr( $label, 0, 100 );
		if ( '' === $label ) {
			$this->redirect_error( __( 'Give the new field a label.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		$allowed_types = array( 'text', 'textarea', 'date', 'number' );
		$type = isset( $_POST['new_field_type'] ) ? sanitize_key( wp_unslash( $_POST['new_field_type'] ) ) : 'text';
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'text';
		}
		$fields        = Ecapla_CertVerify_Fields::get_fields();
		$base_key      = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
		$base_key      = $base_key ? $base_key : 'field';
		$base_key      = function_exists( 'mb_substr' ) ? mb_substr( $base_key, 0, 50 ) : substr( $base_key, 0, 50 );
		$existing_keys = Ecapla_CertVerify_Fields::get_reserved_keys();
		$key           = $base_key;
		$suffix        = 2;
		while ( in_array( $key, $existing_keys, true ) ) {
			$suffix_text = '_' . $suffix++;
			$stem_length = max( 1, 50 - strlen( $suffix_text ) );
			$stem        = function_exists( 'mb_substr' ) ? mb_substr( $base_key, 0, $stem_length ) : substr( $base_key, 0, $stem_length );
			$key         = $stem . $suffix_text;
		}
		$max_order = $fields ? max( wp_list_pluck( $fields, 'order' ) ) : 0;
		$fields[]  = array(
			'key'               => $key,
			'label'             => $label,
			'type'              => $type,
			'column'            => null,
			'core'              => false,
			'protected'         => false,
			'locked_visibility' => false,
			'sensitivity'       => 'personal',
			'public_exposure'   => 'private',
			'show_on_frontend'  => false,
			'order'             => absint( $max_order ) + 1,
		);
		if ( ! Ecapla_CertVerify_Fields::save_fields( $fields ) ) {
			$this->redirect_error( __( 'The custom field could not be added because the field schema was not saved.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_field_added', '1', admin_url( 'admin.php?page=certloom-fields' ) ) );
		exit;
	}

	public function handle_field_delete() {
		$this->require_manage_permission();
		check_admin_referer( 'ecapla_certverify_delete_field' );
		$key   = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';
		$field = Ecapla_CertVerify_Fields::get_field( $key );
		if ( ! $field || ! empty( $field['core'] ) || ! empty( $field['protected'] ) ) {
			$this->redirect_error( __( 'Core certificate fields cannot be removed. You can relabel, reorder, or hide them from public results.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		$usage = ( new \Certloom\Infrastructure\Repository\ScopedFieldUsageRepository() )->usage_for( $key );
		if ( is_wp_error( $usage ) ) {
			$this->redirect_error( $usage->get_error_message(), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		if ( ! empty( $usage['referenced'] ) ) {
			$parts = array();
			if ( ! empty( $usage['program_count'] ) ) { $parts[] = sprintf( /* translators: %d: program count. */ _n( '%d Program', '%d Programs', absint( $usage['program_count'] ), 'certloom' ), absint( $usage['program_count'] ) ); }
			if ( ! empty( $usage['batch_count'] ) ) { $parts[] = sprintf( /* translators: %d: batch count. */ _n( '%d Batch override', '%d Batch overrides', absint( $usage['batch_count'] ), 'certloom' ), absint( $usage['batch_count'] ) ); }
			if ( ! empty( $usage['template_count'] ) ) { $parts[] = sprintf( /* translators: %d: template count. */ _n( '%d active Template', '%d active Templates', absint( $usage['template_count'] ), 'certloom' ), absint( $usage['template_count'] ) ); }
			$this->redirect_error( sprintf( /* translators: %1$s: label; %2$s: parts. */ __( '“%1$s” is still used by %2$s. Remove those active assignments/references before retiring the field. Existing issued credential history will remain protected.', 'certloom' ), (string) ( $field['label'] ?? $key ), implode( ', ', $parts ) ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		if ( ! Ecapla_CertVerify_Fields::retire_field( $field ) ) {
			$this->redirect_error( __( 'The field could not be retired safely. No field was removed.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		$fields = array_values(
			array_filter(
				Ecapla_CertVerify_Fields::get_fields(),
				function ( $candidate ) use ( $key ) {
					return $candidate['key'] !== $key;
				}
			)
		);
		if ( ! Ecapla_CertVerify_Fields::save_fields( $fields ) ) {
			if ( ! Ecapla_CertVerify_Fields::remove_retired_field( $key ) ) {
				$this->redirect_error( __( 'The active field layout could not be updated, and the temporary retired marker could not be rolled back. Review the field registry before continuing.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
			}
			$this->redirect_error( __( 'The active field layout could not be updated. No field was removed.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_field_deleted', '1', admin_url( 'admin.php?page=certloom-fields' ) ) );
		exit;
	}

	/**
	 * Restores a retired field definition without exposing it publicly by default.
	 */
	public function handle_field_restore() {
		$this->require_manage_permission();
		check_admin_referer( 'ecapla_certverify_restore_field' );
		$key     = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';
		$retired = Ecapla_CertVerify_Fields::get_retired_fields();
		if ( '' === $key || ! isset( $retired[ $key ] ) || Ecapla_CertVerify_Fields::get_field( $key ) ) {
			$this->redirect_error( __( 'That retired field cannot be restored.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}

		$original_fields = Ecapla_CertVerify_Fields::get_fields();
		$fields          = $original_fields;
		$max_order       = $fields ? max( wp_list_pluck( $fields, 'order' ) ) : 0;
		$fields[]        = array(
			'key'               => $key,
			'label'             => $retired[ $key ]['label'],
			'type'              => $retired[ $key ]['type'],
			'column'            => null,
			'core'              => false,
			'protected'         => false,
			'locked_visibility' => false,
			'sensitivity'       => isset( $retired[ $key ]['sensitivity'] ) ? $retired[ $key ]['sensitivity'] : 'personal',
			'public_exposure'   => 'private',
			'show_on_frontend'  => false,
			'order'             => absint( $max_order ) + 1,
		);
		if ( ! Ecapla_CertVerify_Fields::save_fields( $fields ) ) {
			$this->redirect_error( __( 'The retired field could not be restored.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		if ( ! Ecapla_CertVerify_Fields::remove_retired_field( $key ) ) {
			if ( ! Ecapla_CertVerify_Fields::save_fields( $original_fields ) ) {
				$this->redirect_error( __( 'The retired marker could not be cleared, and the restored field could not be rolled back. Review the active and retired field registries before continuing.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
			}
			$this->redirect_error( __( 'The retired field could not be restored safely. The active field layout was rolled back.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		Ecapla_CertVerify_Database::clear_custom_field_purge_state( $key );
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_field_restored', '1', admin_url( 'admin.php?page=certloom-fields' ) ) );
		exit;
	}

	/** Resets translated core field labels while preserving custom labels and layout. */
	public function handle_field_retranslate() {
		$this->require_options_permission();
		check_admin_referer( 'ecapla_certverify_retranslate_fields' );
		if ( ! Ecapla_CertVerify_Fields::retranslate_core_labels() ) {
			$this->redirect_error( __( 'Core field labels could not be reset for the current site language.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_fields_retranslated', '1', admin_url( 'admin.php?page=certloom-fields' ) ) );
		exit;
	}

	/** Permanently deletes one safe batch of a retired key's values. */
	public function handle_field_purge() {
		$this->require_options_permission();
		check_admin_referer( 'ecapla_certverify_purge_field' );
		$key     = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';
		$retired = Ecapla_CertVerify_Fields::get_retired_fields();
		if ( '' === $key || ! isset( $retired[ $key ] ) || Ecapla_CertVerify_Fields::get_field( $key ) ) {
			$this->redirect_error( __( 'That retired field cannot be purged.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}

		$this->require_safety_snapshot( 'field_purge', $key, admin_url( 'admin.php?page=certloom-fields' ) );
		$result = Ecapla_CertVerify_Database::purge_custom_field_values( $key );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		$changed  = absint( $result['changed'] ?? 0 );
		$complete = ! empty( $result['complete'] );
		if ( ! $complete ) {
			wp_safe_redirect( add_query_arg( array( 'ecapla_certverify_purge_progress' => '1', 'rows' => $changed ), admin_url( 'admin.php?page=certloom-fields' ) ) );
			exit;
		}
		if ( ! Ecapla_CertVerify_Fields::remove_retired_field( $key ) ) {
			$this->redirect_error( __( 'Stored values were purged, but the retired registry could not be updated. The reserved key remains safe; run Purge again to retry the final registry cleanup.', 'certloom' ), admin_url( 'admin.php?page=certloom-fields' ) );
		}
		Ecapla_CertVerify_Database::clear_custom_field_purge_state( $key );
		wp_safe_redirect( add_query_arg( array( 'ecapla_certverify_field_purged' => '1', 'rows' => $changed ), admin_url( 'admin.php?page=certloom-fields' ) ) );
		exit;
	}

	public function handle_import_analyze() {
		if ( ! isset( $_POST['certverify_import_analyze'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_import_analyze' );
		if ( empty( $_FILES['import_file']['tmp_name'] ) || ! isset( $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
			$this->redirect_error( __( 'Choose a CSV file to upload.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$tmp_name = wp_unslash( $_FILES['import_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
		$file_type = wp_check_filetype( $file_name, array( 'csv' => 'text/csv' ) );
		if ( ! is_uploaded_file( $tmp_name ) || 'csv' !== $file_type['ext'] ) {
			$this->redirect_error( __( 'Upload a valid .csv file.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$file_size = filesize( $tmp_name );
		if ( false === $file_size || $file_size > 5 * MB_IN_BYTES ) {
			$this->redirect_error( __( 'The CSV file is larger than the 5 MB import limit.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$token = Ecapla_CertVerify_Import::store_uploaded_csv( $tmp_name );
		if ( is_wp_error( $token ) ) {
			$this->redirect_error( $token->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'step' => 'map', 'token' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_import_process() {
		if ( ! isset( $_POST['certverify_import_process'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_import_process' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-import' ) );
		$token   = isset( $_POST['import_token'] ) ? sanitize_text_field( wp_unslash( $_POST['import_token'] ) ) : '';
		$preview = $token ? Ecapla_CertVerify_Import::get_preview( $token ) : false;
		if ( ! $preview ) {
			$this->redirect_error( __( 'That import session expired. Upload the file again.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$mapping = array();
		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			$submitted_mapping = wp_unslash( $_POST['map'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			foreach ( $submitted_mapping as $col_index => $field_key ) {
				$mapping[ absint( $col_index ) ] = sanitize_key( $field_key );
			}
		}
		$historical_restore = ! empty( $_POST['historical_restore'] );
		if ( $historical_restore && ! current_user_can( 'manage_options' ) ) {
			$this->redirect_error( __( 'Only an administrator can enable Historical restore mode.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$this->require_safety_snapshot( 'csv_import', $token, admin_url( 'admin.php?page=certloom-import' ) );
		$progress = Ecapla_CertVerify_Import::start_stored_process( $token, $mapping, ! empty( $_POST['update_existing'] ), ! empty( $_POST['clear_empty'] ), $historical_restore );
		$this->redirect_import_progress( $token, $progress );
	}


	public function handle_import_continue() {
		if ( ! isset( $_POST['certverify_import_continue'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_import_continue' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-import' ) );
		$token    = isset( $_POST['import_token'] ) ? sanitize_text_field( wp_unslash( $_POST['import_token'] ) ) : '';
		$progress = Ecapla_CertVerify_Import::continue_stored_process( $token );
		$this->redirect_import_progress( $token, $progress );
	}

	private function redirect_import_progress( $token, $progress ) {
		if ( false === $progress ) {
			$this->redirect_error( __( 'That import session expired during processing. Upload the file again.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		if ( is_wp_error( $progress ) ) {
			$this->redirect_error( $progress->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		if ( ! empty( $progress['complete'] ) ) {
			$results = (array) ( $progress['results'] ?? array() );
			Ecapla_CertVerify_Import::delete_rows( $token );
			$result_token = Ecapla_CertVerify_Import::store_results( $results );
			if ( is_wp_error( $result_token ) ) {
				$this->redirect_error( $result_token->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'result' => $result_token ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'step' => 'process', 'token' => sanitize_text_field( $token ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_settings_save() {
		if ( ! isset( $_POST['certverify_save_settings'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_save_settings' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-settings' ) );
		$accent       = isset( $_POST['accent_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['accent_color'] ) ) : '';
		$credit_color = isset( $_POST['credit_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['credit_color'] ) ) : '';
		$credit_size_raw = isset( $_POST['credit_size'] ) ? sanitize_text_field( wp_unslash( $_POST['credit_size'] ) ) : '';
		$credit_size     = is_numeric( $credit_size_raw ) ? min( 24, max( 8, absint( $credit_size_raw ) ) ) : '';
		$credit_align = isset( $_POST['credit_align'] ) ? sanitize_key( wp_unslash( $_POST['credit_align'] ) ) : 'center';
		if ( ! in_array( $credit_align, array( 'left', 'center', 'right' ), true ) ) {
			$credit_align = 'center';
		}
		$data = array(
			'accent_color'             => $accent ? $accent : '#1450e0',
			'show_credit'              => ! empty( $_POST['show_credit'] ),
			'credit_text'              => isset( $_POST['credit_text'] ) ? sanitize_text_field( wp_unslash( $_POST['credit_text'] ) ) : '',
			'credit_color'             => $credit_color,
			'credit_size'              => $credit_size,
			'credit_align'             => $credit_align,
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
			'privacy_erasure_policy'   => isset( $_POST['privacy_erasure_policy'] ) && 'anonymize_mutable_recipient' === sanitize_key( wp_unslash( $_POST['privacy_erasure_policy'] ) ) ? 'anonymize_mutable_recipient' : 'retain',
			'recipient_user_sync_enabled' => ! empty( $_POST['recipient_user_sync_enabled'] ),
			'certificate_name_source' => isset( $_POST['certificate_name_source'] ) && is_string( $_POST['certificate_name_source'] ) ? sanitize_key( wp_unslash( $_POST['certificate_name_source'] ) ) : Ecapla_CertVerify_Settings::get( 'certificate_name_source', 'profile' ),
			'certificate_name_field_key' => isset( $_POST['certificate_name_field_key'] ) && is_string( $_POST['certificate_name_field_key'] ) ? sanitize_key( wp_unslash( $_POST['certificate_name_field_key'] ) ) : Ecapla_CertVerify_Settings::get( 'certificate_name_field_key', '' ),
			'verification_branding_enabled' => ! empty( $_POST['verification_branding_enabled'] ),
			'verification_brand_name'    => isset( $_POST['verification_brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['verification_brand_name'] ) ) : '',
			'verification_brand_tagline' => isset( $_POST['verification_brand_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['verification_brand_tagline'] ) ) : '',
			'verification_surface_color' => isset( $_POST['verification_surface_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['verification_surface_color'] ) ) : '#ffffff',
			'verification_text_color'    => isset( $_POST['verification_text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['verification_text_color'] ) ) : '#01133d',
			'verification_use_site_icon' => ! empty( $_POST['verification_use_site_icon'] ),
			'verification_qr_enabled' => ! empty( $_POST['verification_qr_enabled'] ),
			'verification_qr_logo_enabled' => ! empty( $_POST['verification_qr_logo_enabled'] ),
			'verification_barcode_enabled' => ! empty( $_POST['verification_barcode_enabled'] ),
			'certificate_layout' => isset( $_POST['certificate_layout'] ) && is_string( $_POST['certificate_layout'] ) ? sanitize_key( wp_unslash( $_POST['certificate_layout'] ) ) : Ecapla_CertVerify_Settings::get( 'certificate_layout', 'right' ),
			'certificate_center_width' => isset( $_POST['certificate_center_width'] ) && is_scalar( $_POST['certificate_center_width'] ) ? wp_unslash( $_POST['certificate_center_width'] ) : Ecapla_CertVerify_Settings::get( 'certificate_center_width', 960 ),
			'numbering_mode'             => isset( $_POST['numbering_mode'] ) ? sanitize_key( wp_unslash( $_POST['numbering_mode'] ) ) : 'manual',
			'numbering_prefix'           => isset( $_POST['numbering_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_prefix'] ) ) : 'CERT-',
			'numbering_suffix'           => isset( $_POST['numbering_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_suffix'] ) ) : '',
			'numbering_start'            => isset( $_POST['numbering_start'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_start'] ) ) : '1',
			'numbering_padding'          => isset( $_POST['numbering_padding'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_padding'] ) ) : '6',
			'usage_profile'              => isset( $_POST['usage_profile'] ) ? sanitize_key( wp_unslash( $_POST['usage_profile'] ) ) : (string) Ecapla_CertVerify_Settings::get( 'usage_profile', 'general_other' ),
			'status_desc'              => array(),
		);
		if ( isset( $_POST['status_desc'] ) && is_array( $_POST['status_desc'] ) ) {
			$known               = array_keys( Ecapla_CertVerify_Fields::get_statuses() );
			$status_descriptions = wp_unslash( $_POST['status_desc'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys and descriptions are validated and sanitized below.
			foreach ( $status_descriptions as $key => $desc ) {
				$key = sanitize_key( $key );
				if ( in_array( $key, $known, true ) ) {
					$data['status_desc'][ $key ] = $this->limit_text( sanitize_textarea_field( $desc ), 1000 );
				}
			}
		}
		$numbering_policy = Ecapla_CertVerify_Numbering::normalize_policy( $data );
		if ( is_wp_error( $numbering_policy ) ) {
			$this->redirect_error( $numbering_policy->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$data = array_merge( $data, $numbering_policy );
		$name_policy_service = new \Certloom\Application\Recipient\CertificateNamePolicy();
		$name_catalog = 'approved_field' === $data['certificate_name_source'] ? Ecapla_CertVerify_Fields::get_field_map() : array();
		$name_policy = $name_policy_service->normalize( $data['certificate_name_source'], $data['certificate_name_field_key'], $name_catalog );
		if ( ! is_wp_error( $name_policy ) && 'approved_field' === $name_policy['source'] && ( Ecapla_CertVerify_Settings::get( 'certificate_name_source', 'profile' ) !== $name_policy['source'] || Ecapla_CertVerify_Settings::get( 'certificate_name_field_key', '' ) !== $name_policy['field_key'] ) ) {
			$name_policy = $name_policy_service->validate_change( $name_policy, $name_catalog );
		}
		if ( is_wp_error( $name_policy ) ) {
			$this->redirect_error( $name_policy->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}

		if ( ! Ecapla_CertVerify_Settings::save( $data ) ) {
			$this->redirect_error( __( 'The settings could not be saved. Role access was not changed.', 'certloom' ), admin_url( 'admin.php?page=certloom-settings' ) );
		}

		$role_access     = isset( $_POST['role_access'] ) && is_array( $_POST['role_access'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['role_access'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only sanitized array keys are used as role identifiers.
		$roles           = Ecapla_CertVerify_Permissions::get_all_roles();
		$original_access = array();
		$role_failure    = false;

		foreach ( $roles as $role_key => $role_info ) {
			if ( 'administrator' === $role_key ) {
				continue;
			}
			$original_access[ $role_key ] = Ecapla_CertVerify_Permissions::role_has_capability( $role_key );
			$should_have_access           = in_array( $role_key, $role_access, true );
			$updated                      = $should_have_access
				? Ecapla_CertVerify_Permissions::grant_role( $role_key )
				: Ecapla_CertVerify_Permissions::revoke_role( $role_key );
			if ( ! $updated ) {
				$role_failure = true;
				break;
			}
		}

		if ( $role_failure ) {
			$rollback_failed = false;
			foreach ( $original_access as $role_key => $had_access ) {
				$restored = $had_access
					? Ecapla_CertVerify_Permissions::grant_role( $role_key )
					: Ecapla_CertVerify_Permissions::revoke_role( $role_key );
				if ( ! $restored ) {
					$rollback_failed = true;
				}
			}
			$message = $rollback_failed
				? __( 'The general settings were saved, but one or more role changes failed and could not be fully rolled back. Review role access before continuing.', 'certloom' )
				: __( 'The general settings were saved, but role access could not be updated. The previous role access was restored.', 'certloom' );
			$this->redirect_error( $message, admin_url( 'admin.php?page=certloom-settings' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_settings_saved', '1', admin_url( 'admin.php?page=certloom-settings' ) ) );
		exit;
	}


	public function handle_database_proof() {
		if ( ! isset( $_POST['certverify_run_database_proof'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_run_database_proof' );
		$result = Ecapla_CertVerify_Database_Proof::run();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$overall = sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) );
		if ( ! in_array( $overall, array( 'passed', 'incomplete', 'failed' ), true ) ) {
			$overall = 'incomplete';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-settings', 'certloom_database_proof' => $overall ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_domain_acceptance_proof() {
		if ( ! isset( $_POST['certverify_run_domain_acceptance_proof'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_run_domain_acceptance_proof' );
		$result = \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::run();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$overall = sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) );
		if ( ! in_array( $overall, array( 'passed', 'incomplete', 'failed' ), true ) ) {
			$overall = 'incomplete';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-settings', 'certloom_domain_acceptance_proof' => $overall ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_legacy_migration_preflight_proof() {
		if ( ! isset( $_POST['certverify_run_legacy_migration_preflight_proof'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_run_legacy_migration_preflight_proof' );
		$result = \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::run_slice();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$state = \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::evidence_state();
		if ( ! in_array( $state, array( 'passed', 'failed', 'running', 'stale' ), true ) ) {
			$state = 'running';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-settings', 'certloom_legacy_migration_preflight' => $state ), admin_url( 'admin.php' ) ) );
		exit;
	}


	public function handle_staging_migration_rehearsal() {
		if ( ! isset( $_POST['certverify_run_staging_migration_rehearsal'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_run_staging_migration_rehearsal' );
		$result = \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::run_slice();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$state = \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::evidence_state();
		if ( ! in_array( $state, array( 'passed', 'failed', 'running', 'stale', 'unavailable' ), true ) ) {
			$state = 'running';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-settings', 'certloom_staging_migration_rehearsal' => $state ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_staging_restore_rehearsal() {
		if ( ! isset( $_POST['certverify_run_staging_restore_rehearsal'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_run_staging_restore_rehearsal' );
		$result = \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::run_slice();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$state = \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::evidence_state();
		if ( ! in_array( $state, array( 'passed', 'failed', 'running', 'stale', 'unavailable' ), true ) ) {
			$state = 'running';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-settings', 'certloom_staging_restore_rehearsal' => $state ), admin_url( 'admin.php' ) ) );
		exit;
	}


	public function handle_staging_cutover_rehearsal() {
		if ( ! isset( $_POST['certverify_run_staging_cutover_rehearsal'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_run_staging_cutover_rehearsal' );
		$result = \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::run_slice();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		$state = \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::evidence_state();
		if ( ! in_array( $state, array( 'passed', 'failed', 'running', 'stale', 'unavailable' ), true ) ) {
			$state = 'running';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-settings', 'certloom_staging_cutover_rehearsal' => $state ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_create_manager_role() {
		if ( ! isset( $_POST['certverify_create_manager_role'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_create_manager_role' );
		$role = Ecapla_CertVerify_Permissions::create_manager_role();
		if ( ! $role || ! $role->has_cap( 'read' ) || ! Ecapla_CertVerify_Permissions::role_has_capability( Ecapla_CertVerify_Permissions::MANAGER_ROLE ) ) {
			$this->redirect_error( __( 'The Certificate Manager role could not be created or updated.', 'certloom' ), admin_url( 'admin.php?page=certloom-settings' ) );
		}
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_role_created', '1', admin_url( 'admin.php?page=certloom-settings' ) ) );
		exit;
	}


	public function handle_backup_download() {
		$this->require_options_permission();
		check_admin_referer( 'ecapla_certverify_backup_download' );
		Ecapla_CertVerify_Backup::stream_export();
		exit;
	}

	public function handle_backup_settings() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_backup_settings' );
		$schedule  = isset( $_POST['backup_schedule'] ) ? sanitize_key( wp_unslash( $_POST['backup_schedule'] ) ) : 'off';
		$retention = isset( $_POST['backup_retention'] ) ? absint( wp_unslash( $_POST['backup_retention'] ) ) : 14;
		$result = \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( $schedule, $retention );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( 'backup_notice', 'settings-saved', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}

	public function handle_backup_test_storage() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_backup_test_storage' );
		$result = ( new \Certloom\Infrastructure\Backup\LocalBackupStore() )->test_storage();
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( 'backup_notice', 'storage-verified', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}

	public function handle_backup_run_now() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_backup_run_now' );
		$result = \Certloom\Infrastructure\Backup\ScheduledBackup::create_now( 'manual' );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( 'backup_notice', 'backup-created', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}

	public function handle_backup_saved_download() {
		$this->require_options_permission();
		$id = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : '';
		check_admin_referer( 'certloom_backup_saved_download_' . $id );
		$resolved = ( new \Certloom\Infrastructure\Backup\LocalBackupStore() )->resolve( $id );
		if ( is_wp_error( $resolved ) ) {
			$this->redirect_error( $resolved->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$path = (string) $resolved['path'];
		$size = absint( $resolved['bytes'] );
		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'certloom-backup-' . gmdate( 'Y-m-d-His', absint( $resolved['created_ts'] ) ) . '.cvbackup' ) . '"' );
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Verified private administrator-requested backup stream.
		if ( false === $handle ) {
			$this->redirect_error( __( 'The verified private backup could not be opened for download.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded private backup stream.
			if ( false === $buffer ) { break; }
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact verified administrator-requested backup bytes.
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming resource lifecycle.
		exit;
	}

	public function handle_backup_saved_review() {
		$this->require_options_permission();
		$id = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : '';
		check_admin_referer( 'certloom_backup_saved_review_' . $id );
		$resolved = ( new \Certloom\Infrastructure\Backup\LocalBackupStore() )->resolve( $id );
		if ( is_wp_error( $resolved ) ) {
			$this->redirect_error( $resolved->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$token = Ecapla_CertVerify_Backup::analyze_file( (string) $resolved['path'] );
		if ( is_wp_error( $token ) ) {
			$this->redirect_error( $token->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'backup_token' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_backup_saved_test_restore() {
		$this->require_options_permission();
		$id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		check_admin_referer( 'certloom_backup_saved_test_restore_' . $id );
		$result = \Certloom\Infrastructure\Backup\RestoreTest::run( $id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( 'backup_notice', 'restore-tested', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}

	public function handle_backup_saved_delete() {
		$this->require_options_permission();
		$id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		check_admin_referer( 'certloom_backup_saved_delete_' . $id );
		$result = ( new \Certloom\Infrastructure\Backup\LocalBackupStore() )->delete( $id );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( 'backup_notice', 'backup-deleted', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}


	public function handle_google_drive_connect() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_google_drive_connect' );
		$client_id       = isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '';
		$client_secret   = isset( $_POST['google_client_secret'] ) ? trim( (string) wp_unslash( $_POST['google_client_secret'] ) ) : '';
		$connection_mode = isset( $_POST['google_connection_mode'] ) ? sanitize_key( wp_unslash( $_POST['google_connection_mode'] ) ) : 'relay';
		$return_mode     = isset( $_POST['certloom_google_return_mode'] ) ? sanitize_key( wp_unslash( $_POST['certloom_google_return_mode'] ) ) : 'backup';
		$return_mode     = 'popup' === $return_mode ? 'popup' : 'backup';
		$use_direct      = 'direct' === $connection_mode || '' !== $client_id || '' !== $client_secret;
		if ( $use_direct ) {
			if ( '' !== $client_id || '' !== $client_secret ) {
				$saved = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::save_client_credentials( $client_id, $client_secret );
				if ( is_wp_error( $saved ) ) {
					if ( 'popup' === $return_mode ) { $this->render_google_drive_popup_result( false, $saved->get_error_message() ); }
					$this->redirect_error( $saved->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
				}
			} elseif ( ! \Certloom\Infrastructure\Backup\GoogleDriveOAuth::has_client_credentials() ) {
				$message = __( 'Add this site’s Google OAuth client ID and secret before using the site-owned connection.', 'certloom' );
				if ( 'popup' === $return_mode ) { $this->render_google_drive_popup_result( false, $message ); }
				$this->redirect_error( $message, admin_url( 'admin.php?page=certloom-import' ) );
			}
			$url = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::authorization_url( $return_mode );
		} else {
			$url = \Certloom\Infrastructure\Backup\EcaplaOAuthRelay::authorization_url( $return_mode );
		}
		if ( is_wp_error( $url ) ) {
			if ( 'popup' === $return_mode ) { $this->render_google_drive_popup_result( false, $url->get_error_message() ); }
			$this->redirect_error( $url->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact reviewed Google or Ecapla OAuth URL generated by the provider boundary.
		exit;
	}

	public function handle_google_drive_oauth_callback() {
		$this->require_options_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state is the one-time CSRF boundary for this provider callback.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$context = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::pending_context( $state );
		if ( ! empty( $_GET['error'] ) ) {
			$message = __( 'Google Drive connection was cancelled or refused.', 'certloom' );
			if ( 'popup' === $context ) { $this->render_google_drive_popup_result( false, $message ); }
			$this->redirect_error( $message, admin_url( 'admin.php?page=certloom-import' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$result = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::complete_authorization( $state, $code );
		if ( is_wp_error( $result ) ) {
			if ( 'popup' === $context ) { $this->render_google_drive_popup_result( false, $result->get_error_message() ); }
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		if ( 'popup' === $context ) { $this->render_google_drive_popup_result( true, __( 'Google Drive connected.', 'certloom' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-connected', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}


	public function handle_google_drive_relay_callback() {
		$this->require_options_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Local one-time state is the CSRF boundary; relay_code is a single-use server-bound handoff.
		$state      = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$relay_code = isset( $_GET['relay_code'] ) ? sanitize_text_field( wp_unslash( $_GET['relay_code'] ) ) : '';
		$context    = \Certloom\Infrastructure\Backup\EcaplaOAuthRelay::pending_context( $state );
		if ( ! empty( $_GET['relay_error'] ) ) {
			$message = __( 'Google Drive connection was cancelled or could not be completed.', 'certloom' );
			if ( 'popup' === $context ) { $this->render_google_drive_popup_result( false, $message ); }
			$this->redirect_error( $message, admin_url( 'admin.php?page=certloom-import' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$result = \Certloom\Infrastructure\Backup\EcaplaOAuthRelay::complete_authorization( $state, $relay_code );
		if ( is_wp_error( $result ) ) {
			if ( 'popup' === $context ) { $this->render_google_drive_popup_result( false, $result->get_error_message() ); }
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		if ( 'popup' === $context ) { $this->render_google_drive_popup_result( true, __( 'Google Drive connected.', 'certloom' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-connected', admin_url( 'admin.php?page=certloom-import' ) ) );
		exit;
	}

	private function render_google_drive_popup_result( $success, $message ) {
		$success = (bool) $success;
		$message = sanitize_text_field( (string) $message );
		$parts = wp_parse_url( admin_url() );
		$origin = isset( $parts['scheme'], $parts['host'] ) ? $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' ) : '';
		status_header( $success ? 200 : 400 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		?><!doctype html><html><head><meta charset="<?php echo esc_attr( get_option( 'blog_charset', 'UTF-8' ) ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Google Drive connection', 'certloom' ); ?></title></head><body><main style="font:16px/1.5 system-ui,sans-serif;max-width:34rem;margin:3rem auto;padding:1.5rem"><h1 style="font-size:1.35rem"><?php echo esc_html( $success ? __( 'Google Drive connected', 'certloom' ) : __( 'Google Drive was not connected', 'certloom' ) ); ?></h1><p><?php echo esc_html( $message ); ?></p><p><?php esc_html_e( 'This window can be closed.', 'certloom' ); ?></p></main><script>(function(){var payload=<?php echo wp_json_encode( array( 'type' => 'certloom-google-drive-oauth', 'status' => $success ? 'connected' : 'error', 'message' => $message ) ); ?>;try{if(window.opener&&!window.opener.closed){window.opener.postMessage(payload,<?php echo wp_json_encode( $origin ); ?>);if(payload.status==='connected'){window.setTimeout(function(){window.close();},250);}}}catch(e){}}());</script></body></html><?php
		exit;
	}

	public function handle_google_drive_settings() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_google_drive_settings' );
		$enabled   = ! empty( $_POST['google_drive_enabled'] );
		$retention = isset( $_POST['google_drive_retention'] ) ? absint( wp_unslash( $_POST['google_drive_retention'] ) ) : 14;
		$result = \Certloom\Infrastructure\Backup\GoogleDriveBackup::save_settings( $enabled, $retention );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-settings-saved', admin_url( 'admin.php?page=certloom-import' ) ) ); exit;
	}

	public function handle_google_drive_test() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_google_drive_test' );
		$result = \Certloom\Infrastructure\Backup\GoogleDriveBackup::test_connection();
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-tested', admin_url( 'admin.php?page=certloom-import' ) ) ); exit;
	}

	public function handle_google_drive_disconnect() {
		$this->require_options_permission();
		check_admin_referer( 'certloom_google_drive_disconnect' );
		$result = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::disconnect();
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-disconnected', admin_url( 'admin.php?page=certloom-import' ) ) ); exit;
	}

	public function handle_google_drive_upload() {
		$this->require_options_permission();
		$id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		check_admin_referer( 'certloom_google_drive_upload_' . $id );
		$result = \Certloom\Infrastructure\Backup\GoogleDriveBackup::upload_local( $id );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-uploaded', admin_url( 'admin.php?page=certloom-import' ) ) ); exit;
	}

	public function handle_google_drive_download_local() {
		$this->require_options_permission();
		$id = isset( $_POST['remote_id'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_id'] ) ) : '';
		check_admin_referer( 'certloom_google_drive_download_local_' . $id );
		$result = \Certloom\Infrastructure\Backup\GoogleDriveBackup::download_to_local( $id );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-downloaded', admin_url( 'admin.php?page=certloom-import' ) ) ); exit;
	}

	public function handle_google_drive_delete() {
		$this->require_options_permission();
		$id = isset( $_POST['remote_id'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_id'] ) ) : '';
		check_admin_referer( 'certloom_google_drive_delete_' . $id );
		$result = \Certloom\Infrastructure\Backup\GoogleDriveBackup::delete_remote( $id );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-deleted', admin_url( 'admin.php?page=certloom-import' ) ) ); exit;
	}

	public function handle_backup_analyze() {
		$this->require_options_permission();
		check_admin_referer( 'ecapla_certverify_backup_analyze' );
		if ( empty( $_FILES['backup_file']['tmp_name'] ) || ! isset( $_FILES['backup_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['backup_file']['error'] ) {
			$this->redirect_error( __( 'Choose a Certloom backup file.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$tmp_name  = wp_unslash( $_FILES['backup_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an uploaded temporary file below.
		$file_name = isset( $_FILES['backup_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['backup_file']['name'] ) ) : '';
		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! is_uploaded_file( $tmp_name ) || ! in_array( $extension, array( 'cvbackup', 'json' ), true ) ) {
			$this->redirect_error( __( 'Upload a valid .cvbackup or legacy .json Certloom backup.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$token = Ecapla_CertVerify_Backup::analyze_file( $tmp_name );
		if ( is_wp_error( $token ) ) {
			$this->redirect_error( $token->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'backup_token' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_backup_apply() {
		$this->require_options_permission();
		check_admin_referer( 'ecapla_certverify_backup_apply' );
		$token  = isset( $_POST['backup_token'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_token'] ) ) : '';
		$policy = isset( $_POST['conflict_policy'] ) ? sanitize_key( wp_unslash( $_POST['conflict_policy'] ) ) : 'skip';
		$mode   = isset( $_POST['restore_mode'] ) ? sanitize_key( wp_unslash( $_POST['restore_mode'] ) ) : 'replace';
		$this->require_safety_snapshot( 'restore', $token, admin_url( 'admin.php?page=certloom-import' ) );
		$result = Ecapla_CertVerify_Backup::start_restore( $token, $policy, $mode );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'step' => 'backup-process', 'backup_token' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_backup_continue() {
		$this->require_options_permission();
		check_admin_referer( 'ecapla_certverify_backup_continue' );
		$token  = isset( $_POST['backup_token'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_token'] ) ) : '';
		$result = Ecapla_CertVerify_Backup::continue_restore( $token );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
		}
		if ( ! empty( $result['complete'] ) ) {
			$result_token = Ecapla_CertVerify_Import::store_results( (array) ( $result['results'] ?? array() ) );
			if ( is_wp_error( $result_token ) ) {
				$this->redirect_error( $result_token->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'result' => $result_token, 'backup_restored' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'certloom-import', 'step' => 'backup-process', 'backup_token' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_export() {
		if ( ! isset( $_GET['page'], $_GET['action'] ) || 'certloom-import' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || 'export' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_export' );
		Ecapla_CertVerify_Import::stream_export();
		exit;
	}

	public function handle_template_download() {
		if ( ! isset( $_GET['page'], $_GET['action'] ) || 'certloom-import' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || 'template' !== sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_template' );
		Ecapla_CertVerify_Import::stream_template();
		exit;
	}

	public function render_dashboard_screen() {
		$this->require_manage_permission();
		$dashboard       = Ecapla_CertVerify_Dashboard::get_data();
		$dashboard_error = is_wp_error( $dashboard ) ? $dashboard : null;
		if ( is_wp_error( $dashboard ) ) {
			$dashboard = array(
				'metrics'             => Ecapla_CertVerify_Dashboard::normalize_metrics( array() ),
				'recent'              => array(),
				'health'              => Ecapla_CertVerify_Dashboard::health_summary( array() ),
				'legacy_review_count' => max( 0, (int) get_option( Ecapla_CertVerify_Database::LEGACY_REVIEW_OPTION, 0 ) ),
			);
		}
		$onboarding_summary = current_user_can( 'manage_options' ) ? Ecapla_CertVerify_Onboarding::get_summary() : null;
		$setup_reminder_visible = current_user_can( 'manage_options' ) ? \Certloom\Infrastructure\Admin\SetupReadinessReminder::should_show() : false;
		$dashboard_analytics = null;
		if ( current_user_can( Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS ) ) {
			$verification_analytics = Ecapla_CertVerify_Analytics::report( 30 );
			$issuance_analytics = ( new \Certloom\Application\Analytics\IssuanceAnalyticsService() )->report( 30 );
			if ( ! is_wp_error( $verification_analytics ) && ! is_wp_error( $issuance_analytics ) ) {
				$dashboard_analytics = array( 'verification' => $verification_analytics, 'issuance' => $issuance_analytics );
			}
		}
		$guided_tour_state = Ecapla_CertVerify_Guided_Tour::get_state();
		$guided_tour_profile = 'general_other';
		if ( is_array( $onboarding_summary ) && ! empty( $onboarding_summary['state']['wizard_draft']['usage_profile'] ) ) {
			$guided_tour_profile = sanitize_key( (string) $onboarding_summary['state']['wizard_draft']['usage_profile'] );
		}
		$guided_tour_steps = Ecapla_CertVerify_Guided_Tour::steps( $guided_tour_profile );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request chooses whether to resume/restart the per-user tour; persistence uses AJAX nonce.
		$guided_tour_request = isset( $_GET['certloom_tour'] ) ? sanitize_key( wp_unslash( $_GET['certloom_tour'] ) ) : '';
		$guided_tour_mode = in_array( $guided_tour_request, array( 'resume', 'restart' ), true ) ? $guided_tour_request : '';
		if ( '' === $guided_tour_mode && Ecapla_CertVerify_Guided_Tour::should_auto_start( $guided_tour_state, ! empty( $onboarding_summary['wizard_completed'] ) ) ) {
			$guided_tour_mode = 'resume';
		}
		$guided_tour_nonce = wp_create_nonce( 'certloom_guided_tour' );
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/dashboard.php';
	}

	/** Redirect compatibility pages before admin notices write output and prevent HTTP redirects. */
	public function redirect_hidden_compatibility_routes() {
		global $pagenow;
		if ( 'admin.php' !== $pagenow ) { return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route selection.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::CERTIFICATES_PAGE, 'certloom-add', 'certloom-bulk-review' ), true ) ) { return; }
		$this->require_manage_permission();
		if ( self::CERTIFICATES_PAGE === $page && \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			wp_safe_redirect( $this->legacy_registry_url() );
			exit;
		}
		if ( 'certloom-add' === $page && empty( $_GET['id'] ) && \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route selection.
			wp_safe_redirect( admin_url( 'admin.php?page=certloom-issuance' ) );
			exit;
		}
		if ( 'certloom-bulk-review' === $page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- User-bound review token is read-only here.
			$token = isset( $_GET['token'] ) && is_scalar( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			$session = Ecapla_CertVerify_Bulk_Manager::get_session( $token );
			if ( is_wp_error( $session ) ) { $this->redirect_error( $session->get_error_message(), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) ); }
		}
	}

	/** Preserve familiar legacy list filters when landing in the unified registry. */
	private function legacy_registry_url() {
		$args = array( 'page' => 'certloom-domain-credentials' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only compatibility redirect state.
		if ( isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) && '' !== trim( (string) $_GET['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		$status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! empty( $_GET['legacy_expired'] ) ) { $status = 'expired'; }
		if ( 'withdrawn' === $status ) { $status = 'revoked'; }
		if ( in_array( $status, array( 'valid', 'suspended', 'revoked', 'expired' ), true ) ) { $args['credential_status'] = $status; }
		if ( ! empty( $_GET['paged'] ) ) { $args['paged'] = max( 1, absint( $_GET['paged'] ) ); }
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public function render_list_screen() {
		$this->require_manage_permission();
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			wp_safe_redirect( $this->legacy_registry_url() );
			exit;
		}
		$table = new Ecapla_CertVerify_List_Table();
		$table->prepare_items();
		$database_error = $table->get_database_error();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/list-certificates.php';
	}

	public function render_bulk_review_screen() {
		$this->require_manage_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only token selects a user-bound transient; mutations use separate POST nonces.
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$session = Ecapla_CertVerify_Bulk_Manager::get_session( $token );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( is_wp_error( $session ) ) {
			$this->redirect_error( $session->get_error_message(), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		}
		$selected_records = array();
		foreach ( $session['ids'] as $id ) {
			$record = Ecapla_CertVerify_Database::get_by_id( $id );
			if ( is_wp_error( $record ) ) {
				$this->redirect_error( $record->get_error_message(), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
			}
			if ( $record ) {
				$selected_records[] = $record;
			}
		}
		$bulk_preview = isset( $session['preview'] ) && is_array( $session['preview'] ) ? $session['preview'] : null;
		$bulk_fields  = Ecapla_CertVerify_Bulk_Manager::get_editable_custom_fields();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/bulk-review.php';
	}

	/** Admin-only sample that lets a new administrator review the public result before a real credential exists. */
	public function render_verification_sample_screen() {
		$this->require_options_permission();
		$back = add_query_arg( array( 'page' => 'certloom-setup', 'step' => 'delivery' ), admin_url( 'admin.php' ) );
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-verification-sample-screen"><section class="certloom-page-hero certloom-page-hero-compact"><div><span class="certloom-eyebrow">' . esc_html__( 'Setup preview', 'certloom' ) . '</span><h1>' . esc_html__( 'Sample verification result', 'certloom' ) . '</h1><p>' . esc_html__( 'This is sample data only. Use it to review the public credential hierarchy, spacing and verification presentation before you have issued a real credential.', 'certloom' ) . '</p></div><a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to setup', 'certloom' ) . '</a></section><div class="certloom-setup-public-sample">';
		echo Ecapla_CertVerify_Render::setup_sample_credential_profile(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its complete presentation.
		echo '</div></div>';
	}

	public function render_onboarding_screen() {
		$this->require_options_permission();
		$setup_summary = Ecapla_CertVerify_Onboarding::get_summary();
		if ( is_wp_error( $setup_summary ) ) {
			$this->redirect_error( $setup_summary->get_error_message(), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) );
		}
		$wizard_engine = new \Certloom\Application\Setup\PurposePresetEngine();
		$wizard_state = $setup_summary['state'];
		$wizard_steps = Ecapla_CertVerify_Onboarding::wizard_step_labels();
		$wizard_has_explicit_step = isset( $_GET['step'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Wizard navigation.
		$wizard_step = $wizard_has_explicit_step ? sanitize_key( wp_unslash( $_GET['step'] ) ) : (string) $wizard_state['wizard_step']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Wizard navigation.
		if ( ! $wizard_has_explicit_step && ! empty( $wizard_state['wizard_completed_at'] ) ) { $wizard_step = 'finish'; }
		if ( ! isset( $wizard_steps[ $wizard_step ] ) ) { $wizard_step = 'welcome'; }
		$wizard_workspace = Ecapla_CertVerify_Onboarding::ensure_wizard_workspace( $wizard_step );
		if ( is_wp_error( $wizard_workspace ) ) { $this->redirect_error( $wizard_workspace->get_error_message(), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ); }
		$setup_summary = Ecapla_CertVerify_Onboarding::get_summary();
		if ( is_wp_error( $setup_summary ) ) { $this->redirect_error( $setup_summary->get_error_message(), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ); }
		$wizard_state = $setup_summary['state'];
		$wizard_draft = is_array( $wizard_state['wizard_draft'] ) ? $wizard_state['wizard_draft'] : array();
		$wizard_profiles = $wizard_engine->profiles();
		$wizard_profile = isset( $wizard_draft['usage_profile'] ) ? $wizard_engine->normalize_profile( $wizard_draft['usage_profile'] ) : Ecapla_CertVerify_Settings::get( 'usage_profile', 'general_other' );
		$wizard_recommendation = $wizard_engine->recommend( $wizard_profile );
		if ( is_wp_error( $wizard_recommendation ) ) { $wizard_profile = 'general_other'; $wizard_recommendation = $wizard_engine->recommend( $wizard_profile ); }
		$wizard_selected_fields = isset( $wizard_draft['selected_fields'] ) && is_array( $wizard_draft['selected_fields'] ) ? array_values( $wizard_draft['selected_fields'] ) : array_values( array_map( static function ( $field ) { return sanitize_key( (string) ( $field['key'] ?? '' ) ); }, (array) ( $wizard_recommendation['recommended_fields'] ?? array() ) ) );
		$wizard_custom_fields = isset( $wizard_draft['custom_fields'] ) && is_array( $wizard_draft['custom_fields'] ) ? array_values( $wizard_draft['custom_fields'] ) : array();
		$wizard_presentation_mode = isset( $wizard_draft['presentation_mode'] ) ? sanitize_key( (string) $wizard_draft['presentation_mode'] ) : 'details_only';
		if ( ! in_array( $wizard_presentation_mode, array( 'details_only', 'image', 'design' ), true ) ) { $wizard_presentation_mode = 'details_only'; }
		$wizard_certificate_image_id = absint( $wizard_draft['certificate_image_id'] ?? 0 );
		$wizard_certificate_image_url = $wizard_certificate_image_id ? (string) wp_get_attachment_image_url( $wizard_certificate_image_id, 'medium' ) : '';
		$wizard_organization_logo_id = absint( $wizard_draft['organization_logo_id'] ?? 0 );
		$wizard_organization_logo_url = $wizard_organization_logo_id ? (string) wp_get_attachment_image_url( $wizard_organization_logo_id, 'thumbnail' ) : '';
		$wizard_template_candidates = array();
		$wizard_profile_bundle = ( new \Certloom\Application\Setup\ProfileStarterBundle() )->get( $wizard_profile );
		$wizard_recommended_template_id = 0;
		$wizard_template = null;
		$wizard_template_preview_html = '';
		$wizard_template_preview_error = '';
		$wizard_selected_template_id = absint( $wizard_draft['design_template_id'] ?? 0 );
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$wizard_template_repo = new \Certloom\Infrastructure\Repository\TemplateRepository();
			$wizard_factory_service = new \Certloom\Application\Templates\FactoryPresetService( $wizard_template_repo );
			$wizard_starter_publication = $wizard_factory_service->ensure_starter_publications( get_current_user_id(), 10 );
			$wizard_factory_candidates = $wizard_template_repo->search_published_candidates( 'certloom-preset-', 50 );
			$wizard_all_candidates = $wizard_template_repo->search_published_candidates( '', 50 );
			$wizard_profile_candidate = $wizard_template_repo->find_published_by_machine_key( 'certloom-preset-profile-' . $wizard_profile . '-essential' );
			$wizard_profile_candidates = array();
			if ( is_array( $wizard_profile_candidate ) && empty( $wizard_profile_candidate['archived_at'] ) ) {
				$wizard_recommended_template_id = absint( $wizard_profile_candidate['id'] ?? 0 );
				$wizard_profile_candidates[] = $wizard_profile_candidate;
			}
			if ( is_wp_error( $wizard_factory_candidates ) ) { $wizard_factory_candidates = array(); }
			if ( is_wp_error( $wizard_all_candidates ) ) { $wizard_all_candidates = array(); }
			$wizard_template_candidates = array();
			$wizard_candidate_ids = array();
			foreach ( array_merge( $wizard_profile_candidates, $wizard_factory_candidates, $wizard_all_candidates ) as $wizard_candidate ) {
				$wizard_candidate_id = absint( $wizard_candidate['id'] ?? 0 );
				if ( ! $wizard_candidate_id || isset( $wizard_candidate_ids[ $wizard_candidate_id ] ) ) { continue; }
				$wizard_candidate_ids[ $wizard_candidate_id ] = true;
				$wizard_template_candidates[] = $wizard_candidate;
			}
			if ( $wizard_selected_template_id ) {
				$wizard_template = $wizard_template_repo->find_published( $wizard_selected_template_id );
				if ( is_wp_error( $wizard_template ) ) { $wizard_template_preview_error = $wizard_template->get_error_message(); $wizard_template = null; }
				if ( is_array( $wizard_template ) ) {
					if ( ! isset( $wizard_candidate_ids[ $wizard_selected_template_id ] ) ) {
						$wizard_template_candidates[] = array( 'id' => $wizard_selected_template_id, 'name' => (string) ( $wizard_template['name'] ?? __( 'Selected design', 'certloom' ) ), 'machine_key' => (string) ( $wizard_template['machine_key'] ?? '' ) );
						$wizard_candidate_ids[ $wizard_selected_template_id ] = true;
					}
					$wizard_plan = ( new \Certloom\Infrastructure\Rendering\TemplatePreviewService() )->build( $wizard_template, 'published', 'standard', $wizard_profile );
					if ( is_wp_error( $wizard_plan ) ) { $wizard_template_preview_error = $wizard_plan->get_error_message(); }
					else {
						try { $wizard_preview = ( new \Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview() )->present( $wizard_plan['render_plan'] ); $wizard_template_preview_html = (string) ( $wizard_preview['html'] ?? '' ); }
						catch ( \Throwable $error ) { $wizard_template_preview_error = __( 'Certloom could not build this design preview.', 'certloom' ); }
					}
				}
			}
		}
		$wizard_template_preview_nonce = wp_create_nonce( 'certloom_setup_wizard_preview' );
		$wizard_profile_terms = \Certloom\Infrastructure\Admin\UsageProfilePresentation::terms( $wizard_profile );
		$wizard_profile_label = (string) ( $wizard_profiles[ $wizard_profile ]['label'] ?? __( 'General / Other', 'certloom' ) );
		$wizard_first_plan = ( new \Certloom\Application\Setup\FirstCredentialSetupPlan() )->build(
			is_array( $wizard_template ) ? $wizard_template : null,
			$wizard_selected_fields,
			Ecapla_CertVerify_Fields::get_field_map(),
			$wizard_custom_fields
		);
		$wizard_issuer_repo = new \Certloom\Infrastructure\Repository\IssuerRepository();
		$wizard_program_repo = new \Certloom\Infrastructure\Repository\ProgramRepository();
		$wizard_batch_repo = new \Certloom\Infrastructure\Repository\BatchRepository();
		$wizard_signer_repo = new \Certloom\Infrastructure\Repository\SignerRepository();
		$wizard_issuers_result = $wizard_issuer_repo->list_page( '', 1, 100 );
		$wizard_programs_result = $wizard_program_repo->list_page( '', 1, 100, 'active' );
		$wizard_batches_result = $wizard_batch_repo->list_page( '', 1, 100, 'active' );
		$wizard_signers_result = $wizard_signer_repo->list_page( '', 1, 100, 'active' );
		$wizard_issuer_candidates = is_wp_error( $wizard_issuers_result ) ? array() : (array) ( $wizard_issuers_result['items'] ?? array() );
		$wizard_program_candidates = is_wp_error( $wizard_programs_result ) ? array() : (array) ( $wizard_programs_result['items'] ?? array() );
		$wizard_batch_candidates = is_wp_error( $wizard_batches_result ) ? array() : (array) ( $wizard_batches_result['items'] ?? array() );
		$wizard_signer_candidates = is_wp_error( $wizard_signers_result ) ? array() : (array) ( $wizard_signers_result['items'] ?? array() );
		$wizard_context_ids = array(
			'issuer' => absint( $wizard_draft['setup_issuer_id'] ?? 0 ),
			'program' => absint( $wizard_draft['setup_program_id'] ?? 0 ),
			'batch' => absint( $wizard_draft['setup_batch_id'] ?? 0 ),
			'signer' => absint( $wizard_draft['setup_signer_id'] ?? 0 ),
		);
		$wizard_selected_program = $wizard_context_ids['program'] ? $wizard_program_repo->find( $wizard_context_ids['program'] ) : null;
		if ( is_wp_error( $wizard_selected_program ) ) { $wizard_selected_program = null; }
		$wizard_program_title = is_array( $wizard_selected_program ) ? (string) ( $wizard_selected_program['title'] ?? '' ) : trim( (string) ( $wizard_draft['setup_new_program_title'] ?? '' ) );
		$wizard_field_planner = new \Certloom\Application\Setup\WizardFieldSetup();
		$wizard_field_catalog = Ecapla_CertVerify_Fields::get_field_map();
		$wizard_field_retired = Ecapla_CertVerify_Fields::get_retired_fields();
		$wizard_field_rows = array_slice( $wizard_field_planner->candidates( $wizard_draft, $wizard_recommendation, $wizard_field_catalog, $wizard_field_retired, $wizard_selected_program ), 0, 150 );
		$wizard_field_plan = isset( $wizard_draft['setup_field_plan'] ) ? $wizard_field_planner->build( $wizard_draft['setup_field_plan'], $wizard_field_catalog, $wizard_field_retired, $wizard_selected_program ) : null;
		$wizard_design_coverage = $this->setup_wizard_design_coverage( is_array( $wizard_template ) ? $wizard_template : null, $wizard_draft );
		$wizard_first_issuance_ready = \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready();
		$wizard_first_issuance_reason = $wizard_first_issuance_ready
			? __( 'The first credential uses the same canonical issuance workflow as the main Credential workspace, including the context and design-required data collected during Setup.', 'certloom' )
			: __( 'Credential issuance is temporarily unavailable because the credential store is not ready.', 'certloom' );
		$wizard_email_designs = ( new \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository() )->for_purpose( \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED, true );
		$wizard_email_current = ( new \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository() )->resolve( $wizard_context_ids['issuer'], $wizard_context_ids['program'], $wizard_context_ids['batch'] );
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/setup-wizard.php';
	}


	public function render_automations_screen() {
		$this->require_manage_permission();
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$this->redirect_error( __( 'Automations become available with Certloom’s Program-based credential workflow.', 'certloom' ), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) );
		}

		$email_templates = ( new \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository() )->all();
		$email_rules     = ( new \Certloom\Infrastructure\Email\EmailAutomationRuleRepository() )->all();
		$program_repository = new \Certloom\Infrastructure\Repository\ProgramRepository();
		$issuer_repository  = new \Certloom\Infrastructure\Repository\IssuerRepository();
		$batch_repository   = new \Certloom\Infrastructure\Repository\BatchRepository();
		$programs_result = $program_repository->list_page( '', 1, 20, 'active' );
		$programs        = is_wp_error( $programs_result ) ? array() : (array) ( $programs_result['items'] ?? array() );
		$issuers_result  = $issuer_repository->list_page( '', 1, 20 );
		$issuers         = is_wp_error( $issuers_result ) ? array() : (array) ( $issuers_result['items'] ?? array() );
		$automation_issuer_ids  = array();
		$automation_program_ids = array();
		$automation_batch_ids   = array();
		foreach ( $email_rules as $rule ) {
			if ( ! is_array( $rule ) ) { continue; }
			if ( ! empty( $rule['issuer_id'] ) ) { $automation_issuer_ids[] = absint( $rule['issuer_id'] ); }
			if ( ! empty( $rule['program_id'] ) ) { $automation_program_ids[] = absint( $rule['program_id'] ); }
			if ( ! empty( $rule['batch_id'] ) ) { $automation_batch_ids[] = absint( $rule['batch_id'] ); }
		}
		$automation_issuer_names  = $issuer_repository->labels_by_ids( $automation_issuer_ids );
		$automation_program_names = $program_repository->labels_by_ids( $automation_program_ids );
		$automation_batch_names   = $batch_repository->labels_by_ids( $automation_batch_ids );
		$automation_issuer_names  = is_wp_error( $automation_issuer_names ) ? array() : $automation_issuer_names;
		$automation_program_names = is_wp_error( $automation_program_names ) ? array() : $automation_program_names;
		$automation_batch_names   = is_wp_error( $automation_batch_names ) ? array() : $automation_batch_names;
		$automation_runs = new \Certloom\Infrastructure\Email\EmailAutomationRunRepository();
		$automation_latest_runs = array();
		foreach ( $email_rules as $rule ) { if ( is_array( $rule ) && ! empty( $rule['id'] ) ) { $automation_latest_runs[ (string) $rule['id'] ] = $automation_runs->latest_for_rule( (string) $rule['id'] ); } }

		$tutor       = ( new \Certloom\Infrastructure\Admin\TutorLmsIntegrationAdmin() )->screen_data();
		$learndash   = ( new \Certloom\Infrastructure\Admin\LearnDashIntegrationAdmin() )->screen_data();
		$woocommerce = ( new \Certloom\Infrastructure\Admin\WooCommerceIntegrationAdmin() )->screen_data();
		$coursera    = ( new \Certloom\Infrastructure\Admin\CourseraIntegrationAdmin() )->screen_data();
		$webhooks    = ( new \Certloom\Infrastructure\Admin\WebhookIntegrationAdmin() )->screen_data();
		$credly      = ( new \Certloom\Infrastructure\Admin\CredlyIntegrationAdmin() )->screen_data();
		$active_mapping_status = static function ( $count ) {
			$count = max( 0, absint( $count ) );
			return sprintf(
				/* translators: %d: number of active integration mappings. */
				_n( '%d active mapping', '%d active mappings', $count, 'certloom' ),
				$count
			);
		};
		$destination_status = array(
			array( 'label' => __( 'Credly', 'certloom' ), 'icon' => 'dashicons-awards', 'status' => ! empty( $credly['connection']['configured'] ) ? __( 'Connected', 'certloom' ) : __( 'Not connected', 'certloom' ) ),
		);
		$source_status = array(
			array(
				'label'  => __( 'Tutor LMS', 'certloom' ),
				'icon'   => 'dashicons-welcome-learn-more',
				'status' => $active_mapping_status( $tutor['status']['active_mapping_count'] ?? 0 ),
			),
			array(
				'label'  => __( 'LearnDash', 'certloom' ),
				'icon'   => 'dashicons-welcome-learn-more',
				'status' => $active_mapping_status( $learndash['status']['active_mapping_count'] ?? 0 ),
			),
			array(
				'label'  => __( 'WooCommerce', 'certloom' ),
				'icon'   => 'dashicons-cart',
				'status' => $active_mapping_status( $woocommerce['status']['active_mapping_count'] ?? 0 ),
			),

			array(
				'label'  => __( 'Webhook / API', 'certloom' ),
				'icon'   => 'dashicons-rest-api',
				'status' => ! empty( $webhooks['sources'] ) ? sprintf( /* translators: %d: number of active webhook/API sources. */ _n( '%d active source', '%d active sources', count( array_filter( (array) $webhooks['sources'], static function ( $row ) { return is_array( $row ) && ! empty( $row['active'] ); } ) ), 'certloom' ), count( array_filter( (array) $webhooks['sources'], static function ( $row ) { return is_array( $row ) && ! empty( $row['active'] ); } ) ) ) : __( 'No active sources', 'certloom' ),
			),
			array(
				'label'  => __( 'Coursera', 'certloom' ),
				'icon'   => 'dashicons-cloud',
				'status' => $active_mapping_status( $coursera['status']['active_mapping_count'] ?? 0 ),
			),
		);

		include ECAPLA_CERTVERIFY_PATH . 'admin/views/automations.php';
	}

	public function render_integrations_screen() {
		$this->require_manage_permission();
		$tutor_admin = new \Certloom\Infrastructure\Admin\TutorLmsIntegrationAdmin();
		$tutor = $tutor_admin->screen_data();
		$learndash_admin = new \Certloom\Infrastructure\Admin\LearnDashIntegrationAdmin();
		$learndash = $learndash_admin->screen_data();
		$woocommerce_admin = new \Certloom\Infrastructure\Admin\WooCommerceIntegrationAdmin();
		$woocommerce = $woocommerce_admin->screen_data();
		$coursera_admin = new \Certloom\Infrastructure\Admin\CourseraIntegrationAdmin();
		$coursera = $coursera_admin->screen_data();
		$webhook_admin = new \Certloom\Infrastructure\Admin\WebhookIntegrationAdmin();
		$webhooks = $webhook_admin->screen_data();
		$webhooks_v2 = ( new \Certloom\Infrastructure\Admin\WebhookV2IntegrationAdmin() )->screen_data();
		$credly_admin = new \Certloom\Infrastructure\Admin\CredlyIntegrationAdmin();
		$credly = $credly_admin->screen_data();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/integrations.php';
	}

	public function render_roadmap_placeholder_screen() {
		$this->require_manage_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin route used only to choose the planned feature explanation.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$planned_features = array(
			'certloom-domain-recipients' => array(
				'title' => __( 'Recipients', 'certloom' ),
				'eyebrow' => __( 'Protected workspace', 'certloom' ),
				'description' => __( 'Recipient management is prepared but not available in this build yet. Certloom keeps this workspace locked until the underlying credential data transition completes its final safety checks. Existing certificate operations remain unchanged.', 'certloom' ),
				'status' => __( 'Protected until final data safety checks complete', 'certloom' ),
			),
			'certloom-domain-programs' => array(
				'title' => __( 'Programs', 'certloom' ),
				'eyebrow' => __( 'Protected workspace', 'certloom' ),
				'description' => __( 'Program management is prepared but not available in this build yet. Certloom keeps Program relationships locked until the underlying credential data transition completes its final safety checks rather than storing a misleading substitute.', 'certloom' ),
				'status' => __( 'Protected until final data safety checks complete', 'certloom' ),
			),
			'certloom-templates' => array(
				'title' => __( 'Templates', 'certloom' ),
				'eyebrow' => __( 'Design availability', 'certloom' ),
				'description' => __( 'The Template Library and Designer are available through Design. Downloadable PDF and image publication stays protected until its final rendering safety checks are approved.', 'certloom' ),
				'status' => __( 'Design tools available · downloadable publication protected', 'certloom' ),
			),
			'certloom-issuance' => array(
				'title' => __( 'Issuance', 'certloom' ),
				'eyebrow' => __( 'Issuance availability', 'certloom' ),
				'description' => __( 'Certloom’s existing certificate, import, delivery, sharing and analytics workflows remain available. Program-based issuance and downloadable PDF or image publication stay protected until their final data and rendering safety checks are approved.', 'certloom' ),
				'status' => __( 'Current credential workflows available · publication authority protected', 'certloom' ),
			),
		);
		$planned_feature = isset( $planned_features[ $page ] ) ? $planned_features[ $page ] : null;
		if ( ! $planned_feature ) {
			$this->redirect_error( __( 'That Certloom workspace is not available.', 'certloom' ), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) );
		}
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/roadmap-placeholder.php';
	}

	public function render_edit_screen() {
		$this->require_manage_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin route used only to select the record displayed.
		$id          = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! $id && \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=certloom-issuance' ) );
			exit;
		}
		$certificate = $id ? Ecapla_CertVerify_Database::get_by_id( $id ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( is_wp_error( $certificate ) ) {
			$this->redirect_error( $certificate->get_error_message(), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		}
		if ( $id && ! $certificate ) {
			$this->redirect_error( __( 'That certificate does not exist.', 'certloom' ), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		}
		$fields = Ecapla_CertVerify_Fields::get_fields();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/edit-certificate.php';
	}

	public function render_fields_screen() {
		$this->require_manage_permission();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/fields-manager.php';
	}

	public function render_import_screen() {
		$this->require_manage_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only import routing; transient ownership is verified by Ecapla_CertVerify_Import.
		if ( isset( $_GET['result'] ) ) {
			$results = Ecapla_CertVerify_Import::get_results( sanitize_text_field( wp_unslash( $_GET['result'] ) ) );
			if ( false === $results ) {
				$this->redirect_error( __( 'Those import results expired. Run the import again to generate a new report.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
			}
			include ECAPLA_CERTVERIFY_PATH . 'admin/views/import-results.php';
			return;
		}
		if ( current_user_can( 'manage_options' ) && isset( $_GET['step'], $_GET['backup_token'] ) && 'backup-process' === sanitize_key( wp_unslash( $_GET['step'] ) ) ) {
			$backup_token    = sanitize_text_field( wp_unslash( $_GET['backup_token'] ) );
			$backup_progress = Ecapla_CertVerify_Backup::get_restore_progress( $backup_token );
			if ( ! $backup_progress ) {
				$this->redirect_error( __( 'That backup restore process expired. Upload the backup again.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
			}
			include ECAPLA_CERTVERIFY_PATH . 'admin/views/backup-progress.php';
			return;
		}
		if ( isset( $_GET['step'], $_GET['token'] ) && 'process' === sanitize_key( wp_unslash( $_GET['step'] ) ) ) {
			$token    = sanitize_text_field( wp_unslash( $_GET['token'] ) );
			$progress = Ecapla_CertVerify_Import::get_process_progress( $token );
			if ( ! $progress ) {
				$this->redirect_error( __( 'That import session expired. Upload the CSV again.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
			}
			include ECAPLA_CERTVERIFY_PATH . 'admin/views/import-progress.php';
			return;
		}
		if ( isset( $_GET['step'], $_GET['token'] ) && 'map' === sanitize_key( wp_unslash( $_GET['step'] ) ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
			$session = Ecapla_CertVerify_Import::get_preview( $token );
			if ( ! $session ) {
				$this->redirect_error( __( 'That import session expired. Upload the CSV again.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
			}
			$header          = $session['header'];
			$preview         = $session['preview'];
			$fields          = Ecapla_CertVerify_Fields::get_exchange_fields();
			$guessed_mapping = Ecapla_CertVerify_Import::guess_mapping( $header, $fields );
			include ECAPLA_CERTVERIFY_PATH . 'admin/views/import-mapping.php';
			return;
		}
		$backup_preview = false;
		$backup_token   = '';
		if ( current_user_can( 'manage_options' ) && isset( $_GET['backup_token'] ) ) {
			$backup_token   = sanitize_text_field( wp_unslash( $_GET['backup_token'] ) );
			$backup_preview = Ecapla_CertVerify_Backup::get_preview( $backup_token );
			if ( false === $backup_preview ) {
				$this->redirect_error( __( 'That backup review session expired. Upload the backup again.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
			}
		}
		$scheduled_backup_state = current_user_can( 'manage_options' ) ? \Certloom\Infrastructure\Backup\ScheduledBackup::settings() : array();
		$local_backup_store     = current_user_can( 'manage_options' ) ? new \Certloom\Infrastructure\Backup\LocalBackupStore() : null;
		$saved_backups          = $local_backup_store ? $local_backup_store->list_backups() : array();
		$backup_storage_state   = $local_backup_store ? $local_backup_store->directory() : '';
		$google_drive_state     = current_user_can( 'manage_options' ) ? \Certloom\Infrastructure\Backup\GoogleDriveBackup::state() : array();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/import-upload.php';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public function render_analytics_screen() {
		if ( ! current_user_can( Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS ) && ! current_user_can( ECAPLA_CERTVERIFY_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view Certloom analytics.', 'certloom' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded reporting filters.
		$analytics_section = isset( $_GET['section'] ) && 'issuance' === sanitize_key( wp_unslash( $_GET['section'] ) ) ? 'issuance' : 'verification';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded reporting filters.
		$analytics_days = isset( $_GET['days'] ) ? Ecapla_CertVerify_Analytics::normalize_period( wp_unslash( $_GET['days'] ) ) : 30;
		if ( 'issuance' === $analytics_section ) {
			$analytics_report = ( new \Certloom\Application\Analytics\IssuanceAnalyticsService() )->report( $analytics_days );
			require ECAPLA_CERTVERIFY_PATH . 'admin/views/issuance-analytics.php';
			return;
		}
		$analytics_report = Ecapla_CertVerify_Analytics::report( $analytics_days );
		require ECAPLA_CERTVERIFY_PATH . 'admin/views/analytics.php';
	}

	public function render_frontend_screen() {
		$this->require_manage_permission();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/frontend-manager.php';
	}

	public function render_settings_screen() {
		$this->require_options_permission();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/settings-manager.php';
	}


	/**
	 * Acknowledges the historical 1.3.0 migration warning after an administrator
	 * has reviewed a pre-update backup or confirmed it did not run in production.
	 */
	public function handle_status_review_acknowledgement() {
		if ( empty( $_POST['certverify_ack_130_status_review'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certverify_ack_130_status_review' );
		$saved = update_option( Ecapla_CertVerify_Database::V130_REVIEW_NOTICE_OPTION, false, false );
		if ( ! $saved && false !== (bool) get_option( Ecapla_CertVerify_Database::V130_REVIEW_NOTICE_OPTION, true ) ) {
			$this->redirect_error( __( 'The review acknowledgement could not be saved.', 'certloom' ), admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) );
		exit;
	}

	/**
	 * Displays transparent status-migration notices only on Certloom screens.
	 */
	public function status_review_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'certloom' ) || ! Ecapla_CertVerify_Permissions::current_user_can_manage() ) {
			return;
		}

		if ( Ecapla_CertVerify_Backup::is_restore_in_progress() ) {
			?>
			<div class="notice notice-info"><p>
				<?php esc_html_e( 'A complete Certloom restore is in progress. Certificate and configuration changes are temporarily paused; public verification remains available.', 'certloom' ); ?>
			</p></div>
			<?php
		}

		$legacy_count = (int) get_option( Ecapla_CertVerify_Database::LEGACY_REVIEW_OPTION, 0 );
		if ( $legacy_count > 0 ) {
			$url = add_query_arg(
				array(
					'page'           => self::CERTIFICATES_PAGE,
					'legacy_expired' => '1',
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="notice notice-warning"><p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: number of legacy records, 2: review URL. */
						_n( '%1$d legacy Expired certificate needs review. <a href="%2$s">Review it now</a>. It will remain Expired until an administrator deliberately chooses a workflow status.', '%1$d legacy Expired certificates need review. <a href="%2$s">Review them now</a>. They will remain Expired until an administrator deliberately chooses a workflow status.', $legacy_count, 'certloom' ),
						absint( $legacy_count ),
						esc_url( $url )
					)
				);
				?>
			</p></div>
			<?php
		}

		if ( current_user_can( 'manage_options' ) && get_option( Ecapla_CertVerify_Database::V130_REVIEW_NOTICE_OPTION, false ) ) {
			?>
			<div class="notice notice-error"><p>
				<?php esc_html_e( 'Certloom 1.3.0 briefly contained an unsafe legacy-status migration. If that version ran on this site, compare certificate statuses with a pre-1.3.0 backup before launch. The current release does not guess or silently rewrite those records.', 'certloom' ); ?>
			</p><form method="post" action="" style="margin:0 0 12px 12px;">
				<?php wp_nonce_field( 'certverify_ack_130_status_review' ); ?>
				<button type="submit" class="button" name="certverify_ack_130_status_review" value="1"><?php esc_html_e( 'I have reviewed this', 'certloom' ); ?></button>
			</form></div>
			<?php
		}
	}

	public function admin_footer_text( $text ) {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'certloom' ) ) {
			return wp_kses_post(
				sprintf(
					/* translators: %s: Ecapla website link. */
					__( 'Certloom is developed and maintained by %s.', 'certloom' ),
					'<a href="https://ecapla.com" target="_blank" rel="noopener noreferrer">' . esc_html( 'Ecapla' ) . '</a>'
				)
			);
		}
		return $text;
	}

	private function require_manage_permission() {
		if ( ! Ecapla_CertVerify_Permissions::current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage certificates.', 'certloom' ) );
		}
	}

	private function require_options_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'certloom' ) );
		}
	}

	private function require_writes_available( $url ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			$this->redirect_error( $allowed->get_error_message(), $url );
		}
	}

	private function limit_text( $value, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, $length ) : substr( (string) $value, 0, $length );
	}

	/**
	 * Rebuilds the certificate list URL with the visitor's active search term,
	 * status/attention filters, legacy view, page number, and sorting so a redirect after an action
	 * returns to the same view instead of an unfiltered first page.
	 *
	 * @return string
	 */
	/** Blocks a reviewed destructive action unless its current-authority safety snapshot is verified. */
	private function require_safety_snapshot( $operation, $operation_key, $return_url ) {
		$result = \Certloom\Infrastructure\Backup\SafetySnapshot::before( $operation, $operation_key );
		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result->get_error_message(), $return_url );
		}
		return $result;
	}

	private function bulk_return_state_from_request( $request ) {
		$request = is_array( $request ) ? $request : array();
		return Ecapla_CertVerify_Bulk_Manager::sanitize_return_state( $request );
	}

	private function list_return_url() {
		$args = array( 'page' => self::CERTIFICATES_PAGE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state; the calling handler verifies its own nonce before this runs.
		if ( ! empty( $_REQUEST['legacy_expired'] ) ) {
			$args['legacy_expired'] = '1';
		}
		if ( isset( $_REQUEST['status'] ) && '' !== $_REQUEST['status'] ) {
			$args['status'] = sanitize_key( wp_unslash( $_REQUEST['status'] ) );
		}
		if ( isset( $_REQUEST['attention'] ) && 'expiring_soon' === sanitize_key( wp_unslash( $_REQUEST['attention'] ) ) ) {
			$args['attention'] = 'expiring_soon';
		}
		if ( isset( $_REQUEST['search_mode'] ) ) {
			$search_mode = sanitize_key( wp_unslash( $_REQUEST['search_mode'] ) );
			if ( in_array( $search_mode, array( 'prefix', 'exact', 'contains' ), true ) ) {
				$args['search_mode'] = $search_mode;
			}
		}
		if ( isset( $_REQUEST['s'] ) && '' !== $_REQUEST['s'] ) {
			$args['s'] = $this->limit_text( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ), 100 );
		}
		foreach ( array( 'issue_from', 'issue_to', 'expiry_from', 'expiry_to' ) as $date_filter_key ) {
			if ( isset( $_REQUEST[ $date_filter_key ] ) && '' !== $_REQUEST[ $date_filter_key ] ) {
				$args[ $date_filter_key ] = $this->limit_text( sanitize_text_field( wp_unslash( $_REQUEST[ $date_filter_key ] ) ), 10 );
			}
		}
		if ( ! empty( $_REQUEST['paged'] ) ) {
			$args['paged'] = absint( $_REQUEST['paged'] );
		}
		$allowed_orderby = array( 'cert_number', 'holder_name', 'status', 'issue_date', 'expiry_date', 'created_at' );
		if ( isset( $_REQUEST['orderby'] ) ) {
			$orderby = sanitize_key( wp_unslash( $_REQUEST['orderby'] ) );
			if ( in_array( $orderby, $allowed_orderby, true ) ) {
				$args['orderby'] = $orderby;
			}
		}
		if ( isset( $_REQUEST['order'] ) ) {
			$order = strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) );
			if ( in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
				$args['order'] = $order;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirects back to a screen with a human-readable error.
	 *
	 * add_query_arg() does not encode the values it is given, so the message has
	 * to be encoded here. Without this an error containing "&", "+", or "#" is
	 * truncated or mangled before the receiving screen can display it.
	 *
	 * @param string $message Translated error message.
	 * @param string $url     Destination URL.
	 */
	private function redirect_error( $message, $url ) {
		$message = $this->limit_text( (string) $message, 300 );
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() && false !== strpos( $url, 'page=' . self::CERTIFICATES_PAGE ) ) {
			$url = $this->legacy_registry_url();
			wp_safe_redirect( add_query_arg( 'certloom_error', $message, $url ) );
		} else {
			wp_safe_redirect( add_query_arg( 'ecapla_certverify_error', $message, $url ) );
		}
		exit;
	}
}
