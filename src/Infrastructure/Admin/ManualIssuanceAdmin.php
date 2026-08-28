<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Infrastructure\Issuance\ManualIssuanceNumberAllocator;
use Certloom\Infrastructure\Rendering\ManualIssuancePreviewService;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Verification\PublicVerificationGate;

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
	}

	/** @return void */
	public function menu() {
		add_submenu_page( 'certloom', __( 'Issuance', 'certloom' ), __( 'Issuance', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, self::PAGE, array( $this, 'render' ) );
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

		echo '<div class="wrap certloom-shell certloom-manual-issuance">';
		echo '<div class="certloom-page-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Premium credential workflow', 'certloom' ) . '</span><h1>' . esc_html__( 'Manual Issuance', 'certloom' ) . '</h1><p>' . esc_html__( 'Compose one credential, review the exact published Template and immutable inputs, then issue one Credential Series and Version atomically.', 'certloom' ) . '</p></div><span class="certloom-domain-preview-badge">' . esc_html__( 'Normalized-domain preview', 'certloom' ) . '</span></div>';
		$this->notice();
		echo '<div class="notice notice-warning inline certloom-manual-issuance-authority"><p><strong>' . esc_html__( 'Authority boundary:', 'certloom' ) . '</strong> ' . esc_html__( 'Manual Issuance is implemented inside the gated normalized-domain administration preview. The legacy registry remains public authority until final database cutover, and PDF/PNG/JPEG exports remain unavailable until renderer acceptance.', 'certloom' ) . '</p></div>';
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
		$this->store_notice( 'success', ! empty( $result['idempotent_replay'] ) ? __( 'That exact issuance was already committed. Certloom returned the existing receipt without issuing a duplicate.', 'certloom' ) : __( 'Credential issued successfully. The immutable Version and issuance event were committed atomically.', 'certloom' ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'issued_version' => absint( $result['version_id'] ?? 0 ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	private function render_compose( array $values ) {
		$recipient_search = isset( $_GET['recipient_search'] ) ? sanitize_text_field( wp_unslash( $_GET['recipient_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$recipients = ( new RecipientRepository() )->list_page( $recipient_search, 1, 50 );
		$programs = ( new ProgramRepository() )->list_page( '', 1, 100, 'active' );
		$templates = ( new TemplateRepository() )->list_published_candidates( 100 );
		if ( is_wp_error( $recipients ) || is_wp_error( $programs ) || is_wp_error( $templates ) ) {
			$error = is_wp_error( $recipients ) ? $recipients : ( is_wp_error( $programs ) ? $programs : $templates );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error->get_error_message() ) . '</p></div>';
			return;
		}
		$recipient_items = (array) ( $recipients['items'] ?? array() );
		$program_items = (array) ( $programs['items'] ?? array() );
		$template_items = (array) $templates;
		$mode = isset( $values['recipient_mode'] ) && 'new' === $values['recipient_mode'] ? 'new' : 'existing';
		$valid_from = (string) ( $values['valid_from'] ?? current_time( 'Y-m-d' ) );
		$expiry_mode = (string) ( $values['expiry_mode'] ?? 'program' );
		$numbering = new ManualIssuanceNumberAllocator();
		$number_preview = $numbering->is_automatic() ? $numbering->review( '' ) : null;

		echo '<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Step 1', 'certloom' ) . '</span><h2>' . esc_html__( 'Compose credential', 'certloom' ) . '</h2></div><p>' . esc_html__( 'No Recipient, sequence value or credential record is created while composing or reviewing.', 'certloom' ) . '</p></div>';
		if ( empty( $program_items ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Create and activate at least one Program before Manual Issuance can continue.', 'certloom' ) . '</p></div>'; }
		if ( empty( $template_items ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Publish at least one active Template before Manual Issuance can continue.', 'certloom' ) . '</p></div>'; }

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="certloom-manual-recipient-search" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label for="certloom-recipient-search"><span>' . esc_html__( 'Find an existing recipient', 'certloom' ) . '</span></label>';
		echo '<div><input id="certloom-recipient-search" type="search" name="recipient_search" value="' . esc_attr( $recipient_search ) . '" maxlength="255" placeholder="' . esc_attr__( 'Name, email, or external ID', 'certloom' ) . '"> <button class="button" type="submit">' . esc_html__( 'Search recipients', 'certloom' ) . '</button>';
		if ( '' !== $recipient_search ) { echo ' <a class="button-link" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Clear search', 'certloom' ) . '</a>'; }
		echo '</div><small>' . esc_html( sprintf( _n( '%d matching recipient shown (up to 50).', '%d matching recipients shown (up to 50).', count( $recipient_items ), 'certloom' ), count( $recipient_items ) ) ) . '</small></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '" class="certloom-manual-form">';
		wp_nonce_field( 'certloom_manual_issuance_review' );
		echo '<input type="hidden" name="certloom_manual_issuance_step" value="review">';

		echo '<fieldset class="certloom-manual-fieldset"><legend>' . esc_html__( 'Recipient', 'certloom' ) . '</legend><div class="certloom-manual-choice-row">';
		$this->radio( 'recipient_mode', 'existing', __( 'Existing recipient', 'certloom' ), $mode );
		$this->radio( 'recipient_mode', 'new', __( 'Create new recipient', 'certloom' ), $mode );
		echo '</div><div class="certloom-manual-grid certloom-manual-grid-2">';
		echo '<label><span>' . esc_html__( 'Existing recipient', 'certloom' ) . '</span><select name="recipient_id"><option value="">' . esc_html__( 'Choose recipient…', 'certloom' ) . '</option>';
		foreach ( $recipient_items as $recipient ) {
			$label = (string) ( $recipient['display_name'] ?? '' ); if ( ! empty( $recipient['email'] ) ) { $label .= ' — ' . (string) $recipient['email']; }
			echo '<option value="' . esc_attr( (string) absint( $recipient['id'] ?? 0 ) ) . '" ' . selected( absint( $values['recipient_id'] ?? 0 ), absint( $recipient['id'] ?? 0 ), false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'The selector shows up to 50 recipients from the current search.', 'certloom' ) . '</small></label>';
		$new = isset( $values['new_recipient'] ) && is_array( $values['new_recipient'] ) ? $values['new_recipient'] : array();
		$this->text_input( 'new_recipient[display_name]', __( 'New recipient display name', 'certloom' ), (string) ( $new['display_name'] ?? '' ), 255 );
		$this->text_input( 'new_recipient[first_name]', __( 'First name', 'certloom' ), (string) ( $new['first_name'] ?? '' ), 191 );
		$this->text_input( 'new_recipient[last_name]', __( 'Last name', 'certloom' ), (string) ( $new['last_name'] ?? '' ), 191 );
		$this->text_input( 'new_recipient[email]', __( 'Email', 'certloom' ), (string) ( $new['email'] ?? '' ), 320, 'email' );
		echo '</div></fieldset>';

		echo '<fieldset class="certloom-manual-fieldset"><legend>' . esc_html__( 'Credential context', 'certloom' ) . '</legend><div class="certloom-manual-grid certloom-manual-grid-2">';
		echo '<label><span>' . esc_html__( 'Program', 'certloom' ) . '</span><select name="program_id" required><option value="">' . esc_html__( 'Choose active Program…', 'certloom' ) . '</option>';
		foreach ( $program_items as $program ) { $label=(string)$program['title']; if(!empty($program['code'])){$label.=' · '.(string)$program['code'];} echo '<option value="'.esc_attr((string)absint($program['id'])).'" '.selected(absint($values['program_id']??0),absint($program['id']),false).'>'.esc_html($label).'</option>'; }
		echo '</select></label>';
		echo '<label><span>' . esc_html__( 'Certificate design', 'certloom' ) . '</span><select name="template_id"><option value="0">' . esc_html__( 'Use Program design automatically', 'certloom' ) . '</option>';
		foreach ( $template_items as $template ) { $label=(string)$template['name'].' · r'.absint($template['revision_number']??0); echo '<option value="'.esc_attr((string)absint($template['id'])).'" '.selected(absint($values['template_id']??0),absint($template['id']),false).'>'.esc_html($label).'</option>'; }
		echo '</select><small>' . esc_html__( 'Recommended: leave this on Program design. Choose a Template only for an explicit one-off override; issued Versions still freeze the exact published revision.', 'certloom' ) . '</small></label>';
		$this->text_input( 'valid_from', __( 'Issue / effective date', 'certloom' ), $valid_from, 10, 'date' );
		$this->text_input( 'grade', __( 'Grade / result (optional)', 'certloom' ), (string) ( $values['grade'] ?? '' ), 191 );
		echo '</div><div class="certloom-manual-choice-row certloom-manual-expiry">';
		$this->radio( 'expiry_mode', 'program', __( 'Use Program validity', 'certloom' ), $expiry_mode );
		$this->radio( 'expiry_mode', 'permanent', __( 'Permanent', 'certloom' ), $expiry_mode );
		$this->radio( 'expiry_mode', 'date', __( 'Specific expiry date', 'certloom' ), $expiry_mode );
		echo '</div>'; $this->text_input( 'expires_at', __( 'Specific expiry date', 'certloom' ), (string) ( $values['expires_at'] ?? '' ), 10, 'date' );
		echo '</fieldset>';

		echo '<fieldset class="certloom-manual-fieldset"><legend>' . esc_html__( 'Certificate number & custom merge values', 'certloom' ) . '</legend>';
		if ( $numbering->is_automatic() ) {
			echo '<p><strong>' . esc_html__( 'Automatic numbering is enabled.', 'certloom' ) . '</strong> ';
			if ( is_wp_error( $number_preview ) ) { echo '<span class="certloom-text-danger">' . esc_html( $number_preview->get_error_message() ) . '</span>'; }
			elseif ( is_array( $number_preview ) ) { echo esc_html__( 'Non-reserved review candidate:', 'certloom' ) . ' <code>' . esc_html( (string) ( $number_preview['certificate_number'] ?? '' ) ) . '</code>'; }
			echo '</p><p class="description">' . esc_html__( 'The candidate is not reserved. The final Issue action allocates under the existing collision policy; a sequential value can legitimately differ if another issuance commits first.', 'certloom' ) . '</p>';
		} else { $this->text_input( 'display_number', __( 'Certificate number', 'certloom' ), (string) ( $values['display_number'] ?? '' ), 100 ); }
		$custom = isset( $values['custom_merge_fields'] ) && is_array( $values['custom_merge_fields'] ) ? $values['custom_merge_fields'] : array();
		echo '<p class="description">' . esc_html__( 'Optional credential-specific custom merge values override only custom:field_key / recipient.custom.field_key tokens for this issuance. They never overwrite built-in identities or mutate the Recipient.', 'certloom' ) . '</p><div class="certloom-manual-custom-grid">';
		$rows = max( 4, min( 10, count( $custom ) + 2 ) ); $keys=array_keys($custom); for($i=0;$i<$rows;$i++){ $key=$keys[$i]??''; $val=''!==$key?(string)$custom[$key]:''; echo '<div class="certloom-manual-custom-row"><label><span>'.esc_html__( 'Field key', 'certloom' ).'</span><input type="text" name="custom_merge_key[]" maxlength="64" value="'.esc_attr($key).'" placeholder="membership_level"></label><label><span>'.esc_html__( 'Value', 'certloom' ).'</span><input type="text" name="custom_merge_value[]" maxlength="2000" value="'.esc_attr($val).'"></label></div>'; }
		echo '</div></fieldset>';
		echo '<div class="certloom-manual-actions"><button type="submit" class="button button-primary button-hero" ' . ( empty( $program_items ) || empty( $template_items ) ? 'disabled' : '' ) . '>' . esc_html__( 'Review credential', 'certloom' ) . '</button><span>' . esc_html__( 'Review is read-only and does not reserve a certificate number.', 'certloom' ) . '</span></div></form></section>';
	}

	/** @return void */
	private function render_review( array $review ) {
		$request = (array) $review['request']; $recipient=(array)$review['recipient']; $program=(array)$review['program']; $template=(array)$review['template']; $revision=(array)$review['template_revision'];
		echo '<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Step 2', 'certloom' ) . '</span><h2>' . esc_html__( 'Review exact issuance', 'certloom' ) . '</h2></div><span class="certloom-template-state certloom-template-state-published">' . esc_html__( 'Published revision only', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-manual-review-grid">';
		$this->review_fact( __( 'Recipient', 'certloom' ), (string) ( $recipient['display_name'] ?? '' ), ! empty( $recipient['email'] ) ? (string) $recipient['email'] : '' );
		$this->review_fact( __( 'Program', 'certloom' ), (string) ( $program['title'] ?? '' ), ! empty( $program['code'] ) ? (string) $program['code'] : '' );
		$locale = isset( $review['credential_locale'] ) && is_array( $review['credential_locale'] ) ? $review['credential_locale'] : array();
		$locale_effective = (string) ( $locale['effective'] ?? '' );
		$locale_configured = (string) ( $locale['configured'] ?? '' );
		$locale_direction = strtoupper( (string) ( $locale['direction'] ?? 'ltr' ) );
		$locale_meta = $locale_direction;
		if ( '' !== $locale_configured && $locale_configured !== $locale_effective ) {
			$locale_meta .= ' · ' . sprintf( __( 'configured %s', 'certloom' ), $locale_configured );
		}
		$this->review_fact( __( 'Credential language', 'certloom' ), '' !== $locale_effective ? $locale_effective : __( 'Site default', 'certloom' ), $locale_meta );
		$template_meta = sprintf( __( 'Published revision #%1$d · ID %2$d', 'certloom' ), absint( $revision['revision_number'] ?? 0 ), absint( $revision['id'] ?? 0 ) );
		$resolution_scope = (string) ( $review['template_resolution'] ?? 'explicit' );
		$variant = isset( $review['template_locale_variant'] ) && is_array( $review['template_locale_variant'] ) ? $review['template_locale_variant'] : array();
		if ( ! empty( $variant['matched_locale'] ) ) {
			$template_meta .= ' · ' . sprintf( __( 'language variant %s', 'certloom' ), (string) $variant['matched_locale'] );
		} elseif ( 'explicit' === $resolution_scope ) {
			$template_meta .= ' · ' . __( 'explicit reviewed override', 'certloom' );
		} else {
			$template_meta .= ' · ' . sprintf( __( 'Program design via %s', 'certloom' ), $resolution_scope );
		}
		$this->review_fact( __( 'Template', 'certloom' ), (string) ( $template['name'] ?? '' ), $template_meta );
		$this->review_fact( __( 'Effective date', 'certloom' ), (string) $review['valid_from'], null === $review['expires_at'] ? __( 'Permanent / no expiry', 'certloom' ) : sprintf( __( 'Expires %s', 'certloom' ), (string) $review['expires_at'] ) );
		$number = is_array( $review['numbering'] ?? null ) ? (string) ( $review['numbering']['certificate_number'] ?? '' ) : (string) ( $request['display_number'] ?? '' );
		$this->review_fact( __( 'Certificate number', 'certloom' ), '' !== $number ? $number : __( 'Allocated on Issue', 'certloom' ), \Ecapla_CertVerify_Numbering::is_enabled() ? __( 'Preview candidate; not reserved', 'certloom' ) : __( 'Manual number', 'certloom' ) );
		$this->review_fact( __( 'Review SHA-256', 'certloom' ), (string) $review['review_fingerprint'], __( 'Any changed input or related record invalidates this confirmation.', 'certloom' ), true );
		echo '</div>';
		echo '<div class="certloom-template-preview-proof certloom-manual-proof"><div><span>' . esc_html__( 'Layout schema', 'certloom' ) . '</span><strong>' . esc_html( (string) ( $revision['layout_schema'] ?? '' ) ) . ' v' . esc_html( (string) absint( $revision['layout_schema_version'] ?? 0 ) ) . '</strong></div><div><span>' . esc_html__( 'Published layout SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) ( $revision['layout_sha256'] ?? '' ) ) . '</code></div></div>';
		$preview=(new ManualIssuancePreviewService())->build($review); if(is_wp_error($preview)){echo '<div class="notice notice-error inline"><p>'.esc_html($preview->get_error_message()).'</p></div>';}else{ try{$presented=(new RenderPlanHtmlPreview())->present($preview['render_plan']); echo '<div class="certloom-template-preview-card certloom-manual-preview"><div class="certloom-template-preview-head"><div><h3>'.esc_html__( 'Credential Preview', 'certloom' ).'</h3><p>'.esc_html__( 'Built from the exact published layout plus this review’s immutable merge candidate through the canonical Render Plan path.', 'certloom' ).'</p></div><span class="certloom-template-preview-authority">'.esc_html__( 'Browser Preview only', 'certloom' ).'</span></div><div class="certloom-template-preview-proof"><div><span>'.esc_html__( 'Render Plan SHA-256', 'certloom' ).'</span><code>'.esc_html((string)$preview['render_plan_sha256']).'</code></div></div>'.$presented['html'].'<p class="description">'.esc_html__( 'Automatic certificate/public IDs and the final self-hosted verification URL are allocated only during Issue. Browser font rasterization remains illustrative until PDF-04 renderer/font authority is accepted.', 'certloom' ).'</p></div>'; }catch(\Throwable $e){echo '<div class="notice notice-error inline"><p>'.esc_html__( 'The canonical review plan could not be presented safely in the browser.', 'certloom' ).'</p></div>';}}
		$custom=(array)($request['custom_merge_fields']??array()); if($custom){echo '<h3>'.esc_html__( 'Credential-specific custom merge values', 'certloom' ).'</h3><dl class="certloom-manual-merge-list">';foreach($custom as $k=>$v){echo '<div><dt><code>custom:'.esc_html((string)$k).'</code></dt><dd>'.esc_html((string)$v).'</dd></div>';}echo '</dl>';}
		echo '<div class="notice notice-info inline"><p><strong>'.esc_html__( 'What Issue will do:', 'certloom' ).'</strong> '.esc_html__( 'Re-run this review, verify the SHA-256, allocate the human certificate number, create the Recipient if needed, create one Credential Series and immutable Version, freeze merge/layout snapshots, transition it to issued, set the current Version, and append the issuance event under one checked transaction.', 'certloom' ).'</p></div>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="certloom-manual-confirm"><input type="hidden" name="action" value="certloom_manual_issuance_issue"><input type="hidden" name="review_fingerprint" value="'.esc_attr((string)$review['review_fingerprint']).'">'; wp_nonce_field('certloom_manual_issuance_issue_'.(string)$review['review_fingerprint']); $this->hidden_request($request); echo '<a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">'.esc_html__( 'Back to compose', 'certloom' ).'</a><button class="button button-primary button-hero" type="submit">'.esc_html__( 'Issue credential', 'certloom' ).'</button></form></section>';
	}

	/** @return void */
	private function render_receipt( $version_id ) {
		$receipt=(new ManualIssuanceService())->receipt($version_id); if(is_wp_error($receipt)){echo '<div class="notice notice-error inline"><p>'.esc_html($receipt->get_error_message()).'</p></div>';return;}
		$public_ready=(new PublicVerificationGate())->is_ready();
		echo '<section class="certloom-manual-card certloom-manual-receipt"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">'.esc_html__( 'Step 4', 'certloom' ).'</span><h2>'.esc_html__( 'Issuance receipt', 'certloom' ).'</h2></div><span class="certloom-template-state certloom-template-state-published">'.esc_html__( 'Issued', 'certloom' ).'</span></div><div class="certloom-manual-review-grid">';
		$this->review_fact(__( 'Certificate number', 'certloom' ),(string)$receipt['display_number'],__( 'Human-facing credential number', 'certloom' )); $this->review_fact(__( 'Public credential ID', 'certloom' ),(string)$receipt['public_id'],__( 'Cryptographically generated normalized identity', 'certloom' ),true); $this->review_fact(__( 'Credential Series', 'certloom' ),'#'.absint($receipt['series_id']),__( 'Stable credential lineage', 'certloom' )); $this->review_fact(__( 'Credential Version', 'certloom' ),'#'.absint($receipt['version_id']),__( 'Immutable issuance snapshot', 'certloom' )); $this->review_fact(__( 'Issued', 'certloom' ),(string)($receipt['issued_at']??'—'),null===($receipt['expires_at']??null)?__( 'No expiry', 'certloom' ):sprintf(__( 'Expires %s', 'certloom' ),(string)$receipt['expires_at']));
		echo '</div><div class="certloom-manual-receipt-url"><span>'.esc_html__( 'Self-hosted verification URL', 'certloom' ).'</span>'; if($public_ready){echo '<a href="'.esc_url((string)$receipt['verification_url']).'" target="_blank" rel="noopener">'.esc_html((string)$receipt['verification_url']).'</a>';}else{echo '<code>'.esc_html((string)$receipt['verification_url']).'</code><small>'.esc_html__( 'Stored in the immutable snapshot, but the normalized public verification gate remains closed until final database cutover certification.', 'certloom' ).'</small>';} echo '</div><div class="notice notice-info inline"><p><strong>'.esc_html__( 'Artifact status:', 'certloom' ).'</strong> '.esc_html__( 'Credential truth is issued, but PDF/PNG/JPEG exports are intentionally unavailable because renderer release authority is still gated. No fake export receipt was created.', 'certloom' ).'</p></div><div class="certloom-manual-actions"><a class="button button-primary" href="'.esc_url(add_query_arg(array('page'=>'certloom-email-delivery','version_id'=>absint($receipt['version_id'])),admin_url('admin.php'))).'">'.esc_html__( 'Email this credential', 'certloom' ).'</a><a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">'.esc_html__( 'Issue another credential', 'certloom' ).'</a></div></section>';
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
				$this->request_error = new \WP_Error( 'certloom_manual_issue_custom_merge_duplicate', __( 'Each credential-specific custom merge field must use a unique machine key.', 'certloom' ) );
				continue;
			}
			$custom[ $key ] = isset( $vals[ $i ] ) && ! is_array( $vals[ $i ] ) ? (string) $vals[ $i ] : '';
		}
		if ( isset( $post['custom_merge_fields'] ) && is_array( $post['custom_merge_fields'] ) ) {
			foreach ( $post['custom_merge_fields'] as $raw_key => $value ) {
				$key = sanitize_key( (string) $raw_key );
				if ( '' === $key || is_array( $value ) ) { continue; }
				if ( array_key_exists( $key, $custom ) ) {
					$this->request_error = new \WP_Error( 'certloom_manual_issue_custom_merge_duplicate', __( 'Each credential-specific custom merge field must use a unique machine key.', 'certloom' ) );
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
	private function hidden_request( array $r ) { foreach(array('recipient_mode','recipient_id','program_id','template_id','valid_from','expiry_mode','expires_at','grade','display_number') as $k){echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)($r[$k]??'')).'">';} foreach((array)($r['new_recipient']??array()) as $k=>$v){if(!is_array($v)){echo '<input type="hidden" name="new_recipient['.esc_attr((string)$k).']" value="'.esc_attr((string)$v).'">';}} foreach((array)($r['custom_merge_fields']??array()) as $k=>$v){echo '<input type="hidden" name="custom_merge_fields['.esc_attr((string)$k).']" value="'.esc_attr((string)$v).'">';} }
	/** @return void */ private function review_fact($label,$value,$meta='',$code=false){echo '<div class="certloom-manual-review-fact"><span>'.esc_html($label).'</span>'.($code?'<code>'.esc_html($value).'</code>':'<strong>'.esc_html($value).'</strong>').(''!==(string)$meta?'<small>'.esc_html((string)$meta).'</small>':'').'</div>';}
	/** @return void */ private function text_input($name,$label,$value,$max,$type='text'){echo '<label><span>'.esc_html($label).'</span><input type="'.esc_attr($type).'" name="'.esc_attr($name).'" maxlength="'.esc_attr((string)$max).'" value="'.esc_attr($value).'"></label>';}
	/** @return void */ private function radio($name,$value,$label,$current){echo '<label><input type="radio" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.checked((string)$current,(string)$value,false).'> <span>'.esc_html($label).'</span></label>';}
	/** @return void */ private function steps($current){$steps=array('compose'=>__( 'Compose', 'certloom' ),'review'=>__( 'Review', 'certloom' ),'issue'=>__( 'Issue', 'certloom' ),'receipt'=>__( 'Receipt', 'certloom' ));echo '<ol class="certloom-manual-steps" aria-label="'.esc_attr__( 'Manual Issuance progress', 'certloom' ).'">';$seen=true;foreach($steps as $key=>$label){$class=$key===$current?'is-current':($seen?'is-complete':'');echo '<li class="'.esc_attr($class).'" '.($key===$current?'aria-current="step"':'').'><span>'.esc_html($label).'</span></li>';if($key===$current)$seen=false;}echo '</ol>';}
	/** @return void */ private function require_capability(){if(!current_user_can(\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS)){wp_die(esc_html__( 'You do not have permission to issue Certloom credentials.', 'certloom' ));}}
	/** @return void */ private function store_notice($type,$message){set_transient(self::NOTICE_PREFIX.get_current_user_id(),array('type'=>(string)$type,'message'=>(string)$message),120);}
	/** @return void */ private function notice(){$key=self::NOTICE_PREFIX.get_current_user_id();$n=get_transient($key);if(!is_array($n)||empty($n['message']))return;delete_transient($key);$class='success'===($n['type']??'')?'notice-success':'notice-error';echo '<div class="notice '.esc_attr($class).' is-dismissible"><p>'.esc_html((string)$n['message']).'</p></div>';}
}
