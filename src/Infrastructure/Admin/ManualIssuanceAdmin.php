<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Program\ProgramProfile;
use Certloom\Application\Program\ProgramLifecyclePolicy;
use Certloom\Domain\Rendering\TableDataContract;
use Certloom\Infrastructure\Issuance\ManualIssuanceNumberAllocator;
use Certloom\Infrastructure\Rendering\ManualIssuancePreviewService;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Verification\PublicVerificationGate;
use Certloom\Infrastructure\Admin\ScopedFieldAdminAjax;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Premium one-at-a-time administrator issuance workflow.
 *
 * The screen is available only inside the normalized-domain admin-preview gate.
 * Review is mutation-free; the final POST revalidates the exact request and
 * review SHA before calling ManualIssuanceService.
 */
final class ManualIssuanceAdmin {
	const PAGE = 'certloom-issuance';
	const NOTICE_PREFIX = 'certloom_manual_issue_notice_';

	/** @var \WP_Error|null */
	private $request_error = null;

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_post_certloom_manual_issuance_issue', array( $this, 'issue' ) );
		add_action( 'wp_ajax_certloom_manual_quick_program', array( $this, 'quick_program' ) );
		add_action( 'wp_ajax_certloom_manual_quick_batch', array( $this, 'quick_batch' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page( null, __( 'Issuance', 'certloom' ), __( 'Issuance', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, self::PAGE, array( $this, 'render' ) );
	}

	/** @return void */
	public function render() {
		$this->require_capability();
		$receipt_version = isset( $_GET['issued_version'] ) ? absint( $_GET['issued_version'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only receipt selector.
		$review = null;
		$request = null;
		if ( isset( $_POST['certloom_manual_issuance_step'] ) && 'review' === sanitize_key( wp_unslash( $_POST['certloom_manual_issuance_step'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			check_admin_referer( 'certloom_manual_issuance_review' );
			$request = $this->request_from_post();
			$compatibility = is_wp_error( $this->request_error ) ? $this->request_error : $this->phase2_backend_compatibility_error( $request );
			$review = is_wp_error( $compatibility ) ? $compatibility : ( new ManualIssuanceService() )->review( $request );
			if ( is_array( $review ) ) { $review['phase2_ui_request'] = $request; }
		}

		$simple = AdminUiMode::is_simple();
		$current_stage = $receipt_version ? 'receipt' : ( is_array( $review ) ? 'review' : 'compose' );
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-manual-issuance">';
		echo '<section class="certloom-page-hero certloom-issue-hero" aria-labelledby="certloom-issue-title"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential operations', 'certloom' ) . '</span><h1 id="certloom-issue-title">' . esc_html__( 'Issue credential', 'certloom' ) . '</h1><p>' . esc_html__( 'Create one credential, review it, then issue only when everything is correct. Recipient, title and effective date form the fast path; organization, Program, Batch, Signer and artwork stay optional.', 'certloom' ) . '</p></div></section>';
		( new AdminProductNavigation() )->render_current_subnav();
		$this->notice();
		$this->steps( $current_stage );

		if ( $receipt_version ) {
			$this->render_receipt( $receipt_version );
		} elseif ( is_wp_error( $review ) ) {
			$this->render_review_error( $review );
			$this->render_compose( is_array( $request ) ? $request : array() );
		} elseif ( is_array( $review ) ) {
			$this->render_review( $review );
		} else {
			$this->render_compose( array() );
		}
		$this->render_technical_status( $simple, $current_stage );
		echo '</div>';
	}

	/** @return void */
	public function issue() {
		$this->require_capability();
		$fingerprint = isset( $_POST['review_fingerprint'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['review_fingerprint'] ) ) ) : '';
		check_admin_referer( 'certloom_manual_issuance_issue_' . $fingerprint );
		$request = $this->request_from_post();
		if ( is_wp_error( $this->request_error ) ) {
			$this->store_notice( 'error', $this->request_error->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}
		$compatibility = $this->phase2_backend_compatibility_error( $request );
		if ( is_wp_error( $compatibility ) ) {
			$this->store_notice( 'error', $compatibility->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}
		$result = ( new ManualIssuanceService() )->issue( $request, $fingerprint, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->store_notice( 'error', $result->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}
		$this->store_notice( 'success', ! empty( $result['idempotent_replay'] ) ? __( 'That exact issuance was already committed. Certloom returned the existing receipt without issuing a duplicate.', 'certloom' ) : __( 'Credential issued successfully. The saved credential and issuance history were recorded together.', 'certloom' ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'issued_version' => absint( $result['version_id'] ?? 0 ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	private function render_compose( array $values ) {
		$selected_recipient_id = absint( $values['recipient_id'] ?? 0 );
		if ( ! $selected_recipient_id && isset( $_GET['recipient_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection from a Recipient profile.
			$selected_recipient_id = absint( $_GET['recipient_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection from a Recipient profile.
			if ( $selected_recipient_id ) {
				$values['recipient_id']   = $selected_recipient_id;
				$values['recipient_mode'] = 'existing';
			}
		}

		$recipient_repo = new RecipientRepository();
		$recipients = $recipient_repo->list_page( '', 1, 20 );
		$selected_program_id = absint( $values['program_id'] ?? 0 );
		$selected_batch_id   = absint( $values['batch_id'] ?? 0 );
		$selected_issuer_id  = absint( $values['issuer_id'] ?? 0 );
		$selected_signer_id  = absint( $values['signer_id'] ?? 0 );
		if ( ! $selected_program_id && isset( $_GET['program_id'] ) ) { $selected_program_id = absint( $_GET['program_id'] ); $values['program_id'] = $selected_program_id; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only quick-create preselection.
		if ( ! $selected_batch_id && isset( $_GET['batch_id'] ) ) { $selected_batch_id = absint( $_GET['batch_id'] ); $values['batch_id'] = $selected_batch_id; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only quick-create preselection.

		$context_choices = new ScopedContextChoices();
		$programs  = $context_choices->programs( $selected_program_id );
		$program_issuer_id = 0;
		$program_issuer_name = '';
		if ( ! is_wp_error( $programs ) && $selected_program_id ) {
			foreach ( (array) $programs as $program_choice ) {
				if ( $selected_program_id !== absint( $program_choice['id'] ?? 0 ) ) { continue; }
				$program_issuer_id = absint( $program_choice['issuer_id'] ?? 0 );
				$program_issuer_name = trim( (string) ( $program_choice['issuer_name'] ?? '' ) );
				break;
			}
		}
		// The Program-owned Issuer is authoritative. Do not ask the operator to
		// make a contradictory second relationship choice during issuance.
		if ( $program_issuer_id ) { $selected_issuer_id = $program_issuer_id; $values['issuer_id'] = $program_issuer_id; }
		$batches   = $context_choices->batches( $selected_program_id, $selected_batch_id );
		$issuers   = $context_choices->issuers( $selected_issuer_id );
		$signers   = $context_choices->signers( $selected_signer_id );
		$templates = $context_choices->templates( absint( $values['template_id'] ?? 0 ) );
		$choice_sets = array( $recipients, $programs, $batches, $issuers, $signers, $templates );
		foreach ( $choice_sets as $choice_set ) {
			if ( is_wp_error( $choice_set ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $choice_set->get_error_message() ) . '</p></div>';
				return;
			}
		}

		$recipient_items = (array) ( $recipients['items'] ?? array() );
		// A direct "Issue credential" action from a Recipient profile must keep
		// that exact Recipient selectable even when it is outside the first search
		// page. The repository lookup is authoritative; the visible search window
		// is only a browsing convenience.
		if ( $selected_recipient_id ) {
			$selected_recipient = $recipient_repo->find( $selected_recipient_id );
			if ( is_array( $selected_recipient ) ) {
				$already_visible = false;
				foreach ( $recipient_items as $recipient_item ) {
					if ( $selected_recipient_id === absint( $recipient_item['id'] ?? 0 ) ) { $already_visible = true; break; }
				}
				if ( ! $already_visible ) { array_unshift( $recipient_items, $selected_recipient ); }
			}
		}

		$program_items  = (array) $programs;
		$batch_items    = (array) $batches;
		$issuer_items   = (array) $issuers;
		$signer_items   = (array) $signers;
		$template_items = (array) $templates;
		$mode = isset( $values['recipient_mode'] ) && 'new' === $values['recipient_mode'] ? 'new' : 'existing';
		$valid_from = (string) ( $values['valid_from'] ?? current_time( 'Y-m-d' ) );
		$presentation_explicit = array_key_exists( 'presentation_mode', $values );
		$presentation_mode = sanitize_key( (string) ( $values['presentation_mode'] ?? 'details_only' ) );
		if ( ! in_array( $presentation_mode, array( 'details_only', 'image', 'design' ), true ) ) { $presentation_mode = 'details_only'; }
		$design_source = sanitize_key( (string) ( $values['design_source'] ?? ( ! empty( $values['template_id'] ) ? 'assigned' : 'inherited' ) ) );
		if ( ! in_array( $design_source, array( 'inherited', 'assigned' ), true ) ) { $design_source = 'inherited'; }
		$credential_basis = sanitize_key( (string) ( $values['credential_basis'] ?? 'program' ) );
		if ( ! in_array( $credential_basis, array( 'program', 'independent' ), true ) ) { $credential_basis = 'program'; }
		$title_source = sanitize_key( (string) ( $values['title_source'] ?? ( 'program' === $credential_basis ? 'program' : 'custom' ) ) );
		if ( ! in_array( $title_source, array( 'program', 'custom' ), true ) ) { $title_source = 'program' === $credential_basis ? 'program' : 'custom'; }
		if ( 'independent' === $credential_basis ) { $title_source = 'custom'; }
		$credential_title = trim( (string) ( $values['credential_title'] ?? '' ) );
		$selected_program_title = '';
		$selected_program = null;
		foreach ( $program_items as $program_item ) {
			if ( $selected_program_id === absint( $program_item['id'] ?? 0 ) ) { $selected_program = $program_item; $selected_program_title = trim( (string) ( $program_item['title'] ?? '' ) ); break; }
		}
		$selected_program_has_validity = is_array( $selected_program ) && ! empty( $selected_program['program_default_available'] );
		$selected_program_validity_summary = is_array( $selected_program ) ? trim( (string) ( $selected_program['validity_summary'] ?? '' ) ) : '';
		$expiry_mode = array_key_exists( 'expiry_mode', $values ) ? sanitize_key( (string) $values['expiry_mode'] ) : ( $selected_program_id ? ( $selected_program_has_validity ? 'program' : '' ) : 'permanent' );
		if ( ! in_array( $expiry_mode, array( '', 'program', 'permanent', 'date' ), true ) ) { $expiry_mode = ''; }
		$certificate_image_id = absint( $values['certificate_image_id'] ?? 0 );
		$numbering = new ManualIssuanceNumberAllocator();
		$number_preview = $numbering->is_automatic() ? $numbering->review( '' ) : null;
		$context_nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		$independent_field_choices = array();
		$field_catalog = class_exists( '\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		foreach ( $field_catalog as $field_key => $field_definition ) {
			if ( ! is_array( $field_definition ) || ! ScopedFieldContract::is_scopable_definition( $field_definition ) || 'credential_custom' !== ScopedFieldContract::value_source( $field_key ) ) { continue; }
			$independent_field_choices[] = array(
				'key' => sanitize_key( (string) $field_key ),
				'label' => (string) ( $field_definition['label'] ?? $field_key ),
				'type' => (string) ( $field_definition['type'] ?? 'text' ),
				'sensitivity' => (string) ( $field_definition['sensitivity'] ?? 'personal' ),
				'public_exposure' => (string) ( $field_definition['public_exposure'] ?? 'private' ),
			);
		}

		echo '<section class="certloom-manual-card certloom-issue-v2-card"><div class="certloom-manual-card-head"><div><h2>' . esc_html__( 'Compose credential', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose the recipient, enter the credential details, then select how it should appear.', 'certloom' ) . '</p></div></div>';

		$task_label = __( 'Issue a credential', 'certloom' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '" aria-label="' . esc_attr( $task_label ) . '" class="certloom-manual-form certloom-issue-v2-form" data-certloom-human-form data-certloom-phase2-issuance data-certloom-scoped-fields-form data-certloom-scoped-fields-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-presentation-explicit="' . ( $presentation_explicit ? '1' : '0' ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted field before reviewing this credential.', 'certloom' ) . '">';
		wp_nonce_field( 'certloom_manual_issuance_review' );
		echo '<input type="hidden" name="certloom_manual_issuance_step" value="review">';
		echo '<div class="certloom-issue-left-column">';

		echo '<fieldset class="certloom-manual-fieldset certloom-recipient-compose" data-certloom-recipient-compose><legend class="screen-reader-text">' . esc_html__( 'Recipient', 'certloom' ) . '</legend>';
		echo '<p class="certloom-manual-fieldset-intro">' . esc_html__( 'Who is receiving this credential?', 'certloom' ) . '</p><div class="certloom-manual-choice-row certloom-choice-pills">';
		$this->radio( 'recipient_mode', 'existing', __( 'Choose existing recipient', 'certloom' ), $mode );
		$this->radio( 'recipient_mode', 'new', __( 'Create new recipient', 'certloom' ), $mode );
		echo '</div>';

		echo '<div class="certloom-recipient-mode-panel" data-certloom-recipient-existing-panel' . ( 'new' === $mode ? ' hidden' : '' ) . '><label class="certloom-manual-primary-field"><span>' . esc_html__( 'Recipient', 'certloom' ) . '</span><select name="recipient_id" data-certloom-recipient-existing-select data-certloom-context-lookup="recipient" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search recipients by name, email, or external ID…', 'certloom' ) . '" data-certloom-required-example="' . esc_attr__( 'Choose the person or organization that should receive this credential.', 'certloom' ) . '"><option value="">' . esc_html__( 'Search or choose recipient…', 'certloom' ) . '</option>';
		foreach ( $recipient_items as $recipient ) {
			$label = (string) ( $recipient['display_name'] ?? '' ); if ( ! empty( $recipient['email'] ) ) { $label .= ' — ' . (string) $recipient['email']; }
			echo '<option value="' . esc_attr( (string) absint( $recipient['id'] ?? 0 ) ) . '" ' . selected( absint( $values['recipient_id'] ?? 0 ), absint( $recipient['id'] ?? 0 ), false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'Start typing to search all Recipient profiles. Reuse one Recipient profile for the same person or organization.', 'certloom' ) . '</small></label></div>';

		$new = isset( $values['new_recipient'] ) && is_array( $values['new_recipient'] ) ? $values['new_recipient'] : array();
		$new_name = trim( (string) ( $new['display_name'] ?? '' ) );
		echo '<div class="certloom-recipient-mode-panel certloom-recipient-new-panel" data-certloom-recipient-new-panel' . ( 'new' !== $mode ? ' hidden' : '' ) . '><div class="certloom-recipient-new-summary"><div><strong data-certloom-recipient-new-summary-name>' . esc_html( '' !== $new_name ? $new_name : __( 'New Recipient profile', 'certloom' ) ) . '</strong><p class="description">' . esc_html__( 'Create the Recipient profile once. Credential-specific facts remain on this issued record.', 'certloom' ) . '</p></div><button type="button" class="button" data-certloom-dialog-open="certloom-new-recipient-dialog">' . esc_html( '' !== $new_name ? __( 'Edit profile', 'certloom' ) : __( 'Complete recipient profile', 'certloom' ) ) . '</button></div></div>';
		$this->render_new_recipient_dialog( $new );
		echo '</fieldset>';

		echo '<fieldset class="certloom-manual-fieldset certloom-credential-basis" data-certloom-credential-basis><legend class="screen-reader-text">' . esc_html__( 'Credential basis', 'certloom' ) . '</legend><p class="certloom-manual-fieldset-intro">' . esc_html__( 'What should this credential be based on?', 'certloom' ) . '</p><div class="certloom-manual-choice-row certloom-choice-pills">';
		$this->radio( 'credential_basis', 'program', __( 'Use a Program', 'certloom' ), $credential_basis );
		$this->radio( 'credential_basis', 'independent', __( 'Independent credential', 'certloom' ), $credential_basis );
		echo '</div><div class="certloom-program-primary-panel" data-certloom-program-basis-panel' . ( 'program' === $credential_basis ? '' : ' hidden' ) . '><label class="certloom-manual-primary-field"><span>' . esc_html__( 'Program', 'certloom' ) . '</span><select name="program_id" data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '" data-certloom-required-example="' . esc_attr__( 'Choose the Program this credential belongs to, or switch to Independent credential.', 'certloom' ) . '"><option value="0">' . esc_html__( 'Choose Program…', 'certloom' ) . '</option>';
		foreach ( $program_items as $program ) {
			echo '<option value="' . esc_attr( (string) absint( $program['id'] ?? 0 ) ) . '" data-program-title="' . esc_attr( (string) ( $program['title'] ?? '' ) ) . '" data-program-issuer-id="' . esc_attr( (string) absint( $program['issuer_id'] ?? 0 ) ) . '" data-program-issuer-name="' . esc_attr( (string) ( $program['issuer_name'] ?? '' ) ) . '" data-validity-mode="' . esc_attr( (string) ( $program['validity_mode'] ?? '' ) ) . '" data-validity-value="' . esc_attr( (string) absint( $program['validity_value'] ?? 0 ) ) . '" data-validity-unit="' . esc_attr( (string) ( $program['validity_unit'] ?? 'days' ) ) . '" data-program-validity-available="' . esc_attr( ! empty( $program['program_default_available'] ) ? '1' : '0' ) . '" data-validity-summary="' . esc_attr( (string) ( $program['validity_summary'] ?? '' ) ) . '" data-renewal-policy="' . esc_attr( (string) ( $program['renewal'] ?? 'not_applicable' ) ) . '" data-repeat-policy="' . esc_attr( (string) ( $program['repeat_policy'] ?? ProgramLifecyclePolicy::REPEAT_LIFECYCLE ) ) . '" ' . selected( $selected_program_id, absint( $program['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::program_label( $program ) ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'Programs define reusable credential semantics such as title, validity, Issuer, fields and inherited design. Choose Independent only for a one-off credential that should not belong to a Program.', 'certloom' ) . '</small></label><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-quick-program-dialog">' . esc_html__( 'Add new Program', 'certloom' ) . '</button></div></div></fieldset>';

		echo '<fieldset class="certloom-manual-fieldset certloom-issue-core"><legend class="screen-reader-text">' . esc_html__( 'Credential', 'certloom' ) . '</legend>';
		echo '<div class="certloom-credential-title-policy" data-certloom-title-policy' . ( 'program' === $credential_basis ? '' : ' hidden' ) . '><div class="certloom-credential-title-policy__head"><strong>' . esc_html__( 'Credential title', 'certloom' ) . '</strong><span data-certloom-inherited-title>' . esc_html( '' !== $selected_program_title ? $selected_program_title : __( 'Choose a Program to inherit its title.', 'certloom' ) ) . '</span></div><div class="certloom-manual-choice-row certloom-credential-title-source">';
		$this->radio( 'title_source', 'program', __( 'Use Program title', 'certloom' ), $title_source );
		$this->radio( 'title_source', 'custom', __( 'Use a custom title', 'certloom' ), $title_source );
		echo '</div></div>';
		echo '<div class="certloom-credential-custom-title" data-certloom-custom-title-panel' . ( 'independent' === $credential_basis || 'custom' === $title_source ? '' : ' hidden' ) . '>';
		$this->text_input( 'credential_title', __( 'Credential title', 'certloom' ), $credential_title, 255, 'text', 'independent' === $credential_basis || 'custom' === $title_source, __( 'Enter the exact title to freeze on this credential.', 'certloom' ) );
		echo '</div><div class="certloom-manual-grid certloom-manual-grid-2 certloom-issue-essential-fields">';
		$this->text_input( 'valid_from', __( 'Effective date', 'certloom' ), $valid_from, 10, 'date', true, __( 'The date this credential becomes effective. Certloom records the actual issuance timestamp separately when you issue it.', 'certloom' ) );
		echo '</div><div class="certloom-manual-choice-row certloom-manual-expiry">';
		$this->radio( 'expiry_mode', 'permanent', __( 'No expiry', 'certloom' ), $expiry_mode );
		$this->radio( 'expiry_mode', 'date', __( 'Specific expiry date', 'certloom' ), $expiry_mode );
		$this->radio( 'expiry_mode', 'program', __( 'Use Program validity', 'certloom' ), $expiry_mode );
		echo '</div><p class="description certloom-program-validity-summary" data-certloom-program-validity-summary>' . esc_html( $selected_program_id ? ( $selected_program_has_validity ? sprintf( /* translators: %s: Program validity summary. */ __( 'Program default: %s', 'certloom' ), $selected_program_validity_summary ) : __( 'This Program decides validity at issuance. Choose No expiry or a specific expiry date.', 'certloom' ) ) : __( 'Choose a Program to use its validity default.', 'certloom' ) ) . '</p><div data-certloom-expiry-date-panel>'; $this->text_input( 'expires_at', __( 'Expiry date', 'certloom' ), (string) ( $values['expires_at'] ?? '' ), 10, 'date' ); echo '</div></fieldset>';

		$custom = isset( $values['custom_merge_fields'] ) && is_array( $values['custom_merge_fields'] ) ? $values['custom_merge_fields'] : array();
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		echo '<section class="certloom-credential-data-card" data-certloom-credential-data><div class="certloom-credential-data-head"><div><h3>' . esc_html__( 'Credential details', 'certloom' ) . '</h3><p data-certloom-credential-details-help>' . esc_html( 'independent' === $credential_basis ? __( 'Add the credential-specific facts you want to store with this independent credential.', 'certloom' ) : __( 'Program and Batch field rules appear here. Add one-off credential details below when this issuance or its design needs them.', 'certloom' ) ) . '</p></div><span class="certloom-credential-data-badge">' . esc_html__( 'Saved with credential', 'certloom' ) . '</span></div>';
		echo '<div data-certloom-grade-field>'; $this->text_input( 'grade', __( 'Grade / result (optional)', 'certloom' ), (string) ( $values['grade'] ?? '' ), 191 ); echo '</div>';
		echo '<div class="certloom-scoped-field-panel" data-certloom-scoped-field-panel><div class="certloom-scoped-field-status" data-certloom-scoped-field-status aria-live="polite">' . esc_html__( 'Choose a Program to load its credential fields.', 'certloom' ) . '</div><div class="certloom-scoped-field-inputs" data-certloom-scoped-field-inputs></div></div>';
		echo '<div class="certloom-independent-details" data-certloom-independent-details><div class="certloom-independent-detail-toolbar"><label><span>' . esc_html__( 'Add credential detail', 'certloom' ) . '</span><select data-certloom-independent-detail-select><option value="">' . esc_html__( 'Choose a detail…', 'certloom' ) . '</option>';
		foreach ( $independent_field_choices as $choice ) { echo '<option value="' . esc_attr( (string) $choice['key'] ) . '">' . esc_html( (string) $choice['label'] ) . '</option>'; }
		echo '</select></label><button type="button" class="button" data-certloom-independent-detail-add>' . esc_html__( 'Add detail', 'certloom' ) . '</button></div><div class="certloom-independent-detail-inputs" data-certloom-independent-detail-inputs></div><div class="certloom-independent-detail-suggestions" data-certloom-independent-detail-suggestions hidden></div><p class="description">' . esc_html__( 'These values belong to this issued credential only. Recipient-profile facts such as name and email are edited on the Recipient profile instead.', 'certloom' ) . '</p></div>';
		echo '<script type="application/json" data-certloom-independent-field-catalog>' . wp_json_encode( $independent_field_choices, $json_flags ) . '</script>';
		echo '<script type="application/json" data-certloom-scoped-existing-values>' . wp_json_encode( $custom, $json_flags ) . '</script>';
		echo '</section>';
		echo '</div>';

		echo '<fieldset class="certloom-manual-fieldset certloom-presentation-fieldset"><legend class="screen-reader-text">' . esc_html__( 'Presentation', 'certloom' ) . '</legend><p class="certloom-manual-fieldset-intro certloom-presentation-intro">' . esc_html__( 'Certificate appearance', 'certloom' ) . '</p><div class="certloom-presentation-choices">';
		$this->presentation_choice( 'details_only', __( 'Details only', 'certloom' ), __( 'No certificate artwork. Show the verified credential details, status, proof and verification actions.', 'certloom' ), $presentation_mode );
		$this->presentation_choice( 'image', __( 'Upload certificate image', 'certloom' ), __( 'Use a ready-made PNG or JPEG instead of a Certloom design.', 'certloom' ), $presentation_mode );
		$this->presentation_choice( 'design', __( 'Use a Certloom design', 'certloom' ), __( 'Use an inherited/assigned design or choose another published design for this issuance.', 'certloom' ), $presentation_mode );
		echo '</div>';
		echo '<div class="certloom-presentation-panel certloom-details-only-preview" data-certloom-presentation-panel="details_only"' . ( 'details_only' === $presentation_mode ? '' : ' hidden' ) . '><strong>' . esc_html__( 'Verified credential profile', 'certloom' ) . '</strong><p>' . esc_html__( 'No certificate artwork will be created. The Recipient, title, dates and the Credential details entered on the left are stored with the credential and shown according to their visibility rules.', 'certloom' ) . '</p><span class="certloom-details-only-hint">' . esc_html__( 'Use Credential details to add grade, results or other structured facts.', 'certloom' ) . '</span></div>';

		echo '<div class="certloom-presentation-panel" data-certloom-presentation-panel="image"' . ( 'image' === $presentation_mode ? '' : ' hidden' ) . '><div class="certloom-media-control certloom-certificate-image-control" data-certloom-media-control data-certloom-media-types="image/png,image/jpeg"><input type="hidden" name="certificate_image_id" value="' . esc_attr( (string) $certificate_image_id ) . '" data-certloom-media-id><div class="certloom-media-preview" data-certloom-media-preview><span>' . esc_html__( 'No certificate image selected', 'certloom' ) . '</span></div><div class="certloom-media-actions"><button type="button" class="button" data-certloom-media-select>' . esc_html__( 'Choose or upload PNG/JPEG', 'certloom' ) . '</button><button type="button" class="button-link-delete" data-certloom-media-clear' . ( $certificate_image_id ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'certloom' ) . '</button></div></div><small>' . esc_html__( 'Image and Certloom design modes are mutually exclusive. Selecting this mode disables design selection.', 'certloom' ) . '</small></div>';

		echo '<div class="certloom-presentation-panel certloom-design-presentation-panel" data-certloom-presentation-panel="design"' . ( 'design' === $presentation_mode ? '' : ' hidden' ) . '>';
		echo '<div class="certloom-issue-design-source" role="radiogroup" aria-label="' . esc_attr__( 'Certificate design source', 'certloom' ) . '">';
		echo '<label class="certloom-issue-design-source-choice"><input type="radio" name="design_source" value="inherited" ' . checked( 'inherited', $design_source, false ) . '><span><strong>' . esc_html__( 'Use inherited design', 'certloom' ) . '</strong><small>' . esc_html__( 'Use the published design resolved from Global → Issuer → Program → Batch.', 'certloom' ) . '</small></span></label>';
		echo '<label class="certloom-issue-design-source-choice"><input type="radio" name="design_source" value="assigned" ' . checked( 'assigned', $design_source, false ) . '><span><strong>' . esc_html__( 'Use assigned design', 'certloom' ) . '</strong><small>' . esc_html__( 'Choose a different published design for this credential only.', 'certloom' ) . '</small></span></label></div>';
		echo '<label class="certloom-issue-design-field" data-certloom-assigned-design-field' . ( 'assigned' === $design_source ? '' : ' hidden' ) . '><span>' . esc_html__( 'Assigned design', 'certloom' ) . '</span><select id="certloom-issue-template" name="template_id" data-certloom-issue-template-select data-certloom-context-lookup="template" data-certloom-context-empty-value="0" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search published designs…', 'certloom' ) . '"><option value="0">' . esc_html__( 'Choose published design…', 'certloom' ) . '</option>';
		foreach ( $template_items as $template ) { $label=ScopedContextChoices::template_label( $template ); echo '<option value="'.esc_attr((string)absint($template['id'])).'" '.selected(absint($values['template_id']??0),absint($template['id']),false).'>'.esc_html($label).'</option>'; }
		echo '</select><small>' . esc_html__( 'This one-off design affects only this issuance and does not change hierarchy assignments.', 'certloom' ) . '</small></label>';
		echo '<div class="certloom-issue-design-preview" data-certloom-issue-design-preview aria-live="polite"><div class="certloom-issue-design-preview-meta"><strong data-certloom-template-preview-name>' . esc_html__( 'Design preview', 'certloom' ) . '</strong><span data-certloom-template-preview-description>' . esc_html__( 'The effective design will preview here automatically using this credential’s real data.', 'certloom' ) . '</span><span class="certloom-issue-design-source-note" data-certloom-template-preview-source></span></div><div class="certloom-issue-design-preview-canvas" data-certloom-template-preview-canvas><span class="certloom-issue-design-preview-empty">' . esc_html__( 'Preview fits automatically in this window.', 'certloom' ) . '</span></div><div class="certloom-design-data-check" data-certloom-design-data-check><div class="certloom-design-data-check-head"><strong>' . esc_html__( 'Design data check', 'certloom' ) . '</strong><span data-certloom-design-data-summary>' . esc_html__( 'Checking required design fields…', 'certloom' ) . '</span></div><div class="certloom-design-data-fields" data-certloom-design-data-fields></div></div></div></div></fieldset>';

		echo '<details class="certloom-additional-details" data-certloom-additional-details><summary><span>' . esc_html__( 'Additional details', 'certloom' ) . '</span><small>' . esc_html__( 'Optional relationship and profile fields', 'certloom' ) . '</small></summary><div class="certloom-additional-details-body">';
		echo '<div class="certloom-manual-grid certloom-manual-grid-2">';

		echo '<label data-certloom-manual-issuer-field><span>' . esc_html__( 'Issuer', 'certloom' ) . '</span><select name="issuer_id" data-certloom-context-lookup="issuer" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search Issuers…', 'certloom' ) . '"' . ( $program_issuer_id ? ' disabled' : '' ) . '><option value="0">' . esc_html__( 'No explicit Issuer', 'certloom' ) . '</option>';
		foreach ( $issuer_items as $issuer ) { echo '<option value="' . esc_attr( (string) absint( $issuer['id'] ?? 0 ) ) . '" ' . selected( $selected_issuer_id, absint( $issuer['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $issuer['name'] ?? __( 'Issuer', 'certloom' ) ) ) . '</option>'; }
		echo '</select><small data-certloom-manual-issuer-policy>' . esc_html( $program_issuer_id ? sprintf( /* translators: %s: inherited Issuer name. */ __( 'Inherited from the selected Program: %s. Change the Program to change this Issuer.', 'certloom' ), '' !== $program_issuer_name ? $program_issuer_name : __( 'Program Issuer', 'certloom' ) ) : __( 'Optional for an Independent credential or a Program without an assigned Issuer.', 'certloom' ) ) . '</small></label>';

		echo '<label><span>' . esc_html__( 'Batch', 'certloom' ) . '</span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search Batches in this Program…', 'certloom' ) . '"><option value="0">' . esc_html__( 'No Batch', 'certloom' ) . '</option>';
		foreach ( $batch_items as $batch ) { echo '<option value="' . esc_attr( (string) absint( $batch['id'] ?? 0 ) ) . '" data-program-id="' . esc_attr( (string) absint( $batch['program_id'] ?? 0 ) ) . '" ' . selected( $selected_batch_id, absint( $batch['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::batch_label( $batch ) ) . '</option>'; }
		echo '</select><small data-certloom-manual-batch-policy>' . esc_html__( 'Optional unless the selected Program issues one credential per Batch or session. When selected, Batch must belong to the Program.', 'certloom' ) . '</small></label><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-quick-batch-dialog">' . esc_html__( 'Add new Batch', 'certloom' ) . '</button></div>';

		echo '<label><span>' . esc_html__( 'Signer (optional)', 'certloom' ) . '</span><select name="signer_id" data-certloom-context-lookup="signer" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Signers…', 'certloom' ) . '"><option value="0">' . esc_html__( 'No explicit Signer', 'certloom' ) . '</option>';
		foreach ( $signer_items as $signer ) { $signer_label = trim( (string) ( $signer['display_name'] ?? $signer['name'] ?? '' ) ); echo '<option value="' . esc_attr( (string) absint( $signer['id'] ?? 0 ) ) . '" ' . selected( $selected_signer_id, absint( $signer['id'] ?? 0 ), false ) . '>' . esc_html( '' !== $signer_label ? $signer_label : __( 'Signer', 'certloom' ) ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'Choose a one-off Signer override, or leave this blank to use Certloom’s Batch → Program → Issuer → global signer hierarchy. The resolved Signer is frozen into the issued credential.', 'certloom' ) . '</small></label>';

		echo '</div>';

		// Legacy free-form custom merge keys are deliberately kept under Additional details.
		// Normal Program/Batch Credential fields and Independent credential details stay in the
		// main compose flow; this specialist escape hatch should not compete with them.
		echo '<div data-certloom-legacy-custom-fields' . ( 'independent' === $credential_basis ? ' hidden' : '' ) . '><details class="certloom-technical-details certloom-manual-custom-fields-technical"><summary>' . esc_html__( 'Technical custom fields', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Use short field keys only for a specialist legacy workflow. Program/Batch field contracts take precedence when configured.', 'certloom' ) . '</p><div class="certloom-manual-custom-grid">';
		$rows = max( 4, min( 10, count( $custom ) + 2 ) );
		$keys = array_keys( $custom );
		for ( $i = 0; $i < $rows; $i++ ) {
			$key = $keys[ $i ] ?? '';
			$val = '' !== $key ? (string) $custom[ $key ] : '';
			$disabled = 'independent' === $credential_basis ? ' disabled' : '';
			echo '<div class="certloom-manual-custom-row"><label><span>' . esc_html__( 'Field key', 'certloom' ) . '</span><input type="text" name="custom_merge_key[]" maxlength="64" value="' . esc_attr( $key ) . '" placeholder="membership_level"' . $disabled . '></label><label><span>' . esc_html__( 'Value', 'certloom' ) . '</span><input type="text" name="custom_merge_value[]" maxlength="2000" value="' . esc_attr( $val ) . '"' . $disabled . '></label></div>';
		}
		echo '</div></details></div>';

		$this->render_repeating_data_editor( isset( $values['repeating_data'] ) && is_array( $values['repeating_data'] ) ? $values['repeating_data'] : array() );

		if ( $numbering->is_automatic() ) {
			echo '<div class="certloom-numbering-summary"><strong>' . esc_html__( 'Credential number', 'certloom' ) . '</strong><span>';
			if ( is_wp_error( $number_preview ) ) { echo esc_html( $number_preview->get_error_message() ); }
			elseif ( is_array( $number_preview ) ) { echo esc_html__( 'Allocated when issued; current preview:', 'certloom' ) . ' <code>' . esc_html( (string) ( $number_preview['certificate_number'] ?? '' ) ) . '</code>'; }
			echo '</span></div>';
		} else { $this->text_input( 'display_number', __( 'Credential number (optional)', 'certloom' ), (string) ( $values['display_number'] ?? '' ), 100 ); }

		echo '</div></details>';

		echo '<div class="certloom-manual-actions certloom-issue-actions"><div class="certloom-review-action-copy"><span class="certloom-review-readiness" data-certloom-phase2-compatibility-message role="status" aria-live="polite">' . esc_html__( 'Complete the required credential details to continue.', 'certloom' ) . '</span><small>' . esc_html__( 'Review is read-only. Nothing is issued and no credential number is reserved until you confirm the next step.', 'certloom' ) . '</small></div><button type="submit" class="button button-primary" data-certloom-manual-review data-certloom-has-templates="' . esc_attr( empty( $template_items ) ? '0' : '1' ) . '">' . esc_html__( 'Review credential', 'certloom' ) . '</button></div></form></section>';
		$this->render_quick_context_dialogs( $selected_program_id );
	}


	/** Keeps release/development constraints available without interrupting the issuance task. */
	private function render_technical_status( $simple, $current_stage ) {
		echo '<details class="certloom-technical-details certloom-manual-issuance-technical-status certloom-issue-technical-status"><summary>' . esc_html__( 'Technical status', 'certloom' ) . '</summary><div class="certloom-issue-technical-body">';
		if ( $simple ) {
			echo '<p>' . esc_html__( 'Online verification or downloadable certificate files may be unavailable until this site finishes its release setup. You can still prepare and review credentials here.', 'certloom' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Availability:', 'certloom' ) . '</strong> ' . esc_html__( 'Manual Issuance is available in the credential-management preview. The existing registry still powers public verification in this build, and PDF/PNG/JPEG exports remain unavailable until the certificate renderer is approved.', 'certloom' ) . '</p>';
		}
		if ( 'compose' === $current_stage ) {
			echo '<p><strong>' . esc_html__( 'Appearance behavior:', 'certloom' ) . '</strong> ' . esc_html__( 'Details-only credentials can be reviewed and issued without certificate artwork. Certloom-design credentials freeze the resolved published design. Uploaded-image credentials require a selected PNG or JPEG.', 'certloom' ) . '</p>';
		}
		echo '</div></details>';
	}

	/** Compact contextual hierarchy copy; canonical entity names remain stable. */
	private function profile_context_row( $profile, $meaning ) {
		echo '<div class="certloom-profile-context-row"><strong>' . esc_html( $profile ) . '</strong><span>' . esc_html( $meaning ) . '</span></div>';
	}

	/** Phase 2 presentation choice: the whole label is the target, not a tiny radio. */
	private function presentation_choice( $value, $label, $description, $current ) {
		echo '<label class="certloom-presentation-choice"><input type="radio" name="presentation_mode" value="' . esc_attr( $value ) . '" ' . checked( (string) $current, (string) $value, false ) . '><span><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $description ) . '</small></span></label>';
	}

	/**
	 * UI Phase 2 intentionally does not mutate the normalized issuance authority.
	 * Unsupported new paths fail visibly instead of being translated to the old
	 * Program + design semantics behind the user's back.
	 *
	 * @return true|\WP_Error
	 */
	private function phase2_backend_compatibility_error( array $request ) {
		// Title validity is domain logic, not a UI compatibility concern. Program-backed
		// issuance may intentionally submit no explicit credential_title because the
		// canonical EffectiveCredentialTitleResolver inherits the selected Program title.
		// Independent/custom issuance is still rejected fail-closed by that resolver.
		$presentation = sanitize_key( (string) ( $request['presentation_mode'] ?? 'details_only' ) );
		if ( ! in_array( $presentation, array( 'details_only', 'image', 'design' ), true ) ) {
			return new \WP_Error( 'certloom_phase2_presentation_invalid', __( 'Choose a supported credential appearance before reviewing.', 'certloom' ) );
		}
		if ( 'image' === $presentation && empty( $request['certificate_image_id'] ) ) {
			return new \WP_Error( 'certloom_phase2_image_required', __( 'Choose a PNG or JPEG certificate image before reviewing.', 'certloom' ) );
		}
		return true;
	}

	/** Friendly error surface, including PressPrimer-inspired duplicate guidance without a bypass. */
	private function render_review_error( \WP_Error $error ) {
		$code = (string) $error->get_error_code();
		if ( in_array( $code, array( 'certloom_manual_issue_duplicate_series', 'certloom_manual_issue_duplicate_batch_series' ), true ) ) {
			$data = $error->get_error_data();
			$series_id = is_array( $data ) ? absint( $data['series_id'] ?? 0 ) : 0;
			$copy = 'certloom_manual_issue_duplicate_batch_series' === $code
				? __( 'This Recipient already has a credential for the selected Program and Batch/session. Choose another Batch or continue from the existing credential.', 'certloom' )
				: __( 'This Recipient already has the credential lifecycle for the selected Program. Renewal or re-certification should continue from the existing credential instead of creating a second lifecycle.', 'certloom' );
			echo '<div class="certloom-duplicate-guidance" role="alert"><div><strong>' . esc_html__( 'A matching credential already exists', 'certloom' ) . '</strong><p>' . esc_html( $copy ) . '</p></div>';
			if ( $series_id ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'certloom-domain-credentials', 'series_id' => $series_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'View existing credential', 'certloom' ) . '</a>'; }
			echo '</div>';
			return;
		}
		if ( 'certloom_manual_issue_design_data_missing' === $code ) {
			$data = $error->get_error_data();
			$fields = is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
			echo '<div class="notice notice-error inline certloom-review-design-data-error" role="alert"><p><strong>' . esc_html__( 'Review blocked: complete the selected design’s data.', 'certloom' ) . '</strong> ' . esc_html__( 'Return to Compose and fill the highlighted Recipient or Credential details before reviewing again.', 'certloom' ) . '</p>';
			if ( $fields ) { echo '<ul>'; foreach ( array_slice( $fields, 0, 8 ) as $field ) { if ( ! is_array( $field ) ) { continue; } $label = trim( (string) ( $field['label'] ?? $field['token'] ?? '' ) ); $source = trim( (string) ( $field['source'] ?? '' ) ); if ( '' === $label ) { continue; } echo '<li><strong>' . esc_html( $label ) . '</strong>' . ( '' !== $source ? ' — ' . esc_html( $source ) : '' ) . '</li>'; } echo '</ul>'; }
			echo '</div>';
			return;
		}
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Review blocked:', 'certloom' ) . '</strong> ' . esc_html( $error->get_error_message() ) . '</p></div>';
	}


	/** Renders the complete mutable Recipient profile in a focused issuance popup. */
	private function render_new_recipient_dialog( array $new ) {
		$wp_user_id = absint( $new['wp_user_id'] ?? 0 );
		$wp_user_label = '';
		if ( $wp_user_id && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $wp_user_id );
			if ( $user ) { $wp_user_label = trim( (string) $user->display_name . ' — ' . (string) $user->user_email ); }
		}
		echo '<dialog id="certloom-new-recipient-dialog" class="certloom-premium-dialog certloom-recipient-profile-dialog" data-certloom-recipient-dialog><div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Recipient profile', 'certloom' ) . '</span><h2>' . esc_html__( 'New recipient', 'certloom' ) . '</h2><p>' . esc_html__( 'Profile information is reusable across future credentials. It is separate from grade, score and other credential-specific values.', 'certloom' ) . '</p></div><button type="button" class="button-link" data-certloom-dialog-close aria-label="' . esc_attr__( 'Close recipient profile', 'certloom' ) . '">×</button></div><div class="certloom-dialog-body"><div class="certloom-manual-grid certloom-manual-grid-2">';
		$this->text_input( 'new_recipient[display_name]', __( 'Display name', 'certloom' ), (string) ( $new['display_name'] ?? '' ), 255, 'text', true, __( 'Example: Amina Khan or Northstar Academy', 'certloom' ) );
		$this->text_input( 'new_recipient[email]', __( 'Email', 'certloom' ), (string) ( $new['email'] ?? '' ), 320, 'email', false, __( 'Optional. Used for delivery and account contact.', 'certloom' ) );
		$this->text_input( 'new_recipient[first_name]', __( 'First name', 'certloom' ), (string) ( $new['first_name'] ?? '' ), 191 );
		$this->text_input( 'new_recipient[middle_name]', __( 'Middle name', 'certloom' ), (string) ( $new['middle_name'] ?? '' ), 191 );
		$this->text_input( 'new_recipient[last_name]', __( 'Last name', 'certloom' ), (string) ( $new['last_name'] ?? '' ), 191 );
		$this->text_input( 'new_recipient[external_id]', __( 'External learner / customer ID', 'certloom' ), (string) ( $new['external_id'] ?? '' ), 191, 'text', false, __( 'Optional. Use an exact LMS, CRM, SIS or customer identifier when available.', 'certloom' ) );
		echo '<label><span>' . esc_html__( 'WordPress account', 'certloom' ) . '</span><select name="new_recipient[wp_user_id]" data-certloom-context-lookup="wp_user" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search WordPress users…', 'certloom' ) . '"><option value="0">' . esc_html__( 'No linked WordPress account', 'certloom' ) . '</option>';
		if ( $wp_user_id ) { echo '<option value="' . esc_attr( (string) $wp_user_id ) . '" selected>' . esc_html( '' !== $wp_user_label ? $wp_user_label : '#' . $wp_user_id ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'Optional. Linking the exact WordPress account powers My Credentials and account-based LMS/WooCommerce identity. Certloom never links by matching email or name.', 'certloom' ) . '</small></label>';
		echo '</div><div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong>' . esc_html__( 'Credential fields stay separate', 'certloom' ) . '</strong><p>' . esc_html__( 'Grade, score, completion result and Program/Batch credential fields are entered after you choose the credential context. They are not Recipient-profile fields.', 'certloom' ) . '</p></div></div></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="button" class="button button-primary" data-certloom-recipient-profile-save>' . esc_html__( 'Use this recipient profile', 'certloom' ) . '</button></div></dialog>';
	}

	/** Quick-create Program/Batch without abandoning the issuance draft. */
	private function render_quick_context_dialogs( $selected_program_id ) {
		$fields = \Ecapla_CertVerify_Fields::get_scopable_fields();

		echo '<dialog id="certloom-quick-program-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="program" data-certloom-quick-target="select[name=program_id]" data-certloom-human-form data-certloom-invalid-message="' . esc_attr__( 'Complete the highlighted Program field.', 'certloom' ) . '"><input type="hidden" name="action" value="certloom_manual_quick_program"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'certloom_manual_quick_program' ) ) . '">';
		echo '<div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html__( 'Add Program', 'certloom' ) . '</h2><p>' . esc_html__( 'Add the essentials needed for issuance here. Visit Programs later for language, renewal and other deeper settings.', 'certloom' ) . '</p></div><button type="button" class="button-link" data-certloom-dialog-close aria-label="' . esc_attr__( 'Close Add Program', 'certloom' ) . '">×</button></div><div class="certloom-dialog-body">';
		echo '<div class="certloom-manual-grid certloom-manual-grid-2">';
		$this->text_input( 'program_title', __( 'Program title', 'certloom' ), '', 255, 'text', true, __( 'Example: Internal Auditor Certification', 'certloom' ) );
		$this->text_input( 'program_code', __( 'Short code (optional)', 'certloom' ), '', 100, 'text', false, __( 'Example: IA-101', 'certloom' ) );
		echo '<div class="certloom-dialog-inheritance-note"><strong>' . esc_html__( 'Designs inherit automatically', 'certloom' ) . '</strong><small>' . esc_html__( 'This Program will use its Issuer and Global certificate/email designs until you add an override from the Program Designs card or Design Rules.', 'certloom' ) . '</small></div></div>';
		echo '<details class="certloom-dialog-options" open><summary>' . esc_html__( 'Credential fields used by this Program', 'certloom' ) . '</summary><div class="certloom-quick-contract-grid">';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key ) { continue; } echo '<label><span>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</span><select name="program_field_requirement[' . esc_attr( $key ) . ']"><option value="">' . esc_html__( 'Not used', 'certloom' ) . '</option><option value="optional">' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required">' . esc_html__( 'Required', 'certloom' ) . '</option></select></label>'; }
		echo '</div></details><p class="certloom-dialog-footnote">' . esc_html__( 'Need another field or more Program options? Save this Program, then open Programs → Program to make deeper changes.', 'certloom' ) . '</p></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add Program', 'certloom' ) . '</button></div></form></dialog>';

		echo '<dialog id="certloom-quick-batch-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="batch" data-certloom-quick-target="select[name=batch_id]" data-certloom-quick-program-source="select[name=program_id]" data-certloom-human-form data-certloom-invalid-message="' . esc_attr__( 'Complete the highlighted Batch field.', 'certloom' ) . '"><input type="hidden" name="action" value="certloom_manual_quick_batch"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'certloom_manual_quick_batch' ) ) . '">';
		echo '<div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html__( 'Add Batch', 'certloom' ) . '</h2><p>' . esc_html__( 'Create a cohort or intake and its credential-field changes, then return to this issuance. The Batch inherits its Program designs by default.', 'certloom' ) . '</p></div><button type="button" class="button-link" data-certloom-dialog-close aria-label="' . esc_attr__( 'Close Add Batch', 'certloom' ) . '">×</button></div><div class="certloom-dialog-body">';
		echo '<div class="certloom-manual-grid certloom-manual-grid-2"><label><span>' . esc_html__( 'Program', 'certloom' ) . '</span><select name="program_id" required data-certloom-required-example="' . esc_attr__( 'Choose the Program this Batch belongs to.', 'certloom' ) . '" data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose Program…', 'certloom' ) . '</option>';
		$programs = ( new ScopedContextChoices() )->programs( $selected_program_id );
		if ( ! is_wp_error( $programs ) ) { foreach ( $programs as $program ) { echo '<option value="' . esc_attr( (string) absint( $program['id'] ?? 0 ) ) . '" ' . selected( absint( $selected_program_id ), absint( $program['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::program_label( $program ) ) . '</option>'; } }
		echo '</select></label>';
		$this->text_input( 'batch_name', __( 'Batch name', 'certloom' ), '', 255, 'text', true, __( 'Example: Spring 2027 Cohort', 'certloom' ) );
		$this->text_input( 'batch_code', __( 'Batch code (optional)', 'certloom' ), '', 100, 'text', false, __( 'Leave blank to generate one from the Batch name.', 'certloom' ) );
		echo '<div class="certloom-dialog-inheritance-note"><strong>' . esc_html__( 'Designs inherit automatically', 'certloom' ) . '</strong><small>' . esc_html__( 'This Batch will follow its Program certificate/email designs until you add a Batch override later.', 'certloom' ) . '</small></div></div>';
		echo '<details class="certloom-dialog-options"><summary>' . esc_html__( 'Batch credential-field changes', 'certloom' ) . '</summary><div class="certloom-quick-contract-grid">';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key ) { continue; } echo '<label><span>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</span><select name="batch_field_requirement[' . esc_attr( $key ) . ']"><option value="">' . esc_html__( 'Inherit Program', 'certloom' ) . '</option><option value="optional">' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required">' . esc_html__( 'Required', 'certloom' ) . '</option><option value="exclude">' . esc_html__( 'Do not use', 'certloom' ) . '</option></select></label>'; }
		echo '</div></details><p class="certloom-dialog-footnote">' . esc_html__( 'Only issuance-relevant choices are shown here. Visit Programs → Batches for dates, signer and deeper Batch settings.', 'certloom' ) . '</p></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add Batch', 'certloom' ) . '</button></div></form></dialog>';
	}

	/** @return void */
	public function quick_program() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission to add Programs.', 'certloom' ) ), 403 ); }
		check_ajax_referer( 'certloom_manual_quick_program', 'nonce' );
		$title = isset( $_POST['program_title'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['program_title'] ) ) ) : '';
		$code = isset( $_POST['program_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['program_code'] ) ) ) : '';
		$rules = $this->quick_field_rules( 'program_field_requirement', array( 'optional', 'required' ) );
		$profile = ProgramProfile::defaults();
		$profile['validity_mode'] = ProgramLifecyclePolicy::VALIDITY_PERMANENT;
		$profile['renewal'] = 'not_applicable';
		$profile['repeat_policy'] = ProgramLifecyclePolicy::REPEAT_LIFECYCLE;
		$metadata = ( new ProgramProfile() )->with_metadata( array(), $profile );
		if ( is_wp_error( $metadata ) ) { wp_send_json_error( array( 'message' => $metadata->get_error_message() ), 400 ); }
		$metadata = ( new ScopedFieldContract() )->with_program_metadata( $metadata, $rules, \Ecapla_CertVerify_Fields::get_field_map() );
		if ( is_wp_error( $metadata ) ) { wp_send_json_error( array( 'message' => $metadata->get_error_message() ), 400 ); }
		$result = ( new ProgramRepository() )->create( array( 'title' => $title, 'code' => $code, 'active' => 1, 'validity_days' => null, 'metadata' => $metadata ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( array( 'id' => absint( $result ), 'label' => '' !== $code ? $title . ' — ' . $code : $title, 'title' => $title, 'validity_mode' => ProgramLifecyclePolicy::VALIDITY_PERMANENT, 'validity_value' => 0, 'validity_unit' => 'days', 'program_validity_available' => true, 'validity_summary' => __( 'Does not expire', 'certloom' ), 'renewal' => 'not_applicable', 'repeat_policy' => ProgramLifecyclePolicy::REPEAT_LIFECYCLE, 'message' => __( 'Program added with a non-expiring lifecycle, its issuance fields and inherited designs, then selected.', 'certloom' ) ) );
	}

	/** @return void */
	public function quick_batch() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission to add Batches.', 'certloom' ) ), 403 ); }
		check_ajax_referer( 'certloom_manual_quick_batch', 'nonce' );
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$name = isset( $_POST['batch_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['batch_name'] ) ) ) : '';
		$code = isset( $_POST['batch_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['batch_code'] ) ) ) : '';
		if ( '' === $code && '' !== $name ) { $code = trim( strtoupper( substr( preg_replace( '/[^A-Za-z0-9]+/', '-', $name ), 0, 80 ) ), '-' ); }
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) || ! is_array( $program ) ) { wp_send_json_error( array( 'message' => is_wp_error( $program ) ? $program->get_error_message() : __( 'Choose a valid Program for this Batch.', 'certloom' ) ), 400 ); }
		$rules = $this->quick_field_rules( 'batch_field_requirement', array( 'optional', 'required', 'exclude' ) );
		$contract = new ScopedFieldContract();
		$metadata = $contract->with_batch_metadata( array(), $rules, \Ecapla_CertVerify_Fields::get_field_map() );
		if ( is_wp_error( $metadata ) ) { wp_send_json_error( array( 'message' => $metadata->get_error_message() ), 400 ); }
		$batch_contract = $contract->from_batch_metadata( $metadata, \Ecapla_CertVerify_Fields::get_field_map() );
		$resolved = $contract->resolve( is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program(), $batch_contract, \Ecapla_CertVerify_Fields::get_field_map() );
		if ( is_wp_error( $resolved ) ) { wp_send_json_error( array( 'message' => $resolved->get_error_message() ), 400 ); }
		$result = ( new BatchRepository() )->create( array( 'program_id' => $program_id, 'name' => $name, 'code' => $code, 'active' => 1, 'metadata' => $metadata ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( array( 'id' => absint( $result ), 'program_id' => $program_id, 'label' => '' !== $code ? $name . ' — ' . $code : $name, 'message' => __( 'Batch added with its issuance settings and inherited designs, then selected.', 'certloom' ) ) );
	}


	/** @return array<string,string> */
	private function quick_field_rules( $post_key, array $allowed ) {
		$raw = isset( $_POST[ $post_key ] ) && is_array( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : array();
		$out = array();
		foreach ( $raw as $raw_key => $raw_value ) { $key = sanitize_key( (string) $raw_key ); $value = sanitize_key( (string) $raw_value ); if ( '' !== $key && in_array( $value, $allowed, true ) ) { $out[ $key ] = $value; } }
		return $out;
	}

	/** @return void */
	private function render_review( array $review ) {
		$request = array_merge( isset( $review['phase2_ui_request'] ) && is_array( $review['phase2_ui_request'] ) ? $review['phase2_ui_request'] : array(), (array) $review['request'] ); $recipient=(array)$review['recipient']; $program=(array)$review['program']; $template=(array)$review['template']; $revision=(array)$review['template_revision'];
		$presentation_mode = sanitize_key( (string) ( $request['presentation_mode'] ?? 'details_only' ) );
		$presentation_label = 'image' === $presentation_mode ? __( 'Certificate image', 'certloom' ) : ( 'design' === $presentation_mode ? __( 'Certloom design', 'certloom' ) : __( 'Details only', 'certloom' ) );
		$presentation_meta = 'image' === $presentation_mode ? __( 'Uploaded artwork is frozen as the credential appearance choice', 'certloom' ) : ( 'design' === $presentation_mode ? __( 'Published design frozen into this credential', 'certloom' ) : __( 'No certificate artwork; verification details remain authoritative', 'certloom' ) );
		echo '<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Step 2', 'certloom' ) . '</span><h2>' . esc_html__( 'Review credential', 'certloom' ) . '</h2></div><span class="certloom-template-state certloom-template-state-published">' . esc_html__( 'Ready to issue', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-manual-review-grid">';
		$this->review_fact( __( 'Recipient', 'certloom' ), (string) ( $recipient['display_name'] ?? '' ), ! empty( $recipient['email'] ) ? (string) $recipient['email'] : '' );
		$this->review_fact( __( 'Credential title', 'certloom' ), (string) ( $request['credential_title'] ?? '' ), 'program' === (string) ( $request['title_source'] ?? '' ) ? __( 'Inherited from the selected Program', 'certloom' ) : __( 'Custom title for this credential', 'certloom' ) );
		$this->review_fact( __( 'Presentation', 'certloom' ), $presentation_label, $presentation_meta );
		$this->review_fact( __( 'Program', 'certloom' ), ! empty( $program['id'] ) ? (string) ( $program['title'] ?? '' ) : __( 'None — standalone credential', 'certloom' ), ! empty( $program['code'] ) ? (string) $program['code'] : '' );
		$signer = isset( $review['signer'] ) && is_array( $review['signer'] ) ? $review['signer'] : null;
		$signer_resolution = isset( $review['signer_resolution'] ) && is_array( $review['signer_resolution'] ) ? $review['signer_resolution'] : array();
		$signer_meta = 'explicit' === (string) ( $signer_resolution['mode'] ?? '' ) ? __( 'One-off Signer override', 'certloom' ) : ( 'hierarchy' === (string) ( $signer_resolution['mode'] ?? '' ) ? sprintf( /* translators: %s: signer assignment scope. */ __( 'Resolved from %s signer assignment', 'certloom' ), (string) ( $signer_resolution['scope_type'] ?? 'hierarchy' ) ) : __( 'No Signer resolved', 'certloom' ) );
		$this->review_fact( __( 'Signer', 'certloom' ), is_array( $signer ) ? (string) ( $signer['display_name'] ?? '' ) : __( 'None', 'certloom' ), $signer_meta );
		$locale = isset( $review['credential_locale'] ) && is_array( $review['credential_locale'] ) ? $review['credential_locale'] : array();
		$locale_effective = (string) ( $locale['effective'] ?? '' );
		$locale_configured = (string) ( $locale['configured'] ?? '' );
		$locale_direction = strtoupper( (string) ( $locale['direction'] ?? 'ltr' ) );
		$locale_meta = $locale_direction;
		if ( '' !== $locale_configured && $locale_configured !== $locale_effective ) {
			$locale_meta .= ' · ' . sprintf( /* translators: %s: locale configured. */ __( 'configured %s', 'certloom' ), $locale_configured );
		}
		$this->review_fact( __( 'Credential language', 'certloom' ), '' !== $locale_effective ? $locale_effective : __( 'Site default', 'certloom' ), $locale_meta );
		if ( 'design' === $presentation_mode ) {
			$template_meta = sprintf( /* translators: %1$d: revision number; %2$d: ID. */ __( 'Published revision #%1$d · ID %2$d', 'certloom' ), absint( $revision['revision_number'] ?? 0 ), absint( $revision['id'] ?? 0 ) );
			$resolution_scope = (string) ( $review['template_resolution'] ?? 'explicit' );
			$variant = isset( $review['template_locale_variant'] ) && is_array( $review['template_locale_variant'] ) ? $review['template_locale_variant'] : array();
			if ( ! empty( $variant['matched_locale'] ) ) {
				$template_meta .= ' · ' . sprintf( /* translators: %s: matched locale. */ __( 'language variant %s', 'certloom' ), (string) $variant['matched_locale'] );
			} elseif ( 'explicit' === $resolution_scope ) {
				$template_meta .= ' · ' . __( 'explicit reviewed override', 'certloom' );
			} else {
				$details = isset( $review['template_resolution_details'] ) && is_array( $review['template_resolution_details'] ) ? $review['template_resolution_details'] : array();
				$scope_id = absint( $details['scope_id'] ?? 0 );
				$template_meta .= ' · ' . $this->inherited_design_source_label( $resolution_scope, $scope_id, $program, isset( $review['issuer'] ) && is_array( $review['issuer'] ) ? $review['issuer'] : null, isset( $review['batch'] ) && is_array( $review['batch'] ) ? $review['batch'] : null );
			}
			$this->review_fact( __( 'Template', 'certloom' ), (string) ( $template['name'] ?? '' ), $template_meta );
		} elseif ( 'image' === $presentation_mode ) {
			$this->review_fact( __( 'Certificate image', 'certloom' ), '#' . absint( $request['certificate_image_id'] ?? 0 ), __( 'Selected WordPress Media attachment', 'certloom' ) );
		}
		$this->review_fact( __( 'Effective date', 'certloom' ), (string) $review['valid_from'], null === $review['expires_at'] ? __( 'Permanent / no expiry', 'certloom' ) : sprintf( /* translators: %s: expires at. */ __( 'Expires %s', 'certloom' ), (string) $review['expires_at'] ) );
		$number = is_array( $review['numbering'] ?? null ) ? (string) ( $review['numbering']['certificate_number'] ?? '' ) : (string) ( $request['display_number'] ?? '' );
		$this->review_fact( __( 'Certificate number', 'certloom' ), '' !== $number ? $number : __( 'Allocated on Issue', 'certloom' ), \Ecapla_CertVerify_Numbering::is_enabled() ? __( 'Number preview; not reserved', 'certloom' ) : __( 'Manual number', 'certloom' ) );
		echo '</div>';
		$preview = 'design' === $presentation_mode ? ( new ManualIssuancePreviewService() )->build( $review ) : null;
		echo '<details class="certloom-technical-details certloom-manual-proof"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Integrity identifiers are available for support and auditing. They are checked automatically and are not required for normal issuance.', 'certloom' ) . '</p><div class="certloom-template-preview-proof"><div><span>' . esc_html__( 'Review SHA-256', 'certloom' ) . '</span><code data-certloom-review-sha>' . esc_html( (string) $review['review_fingerprint'] ) . '</code></div>';
		if ( 'design' === $presentation_mode ) {
			echo '<div><span>' . esc_html__( 'Layout schema', 'certloom' ) . '</span><strong>' . esc_html( (string) ( $revision['layout_schema'] ?? '' ) ) . ' v' . esc_html( (string) absint( $revision['layout_schema_version'] ?? 0 ) ) . '</strong></div><div><span>' . esc_html__( 'Published layout SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) ( $revision['layout_sha256'] ?? '' ) ) . '</code></div>' . ( is_wp_error( $preview ) ? '' : '<div><span>' . esc_html__( 'Render Plan SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) $preview['render_plan_sha256'] ) . '</code></div>' );
		}
		echo '</div></details>';
		if ( 'design' === $presentation_mode ) {
			if ( is_wp_error( $preview ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>'; }
			else { try { $presented = ( new RenderPlanHtmlPreview() )->present( $preview['render_plan'] ); echo '<div class="certloom-template-preview-card certloom-manual-preview"><div class="certloom-template-preview-head"><div><h3>' . esc_html__( 'Credential Preview', 'certloom' ) . '</h3><p>' . esc_html__( 'Built from the selected published design and the exact recipient/credential details in this review.', 'certloom' ) . '</p></div><span class="certloom-template-preview-authority">' . esc_html__( 'Browser preview', 'certloom' ) . '</span></div>' . $presented['html'] . '<p class="description">' . esc_html__( 'Certificate IDs and the final verification URL are created only when you issue. The browser preview may render fonts slightly differently from future downloadable certificate files.', 'certloom' ) . '</p></div>'; } catch ( \Throwable $e ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The credential preview could not be shown safely in the browser.', 'certloom' ) . '</p></div>'; } }
		} elseif ( 'details_only' === $presentation_mode ) {
			echo '<div class="certloom-review-appearance-note"><strong>' . esc_html__( 'No certificate artwork', 'certloom' ) . '</strong><span>' . esc_html__( 'This credential will use its verification page, status, proof and saved details without a generated certificate design.', 'certloom' ) . '</span></div>';
		} else {
			$image_html = function_exists( 'wp_get_attachment_image' ) ? wp_get_attachment_image( absint( $request['certificate_image_id'] ?? 0 ), 'medium', false, array( 'class' => 'certloom-review-certificate-image' ) ) : '';
			echo '<div class="certloom-review-appearance-note certloom-review-image-note"><strong>' . esc_html__( 'Uploaded certificate image', 'certloom' ) . '</strong>' . $image_html . '<span>' . esc_html__( 'The selected image is saved as this credential’s certificate appearance.', 'certloom' ) . '</span></div>';
		}
		$this->render_repeating_data_review( isset( $request['repeating_data'] ) && is_array( $request['repeating_data'] ) ? $request['repeating_data'] : array() );
		$custom=(array)($request['custom_merge_fields']??array()); if($custom){echo '<h3>'.esc_html__( 'Credential-specific custom merge values', 'certloom' ).'</h3><dl class="certloom-manual-merge-list">';foreach($custom as $k=>$v){echo '<div><dt><code>custom:'.esc_html((string)$k).'</code></dt><dd>'.esc_html((string)$v).'</dd></div>';}echo '</dl>';}
		echo '<div class="notice notice-info inline"><p><strong>'.esc_html__( 'What Issue will do:', 'certloom' ).'</strong> '.esc_html__( 'Check this review again, assign the certificate number, create the recipient if needed, save the issued credential and its selected appearance/details, and record the issuance as one checked transaction.', 'certloom' ).'</p></div>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="certloom-manual-confirm"><input type="hidden" name="action" value="certloom_manual_issuance_issue"><input type="hidden" name="review_fingerprint" value="'.esc_attr((string)$review['review_fingerprint']).'">'; wp_nonce_field('certloom_manual_issuance_issue_'.(string)$review['review_fingerprint']); $this->hidden_request($request); echo '<a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">'.esc_html__( 'Back to compose', 'certloom' ).'</a><button class="button button-primary button-hero" type="submit">'.esc_html__( 'Issue credential', 'certloom' ).'</button></form></section>';
	}

	/** @return void */
	private function render_receipt( $version_id ) {
		$receipt=(new ManualIssuanceService())->receipt($version_id); if(is_wp_error($receipt)){echo '<div class="notice notice-error inline"><p>'.esc_html($receipt->get_error_message()).'</p></div>';return;}
		$public_ready=(new PublicVerificationGate())->is_ready();
		echo '<section class="certloom-manual-card certloom-manual-receipt"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">'.esc_html__( 'Step 4', 'certloom' ).'</span><h2>'.esc_html__( 'Issuance receipt', 'certloom' ).'</h2></div><span class="certloom-template-state certloom-template-state-published">'.esc_html__( 'Issued', 'certloom' ).'</span></div><div class="certloom-manual-review-grid">';
		$this->review_fact(__( 'Certificate number', 'certloom' ),(string)$receipt['display_number'],__( 'Human-facing credential number', 'certloom' )); $this->review_fact(__( 'Public credential ID', 'certloom' ),(string)$receipt['public_id'],__( 'Secure generated identity', 'certloom' ),true); $this->review_fact(__( 'Credential Series', 'certloom' ),'#'.absint($receipt['series_id']),__( 'Stable credential lineage', 'certloom' )); $this->review_fact(__( 'Credential Version', 'certloom' ),'#'.absint($receipt['version_id']),__( 'Saved issuance details', 'certloom' )); $this->review_fact(__( 'Issued', 'certloom' ),(string)($receipt['issued_at']??'—'),null===($receipt['expires_at']??null)?__( 'No expiry', 'certloom' ):sprintf(/* translators: %s: expires at. */ __( 'Expires %s', 'certloom' ),(string)$receipt['expires_at']));
		echo '</div><div class="certloom-manual-receipt-url"><span>'.esc_html__( 'Self-hosted verification URL', 'certloom' ).'</span>'; if($public_ready){echo '<a href="'.esc_url((string)$receipt['verification_url']).'" target="_blank" rel="noopener">'.esc_html((string)$receipt['verification_url']).'</a>';}else{echo '<code>'.esc_html((string)$receipt['verification_url']).'</code><small>'.esc_html__( 'Saved with the issued credential, but public verification from the new credential store is not enabled in this build yet.', 'certloom' ).'</small>';} echo '</div><div class="notice notice-info inline"><p><strong>'.esc_html__( 'Download status:', 'certloom' ).'</strong> '.esc_html__( 'The credential is issued, but PDF/PNG/JPEG downloads are not enabled until the certificate renderer passes its production approval checks.', 'certloom' ).'</p></div><div class="certloom-manual-actions"><a class="button button-primary" href="'.esc_url(add_query_arg(array('page'=>'certloom-email-delivery','version_id'=>absint($receipt['version_id'])),admin_url('admin.php'))).'">'.esc_html__( 'Email this credential', 'certloom' ).'</a><a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">'.esc_html__( 'Issue another credential', 'certloom' ).'</a></div></section>';
	}

	/** @return array<string,mixed> */
	private function request_from_post() {
		$this->request_error = null;
		$post = wp_unslash( $_POST );
		$custom = array();
		$keys = isset( $post['custom_merge_key'] ) && is_array( $post['custom_merge_key'] ) ? array_values( $post['custom_merge_key'] ) : array();
		$vals = isset( $post['custom_merge_value'] ) && is_array( $post['custom_merge_value'] ) ? array_values( $post['custom_merge_value'] ) : array();
		if ( count( $keys ) > 100 || count( $vals ) > 100 ) {
			$this->request_error = new \WP_Error( 'certloom_manual_issue_custom_merge_rows_too_many', __( 'Credential-specific custom merge input is limited to 100 submitted rows and 30 saved values.', 'certloom' ) );
		}
		foreach ( array_slice( $keys, 0, 100 ) as $i => $raw_key ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key ) { continue; }
			if ( array_key_exists( $key, $custom ) ) {
				$this->request_error = new \WP_Error( 'certloom_manual_issue_custom_merge_duplicate', __( 'Each credential-specific custom field must use a unique field key.', 'certloom' ) );
				continue;
			}
			$custom[ $key ] = isset( $vals[ $i ] ) && ! is_array( $vals[ $i ] ) ? (string) $vals[ $i ] : '';
		}
		if ( isset( $post['custom_merge_fields'] ) && is_array( $post['custom_merge_fields'] ) ) {
			foreach ( $post['custom_merge_fields'] as $raw_key => $value ) {
				$key = sanitize_key( (string) $raw_key );
				if ( '' === $key || is_array( $value ) ) { continue; }
				if ( array_key_exists( $key, $custom ) ) {
					$this->request_error = new \WP_Error( 'certloom_manual_issue_custom_merge_duplicate', __( 'Each credential-specific custom field must use a unique field key.', 'certloom' ) );
					continue;
				}
				$custom[ $key ] = (string) $value;
			}
		}
		$design_field_aliases = array();
		if ( isset( $post['design_field_aliases'] ) && is_array( $post['design_field_aliases'] ) ) {
			if ( count( $post['design_field_aliases'] ) > 30 ) {
				$this->request_error = new \WP_Error( 'certloom_manual_issue_design_field_aliases_too_many', __( 'Design field assignments are limited to 30 per credential.', 'certloom' ) );
			}
			foreach ( array_slice( $post['design_field_aliases'], 0, 30, true ) as $raw_target => $raw_source ) {
				if ( is_array( $raw_source ) ) { continue; }
				$target = sanitize_key( (string) $raw_target );
				$source = sanitize_key( (string) $raw_source );
				if ( '' === $target || '' === $source ) { continue; }
				$design_field_aliases[ $target ] = $source;
			}
		}
		$repeating_data = $this->repeating_data_from_post( $post['repeating_data'] ?? array() );
		if ( is_wp_error( $repeating_data ) ) { $this->request_error = $repeating_data; $repeating_data = array(); }
		return array(
			'recipient_mode' => isset( $post['recipient_mode'] ) ? (string) $post['recipient_mode'] : 'existing',
			'recipient_id' => isset( $post['recipient_id'] ) ? $post['recipient_id'] : 0,
			'new_recipient' => isset( $post['new_recipient'] ) && is_array( $post['new_recipient'] ) ? $post['new_recipient'] : array(),
			'credential_basis' => isset( $post['credential_basis'] ) ? sanitize_key( (string) $post['credential_basis'] ) : '',
			'title_source' => isset( $post['title_source'] ) ? sanitize_key( (string) $post['title_source'] ) : '',
			'credential_title' => isset( $post['credential_title'] ) ? sanitize_text_field( (string) $post['credential_title'] ) : '',
			'presentation_mode' => isset( $post['presentation_mode'] ) ? sanitize_key( (string) $post['presentation_mode'] ) : 'details_only',
			'certificate_image_id' => isset( $post['certificate_image_id'] ) ? absint( $post['certificate_image_id'] ) : 0,
			'issuer_id' => isset( $post['issuer_id'] ) ? absint( $post['issuer_id'] ) : 0,
			'signer_id' => isset( $post['signer_id'] ) ? absint( $post['signer_id'] ) : 0,
			'program_id' => isset( $post['program_id'] ) ? $post['program_id'] : 0,
			'batch_id' => isset( $post['batch_id'] ) ? $post['batch_id'] : 0,
			'template_id' => isset( $post['template_id'] ) ? $post['template_id'] : 0,
			'design_source' => isset( $post['design_source'] ) ? sanitize_key( (string) $post['design_source'] ) : ( ! empty( $post['template_id'] ) ? 'assigned' : 'inherited' ),
			'valid_from' => isset( $post['valid_from'] ) ? (string) $post['valid_from'] : '',
			'expiry_mode' => isset( $post['expiry_mode'] ) ? sanitize_key( (string) $post['expiry_mode'] ) : '',
			'expires_at' => isset( $post['expires_at'] ) ? (string) $post['expires_at'] : '',
			'grade' => isset( $post['grade'] ) ? (string) $post['grade'] : '',
			'display_number' => isset( $post['display_number'] ) ? (string) $post['display_number'] : '',
			'custom_merge_fields' => $custom,
			'design_field_aliases' => $design_field_aliases,
			'repeating_data' => $repeating_data,
		);
	}


	/** Human-facing editor for immutable repeating rows used by Schema 4 Data Tables. @return void */
	private function render_repeating_data_editor( array $data ) {
		echo '<details class="certloom-manual-repeating" data-certloom-repeating-data><summary><span>' . esc_html__( 'Subjects & results', 'certloom' ) . '</span><small>' . esc_html__( 'Optional repeating rows for Data Tables', 'certloom' ) . '</small></summary><div class="certloom-manual-repeating-body">';
		echo '<p class="description">' . esc_html__( 'Add only the rows needed by this credential. They are frozen into the issued version so a Data Table never reads changing Recipient, Program or LMS data later.', 'certloom' ) . '</p>';
		$this->render_repeating_rowset( 'results', isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array(), __( 'Results', 'certloom' ), __( 'Scores, grades and status for transcript-style certificates.', 'certloom' ) );
		$this->render_repeating_rowset( 'subjects', isset( $data['subjects'] ) && is_array( $data['subjects'] ) ? $data['subjects'] : array(), __( 'Subjects', 'certloom' ), __( 'Subject/course lists when scores are not required.', 'certloom' ) );
		echo '</div></details>';
	}

	/** @return void */
	private function render_repeating_rowset( $rowset, array $rows, $label, $help ) {
		$source = 'results' === $rowset ? TableDataContract::RESULTS : TableDataContract::SUBJECTS;
		$fields = TableDataContract::fields_for_source( $source );
		$labels = array( 'subject' => __( 'Subject', 'certloom' ), 'code' => __( 'Code', 'certloom' ), 'score' => __( 'Score', 'certloom' ), 'max_score' => __( 'Max score', 'certloom' ), 'percentage' => __( 'Percentage', 'certloom' ), 'grade' => __( 'Grade', 'certloom' ), 'credits' => __( 'Credits', 'certloom' ), 'status' => __( 'Status', 'certloom' ) );
		$rows = array_slice( array_values( $rows ), 0, TableDataContract::MAX_ROWS );
		if ( ! $rows ) { $rows = array( array() ); }
		echo '<section class="certloom-manual-rowset" data-certloom-repeating-rowset="' . esc_attr( $rowset ) . '" data-certloom-next-row="' . esc_attr( (string) count( $rows ) ) . '"><div class="certloom-manual-rowset-head"><div><h4>' . esc_html( $label ) . '</h4><p>' . esc_html( $help ) . '</p></div><button type="button" class="button" data-certloom-repeating-add>' . esc_html__( 'Add row', 'certloom' ) . '</button></div><div class="certloom-manual-rowset-rows" data-certloom-repeating-rows>';
		foreach ( $rows as $index => $row ) { $this->render_repeating_row( $rowset, $index, $fields, $labels, is_array( $row ) ? $row : array() ); }
		echo '</div><template data-certloom-repeating-template>';
		$this->render_repeating_row( $rowset, '__INDEX__', $fields, $labels, array() );
		echo '</template><small class="certloom-manual-rowset-limit">' . esc_html__( 'Maximum 50 non-empty rows. Empty rows are ignored.', 'certloom' ) . '</small></section>';
	}

	/** @return void */
	private function render_repeating_row( $rowset, $index, array $fields, array $labels, array $row ) {
		echo '<div class="certloom-manual-repeating-row" data-certloom-repeating-row><div class="certloom-manual-repeating-fields">';
		foreach ( $fields as $field ) {
			$value = isset( $row[ $field ] ) && ! is_array( $row[ $field ] ) ? (string) $row[ $field ] : '';
			echo '<label><span>' . esc_html( $labels[ $field ] ?? $field ) . '</span><input type="text" maxlength="1000" name="repeating_data[' . esc_attr( $rowset ) . '][' . esc_attr( (string) $index ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( $value ) . '"></label>';
		}
		echo '</div><button type="button" class="button-link-delete certloom-manual-repeating-remove" data-certloom-repeating-remove aria-label="' . esc_attr__( 'Remove this repeating row', 'certloom' ) . '">' . esc_html__( 'Remove', 'certloom' ) . '</button></div>';
	}

	/** @return array<string,array<int,array<string,string>>>|\WP_Error */
	private function repeating_data_from_post( $value ) {
		if ( null === $value || '' === $value || array() === $value ) { return array(); }
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'subjects', 'results' ) ) ) {
			return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'Subjects/results input contains an unsupported rowset.', 'certloom' ) );
		}
		$out = array();
		foreach ( array( 'subjects' => TableDataContract::SUBJECTS, 'results' => TableDataContract::RESULTS ) as $rowset => $source ) {
			if ( ! array_key_exists( $rowset, $value ) ) { continue; }
			if ( ! is_array( $value[ $rowset ] ) || count( $value[ $rowset ] ) > TableDataContract::MAX_ROWS ) {
				return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'Subjects/results input is limited to 50 rows per rowset.', 'certloom' ) );
			}
			$allowed = TableDataContract::fields_for_source( $source );
			$clean_rows = array();
			foreach ( array_values( $value[ $rowset ] ) as $row ) {
				if ( ! is_array( $row ) || array_diff( array_keys( $row ), $allowed ) ) {
					return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'A subjects/results row contains an unsupported field.', 'certloom' ) );
				}
				$clean = array(); $has_value = false;
				foreach ( $allowed as $field ) {
					$cell = $row[ $field ] ?? '';
					if ( is_array( $cell ) || is_object( $cell ) ) { return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'Subjects/results cells must contain text values only.', 'certloom' ) ); }
					$cell = sanitize_text_field( (string) $cell );
					if ( '' !== trim( $cell ) ) { $has_value = true; }
					$clean[ $field ] = $cell;
				}
				if ( $has_value ) { $clean_rows[] = $clean; }
			}
			if ( $clean_rows ) { $out[ $rowset ] = $clean_rows; }
		}
		return $out;
	}

	/** @return void */
	private function render_repeating_data_review( array $data ) {
		$nonempty = array_filter( array( 'results' => (array) ( $data['results'] ?? array() ), 'subjects' => (array) ( $data['subjects'] ?? array() ) ) );
		if ( ! $nonempty ) { return; }
		echo '<div class="certloom-manual-repeating-review"><h3>' . esc_html__( 'Subjects & results', 'certloom' ) . '</h3><p class="description">' . esc_html__( 'These rows will be frozen into this issued credential and can feed Schema 4 Data Tables.', 'certloom' ) . '</p>';
		foreach ( $nonempty as $rowset => $rows ) {
			$row_count = count( $rows );
			$row_label = sprintf( /* translators: %d: number of immutable Subjects/Results rows. */ _n( '%d row', '%d rows', $row_count, 'certloom' ), $row_count );
			echo '<div class="certloom-manual-review-rowset"><strong>' . esc_html( 'results' === $rowset ? __( 'Results', 'certloom' ) : __( 'Subjects', 'certloom' ) ) . '</strong><span>' . esc_html( $row_label ) . '</span></div>';
		}
		echo '</div>';
	}

	/** @return void */
	private function hidden_request( array $r ) {
		foreach ( array( 'recipient_mode', 'recipient_id', 'credential_basis', 'title_source', 'credential_title', 'presentation_mode', 'certificate_image_id', 'issuer_id', 'signer_id', 'program_id', 'batch_id', 'template_id', 'design_source', 'valid_from', 'expiry_mode', 'expires_at', 'grade', 'display_number' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $r[ $key ] ?? '' ) ) . '">';
		}
		foreach ( (array) ( $r['new_recipient'] ?? array() ) as $key => $value ) {
			if ( ! is_array( $value ) ) { echo '<input type="hidden" name="new_recipient[' . esc_attr( (string) $key ) . ']" value="' . esc_attr( (string) $value ) . '">'; }
		}
		foreach ( (array) ( $r['custom_merge_fields'] ?? array() ) as $key => $value ) {
			echo '<input type="hidden" name="custom_merge_fields[' . esc_attr( (string) $key ) . ']" value="' . esc_attr( (string) $value ) . '">';
		}
		foreach ( (array) ( $r['design_field_aliases'] ?? array() ) as $target => $source ) {
			echo '<input type="hidden" name="design_field_aliases[' . esc_attr( (string) $target ) . ']" value="' . esc_attr( (string) $source ) . '">';
		}
		foreach ( (array) ( $r['repeating_data'] ?? array() ) as $rowset => $rows ) {
			if ( ! in_array( $rowset, array( 'subjects', 'results' ), true ) || ! is_array( $rows ) ) { continue; }
			foreach ( array_values( $rows ) as $index => $row ) {
				if ( ! is_array( $row ) ) { continue; }
				foreach ( $row as $field => $value ) {
					echo '<input type="hidden" name="repeating_data[' . esc_attr( $rowset ) . '][' . esc_attr( (string) $index ) . '][' . esc_attr( (string) $field ) . ']" value="' . esc_attr( (string) $value ) . '">';
				}
			}
		}
	}

	/** Human-readable provenance for an inherited certificate design. */
	private function inherited_design_source_label( $scope, $scope_id, array $program, $issuer, $batch ) {
		$scope = sanitize_key( (string) $scope );
		if ( 'batch' === $scope && is_array( $batch ) && ! empty( $batch['id'] ) ) { return sprintf( /* translators: %s: Batch name. */ __( 'Inherited from Batch “%s”', 'certloom' ), (string) ( $batch['name'] ?? $batch['code'] ?? '#' . $scope_id ) ); }
		if ( 'program' === $scope && ! empty( $program['id'] ) ) { return sprintf( /* translators: %s: Program title. */ __( 'Inherited from Program “%s”', 'certloom' ), (string) ( $program['title'] ?? '#' . $scope_id ) ); }
		if ( 'issuer' === $scope && is_array( $issuer ) && ! empty( $issuer['id'] ) ) { return sprintf( /* translators: %s: Issuer name. */ __( 'Inherited from Issuer “%s”', 'certloom' ), (string) ( $issuer['name'] ?? '#' . $scope_id ) ); }
		if ( 'global' === $scope ) { return __( 'Inherited from Global design', 'certloom' ); }
		return sprintf( /* translators: %s: design inheritance scope. */ __( 'Inherited via %s', 'certloom' ), '' !== $scope ? $scope : __( 'design hierarchy', 'certloom' ) );
	}

	/** @return void */ private function review_fact($label,$value,$meta='',$code=false){echo '<div class="certloom-manual-review-fact"><span>'.esc_html($label).'</span>'.($code?'<code>'.esc_html($value).'</code>':'<strong>'.esc_html($value).'</strong>').(''!==(string)$meta?'<small>'.esc_html((string)$meta).'</small>':'').'</div>';}
	/** @return void */ private function text_input($name,$label,$value,$max,$type='text',$required=false,$example=''){echo '<label><span>'.esc_html($label).'</span><input type="'.esc_attr($type).'" name="'.esc_attr($name).'" maxlength="'.esc_attr((string)$max).'" value="'.esc_attr($value).'"'.($required?' required':'').(''!==$example?' data-certloom-required-example="'.esc_attr($example).'"':'').'>'.(''!==$example?'<small>'.esc_html($example).'</small>':'').'</label>';}
	/** @return void */ private function radio($name,$value,$label,$current){echo '<label><input type="radio" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.checked((string)$current,(string)$value,false).'> <span>'.esc_html($label).'</span></label>';}
	/** @return void */ private function steps($current){$steps=array('compose'=>__( 'Compose', 'certloom' ),'review'=>__( 'Review', 'certloom' ),'issue'=>__( 'Issue', 'certloom' ),'receipt'=>__( 'Receipt', 'certloom' ));echo '<ol class="certloom-manual-steps" aria-label="'.esc_attr__( 'Manual Issuance progress', 'certloom' ).'">';$seen=true;foreach($steps as $key=>$label){$class=$key===$current?'is-current':($seen?'is-complete':'');echo '<li class="'.esc_attr($class).'" '.($key===$current?'aria-current="step"':'').'><span>'.esc_html($label).'</span></li>';if($key===$current)$seen=false;}echo '</ol>';}
	/** @return void */ private function require_capability(){if(!current_user_can(\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS)){wp_die(esc_html__( 'You do not have permission to issue Certloom credentials.', 'certloom' ));}}
	/** @return void */ private function store_notice($type,$message){set_transient(self::NOTICE_PREFIX.get_current_user_id(),array('type'=>(string)$type,'message'=>(string)$message),120);}
	/** @return void */ private function notice(){$key=self::NOTICE_PREFIX.get_current_user_id();$n=get_transient($key);if(!is_array($n)||empty($n['message']))return;delete_transient($key);$class='success'===($n['type']??'')?'notice-success':'notice-error';echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html((string)$n['message']).'</p></div>';}
}
