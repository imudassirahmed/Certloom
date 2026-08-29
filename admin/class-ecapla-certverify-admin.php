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
		add_action( 'admin_post_certloom_set_ui_mode', array( $this, 'handle_ui_mode_switch' ) );
		add_action( 'admin_notices', array( $this, 'render_ui_mode_switch' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
		add_action( 'admin_post_certloom_google_drive_settings', array( $this, 'handle_google_drive_settings' ) );
		add_action( 'admin_post_certloom_google_drive_test', array( $this, 'handle_google_drive_test' ) );
		add_action( 'admin_post_certloom_google_drive_disconnect', array( $this, 'handle_google_drive_disconnect' ) );
		add_action( 'admin_post_certloom_google_drive_upload', array( $this, 'handle_google_drive_upload' ) );
		add_action( 'admin_post_certloom_google_drive_download_local', array( $this, 'handle_google_drive_download_local' ) );
		add_action( 'admin_post_certloom_google_drive_delete', array( $this, 'handle_google_drive_delete' ) );
		add_action( 'admin_init', array( $this, 'handle_status_review_acknowledgement' ) );
		add_action( 'admin_post_ecapla_certverify_delete_certificate', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ecapla_certverify_delete_field', array( $this, 'handle_field_delete' ) );
		add_action( 'admin_post_ecapla_certverify_restore_field', array( $this, 'handle_field_restore' ) );
		add_action( 'admin_post_ecapla_certverify_purge_field', array( $this, 'handle_field_purge' ) );
		add_action( 'admin_post_ecapla_certverify_retranslate_fields', array( $this, 'handle_field_retranslate' ) );
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
		add_action( 'admin_notices', array( $this, 'status_review_notices' ) );
	}

	public function add_menu() {
		$simple = \Certloom\Infrastructure\Admin\AdminUiMode::is_simple();
		$home_label = $simple ? __( 'Home', 'certloom' ) : __( 'Dashboard', 'certloom' );
		$design_label = $simple ? __( 'Design', 'certloom' ) : __( 'Templates', 'certloom' );

		add_menu_page(
			__( 'Certloom', 'certloom' ),
			__( 'Certloom', 'certloom' ),
			ECAPLA_CERTVERIFY_CAP,
			self::DASHBOARD_PAGE,
			array( $this, 'render_dashboard_screen' ),
			'dashicons-awards',
			30
		);
		add_submenu_page( self::DASHBOARD_PAGE, $home_label, $home_label, ECAPLA_CERTVERIFY_CAP, self::DASHBOARD_PAGE, array( $this, 'render_dashboard_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Credentials', 'certloom' ), __( 'Credentials', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::CERTIFICATES_PAGE, array( $this, 'render_list_screen' ) );

		// Keep the planned application architecture visible without exposing gated domain data.
		if ( ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) ) {
			if ( ! $simple ) {
				add_submenu_page( self::DASHBOARD_PAGE, __( 'Recipients', 'certloom' ), __( 'Recipients', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-recipients', array( $this, 'render_roadmap_placeholder_screen' ) );
			}
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Programs', 'certloom' ), __( 'Programs', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-programs', array( $this, 'render_roadmap_placeholder_screen' ) );
		}
		add_submenu_page( self::DASHBOARD_PAGE, $design_label, $design_label, ECAPLA_CERTVERIFY_CAP, 'certloom-templates', array( $this, 'render_roadmap_placeholder_screen' ) );
		if ( ! $simple && ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) ) {
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Issuance', 'certloom' ), __( 'Issuance', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-issuance', array( $this, 'render_roadmap_placeholder_screen' ) );
		}
		if ( ! $simple ) {
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Data Tools', 'certloom' ), __( 'Data Tools', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-import', array( $this, 'render_import_screen' ) );
		}
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Analytics', 'certloom' ), __( 'Analytics', 'certloom' ), Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS, self::ANALYTICS_PAGE, array( $this, 'render_analytics_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Integrations', 'certloom' ), __( 'Integrations', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-integrations', array( $this, 'render_integrations_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Settings', 'certloom' ), __( 'Settings', 'certloom' ), 'manage_options', 'certloom-settings', array( $this, 'render_settings_screen' ) );

		// Contextual utility screens stay reachable from the application but do not clutter the primary shell.
		add_submenu_page( null, __( 'Add Certificate', 'certloom' ), __( 'Add Certificate', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-add', array( $this, 'render_edit_screen' ) );
		add_submenu_page( null, __( 'Field Manager', 'certloom' ), __( 'Field Manager', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-fields', array( $this, 'render_fields_screen' ) );
		add_submenu_page( null, __( 'Appearance & Embed', 'certloom' ), __( 'Appearance & Embed', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-frontend', array( $this, 'render_frontend_screen' ) );
		add_submenu_page( null, __( 'Setup', 'certloom' ), __( 'Setup', 'certloom' ), 'manage_options', 'certloom-setup', array( $this, 'render_onboarding_screen' ) );
		add_submenu_page( null, __( 'Review bulk change', 'certloom' ), __( 'Review bulk change', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-bulk-review', array( $this, 'render_bulk_review_screen' ) );
		if ( $simple ) {
			add_submenu_page( null, __( 'Data Tools', 'certloom' ), __( 'Data Tools', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-import', array( $this, 'render_import_screen' ) );
			if ( ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) ) {
				add_submenu_page( null, __( 'Recipients', 'certloom' ), __( 'Recipients', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-recipients', array( $this, 'render_roadmap_placeholder_screen' ) );
				add_submenu_page( null, __( 'Issuance', 'certloom' ), __( 'Issuance', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-issuance', array( $this, 'render_roadmap_placeholder_screen' ) );
			}
		}
	}


	/** Saves the presentation-only Simple/Advanced preference for this administrator. */
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

	/** Renders one compact mode control on Certloom administration screens only. */
	public function render_ui_mode_switch() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection.
		if ( 0 !== strpos( $page, 'certloom' ) || ! is_user_logged_in() ) {
			return;
		}
		$current = \Certloom\Infrastructure\Admin\AdminUiMode::current();
		$redirect = remove_query_arg( array( 'certloom_error', 'certloom_notice' ) );
		echo '<div class="certloom-ui-mode-bar" role="region" aria-label="' . esc_attr__( 'Certloom workspace mode', 'certloom' ) . '"><div><strong>' . esc_html__( 'Workspace', 'certloom' ) . '</strong><span>' . esc_html__( 'Simple keeps everyday work concise. Advanced restores specialist and diagnostic surfaces.', 'certloom' ) . '</span></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_set_ui_mode"><input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">';
		wp_nonce_field( 'certloom_set_ui_mode' );
		foreach ( array( 'simple' => __( 'Simple', 'certloom' ), 'advanced' => __( 'Advanced', 'certloom' ) ) as $value => $label ) {
			echo '<button type="submit" name="certloom_ui_mode" value="' . esc_attr( $value ) . '" class="certloom-ui-mode-choice ' . ( $current === $value ? 'is-current' : '' ) . '" aria-pressed="' . ( $current === $value ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</form></div>';
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'certloom' ) ) {
			return;
		}
		wp_enqueue_style( 'ecapla-certverify-admin', ECAPLA_CERTVERIFY_URL . 'admin/assets/admin.css', array(), ECAPLA_CERTVERIFY_VERSION );
		wp_enqueue_script( 'ecapla-certverify-admin', ECAPLA_CERTVERIFY_URL . 'admin/assets/admin.js', array(), ECAPLA_CERTVERIFY_VERSION, true );
	}

	public function handle_form_submission() {
		if ( ! isset( $_POST['certverify_save'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_save_certificate' );

		$id       = isset( $_POST['cert_id'] ) ? absint( $_POST['cert_id'] ) : 0;
		$back_url = $id ? admin_url( 'admin.php?page=certloom-add&id=' . $id ) : admin_url( 'admin.php?page=certloom-add' );
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

		wp_safe_redirect( add_query_arg( 'ecapla_certverify_saved', '1', admin_url( 'admin.php?page=' . self::CERTIFICATES_PAGE ) ) );
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
		if ( empty( $_POST['certloom_setup_wizard_action'] ) ) {
			return;
		}
		$this->require_options_permission();
		check_admin_referer( 'certloom_setup_wizard' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-setup' ) );
		$action = sanitize_key( wp_unslash( $_POST['certloom_setup_wizard_action'] ) );
		$base = admin_url( 'admin.php?page=certloom-setup' );

		if ( 'restart' === $action ) {
			$result = Ecapla_CertVerify_Onboarding::restart_wizard();
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
			wp_safe_redirect( add_query_arg( 'certloom_wizard_restarted', '1', $base ) );
			exit;
		}

		if ( in_array( $action, array( 'save_step', 'skip_step' ), true ) ) {
			$step = isset( $_POST['wizard_step'] ) ? sanitize_key( wp_unslash( $_POST['wizard_step'] ) ) : '';
			$next = isset( $_POST['wizard_next_step'] ) ? sanitize_key( wp_unslash( $_POST['wizard_next_step'] ) ) : '';
			if ( isset( $_POST['workspace_mode'] ) ) {
				$mode = \Certloom\Infrastructure\Admin\AdminUiMode::save_for_current_user( sanitize_key( wp_unslash( $_POST['workspace_mode'] ) ) );
				if ( is_wp_error( $mode ) ) { $this->redirect_error( $mode->get_error_message(), $base ); }
			}
			$patch = 'skip_step' === $action ? array() : $this->setup_wizard_draft_request( $step );
			$result = Ecapla_CertVerify_Onboarding::save_wizard_step( $step, $patch, $next, 'skip_step' === $action );
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), $base ); }
			$state = Ecapla_CertVerify_Onboarding::get_state();
			if ( ! empty( $_POST['wizard_finish_later'] ) && 'skip_step' !== $action ) {
				wp_safe_redirect( add_query_arg( 'certloom_wizard_saved', '1', admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ) );
				exit;
			}
			wp_safe_redirect( add_query_arg( array( 'certloom_wizard_saved' => '1', 'step' => $state['wizard_step'] ), $base ) );
			exit;
		}

		if ( 'apply_review' === $action ) {
			$result = $this->apply_setup_wizard_review();
			if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), add_query_arg( 'step', 'review', $base ) ); }
			wp_safe_redirect( add_query_arg( array( 'certloom_wizard_applied' => '1', 'step' => 'review' ), $base ) );
			exit;
		}

		$this->redirect_error( __( 'Certloom could not identify that setup action.', 'certloom' ), $base );
	}

	/** @return array<string,mixed> */
	private function setup_wizard_draft_request( $step ) {
		$step = sanitize_key( (string) $step );
		switch ( $step ) {
			case 'purpose':
				return array(
					'purpose' => isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : '',
					'subcategory' => isset( $_POST['subcategory'] ) ? sanitize_key( wp_unslash( $_POST['subcategory'] ) ) : '',
					'scheme_reference' => isset( $_POST['scheme_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['scheme_reference'] ) ) : '',
				);
			case 'organization':
				return array(
					'organization_name' => isset( $_POST['organization_name'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_name'] ) ) : '',
					'verification_page_id' => isset( $_POST['verification_page_id'] ) ? absint( $_POST['verification_page_id'] ) : 0,
				);
			case 'program':
				return array( 'program_title' => isset( $_POST['program_title'] ) ? sanitize_text_field( wp_unslash( $_POST['program_title'] ) ) : '' );
			case 'design':
				return array( 'design_note' => isset( $_POST['design_note'] ) ? sanitize_text_field( wp_unslash( $_POST['design_note'] ) ) : '' );
			case 'delivery':
				return array(
					'numbering_mode' => isset( $_POST['numbering_mode'] ) ? sanitize_key( wp_unslash( $_POST['numbering_mode'] ) ) : 'manual',
					'verification_qr_enabled' => ! empty( $_POST['verification_qr_enabled'] ),
					'verification_barcode_enabled' => ! empty( $_POST['verification_barcode_enabled'] ),
					'appearance_reviewed' => ! empty( $_POST['appearance_reviewed'] ),
					'verification_tested' => ! empty( $_POST['verification_tested'] ),
				);
			case 'backup':
				return array(
					'backup_schedule' => isset( $_POST['backup_schedule'] ) ? sanitize_key( wp_unslash( $_POST['backup_schedule'] ) ) : 'off',
					'backup_retention' => isset( $_POST['backup_retention'] ) ? absint( $_POST['backup_retention'] ) : 14,
				);
		}
		return array();
	}

	/**
	 * Applies only current-authority settings supported by the Wizard.
	 * Program/Template intent stays a recommendation while normalized admin
	 * authority is closed; the Wizard must never bypass those gates.
	 *
	 * @return true|\WP_Error
	 */
	private function apply_setup_wizard_review() {
		$state = Ecapla_CertVerify_Onboarding::get_state();
		$draft = isset( $state['wizard_draft'] ) && is_array( $state['wizard_draft'] ) ? $state['wizard_draft'] : array();
		$current_settings = Ecapla_CertVerify_Settings::get_all();
		$next_settings = $current_settings;
		if ( isset( $draft['numbering_mode'] ) ) { $next_settings['numbering_mode'] = $draft['numbering_mode']; }
		if ( array_key_exists( 'verification_qr_enabled', $draft ) ) { $next_settings['verification_qr_enabled'] = ! empty( $draft['verification_qr_enabled'] ); }
		if ( array_key_exists( 'verification_barcode_enabled', $draft ) ) { $next_settings['verification_barcode_enabled'] = ! empty( $draft['verification_barcode_enabled'] ); }
		$numbering = Ecapla_CertVerify_Numbering::normalize_policy( $next_settings );
		if ( is_wp_error( $numbering ) ) { return $numbering; }

		$current_backup = \Certloom\Infrastructure\Backup\ScheduledBackup::settings();
		$next_schedule = isset( $draft['backup_schedule'] ) ? sanitize_key( (string) $draft['backup_schedule'] ) : (string) $current_backup['schedule'];
		$next_retention = isset( $draft['backup_retention'] ) ? absint( $draft['backup_retention'] ) : absint( $current_backup['retention'] );
		if ( 'off' !== $next_schedule ) {
			$storage = ( new \Certloom\Infrastructure\Backup\LocalBackupStore() )->test_storage();
			if ( is_wp_error( $storage ) ) { return $storage; }
		}

		$organization = isset( $draft['organization_name'] ) ? trim( (string) $draft['organization_name'] ) : '';
		$page_id = isset( $draft['verification_page_id'] ) ? absint( $draft['verification_page_id'] ) : 0;
		if ( $page_id && ( ! get_post( $page_id ) || 'page' !== get_post_type( $page_id ) ) ) {
			return new \WP_Error( 'certloom_setup_review_page_invalid', __( 'The selected verification page is no longer available.', 'certloom' ) );
		}

		if ( ! Ecapla_CertVerify_Settings::save( $next_settings ) ) {
			return new \WP_Error( 'certloom_setup_settings_save_failed', __( 'Certloom could not apply the reviewed delivery and verification settings.', 'certloom' ) );
		}
		$backup = \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( $next_schedule, $next_retention );
		if ( is_wp_error( $backup ) ) {
			Ecapla_CertVerify_Settings::save( $current_settings );
			return $backup;
		}
		if ( '' !== $organization ) {
			$profile = Ecapla_CertVerify_Onboarding::save_profile(
				$organization,
				$page_id,
				array_key_exists( 'appearance_reviewed', $draft ) ? ! empty( $draft['appearance_reviewed'] ) : null,
				array_key_exists( 'verification_tested', $draft ) ? ! empty( $draft['verification_tested'] ) : null
			);
			if ( is_wp_error( $profile ) ) {
				\Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( $current_backup['schedule'], $current_backup['retention'] );
				Ecapla_CertVerify_Settings::save( $current_settings );
				return $profile;
			}
		}
		$completed = Ecapla_CertVerify_Onboarding::complete_wizard();
		if ( is_wp_error( $completed ) ) {
			return new \WP_Error( 'certloom_setup_progress_finalize_failed', __( 'Your reviewed settings were applied, but Certloom could not mark the Wizard as complete. You can safely reopen Setup and review the same settings again.', 'certloom' ) );
		}
		return true;
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
		if ( ! isset( $_POST['certverify_save_fields'] ) ) {
			return;
		}
		$this->require_manage_permission();
		check_admin_referer( 'certverify_save_fields' );
		$this->require_writes_available( admin_url( 'admin.php?page=certloom-fields' ) );

		$existing = Ecapla_CertVerify_Fields::get_field_map();
		$submitted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each nested field key and value is allow-listed and sanitized below.
		$new_fields = array();
		$order = 0;
		foreach ( $submitted as $row ) {
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
			'accent_color'             => $accent ? $accent : '#0f6e56',
			'show_credit'              => ! empty( $_POST['show_credit'] ),
			'credit_text'              => isset( $_POST['credit_text'] ) ? sanitize_text_field( wp_unslash( $_POST['credit_text'] ) ) : '',
			'credit_color'             => $credit_color,
			'credit_size'              => $credit_size,
			'credit_align'             => $credit_align,
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
			'privacy_erasure_policy'   => isset( $_POST['privacy_erasure_policy'] ) && 'anonymize_mutable_recipient' === sanitize_key( wp_unslash( $_POST['privacy_erasure_policy'] ) ) ? 'anonymize_mutable_recipient' : 'retain',
			'verification_branding_enabled' => ! empty( $_POST['verification_branding_enabled'] ),
			'verification_brand_name'    => isset( $_POST['verification_brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['verification_brand_name'] ) ) : '',
			'verification_brand_tagline' => isset( $_POST['verification_brand_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['verification_brand_tagline'] ) ) : '',
			'verification_surface_color' => isset( $_POST['verification_surface_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['verification_surface_color'] ) ) : '#ffffff',
			'verification_text_color'    => isset( $_POST['verification_text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['verification_text_color'] ) ) : '#17201d',
			'verification_use_site_icon' => ! empty( $_POST['verification_use_site_icon'] ),
			'verification_qr_enabled' => ! empty( $_POST['verification_qr_enabled'] ),
			'verification_qr_logo_enabled' => ! empty( $_POST['verification_qr_logo_enabled'] ),
			'verification_barcode_enabled' => ! empty( $_POST['verification_barcode_enabled'] ),
			'numbering_mode'             => isset( $_POST['numbering_mode'] ) ? sanitize_key( wp_unslash( $_POST['numbering_mode'] ) ) : 'manual',
			'numbering_prefix'           => isset( $_POST['numbering_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_prefix'] ) ) : 'CERT-',
			'numbering_suffix'           => isset( $_POST['numbering_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_suffix'] ) ) : '',
			'numbering_start'            => isset( $_POST['numbering_start'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_start'] ) ) : '1',
			'numbering_padding'          => isset( $_POST['numbering_padding'] ) ? sanitize_text_field( wp_unslash( $_POST['numbering_padding'] ) ) : '6',
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
		$client_id     = isset( $_POST['google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_client_id'] ) ) : '';
		$client_secret = isset( $_POST['google_client_secret'] ) ? trim( (string) wp_unslash( $_POST['google_client_secret'] ) ) : '';
		$saved = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::save_client_credentials( $client_id, $client_secret );
		if ( is_wp_error( $saved ) ) { $this->redirect_error( $saved->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		$url = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::authorization_url();
		if ( is_wp_error( $url ) ) { $this->redirect_error( $url->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_redirect( esc_url_raw( $url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact Google OAuth URL generated by the provider boundary.
		exit;
	}

	public function handle_google_drive_oauth_callback() {
		$this->require_options_permission();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state is the one-time CSRF boundary for this provider callback.
		if ( ! empty( $_GET['error'] ) ) {
			$this->redirect_error( __( 'Google Drive connection was cancelled or refused.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$result = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::complete_authorization( $state, $code );
		if ( is_wp_error( $result ) ) { $this->redirect_error( $result->get_error_message(), admin_url( 'admin.php?page=certloom-import' ) ); }
		wp_safe_redirect( add_query_arg( 'backup_notice', 'google-connected', admin_url( 'admin.php?page=certloom-import' ) ) );
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
		$dashboard_analytics = null;
		if ( current_user_can( Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS ) ) {
			$verification_analytics = Ecapla_CertVerify_Analytics::report( 30 );
			$issuance_analytics = ( new \Certloom\Application\Analytics\IssuanceAnalyticsService() )->report( 30 );
			if ( ! is_wp_error( $verification_analytics ) && ! is_wp_error( $issuance_analytics ) ) {
				$dashboard_analytics = array( 'verification' => $verification_analytics, 'issuance' => $issuance_analytics );
			}
		}
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/dashboard.php';
	}

	public function render_list_screen() {
		$this->require_manage_permission();
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

	public function render_onboarding_screen() {
		$this->require_options_permission();
		$setup_summary = Ecapla_CertVerify_Onboarding::get_summary();
		if ( is_wp_error( $setup_summary ) ) {
			$this->redirect_error( $setup_summary->get_error_message(), admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) );
		}
		$wizard_engine = new \Certloom\Application\Setup\PurposePresetEngine();
		$wizard_state = $setup_summary['state'];
		$wizard_steps = Ecapla_CertVerify_Onboarding::wizard_step_labels();
		$wizard_step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : (string) $wizard_state['wizard_step']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Wizard navigation.
		if ( ! isset( $wizard_steps[ $wizard_step ] ) ) { $wizard_step = 'purpose'; }
		$wizard_draft = is_array( $wizard_state['wizard_draft'] ) ? $wizard_state['wizard_draft'] : array();
		$wizard_purpose = isset( $wizard_draft['purpose'] ) ? (string) $wizard_draft['purpose'] : 'custom_other';
		$wizard_subcategory = isset( $wizard_draft['subcategory'] ) ? (string) $wizard_draft['subcategory'] : 'custom';
		$wizard_recommendation = $wizard_engine->recommend( $wizard_purpose, $wizard_subcategory );
		if ( is_wp_error( $wizard_recommendation ) ) { $wizard_recommendation = array(); }
		$wizard_current_settings = Ecapla_CertVerify_Settings::get_all();
		$wizard_backup_settings = \Certloom\Infrastructure\Backup\ScheduledBackup::settings();
		$wizard_domain_preview = defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW;
		$wizard_mode = \Certloom\Infrastructure\Admin\AdminUiMode::current();
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/setup-wizard.php';
	}


	public function render_integrations_screen() {
		$this->require_manage_permission();
		$tutor_admin = new \Certloom\Infrastructure\Admin\TutorLmsIntegrationAdmin();
		$tutor = $tutor_admin->screen_data();
		$learndash_admin = new \Certloom\Infrastructure\Admin\LearnDashIntegrationAdmin();
		$learndash = $learndash_admin->screen_data();
		$detected = array();
		if ( ! empty( $tutor['status']['available'] ) ) { $detected[] = __( 'Tutor LMS', 'certloom' ); }
		if ( ! empty( $learndash['status']['available'] ) ) { $detected[] = __( 'LearnDash', 'certloom' ); }
		$framework = array(
			'status' => $detected ? sprintf( /* translators: %s: detected LMS names. */ __( 'Detected: %s', 'certloom' ), implode( ', ', $detected ) ) : __( 'Integration framework ready', 'certloom' ),
			'contract' => __( 'INT-02/03 · LMS adapters', 'certloom' ),
			'adapters' => array(
				array( 'key' => 'tutor_lms', 'label' => __( 'Tutor LMS', 'certloom' ), 'available' => ! empty( $tutor['status']['available'] ) ),
				array( 'key' => 'learndash', 'label' => __( 'LearnDash', 'certloom' ), 'available' => ! empty( $learndash['status']['available'] ) ),
			),
			'next' => __( 'Tutor LMS and LearnDash share the same bounded recipient, mapping and idempotent issuance architecture. The approved navigation and plain-language consolidation follows both adapters.', 'certloom' ),
		);
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
				'eyebrow' => __( 'Phase 1 data model', 'certloom' ),
				'description' => __( 'First-class Recipient administration is implemented in the normalized domain and remains acceptance-gated until the real WordPress/MySQL migration and cutover proof passes.', 'certloom' ),
				'status' => __( 'Implementation complete · production acceptance gated', 'certloom' ),
			),
			'certloom-domain-programs' => array(
				'title' => __( 'Programs', 'certloom' ),
				'eyebrow' => __( 'Phase 1 data model', 'certloom' ),
				'description' => __( 'Standalone Program administration is implemented in the normalized domain and remains acceptance-gated. Certloom will not emulate Program relationships inside legacy custom fields.', 'certloom' ),
				'status' => __( 'Implementation complete · production acceptance gated', 'certloom' ),
			),
			'certloom-templates' => array(
				'title' => __( 'Templates', 'certloom' ),
				'eyebrow' => __( 'Pro commercial core · implementation gated', 'certloom' ),
				'description' => __( 'Versioned Templates, the visual Template Library, curated premium design concepts and Quick/Pro Designer are implemented over immutable Template revisions and the canonical Render Plan. I18N-02 locale/RTL and Program language-specific published-design resolution are implemented. Production PDF/image publication remains fail-closed until renderer release authority is accepted.', 'certloom' ),
				'status' => __( 'Template Library + Designer implemented · production artifact authority gated', 'certloom' ),
			),
			'certloom-issuance' => array(
				'title' => __( 'Issuance', 'certloom' ),
				'eyebrow' => __( 'P0 issuance workflow · implementation gated', 'certloom' ),
				'description' => __( 'Manual and Bulk Issuance, Credential Management, Email Delivery, Recipient Portal, Public Sharing, Analytics and INT-01 are implemented. Program Profiles resolve Program and locale-specific published designs, while immutable Versions freeze exact Program, locale and Template provenance. Normalized public authority and production PDF/image exports remain acceptance-gated.', 'certloom' ),
				'status' => __( 'Issuance workflow implemented · normalized/public artifact authority gated', 'certloom' ),
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
		if ( ! current_user_can( Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS ) ) {
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
					'<a href="https://ecapla.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ecapla', 'certloom' ) . '</a>'
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
		wp_safe_redirect( add_query_arg( 'ecapla_certverify_error', $message, $url ) );
		exit;
	}
}
