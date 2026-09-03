<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Email\EmailDesignAssignmentRepository;
use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** First-class Email Design workspace and hierarchy authority. */
final class EmailDesignAdmin {
	const PAGE = 'certloom-email-designs';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 53 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_certloom_email_design_save', array( $this, 'save' ) );
		add_action( 'admin_post_certloom_email_design_delete', array( $this, 'delete' ) );
		add_action( 'admin_post_certloom_email_design_assign', array( $this, 'assign' ) );
		add_action( 'admin_post_certloom_email_design_inherit', array( $this, 'inherit' ) );
	}

	/** @return void */
	public function menu() {
		// Keep route reachability on the established Certloom management boundary.
		// The render/mutation callbacks enforce granular Email authorization too,
		// so a stale granular role map cannot make WordPress permanently mark this
		// contextual route as no-privilege before Certloom can explain/repair it.
		\Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		add_submenu_page( 'certloom', __( 'Email designs', 'certloom' ), __( 'Email designs', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::PAGE, array( $this, 'render' ) );
	}

	/** @return void */
	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- asset routing.
		if ( self::PAGE !== $page ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'certloom-email-designer', CERTLOOM_URL . 'admin/assets/email-designer.js', array(), CERTLOOM_VERSION, true );
		wp_localize_script( 'certloom-email-designer', 'certloomEmailDesignerL10n', array(
			'addHeading' => __( 'Heading', 'certloom' ), 'addText' => __( 'Text', 'certloom' ), 'addButton' => __( 'Button', 'certloom' ), 'addImage' => __( 'Image', 'certloom' ), 'addDivider' => __( 'Divider', 'certloom' ), 'addSpacer' => __( 'Spacer', 'certloom' ), 'addColumns' => __( 'Two columns', 'certloom' ),
			'headingPlaceholder' => __( 'Your credential is ready', 'certloom' ), 'textPlaceholder' => __( 'Write the message recipients should receive.', 'certloom' ), 'buttonText' => __( 'View credential', 'certloom' ), 'buttonUrl' => '{{verification_url}}',
			'chooseImage' => __( 'Choose an email image', 'certloom' ), 'useImage' => __( 'Use this image', 'certloom' ), 'remove' => __( 'Remove', 'certloom' ), 'moveUp' => __( 'Move up', 'certloom' ), 'moveDown' => __( 'Move down', 'certloom' ), 'blockType' => __( 'Content block', 'certloom' ),
			'leftColumn' => __( 'Left column', 'certloom' ), 'rightColumn' => __( 'Right column', 'certloom' ), 'urlLabel' => __( 'URL', 'certloom' ), 'altText' => __( 'Alternative text', 'certloom' ), 'spacingPixels' => __( 'Spacing (px)', 'certloom' ), 'headingLevel' => __( 'Heading level', 'certloom' ),
			'sampleRecipient' => __( 'Sara Khan', 'certloom' ), 'sampleProgram' => __( 'Professional Leadership Program', 'certloom' ), 'sampleIssuer' => __( 'Example Institute', 'certloom' ), 'sampleGrade' => __( 'Distinction', 'certloom' ), 'verifyThisCredential' => __( 'Verify this credential', 'certloom' ),
			'desktop' => __( 'Desktop preview', 'certloom' ), 'mobile' => __( 'Mobile preview', 'certloom' ), 'previewTitle' => __( 'Email preview', 'certloom' ),
			'noCompatibleDesign' => __( 'No available email design matches this message purpose. Create or activate a compatible design first.', 'certloom' ),
			'contentBlocksRequired' => __( 'Add at least one usable content block before saving this email design.', 'certloom' ),
			'emptyContent' => __( 'No content blocks yet. Add a Heading, Text, Button, Image or another block to build this message.', 'certloom' ),
			'tokens' => array(
				'recipient_name' => __( 'Recipient name', 'certloom' ), 'program_title' => __( 'Program title', 'certloom' ), 'issuer_name' => __( 'Issuer name', 'certloom' ), 'credential_number' => __( 'Credential number', 'certloom' ), 'issue_date' => __( 'Issue date', 'certloom' ), 'expiry_date' => __( 'Expiry date', 'certloom' ), 'grade' => __( 'Grade', 'certloom' ), 'verification_url' => __( 'Verification URL', 'certloom' ), 'verification_link' => __( 'Verification link', 'certloom' ),
			),
		) );
	}

	/** @return void */
	public function render() {
		$this->cap();
		$catalog = new EmailTemplateCatalogRepository();
		$designs = $catalog->all();
		$design_by_id = array();
		foreach ( $designs as $design ) { if ( is_array( $design ) && ! empty( $design['id'] ) ) { $design_by_id[ (string) $design['id'] ] = $design; } }
		$edit_id = isset( $_GET['design_id'] ) ? sanitize_key( wp_unslash( $_GET['design_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only editor selection.
		$editing = '' !== $edit_id ? $catalog->get( $edit_id ) : null;
		if ( ! is_array( $editing ) ) {
			$compiled = ( new EmailDesignCompiler() )->compile( EmailDesignCompiler::default_blocks(), EmailDesignCompiler::default_theme() );
			$editing = array( 'id' => '', 'name' => '', 'purpose' => EmailDesignPurposes::ISSUED, 'preheader' => __( 'Your credential is ready to view and verify.', 'certloom' ), 'active' => 1, 'subject' => __( 'Your {{program_title}} credential', 'certloom' ), 'text_body' => '', 'blocks' => $compiled['blocks'], 'theme' => $compiled['theme'] );
		}
		$assignments = ( new EmailDesignAssignmentRepository() )->all();
		$issuer_ids = $program_ids = $batch_ids = array();
		foreach ( $assignments as $row ) {
			if ( 'issuer' === $row['scope_type'] ) { $issuer_ids[] = absint( $row['scope_id'] ); }
			if ( 'program' === $row['scope_type'] ) { $program_ids[] = absint( $row['scope_id'] ); }
			if ( 'batch' === $row['scope_type'] ) { $batch_ids[] = absint( $row['scope_id'] ); }
		}
		$issuer_names = ( new IssuerRepository() )->labels_by_ids( $issuer_ids ); if ( is_wp_error( $issuer_names ) ) { $issuer_names = array(); }
		$program_names = ( new ProgramRepository() )->labels_by_ids( $program_ids ); if ( is_wp_error( $program_names ) ) { $program_names = array(); }
		$batch_names = ( new BatchRepository() )->labels_by_ids( $batch_ids ); if ( is_wp_error( $batch_names ) ) { $batch_names = array(); }
		$initial_issuers = ( new IssuerRepository() )->list_page( '', 1, 20 ); $initial_issuers = is_wp_error( $initial_issuers ) ? array() : (array) $initial_issuers['items'];
		$initial_programs = ( new ProgramRepository() )->list_page( '', 1, 20, 'active' ); $initial_programs = is_wp_error( $initial_programs ) ? array() : (array) $initial_programs['items'];
		$context_nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		$notice = isset( $_GET['certloom_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['certloom_error'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$purposes = EmailDesignPurposes::labels();
		include CERTLOOM_PATH . 'admin/views/email-designs.php';
	}

	/** @return void */
	public function save() {
		$this->cap(); check_admin_referer( 'certloom_email_design_save' );
		$raw_blocks = isset( $_POST['blocks_json'] ) ? (string) wp_unslash( $_POST['blocks_json'] ) : '';
		$blocks = '' !== trim( $raw_blocks ) ? json_decode( $raw_blocks, true ) : null;
		if ( ! is_array( $blocks ) || JSON_ERROR_NONE !== json_last_error() ) {
			$this->redirect( new \WP_Error( 'certloom_email_design_blocks_invalid', __( 'Certloom could not read the email content blocks. Reload the editor and try again; your saved design has not been changed.', 'certloom' ) ), '' );
		}
		if ( ! ( new EmailDesignCompiler() )->sanitize_blocks( $blocks ) ) {
			$this->redirect( new \WP_Error( 'certloom_email_design_blocks_empty', __( 'Add at least one usable content block before saving this email design.', 'certloom' ) ), '' );
		}
		$theme = array(
			'background' => $_POST['theme_background'] ?? '', 'content_background' => $_POST['theme_content_background'] ?? '', 'text_color' => $_POST['theme_text_color'] ?? '', 'heading_color' => $_POST['theme_heading_color'] ?? '', 'button_background' => $_POST['theme_button_background'] ?? '', 'button_text' => $_POST['theme_button_text'] ?? '', 'link_color' => $_POST['theme_link_color'] ?? '', 'font_family' => isset( $_POST['theme_font_family'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_font_family'] ) ) : '', 'email_width' => absint( $_POST['theme_email_width'] ?? 640 ), 'border_radius' => absint( $_POST['theme_border_radius'] ?? 12 ),
		);
		$result = ( new EmailTemplateCatalogRepository() )->save( array(
			'id' => isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : '', 'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', 'purpose' => isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : '', 'preheader' => isset( $_POST['preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['preheader'] ) ) : '', 'active' => ! empty( $_POST['active'] ), 'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '', 'text_body' => isset( $_POST['text_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text_body'] ) ) : '', 'blocks' => $blocks, 'theme' => $theme,
		) );
		$this->redirect( $result, __( 'Email design saved.', 'certloom' ), is_wp_error( $result ) ? '' : (string) $result );
	}

	/** @return void */
	public function delete() {
		$this->cap(); $id = isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : ''; check_admin_referer( 'certloom_email_design_delete_' . $id );
		$this->redirect( ( new EmailTemplateCatalogRepository() )->delete( $id ), __( 'Email design deleted.', 'certloom' ) );
	}

	/** @return void */
	public function assign() {
		$this->cap(); check_admin_referer( 'certloom_email_design_assign' );
		$scope = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : 'global';
		$id = 0; if ( 'issuer' === $scope ) { $id = absint( $_POST['issuer_id'] ?? 0 ); } elseif ( 'program' === $scope ) { $id = absint( $_POST['program_id'] ?? 0 ); } elseif ( 'batch' === $scope ) { $id = absint( $_POST['batch_id'] ?? 0 ); }
		$result = ( new EmailDesignAssignmentRepository() )->save( $scope, $id, $_POST['purpose'] ?? '', $_POST['design_id'] ?? '' );
		$this->redirect( $result, __( 'Email inheritance updated.', 'certloom' ) );
	}

	/** @return void */
	public function inherit() {
		$this->cap(); $scope = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : ''; $id = absint( $_POST['scope_id'] ?? 0 ); $purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : ''; check_admin_referer( 'certloom_email_design_inherit_' . $scope . '_' . $id . '_' . $purpose );
		$this->redirect( ( new EmailDesignAssignmentRepository() )->remove( $scope, $id, $purpose ), __( 'Inheritance restored.', 'certloom' ) );
	}

	/** @return string */
	public static function scope_label( array $row, array $issuer_names, array $program_names, array $batch_names ) {
		$id = absint( $row['scope_id'] ?? 0 );
		if ( 'global' === $row['scope_type'] ) { return __( 'Global', 'certloom' ); }
		if ( 'issuer' === $row['scope_type'] ) { return (string) ( $issuer_names[ $id ] ?? sprintf( /* translators: %d: Issuer database ID used only as a fallback label. */ __( 'Issuer #%d', 'certloom' ), $id ) ); }
		if ( 'program' === $row['scope_type'] ) { return (string) ( $program_names[ $id ] ?? sprintf( /* translators: %d: Program database ID used only as a fallback label. */ __( 'Program #%d', 'certloom' ), $id ) ); }
		return (string) ( $batch_names[ $id ] ?? sprintf( /* translators: %d: Batch database ID used only as a fallback label. */ __( 'Batch #%d', 'certloom' ), $id ) );
	}

	private function cap() { if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) && ! current_user_can( ECAPLA_CERTVERIFY_CAP ) ) { wp_die( esc_html__( 'You do not have permission to manage Certloom email designs.', 'certloom' ) ); } }
	private function redirect( $result, $success, $design_id = '' ) { $args = array( 'page' => self::PAGE ); if ( is_wp_error( $result ) ) { $args['certloom_error'] = $result->get_error_message(); } else { $args['certloom_notice'] = $success; if ( '' !== $design_id ) { $args['design_id'] = $design_id; } } wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit; }
}
