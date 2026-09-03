<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Application\Fields\ScopedFieldContract;
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
			$review = is_wp_error( $this->request_error ) ? $this->request_error : ( new ManualIssuanceService() )->review( $request );
		}

		$simple = AdminUiMode::is_simple();
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-manual-issuance">';
		echo '<div class="certloom-page-head"><div><span class="certloom-eyebrow">' . esc_html( $simple ? __( 'Issue a credential', 'certloom' ) : __( 'Premium credential workflow', 'certloom' ) ) . '</span><h1>' . esc_html__( 'Manual Issuance', 'certloom' ) . '</h1><p>' . esc_html__( 'Create one credential, review the recipient and published design, then issue it as one checked transaction.', 'certloom' ) . '</p></div>' . ( $simple ? '' : '<span class="certloom-domain-preview-badge">' . esc_html__( 'Credential preview', 'certloom' ) . '</span>' ) . '</div>';
		$this->notice();
		if ( $simple ) {
			echo '<div class="notice notice-info inline certloom-manual-issuance-authority"><p>' . esc_html__( 'Online verification or downloadable certificate files may be unavailable until this site finishes its release setup. You can still prepare and review the credential here.', 'certloom' ) . '</p></div>';
			echo '<details class="certloom-technical-details certloom-manual-issuance-technical-status"><summary>' . esc_html__( 'Technical status', 'certloom' ) . '</summary><p>' . esc_html__( 'Manual Issuance is available in the credential-management preview. The existing registry still powers public verification in this build, and PDF/PNG/JPEG exports remain unavailable until the certificate renderer is approved.', 'certloom' ) . '</p></details>';
		} else {
			echo '<div class="notice notice-warning inline certloom-manual-issuance-authority"><p><strong>' . esc_html__( 'Availability:', 'certloom' ) . '</strong> ' . esc_html__( 'Manual Issuance is available in the credential-management preview. The existing registry still powers public verification in this build, and PDF/PNG/JPEG exports remain unavailable until the certificate renderer is approved.', 'certloom' ) . '</p></div>';
		}
		$this->steps( $receipt_version ? 'receipt' : ( is_array( $review ) ? 'review' : 'compose' ) );

		if ( $receipt_version ) {
			$this->render_receipt( $receipt_version );
		} elseif ( is_wp_error( $review ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Review blocked:', 'certloom' ) . '</strong> ' . esc_html( $review->get_error_message() ) . '</p></div>';
			$this->render_compose( is_array( $request ) ? $request : array() );
		} elseif ( is_array( $review ) ) {
			$this->render_review( $review );
		} else {
			$this->render_compose( array() );
		}
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
		if ( ! $selected_program_id && isset( $_GET['program_id'] ) ) { $selected_program_id = absint( $_GET['program_id'] ); $values['program_id'] = $selected_program_id; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only quick-create preselection.
		if ( ! $selected_batch_id && isset( $_GET['batch_id'] ) ) { $selected_batch_id = absint( $_GET['batch_id'] ); $values['batch_id'] = $selected_batch_id; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only quick-create preselection.
		$context_choices = new ScopedContextChoices();
		$programs = $context_choices->programs( $selected_program_id );
		$batches  = $context_choices->batches( $selected_program_id, $selected_batch_id );
		$templates = $context_choices->templates( absint( $values['template_id'] ?? 0 ) );
		if ( is_wp_error( $recipients ) || is_wp_error( $programs ) || is_wp_error( $batches ) || is_wp_error( $templates ) ) {
			$error = is_wp_error( $recipients ) ? $recipients : ( is_wp_error( $programs ) ? $programs : ( is_wp_error( $batches ) ? $batches : $templates ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
			return;
		}
		$recipient_items = (array) ( $recipients['items'] ?? array() );
		// A direct "Issue credential" action from a Recipient profile must keep
		// that exact Recipient selectable even when it is outside the first search
		// page. The repository lookup is authoritative; the visible 50-row search
		// window is only a browsing convenience.
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
		$program_items = (array) $programs;
		$batch_items = (array) $batches;
		$template_items = (array) $templates;
		$mode = isset( $values['recipient_mode'] ) && 'new' === $values['recipient_mode'] ? 'new' : 'existing';
		$valid_from = (string) ( $values['valid_from'] ?? current_time( 'Y-m-d' ) );
		$expiry_mode = (string) ( $values['expiry_mode'] ?? 'program' );
		$numbering = new ManualIssuanceNumberAllocator();
		$number_preview = $numbering->is_automatic() ? $numbering->review( '' ) : null;

		echo '<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Step 1', 'certloom' ) . '</span><h2>' . esc_html__( 'Compose credential', 'certloom' ) . '</h2></div><p>' . esc_html__( 'Nothing is issued or numbered until you review and confirm.', 'certloom' ) . '</p></div>';
		if ( empty( $program_items ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Create and activate at least one Program before Manual Issuance can continue.', 'certloom' ) . '</p></div>'; }
		if ( empty( $template_items ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Publish at least one active Template before Manual Issuance can continue.', 'certloom' ) . '</p></div>'; }

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '" class="certloom-manual-form" data-certloom-human-form data-certloom-scoped-fields-form data-certloom-scoped-fields-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted field before reviewing this credential.', 'certloom' ) . '">';
		wp_nonce_field( 'certloom_manual_issuance_review' );
		echo '<input type="hidden" name="certloom_manual_issuance_step" value="review">';

		echo '<fieldset class="certloom-manual-fieldset certloom-recipient-compose" data-certloom-recipient-compose><legend>' . esc_html__( 'Recipient', 'certloom' ) . '</legend>';
		echo '<p class="certloom-manual-fieldset-intro">' . esc_html__( 'Who is receiving this credential?', 'certloom' ) . '</p><div class="certloom-manual-choice-row certloom-choice-pills">';
		$this->radio( 'recipient_mode', 'existing', __( 'Choose existing recipient', 'certloom' ), $mode );
		$this->radio( 'recipient_mode', 'new', __( 'Create new recipient', 'certloom' ), $mode );
		echo '</div>';

		echo '<div class="certloom-recipient-mode-panel" data-certloom-recipient-existing-panel' . ( 'new' === $mode ? ' hidden' : '' ) . '><label class="certloom-manual-primary-field"><span>' . esc_html__( 'Recipient', 'certloom' ) . '</span><select name="recipient_id" data-certloom-recipient-existing-select data-certloom-context-lookup="recipient" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search recipients by name, email, or external ID…', 'certloom' ) . '" data-certloom-required-example="' . esc_attr__( 'Choose the person or organization that should receive this credential.', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose recipient…', 'certloom' ) . '</option>';
		foreach ( $recipient_items as $recipient ) {
			$label = (string) ( $recipient['display_name'] ?? '' ); if ( ! empty( $recipient['email'] ) ) { $label .= ' — ' . (string) $recipient['email']; }
			echo '<option value="' . esc_attr( (string) absint( $recipient['id'] ?? 0 ) ) . '" ' . selected( absint( $values['recipient_id'] ?? 0 ), absint( $recipient['id'] ?? 0 ), false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'Start typing to search all Recipient profiles. Use one profile for the same person or organization across multiple credentials.', 'certloom' ) . '</small></label></div>';

		$new = isset( $values['new_recipient'] ) && is_array( $values['new_recipient'] ) ? $values['new_recipient'] : array();
		$new_name = trim( (string) ( $new['display_name'] ?? '' ) );
		echo '<div class="certloom-recipient-mode-panel certloom-recipient-new-panel" data-certloom-recipient-new-panel' . ( 'new' !== $mode ? ' hidden' : '' ) . '><div class="certloom-recipient-new-summary"><div><strong data-certloom-recipient-new-summary-name>' . esc_html( '' !== $new_name ? $new_name : __( 'New Recipient profile', 'certloom' ) ) . '</strong><p class="description">' . esc_html__( 'Create the complete Recipient profile once. Credential-specific fields such as grade remain in the credential section below.', 'certloom' ) . '</p></div><button type="button" class="button" data-certloom-dialog-open="certloom-new-recipient-dialog">' . esc_html( '' !== $new_name ? __( 'Edit profile', 'certloom' ) : __( 'Complete recipient profile', 'certloom' ) ) . '</button></div></div>';
		$this->render_new_recipient_dialog( $new );

		echo '<fieldset class="certloom-manual-fieldset"><legend>' . esc_html__( 'Credential context', 'certloom' ) . '</legend><div class="certloom-manual-grid certloom-manual-grid-2">';
		$context_nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		echo '<label><span>' . esc_html__( 'Program', 'certloom' ) . '</span><select name="program_id" required data-certloom-required-example="' . esc_attr__( 'Choose the Program this credential belongs to.', 'certloom' ) . '" data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose active Program…', 'certloom' ) . '</option>';
		foreach ( $program_items as $program ) { echo '<option value="' . esc_attr( (string) absint( $program['id'] ?? 0 ) ) . '" ' . selected( $selected_program_id, absint( $program['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::program_label( $program ) ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'Choose the Program or qualification this credential represents.', 'certloom' ) . '</small></label><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-quick-program-dialog">' . esc_html__( 'Add new Program', 'certloom' ) . '</button></div>';
		echo '<label><span>' . esc_html__( 'Batch / cohort (optional)', 'certloom' ) . '</span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search Batches in this Program…', 'certloom' ) . '"><option value="0">' . esc_html__( 'No Batch', 'certloom' ) . '</option>';
		foreach ( $batch_items as $batch ) { echo '<option value="' . esc_attr( (string) absint( $batch['id'] ?? 0 ) ) . '" data-program-id="' . esc_attr( (string) absint( $batch['program_id'] ?? 0 ) ) . '" ' . selected( $selected_batch_id, absint( $batch['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::batch_label( $batch ) ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'Optional: choose a cohort or intake within the selected Program.', 'certloom' ) . '</small></label><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-quick-batch-dialog">' . esc_html__( 'Add new Batch', 'certloom' ) . '</button></div>';
		echo '<label><span>' . esc_html__( 'Certificate design', 'certloom' ) . '</span><select name="template_id" data-certloom-context-lookup="template" data-certloom-context-empty-value="0" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search published Templates…', 'certloom' ) . '"><option value="0">' . esc_html__( 'Use inherited design automatically', 'certloom' ) . '</option>';
		foreach ( $template_items as $template ) { $label=ScopedContextChoices::template_label( $template ); echo '<option value="'.esc_attr((string)absint($template['id'])).'" '.selected(absint($values['template_id']??0),absint($template['id']),false).'>'.esc_html($label).'</option>'; }
		echo '</select><small>' . esc_html__( 'Usually keep the inherited result: Global → Issuer → Program → Batch, with the most specific selected design winning. Choose another published design only as a one-off override for this credential.', 'certloom' ) . '</small></label>';
		$this->text_input( 'valid_from', __( 'Issue / effective date', 'certloom' ), $valid_from, 10, 'date' );
		echo '<div data-certloom-grade-field>'; $this->text_input( 'grade', __( 'Grade / result (optional)', 'certloom' ), (string) ( $values['grade'] ?? '' ), 191 ); echo '</div>';
		echo '</div><div class="certloom-manual-choice-row certloom-manual-expiry">';
		$this->radio( 'expiry_mode', 'program', __( 'Use Program validity', 'certloom' ), $expiry_mode );
		$this->radio( 'expiry_mode', 'permanent', __( 'Permanent', 'certloom' ), $expiry_mode );
		$this->radio( 'expiry_mode', 'date', __( 'Specific expiry date', 'certloom' ), $expiry_mode );
		echo '</div>'; $this->text_input( 'expires_at', __( 'Specific expiry date', 'certloom' ), (string) ( $values['expires_at'] ?? '' ), 10, 'date' );
		echo '</fieldset>';

		echo '<fieldset class="certloom-manual-fieldset"><legend>' . esc_html__( 'Credential details', 'certloom' ) . '</legend>';
		if ( $numbering->is_automatic() ) {
			echo '<p><strong>' . esc_html__( 'Automatic numbering is enabled.', 'certloom' ) . '</strong> ';
			if ( is_wp_error( $number_preview ) ) { echo '<span class="certloom-text-danger">' . esc_html( $number_preview->get_error_message() ) . '</span>'; }
			elseif ( is_array( $number_preview ) ) { echo esc_html__( 'Number preview:', 'certloom' ) . ' <code>' . esc_html( (string) ( $number_preview['certificate_number'] ?? '' ) ) . '</code>'; }
			echo '</p><p class="description">' . esc_html__( 'This number is only a preview. The final number is assigned when you issue the credential, so a sequential number can change if another credential is issued first.', 'certloom' ) . '</p>';
		} else { $this->text_input( 'display_number', __( 'Certificate number', 'certloom' ), (string) ( $values['display_number'] ?? '' ), 100 ); }
		$custom = isset( $values['custom_merge_fields'] ) && is_array( $values['custom_merge_fields'] ) ? $values['custom_merge_fields'] : array();
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		echo '<div class="certloom-scoped-field-panel" data-certloom-scoped-field-panel><div class="certloom-scoped-field-status" data-certloom-scoped-field-status aria-live="polite">' . esc_html__( 'Choose a Program to load its credential fields.', 'certloom' ) . '</div><div class="certloom-scoped-field-inputs" data-certloom-scoped-field-inputs></div></div>';
		echo '<script type="application/json" data-certloom-scoped-existing-values>' . wp_json_encode( $custom, $json_flags ) . '</script>';
		if ( AdminUiMode::is_simple() ) {
			echo '<div data-certloom-legacy-custom-fields><p class="description">' . esc_html__( 'Additional credential fields are managed from the Program. Choose a Program above to load the fields it uses.', 'certloom' ) . '</p><details class="certloom-technical-details certloom-manual-custom-fields-technical"><summary>' . esc_html__( 'Technical custom fields', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Use short field keys only for an older or specialist workflow that has not yet adopted Program credential fields. Program and Batch field rules take precedence when they are available.', 'certloom' ) . '</p><div class="certloom-manual-custom-grid">';
			$rows = max( 4, min( 10, count( $custom ) + 2 ) ); $keys=array_keys($custom); for($i=0;$i<$rows;$i++){ $key=$keys[$i]??''; $val=''!==$key?(string)$custom[$key]:''; echo '<div class="certloom-manual-custom-row"><label><span>'.esc_html__( 'Field key', 'certloom' ).'</span><input type="text" name="custom_merge_key[]" maxlength="64" value="'.esc_attr($key).'" placeholder="membership_level"></label><label><span>'.esc_html__( 'Value', 'certloom' ).'</span><input type="text" name="custom_merge_value[]" maxlength="2000" value="'.esc_attr($val).'"></label></div>'; }
			echo '</div></details></div>';
		} else {
			echo '<div data-certloom-legacy-custom-fields><p class="description">' . esc_html__( 'Basic Program mode: optional credential-specific values use short field keys. Once a Program adopts Credential fields, Certloom replaces this free-key grid with the exact Program and Batch fields.', 'certloom' ) . '</p><div class="certloom-manual-custom-grid">';
			$rows = max( 4, min( 10, count( $custom ) + 2 ) ); $keys=array_keys($custom); for($i=0;$i<$rows;$i++){ $key=$keys[$i]??''; $val=''!==$key?(string)$custom[$key]:''; echo '<div class="certloom-manual-custom-row"><label><span>'.esc_html__( 'Field key', 'certloom' ).'</span><input type="text" name="custom_merge_key[]" maxlength="64" value="'.esc_attr($key).'" placeholder="membership_level"></label><label><span>'.esc_html__( 'Value', 'certloom' ).'</span><input type="text" name="custom_merge_value[]" maxlength="2000" value="'.esc_attr($val).'"></label></div>'; }
			echo '</div></div>';
		}
		echo '</fieldset>';
		echo '<div class="certloom-manual-actions"><button type="submit" class="button button-primary button-hero" data-certloom-manual-review data-certloom-has-templates="' . esc_attr( empty( $template_items ) ? '0' : '1' ) . '" ' . ( empty( $program_items ) || empty( $template_items ) ? 'disabled' : '' ) . '>' . esc_html__( 'Review credential', 'certloom' ) . '</button><span>' . esc_html__( 'Review is read-only and does not reserve a certificate number.', 'certloom' ) . '</span></div></form></section>';
		$this->render_quick_context_dialogs( $selected_program_id );
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
		$templates = ( new ScopedContextChoices() )->templates();
		$templates = is_wp_error( $templates ) ? array() : (array) $templates;
		$fields = \Ecapla_CertVerify_Fields::get_scopable_fields();

		echo '<dialog id="certloom-quick-program-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="program" data-certloom-quick-target="select[name=program_id]" data-certloom-human-form data-certloom-invalid-message="' . esc_attr__( 'Complete the highlighted Program field.', 'certloom' ) . '"><input type="hidden" name="action" value="certloom_manual_quick_program"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'certloom_manual_quick_program' ) ) . '">';
		echo '<div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html__( 'Add Program', 'certloom' ) . '</h2><p>' . esc_html__( 'Add the essentials needed for issuance here. Visit Programs later for language, renewal and other deeper settings.', 'certloom' ) . '</p></div><button type="button" class="button-link" data-certloom-dialog-close aria-label="' . esc_attr__( 'Close Add Program', 'certloom' ) . '">×</button></div><div class="certloom-dialog-body">';
		echo '<div class="certloom-manual-grid certloom-manual-grid-2">';
		$this->text_input( 'program_title', __( 'Program title', 'certloom' ), '', 255, 'text', true, __( 'Example: Internal Auditor Certification', 'certloom' ) );
		$this->text_input( 'program_code', __( 'Short code (optional)', 'certloom' ), '', 100, 'text', false, __( 'Example: IA-101', 'certloom' ) );
		echo '<label><span>' . esc_html__( 'Certificate design', 'certloom' ) . '</span><select name="program_template_id"><option value="0">' . esc_html__( 'Inherit Issuer / Global design', 'certloom' ) . '</option>';
		foreach ( $templates as $template ) { echo '<option value="' . esc_attr( (string) absint( $template['id'] ?? 0 ) ) . '">' . esc_html( (string) ( $template['name'] ?? '' ) ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'Choose the default published design for this Program.', 'certloom' ) . '</small></label></div>';
		echo '<details class="certloom-dialog-options" open><summary>' . esc_html__( 'Credential fields used by this Program', 'certloom' ) . '</summary><div class="certloom-quick-contract-grid">';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key ) { continue; } echo '<label><span>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</span><select name="program_field_requirement[' . esc_attr( $key ) . ']"><option value="">' . esc_html__( 'Not used', 'certloom' ) . '</option><option value="optional">' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required">' . esc_html__( 'Required', 'certloom' ) . '</option></select></label>'; }
		echo '</div></details><p class="certloom-dialog-footnote">' . esc_html__( 'Need another field or more Program options? Save this Program, then open Programs → Program to make deeper changes.', 'certloom' ) . '</p></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add Program', 'certloom' ) . '</button></div></form></dialog>';

		echo '<dialog id="certloom-quick-batch-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="batch" data-certloom-quick-target="select[name=batch_id]" data-certloom-quick-program-source="select[name=program_id]" data-certloom-human-form data-certloom-invalid-message="' . esc_attr__( 'Complete the highlighted Batch field.', 'certloom' ) . '"><input type="hidden" name="action" value="certloom_manual_quick_batch"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'certloom_manual_quick_batch' ) ) . '">';
		echo '<div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html__( 'Add Batch', 'certloom' ) . '</h2><p>' . esc_html__( 'Create a cohort or intake, including the design and credential-field changes it needs, then return to this issuance.', 'certloom' ) . '</p></div><button type="button" class="button-link" data-certloom-dialog-close aria-label="' . esc_attr__( 'Close Add Batch', 'certloom' ) . '">×</button></div><div class="certloom-dialog-body">';
		echo '<div class="certloom-manual-grid certloom-manual-grid-2"><label><span>' . esc_html__( 'Program', 'certloom' ) . '</span><select name="program_id" required data-certloom-required-example="' . esc_attr__( 'Choose the Program this Batch belongs to.', 'certloom' ) . '" data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose Program…', 'certloom' ) . '</option>';
		$programs = ( new ScopedContextChoices() )->programs( $selected_program_id );
		if ( ! is_wp_error( $programs ) ) { foreach ( $programs as $program ) { echo '<option value="' . esc_attr( (string) absint( $program['id'] ?? 0 ) ) . '" ' . selected( absint( $selected_program_id ), absint( $program['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::program_label( $program ) ) . '</option>'; } }
		echo '</select></label>';
		$this->text_input( 'batch_name', __( 'Batch name', 'certloom' ), '', 255, 'text', true, __( 'Example: Spring 2027 Cohort', 'certloom' ) );
		$this->text_input( 'batch_code', __( 'Batch code (optional)', 'certloom' ), '', 100, 'text', false, __( 'Leave blank to generate one from the Batch name.', 'certloom' ) );
		echo '<label><span>' . esc_html__( 'Batch certificate design', 'certloom' ) . '</span><select name="batch_template_id"><option value="0">' . esc_html__( 'Inherit Program design', 'certloom' ) . '</option>';
		foreach ( $templates as $template ) { echo '<option value="' . esc_attr( (string) absint( $template['id'] ?? 0 ) ) . '">' . esc_html( (string) ( $template['name'] ?? '' ) ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'A selected Batch design is the most specific choice and overrides Program, Issuer and Global for this Batch only.', 'certloom' ) . '</small></label></div>';
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
		$metadata = ( new ScopedFieldContract() )->with_program_metadata( array(), $rules, \Ecapla_CertVerify_Fields::get_field_map() );
		if ( is_wp_error( $metadata ) ) { wp_send_json_error( array( 'message' => $metadata->get_error_message() ), 400 ); }
		$result = ( new ProgramRepository() )->create( array( 'title' => $title, 'code' => $code, 'active' => 1, 'metadata' => $metadata ) );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		$template_id = isset( $_POST['program_template_id'] ) ? absint( $_POST['program_template_id'] ) : 0;
		if ( $template_id ) { $assigned = ( new TemplateRepository() )->assign( $template_id, 'program', absint( $result ) ); if ( is_wp_error( $assigned ) ) { wp_send_json_error( array( 'message' => $assigned->get_error_message() ), 400 ); } }
		wp_send_json_success( array( 'id' => absint( $result ), 'label' => '' !== $code ? $title . ' — ' . $code : $title, 'message' => __( 'Program added with its issuance fields and design, then selected.', 'certloom' ) ) );
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
		$template_id = isset( $_POST['batch_template_id'] ) ? absint( $_POST['batch_template_id'] ) : 0;
		if ( $template_id ) { $assigned = ( new TemplateRepository() )->assign( $template_id, 'batch', absint( $result ) ); if ( is_wp_error( $assigned ) ) { wp_send_json_error( array( 'message' => $assigned->get_error_message() ), 400 ); } }
		wp_send_json_success( array( 'id' => absint( $result ), 'program_id' => $program_id, 'label' => '' !== $code ? $name . ' — ' . $code : $name, 'message' => __( 'Batch added with its issuance settings and selected.', 'certloom' ) ) );
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
		$request = (array) $review['request']; $recipient=(array)$review['recipient']; $program=(array)$review['program']; $template=(array)$review['template']; $revision=(array)$review['template_revision'];
		echo '<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Step 2', 'certloom' ) . '</span><h2>' . esc_html__( 'Review credential', 'certloom' ) . '</h2></div><span class="certloom-template-state certloom-template-state-published">' . esc_html__( 'Ready to issue', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-manual-review-grid">';
		$this->review_fact( __( 'Recipient', 'certloom' ), (string) ( $recipient['display_name'] ?? '' ), ! empty( $recipient['email'] ) ? (string) $recipient['email'] : '' );
		$this->review_fact( __( 'Program', 'certloom' ), (string) ( $program['title'] ?? '' ), ! empty( $program['code'] ) ? (string) $program['code'] : '' );
		$locale = isset( $review['credential_locale'] ) && is_array( $review['credential_locale'] ) ? $review['credential_locale'] : array();
		$locale_effective = (string) ( $locale['effective'] ?? '' );
		$locale_configured = (string) ( $locale['configured'] ?? '' );
		$locale_direction = strtoupper( (string) ( $locale['direction'] ?? 'ltr' ) );
		$locale_meta = $locale_direction;
		if ( '' !== $locale_configured && $locale_configured !== $locale_effective ) {
			$locale_meta .= ' · ' . sprintf( /* translators: %s: locale configured. */ __( 'configured %s', 'certloom' ), $locale_configured );
		}
		$this->review_fact( __( 'Credential language', 'certloom' ), '' !== $locale_effective ? $locale_effective : __( 'Site default', 'certloom' ), $locale_meta );
		$template_meta = sprintf( /* translators: %1$d: revision number; %2$d: ID. */ __( 'Published revision #%1$d · ID %2$d', 'certloom' ), absint( $revision['revision_number'] ?? 0 ), absint( $revision['id'] ?? 0 ) );
		$resolution_scope = (string) ( $review['template_resolution'] ?? 'explicit' );
		$variant = isset( $review['template_locale_variant'] ) && is_array( $review['template_locale_variant'] ) ? $review['template_locale_variant'] : array();
		if ( ! empty( $variant['matched_locale'] ) ) {
			$template_meta .= ' · ' . sprintf( /* translators: %s: matched locale. */ __( 'language variant %s', 'certloom' ), (string) $variant['matched_locale'] );
		} elseif ( 'explicit' === $resolution_scope ) {
			$template_meta .= ' · ' . __( 'explicit reviewed override', 'certloom' );
		} else {
			$template_meta .= ' · ' . sprintf( /* translators: %s: resolution scope. */ __( 'Program design via %s', 'certloom' ), $resolution_scope );
		}
		$this->review_fact( __( 'Template', 'certloom' ), (string) ( $template['name'] ?? '' ), $template_meta );
		$this->review_fact( __( 'Effective date', 'certloom' ), (string) $review['valid_from'], null === $review['expires_at'] ? __( 'Permanent / no expiry', 'certloom' ) : sprintf( /* translators: %s: expires at. */ __( 'Expires %s', 'certloom' ), (string) $review['expires_at'] ) );
		$number = is_array( $review['numbering'] ?? null ) ? (string) ( $review['numbering']['certificate_number'] ?? '' ) : (string) ( $request['display_number'] ?? '' );
		$this->review_fact( __( 'Certificate number', 'certloom' ), '' !== $number ? $number : __( 'Allocated on Issue', 'certloom' ), \Ecapla_CertVerify_Numbering::is_enabled() ? __( 'Number preview; not reserved', 'certloom' ) : __( 'Manual number', 'certloom' ) );
		echo '</div>';
		$preview=(new ManualIssuancePreviewService())->build($review);
		echo '<details class="certloom-technical-details certloom-manual-proof"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Integrity identifiers are available for support and auditing. They are checked automatically and are not required for normal issuance.', 'certloom' ) . '</p><div class="certloom-template-preview-proof"><div><span>' . esc_html__( 'Review SHA-256', 'certloom' ) . '</span><code data-certloom-review-sha>' . esc_html( (string) $review['review_fingerprint'] ) . '</code></div><div><span>' . esc_html__( 'Layout schema', 'certloom' ) . '</span><strong>' . esc_html( (string) ( $revision['layout_schema'] ?? '' ) ) . ' v' . esc_html( (string) absint( $revision['layout_schema_version'] ?? 0 ) ) . '</strong></div><div><span>' . esc_html__( 'Published layout SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) ( $revision['layout_sha256'] ?? '' ) ) . '</code></div>' . ( is_wp_error( $preview ) ? '' : '<div><span>' . esc_html__( 'Render Plan SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) $preview['render_plan_sha256'] ) . '</code></div>' ) . '</div></details>';
		if(is_wp_error($preview)){echo '<div class="notice notice-error inline"><p>'.esc_html($preview->get_error_message()).'</p></div>';}else{ try{$presented=(new RenderPlanHtmlPreview())->present($preview['render_plan']); echo '<div class="certloom-template-preview-card certloom-manual-preview"><div class="certloom-template-preview-head"><div><h3>'.esc_html__( 'Credential Preview', 'certloom' ).'</h3><p>'.esc_html__( 'Built from the selected published design and the exact recipient/credential details in this review.', 'certloom' ).'</p></div><span class="certloom-template-preview-authority">'.esc_html__( 'Browser preview', 'certloom' ).'</span></div>'.$presented['html'].'<p class="description">'.esc_html__( 'Certificate IDs and the final verification URL are created only when you issue. The browser preview may render fonts slightly differently from future downloadable certificate files.', 'certloom' ).'</p></div>'; }catch(\Throwable $e){echo '<div class="notice notice-error inline"><p>'.esc_html__( 'The credential preview could not be shown safely in the browser.', 'certloom' ).'</p></div>';}}
		$custom=(array)($request['custom_merge_fields']??array()); if($custom){echo '<h3>'.esc_html__( 'Credential-specific custom merge values', 'certloom' ).'</h3><dl class="certloom-manual-merge-list">';foreach($custom as $k=>$v){echo '<div><dt><code>custom:'.esc_html((string)$k).'</code></dt><dd>'.esc_html((string)$v).'</dd></div>';}echo '</dl>';}
		echo '<div class="notice notice-info inline"><p><strong>'.esc_html__( 'What Issue will do:', 'certloom' ).'</strong> '.esc_html__( 'Check this review again, assign the certificate number, create the recipient if needed, save the issued credential and its design/details, and record the issuance as one checked transaction.', 'certloom' ).'</p></div>';
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
		return array(
			'recipient_mode' => isset( $post['recipient_mode'] ) ? (string) $post['recipient_mode'] : 'existing',
			'recipient_id' => isset( $post['recipient_id'] ) ? $post['recipient_id'] : 0,
			'new_recipient' => isset( $post['new_recipient'] ) && is_array( $post['new_recipient'] ) ? $post['new_recipient'] : array(),
			'program_id' => isset( $post['program_id'] ) ? $post['program_id'] : 0,
			'batch_id' => isset( $post['batch_id'] ) ? $post['batch_id'] : 0,
			'template_id' => isset( $post['template_id'] ) ? $post['template_id'] : 0,
			'valid_from' => isset( $post['valid_from'] ) ? (string) $post['valid_from'] : '',
			'expiry_mode' => isset( $post['expiry_mode'] ) ? (string) $post['expiry_mode'] : 'program',
			'expires_at' => isset( $post['expires_at'] ) ? (string) $post['expires_at'] : '',
			'grade' => isset( $post['grade'] ) ? (string) $post['grade'] : '',
			'display_number' => isset( $post['display_number'] ) ? (string) $post['display_number'] : '',
			'custom_merge_fields' => $custom,
		);
	}

	/** @return void */
	private function hidden_request( array $r ) { foreach(array('recipient_mode','recipient_id','program_id','batch_id','template_id','valid_from','expiry_mode','expires_at','grade','display_number') as $k){echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)($r[$k]??'')).'">';} foreach((array)($r['new_recipient']??array()) as $k=>$v){if(!is_array($v)){echo '<input type="hidden" name="new_recipient['.esc_attr((string)$k).']" value="'.esc_attr((string)$v).'">';}} foreach((array)($r['custom_merge_fields']??array()) as $k=>$v){echo '<input type="hidden" name="custom_merge_fields['.esc_attr((string)$k).']" value="'.esc_attr((string)$v).'">';} }
	/** @return void */ private function review_fact($label,$value,$meta='',$code=false){echo '<div class="certloom-manual-review-fact"><span>'.esc_html($label).'</span>'.($code?'<code>'.esc_html($value).'</code>':'<strong>'.esc_html($value).'</strong>').(''!==(string)$meta?'<small>'.esc_html((string)$meta).'</small>':'').'</div>';}
	/** @return void */ private function text_input($name,$label,$value,$max,$type='text',$required=false,$example=''){echo '<label><span>'.esc_html($label).'</span><input type="'.esc_attr($type).'" name="'.esc_attr($name).'" maxlength="'.esc_attr((string)$max).'" value="'.esc_attr($value).'"'.($required?' required':'').(''!==$example?' data-certloom-required-example="'.esc_attr($example).'"':'').'>'.(''!==$example?'<small>'.esc_html($example).'</small>':'').'</label>';}
	/** @return void */ private function radio($name,$value,$label,$current){echo '<label><input type="radio" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.checked((string)$current,(string)$value,false).'> <span>'.esc_html($label).'</span></label>';}
	/** @return void */ private function steps($current){$steps=array('compose'=>__( 'Compose', 'certloom' ),'review'=>__( 'Review', 'certloom' ),'issue'=>__( 'Issue', 'certloom' ),'receipt'=>__( 'Receipt', 'certloom' ));echo '<ol class="certloom-manual-steps" aria-label="'.esc_attr__( 'Manual Issuance progress', 'certloom' ).'">';$seen=true;foreach($steps as $key=>$label){$class=$key===$current?'is-current':($seen?'is-complete':'');echo '<li class="'.esc_attr($class).'" '.($key===$current?'aria-current="step"':'').'><span>'.esc_html($label).'</span></li>';if($key===$current)$seen=false;}echo '</ol>';}
	/** @return void */ private function require_capability(){if(!current_user_can(\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS)){wp_die(esc_html__( 'You do not have permission to issue Certloom credentials.', 'certloom' ));}}
	/** @return void */ private function store_notice($type,$message){set_transient(self::NOTICE_PREFIX.get_current_user_id(),array('type'=>(string)$type,'message'=>(string)$message),120);}
	/** @return void */ private function notice(){$key=self::NOTICE_PREFIX.get_current_user_id();$n=get_transient($key);if(!is_array($n)||empty($n['message']))return;delete_transient($key);$class='success'===($n['type']??'')?'notice-success':'notice-error';echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html((string)$n['message']).'</p></div>';}
}
