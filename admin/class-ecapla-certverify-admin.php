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

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_delete' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_preview' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_commit' ) );
		add_action( 'admin_init', array( $this, 'handle_onboarding_save' ) );
		add_action( 'admin_init', array( $this, 'handle_onboarding_create_page' ) );
		add_action( 'admin_init', array( $this, 'handle_onboarding_complete' ) );
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
		add_menu_page(
			__( 'Certloom', 'certloom' ),
			__( 'Certloom', 'certloom' ),
			ECAPLA_CERTVERIFY_CAP,
			self::DASHBOARD_PAGE,
			array( $this, 'render_dashboard_screen' ),
			'dashicons-awards',
			30
		);
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Dashboard', 'certloom' ), __( 'Dashboard', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::DASHBOARD_PAGE, array( $this, 'render_dashboard_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Credentials', 'certloom' ), __( 'Credentials', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::CERTIFICATES_PAGE, array( $this, 'render_list_screen' ) );

		// Keep the planned application architecture visible without exposing gated domain data.
		if ( ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) ) {
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Recipients', 'certloom' ), __( 'Recipients', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-recipients', array( $this, 'render_roadmap_placeholder_screen' ) );
			add_submenu_page( self::DASHBOARD_PAGE, __( 'Programs', 'certloom' ), __( 'Programs', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-domain-programs', array( $this, 'render_roadmap_placeholder_screen' ) );
		}
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Templates', 'certloom' ), __( 'Templates', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-templates', array( $this, 'render_roadmap_placeholder_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Issuance', 'certloom' ), __( 'Issuance', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-issuance', array( $this, 'render_roadmap_placeholder_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Data Tools', 'certloom' ), __( 'Data Tools', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-import', array( $this, 'render_import_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Integrations', 'certloom' ), __( 'Integrations', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-integrations', array( $this, 'render_roadmap_placeholder_screen' ) );
		add_submenu_page( self::DASHBOARD_PAGE, __( 'Settings', 'certloom' ), __( 'Settings', 'certloom' ), 'manage_options', 'certloom-settings', array( $this, 'render_settings_screen' ) );

		// Contextual utility screens stay reachable from the application but do not clutter the primary shell.
		add_submenu_page( null, __( 'Add Certificate', 'certloom' ), __( 'Add Certificate', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-add', array( $this, 'render_edit_screen' ) );
		add_submenu_page( null, __( 'Field Manager', 'certloom' ), __( 'Field Manager', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-fields', array( $this, 'render_fields_screen' ) );
		add_submenu_page( null, __( 'Appearance & Embed', 'certloom' ), __( 'Appearance & Embed', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-frontend', array( $this, 'render_frontend_screen' ) );
		add_submenu_page( null, __( 'Setup', 'certloom' ), __( 'Setup', 'certloom' ), 'manage_options', 'certloom-setup', array( $this, 'render_onboarding_screen' ) );
		add_submenu_page( null, __( 'Review bulk change', 'certloom' ), __( 'Review bulk change', 'certloom' ), ECAPLA_CERTVERIFY_CAP, 'certloom-bulk-review', array( $this, 'render_bulk_review_screen' ) );
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
		if ( empty( $_POST['confirm_restore'] ) ) {
			$this->redirect_error( __( 'Confirm that you understand the selected restore mode can replace or merge Certloom data and that Certloom writes will be paused until the restore finishes.', 'certloom' ), admin_url( 'admin.php?page=certloom-import' ) );
		}
		$token  = isset( $_POST['backup_token'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_token'] ) ) : '';
		$policy = isset( $_POST['conflict_policy'] ) ? sanitize_key( wp_unslash( $_POST['conflict_policy'] ) ) : 'skip';
		$mode   = isset( $_POST['restore_mode'] ) ? sanitize_key( wp_unslash( $_POST['restore_mode'] ) ) : 'replace';
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
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/setup-wizard.php';
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
				'eyebrow' => __( 'Planned Pro commercial core', 'certloom' ),
				'description' => __( 'Versioned certificate templates, starter designs, assignments and the visual designer arrive after the production renderer acceptance track. No placeholder control here writes template data.', 'certloom' ),
				'status' => __( 'Planned · renderer-dependent', 'certloom' ),
			),
			'certloom-issuance' => array(
				'title' => __( 'Issuance', 'certloom' ),
				'eyebrow' => __( 'Planned issuance workflow', 'certloom' ),
				'description' => __( 'Manual and bulk issuance will use immutable Credential Versions, server-authoritative artifacts, reviewed eligibility and durable jobs. The current registry editor remains the safe live write path until those dependencies are accepted.', 'certloom' ),
				'status' => __( 'Planned · domain and renderer dependencies', 'certloom' ),
			),
			'certloom-integrations' => array(
				'title' => __( 'Integrations', 'certloom' ),
				'eyebrow' => __( 'Planned automation layer', 'certloom' ),
				'description' => __( 'Tutor LMS and LearnDash are the first planned LMS adapters, followed by additional LMS, commerce, API and webhook integrations. No integration is presented as active before its acceptance tests exist.', 'certloom' ),
				'status' => __( 'Planned', 'certloom' ),
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
		include ECAPLA_CERTVERIFY_PATH . 'admin/views/import-upload.php';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
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
