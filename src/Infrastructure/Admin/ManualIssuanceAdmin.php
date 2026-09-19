<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Eight-step Issue Credential REST application shell.
 *
 * The previous server-rendered Compose/Review mega-form was removed. This class
 * now owns only page registration, assets and accessible application markup;
 * IssueCredentialRest + ManualIssuanceService own all data and issuance work.
 */
final class ManualIssuanceAdmin {
	const PAGE = 'certloom-issuance';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page( null, __( 'Issue credential', 'certloom' ), __( 'Issue credential', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, self::PAGE, array( $this, 'render' ) );
	}

	/** @return void */
	public function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) { return; }
		$this->require_capability();
		wp_enqueue_media();
		wp_enqueue_style( 'certloom-issue-credential', CERTLOOM_URL . 'admin/assets/issue-credential.css', array( 'certloom-admin-foundation' ), CERTLOOM_VERSION );
		wp_enqueue_script( 'certloom-issue-credential', CERTLOOM_URL . 'admin/assets/issue-credential.js', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION, true );
		wp_localize_script( 'certloom-issue-credential', 'certloomIssueCredential', array(
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'bootstrapUrl' => rest_url( 'certloom/v1/admin/issue/bootstrap' ),
			'programContextUrl' => rest_url( 'certloom/v1/admin/issue/program-context' ),
			'quickProgramUrl' => rest_url( 'certloom/v1/admin/issue/quick-program' ),
			'quickBatchUrl' => rest_url( 'certloom/v1/admin/issue/quick-batch' ),
			'reviewUrl' => rest_url( 'certloom/v1/admin/issue/review' ),
			'issueUrl' => rest_url( 'certloom/v1/admin/issue/issue' ),
			'contextUrl' => rest_url( 'certloom/v1/admin/context' ),
			'previewUrl' => rest_url( 'certloom/v1/admin/issuance-preview' ),
			'designsUrl' => admin_url( 'admin.php?page=certloom-templates' ),
			'emailDesignsUrl' => admin_url( 'admin.php?page=certloom-email-designs' ),
			'credentialsUrl' => admin_url( 'admin.php?page=certloom-domain-credentials' ),
			'deepLink' => array(
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link context.
				'recipient_id' => isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link context.
				'program_id' => isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link context.
				'batch_id' => isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0,
			),
			'i18n' => array(
				'loading' => __( 'Loading…', 'certloom' ),
				'searching' => __( 'Searching…', 'certloom' ),
				'noMatches' => __( 'No matching choices.', 'certloom' ),
				'loadFailed' => __( 'Certloom could not load this step. Try again.', 'certloom' ),
				'previewWaiting' => __( 'Complete Recipient and Credential details to load the live preview.', 'certloom' ),
				'previewUpdating' => __( 'Updating preview…', 'certloom' ),
				'reviewing' => __( 'Building server review…', 'certloom' ),
				'issuing' => __( 'Issuing credential…', 'certloom' ),
				'programTitleHint' => __( 'Prefilled from the selected Program. Edit it freely for this credential only.', 'certloom' ),
				'customTitleHint' => __( 'Custom for this credential. The Program title is unchanged.', 'certloom' ),
				'programChangedBatch' => __( 'Batch cleared because the Program changed. Loading the current Batches…', 'certloom' ),
				'chooseProgramFirst' => __( 'Choose a Program before selecting a Batch.', 'certloom' ),
				'imageType' => __( 'Choose a PNG or JPEG image.', 'certloom' ),
				'repeatingLimit' => __( 'Repeating credential data is limited to 50 rows.', 'certloom' ),
				'reviewChanged' => __( 'Something changed after Review. Review again before issuing.', 'certloom' ),
			),
		) );
	}

	/** @return void */
	public function render() {
		$this->require_capability();
		$profile = UsageProfilePresentation::active_profile();
		$terms = UsageProfilePresentation::terms( $profile );
		$program = (string) ( $terms['program']['singular'] ?? __( 'Program', 'certloom' ) );
		$batch = (string) ( $terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) );
		$issuer = (string) ( $terms['issuer']['singular'] ?? __( 'Issuer', 'certloom' ) );
		$signer = (string) ( $terms['signer']['singular'] ?? __( 'Signer', 'certloom' ) );

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-issue-app-shell">';
		echo '<section class="certloom-page-hero certloom-issue-app-hero"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential operations', 'certloom' ) . '</span><h1>' . esc_html__( 'Issue credential', 'certloom' ) . '</h1><p>' . esc_html__( 'A focused eight-step workflow. Large selectors and previews load only when needed, while final Review and Issue are revalidated on the server.', 'certloom' ) . '</p></div><div class="certloom-issue-app-hero-meta"><span class="certloom-state-pill">' . esc_html( UsageProfilePresentation::profile_label() ) . '</span></div></section>';
		( new AdminProductNavigation() )->render_current_subnav();
		$this->render_technical_status();

		echo '<div class="certloom-issue-app" data-certloom-issue-app data-program-term="' . esc_attr( $program ) . '" data-batch-term="' . esc_attr( $batch ) . '" data-issuer-term="' . esc_attr( $issuer ) . '" data-signer-term="' . esc_attr( $signer ) . '">';
		echo '<div class="certloom-issue-loading" data-issue-loading><span class="spinner is-active" aria-hidden="true"></span><strong>' . esc_html__( 'Preparing issuance workspace…', 'certloom' ) . '</strong></div>';
		echo '<div class="certloom-issue-app-body" data-issue-body hidden>';
		$this->render_steps( $program, $batch, $issuer, $signer );
		$this->render_summary( $program, $batch );
		echo '</div>';
		echo '<div class="certloom-issue-global-message" data-issue-message role="status" aria-live="polite" hidden></div>';
		echo '<noscript><div class="notice notice-error inline"><p>' . esc_html__( 'Issue Credential requires JavaScript because Program/Batch lookup, live certificate preview and server Review are loaded through the authenticated WordPress REST API.', 'certloom' ) . '</p></div></noscript>';
		echo '</div></div>';
	}

	/** Keeps release constraints available without interrupting the issuance task. */
	private function render_technical_status() {
		echo '<details class="certloom-technical-details certloom-manual-issuance-technical-status certloom-issue-technical-status"><summary>' . esc_html__( 'Technical status', 'certloom' ) . '</summary><div class="certloom-issue-technical-body">';
		echo '<p>' . esc_html__( 'Online verification is available whenever the credential store is healthy. Downloadable PDF/PNG/JPEG certificate files are not available yet.', 'certloom' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'System detail:', 'certloom' ) . '</strong> ' . esc_html__( 'Manual Issuance and normalized public verification are operational when the credential store is healthy. PDF/PNG/JPEG exports remain unavailable until the certificate renderer is approved.', 'certloom' ) . '</p>';
		echo '</div></details>';
	}

	/** @return void */
	private function render_steps( $program, $batch, $issuer, $signer ) {
		$steps = array(
			array( 'recipient', __( 'Recipient', 'certloom' ), __( 'Who receives it', 'certloom' ), __( 'Recipient', 'certloom' ) ),
			array( 'context', __( 'Context', 'certloom' ), sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( '%1$s & %2$s', 'certloom' ), $program, $batch ), sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( '%1$s & %2$s', 'certloom' ), $program, $batch ) ),
			array( 'credential', __( 'Credential', 'certloom' ), __( 'Title & validity', 'certloom' ), __( 'Credential', 'certloom' ) ),
			array( 'data', __( 'Data', 'certloom' ), __( 'Fields & results', 'certloom' ), __( 'Data & results', 'certloom' ) ),
			array( 'certificate', __( 'Certificate', 'certloom' ), __( 'Appearance', 'certloom' ), __( 'Certificate', 'certloom' ) ),
			array( 'authority', __( 'Authority', 'certloom' ), sprintf( /* translators: 1: Issuer terminology, 2: Signer terminology. */ __( '%1$s & %2$s', 'certloom' ), $issuer, $signer ), sprintf( /* translators: 1: Issuer terminology, 2: Signer terminology. */ __( '%1$s & %2$s', 'certloom' ), $issuer, $signer ) ),
			array( 'email', __( 'Email', 'certloom' ), __( 'Delivery', 'certloom' ), __( 'Email', 'certloom' ) ),
			array( 'review', __( 'Review', 'certloom' ), __( 'Final confirmation', 'certloom' ), __( 'Review & issue', 'certloom' ) ),
		);
		echo '<main class="certloom-issue-workspace">';
		echo '<nav class="certloom-issue-stepper" aria-label="' . esc_attr__( 'Issue credential steps', 'certloom' ) . '"><ol>';
		foreach ( $steps as $index => $step ) {
			echo '<li><button type="button" data-issue-step-button="' . esc_attr( (string) $index ) . '" aria-label="' . esc_attr( $step[3] ) . '"' . ( 0 === $index ? ' class="is-current" aria-current="step"' : '' ) . '><span class="certloom-issue-step-number">' . esc_html( (string) ( $index + 1 ) ) . '</span><span><strong>' . esc_html( $step[1] ) . '</strong><small>' . esc_html( $step[2] ) . '</small></span></button></li>';
		}
		echo '</ol></nav>';

		echo '<form class="certloom-issue-form" data-issue-form novalidate>';
		$this->render_recipient_step();
		$this->render_context_step( $program, $batch );
		$this->render_credential_step( $program );
		$this->render_data_step();
		$this->render_certificate_step();
		$this->render_authority_step( $issuer, $signer );
		$this->render_email_step();
		$this->render_review_step();
		$this->render_footer();
		echo '</form></main>';
	}

	/** @return void */
	private function render_recipient_step() {
		echo '<section class="certloom-issue-step is-current" data-issue-step="0" data-step-key="recipient"><header><span class="certloom-eyebrow">' . esc_html__( 'Step 1 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Who receives this credential?', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose an existing Recipient or create a reusable Recipient profile without leaving issuance.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-segmented" role="group" aria-label="' . esc_attr__( 'Recipient type', 'certloom' ) . '"><button type="button" class="is-active" data-recipient-mode="existing">' . esc_html__( 'Existing recipient', 'certloom' ) . '</button><button type="button" data-recipient-mode="new">' . esc_html__( 'New recipient', 'certloom' ) . '</button></div>';
		$this->render_async_picker( 'recipient', __( 'Recipient', 'certloom' ), __( 'Search by name, email or external ID…', 'certloom' ), true );
		echo '<div class="certloom-issue-new-recipient" data-new-recipient hidden><div class="certloom-issue-grid certloom-issue-grid-2">';
		$this->input( 'new_recipient.display_name', __( 'Display name', 'certloom' ), 'text', true );
		$this->input( 'new_recipient.email', __( 'Email', 'certloom' ), 'email', false );
		$this->input( 'new_recipient.first_name', __( 'First name', 'certloom' ) );
		$this->input( 'new_recipient.middle_name', __( 'Middle name', 'certloom' ) );
		$this->input( 'new_recipient.last_name', __( 'Last name', 'certloom' ) );
		$this->input( 'new_recipient.external_id', __( 'External learner / customer ID', 'certloom' ) );
		echo '</div>';
		$this->render_async_picker( 'wp_user', __( 'WordPress account', 'certloom' ), __( 'Search WordPress users by name, email or login…', 'certloom' ), false );
		echo '<p class="description">' . esc_html__( 'Recipient profiles are reusable across future credentials. Create the Recipient profile once; credential-specific facts remain on each issued credential.', 'certloom' ) . '</p></div></section>';
	}

	/** @return void */
	private function render_context_step( $program, $batch ) {
		echo '<section class="certloom-issue-step" data-issue-step="1" data-step-key="context" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 2 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Choose credential context', 'certloom' ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( 'Use a reusable %1$s and optional %2$s when they apply, or issue an independent credential.', 'certloom' ), $program, $batch ) ) . '</p></header>';
		echo '<div class="certloom-segmented" role="group" aria-label="' . esc_attr__( 'Credential basis', 'certloom' ) . '"><button type="button" class="is-active" data-credential-basis="program">' . esc_html( $program ) . '</button><button type="button" data-credential-basis="independent">' . esc_html__( 'Independent credential', 'certloom' ) . '</button></div>';
		echo '<div data-program-context>'; 
		$this->render_async_picker( 'program', $program, sprintf( /* translators: %s: Program terminology. */ __( 'Search %s…', 'certloom' ), $program ), true, __( 'Add', 'certloom' ) . ' ' . $program );
		$this->render_async_picker( 'batch', $batch, sprintf( /* translators: %s: Batch terminology. */ __( 'Search %s…', 'certloom' ), $batch ), false, __( 'Add', 'certloom' ) . ' ' . $batch );
		echo '<div class="certloom-issue-context-note" data-program-context-note hidden></div></div>';
		echo '<div class="certloom-issue-independent-note" data-independent-note hidden><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>' . esc_html__( 'No Program or Batch relationship will be stored. You can still choose certificate appearance, authority, signer, email and one-off credential data.', 'certloom' ) . '</p></div>';
		$this->render_quick_dialogs( $program, $batch );
		echo '</section>';
	}

	/** @return void */
	private function render_credential_step( $program ) {
		echo '<section class="certloom-issue-step" data-issue-step="2" data-step-key="credential" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 3 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Credential title and validity', 'certloom' ) . '</h2><p>' . esc_html__( 'The title stays editable even when a Program supplies the default.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-grid certloom-issue-grid-2"><label class="certloom-issue-field certloom-issue-field-span"><span>' . esc_html__( 'Credential title', 'certloom' ) . ' <em>*</em></span><input type="text" data-field="credential_title" maxlength="255" required aria-required="true"><small data-title-hint>' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Choose a %s to prefill its title, or type a custom title.', 'certloom' ), $program ) ) . '</small></label>';
		$this->input( 'valid_from', __( 'Effective date', 'certloom' ), 'date', true );
		echo '<label class="certloom-issue-field"><span>' . esc_html__( 'Validity', 'certloom' ) . '</span><select data-field="expiry_mode"><option value="program">' . esc_html__( 'Use Program default', 'certloom' ) . '</option><option value="permanent">' . esc_html__( 'No expiry', 'certloom' ) . '</option><option value="date">' . esc_html__( 'Specific expiry date', 'certloom' ) . '</option></select><small data-validity-summary></small></label>';
		echo '<label class="certloom-issue-field" data-expiry-date-field hidden><span>' . esc_html__( 'Expiry date', 'certloom' ) . '</span><input type="date" data-field="expires_at"></label>';
		echo '<label class="certloom-issue-field" data-display-number-field hidden><span>' . esc_html__( 'Certificate number', 'certloom' ) . ' <em>*</em></span><input type="text" data-field="display_number" maxlength="191" required aria-required="true"><small>' . esc_html__( 'Your numbering policy requires a manual number.', 'certloom' ) . '</small></label></div></section>';
	}

	/** @return void */
	private function render_data_step() {
		echo '<section class="certloom-issue-step" data-issue-step="3" data-step-key="data" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 4 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Credential data and results', 'certloom' ) . '</h2><p>' . esc_html__( 'Only fields relevant to the selected context and certificate design appear here.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-data-section" data-scoped-fields><div class="certloom-issue-empty"><p>' . esc_html__( 'Choose a Program or continue with an independent credential to configure credential data.', 'certloom' ) . '</p></div></div>';
		echo '<div class="certloom-issue-data-section" data-design-required-fields hidden><div class="certloom-issue-subhead"><div><h3>' . esc_html__( 'Required by certificate design', 'certloom' ) . '</h3><p>' . esc_html__( 'Missing values detected from the live certificate design appear here.', 'certloom' ) . '</p></div></div><div data-design-required-list></div></div>';
		echo '<div class="certloom-issue-data-section" data-repeating-section hidden><div class="certloom-issue-subhead"><div><h3>' . esc_html__( 'Subjects & results', 'certloom' ) . '</h3><p>' . esc_html__( 'Add transcript-style rows only when this credential needs them.', 'certloom' ) . '</p></div></div><div data-repeating-rowsets></div></div>';
		echo '<details class="certloom-issue-disclosure"><summary>' . esc_html__( 'Additional one-off credential data', 'certloom' ) . '<span>' . esc_html__( 'Optional', 'certloom' ) . '</span></summary><div class="certloom-issue-oneoff" data-oneoff-fields><div data-oneoff-list></div><button type="button" class="button" data-add-oneoff>' . esc_html__( 'Add one-off field', 'certloom' ) . '</button><p class="description">' . esc_html__( 'These values are frozen only on this credential and do not change the Program, Batch or Recipient profile.', 'certloom' ) . '</p></div></details>';
		echo '</section>';
	}

	/** @return void */
	private function render_certificate_step() {
		echo '<section class="certloom-issue-step" data-issue-step="4" data-step-key="certificate" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 5 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Certificate appearance', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose what the recipient receives and inspect the live unsaved preview before Review.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-presentation-options"><button type="button" data-presentation="details_only" class="is-active"><span class="dashicons dashicons-id-alt" aria-hidden="true"></span><strong>' . esc_html__( 'Credential details only', 'certloom' ) . '</strong><small>' . esc_html__( 'Public verification without a certificate artwork file.', 'certloom' ) . '</small></button><button type="button" data-presentation="image"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><strong>' . esc_html__( 'Uploaded certificate image', 'certloom' ) . '</strong><small>' . esc_html__( 'Use one PNG or JPEG image for this credential.', 'certloom' ) . '</small></button><button type="button" data-presentation="design"><span class="dashicons dashicons-art" aria-hidden="true"></span><strong>' . esc_html__( 'Certloom design', 'certloom' ) . '</strong><small>' . esc_html__( 'Resolve inheritance or assign a published certificate design.', 'certloom' ) . '</small></button></div>';
		echo '<div class="certloom-issue-image-controls" data-image-controls hidden><button type="button" class="button" data-choose-image>' . esc_html__( 'Choose PNG/JPEG', 'certloom' ) . '</button><button type="button" class="button-link-delete" data-clear-image hidden>' . esc_html__( 'Remove image', 'certloom' ) . '</button><div data-image-selection></div></div>';
		echo '<div class="certloom-issue-design-controls" data-design-controls hidden><div class="certloom-segmented"><button type="button" class="is-active" data-design-source="inherited">' . esc_html__( 'Use inherited design', 'certloom' ) . '</button><button type="button" data-design-source="assigned">' . esc_html__( 'Assign for this credential', 'certloom' ) . '</button></div><div data-assigned-design hidden>';
		$this->render_async_picker( 'template', __( 'Certificate design', 'certloom' ), __( 'Search published certificate designs…', 'certloom' ), true );
		echo '</div><p class="description" data-design-source-summary></p></div>';
		echo '<div class="certloom-issue-preview-card"><div class="certloom-issue-preview-head"><div><h3>' . esc_html__( 'Live certificate preview', 'certloom' ) . '</h3><p data-preview-status>' . esc_html__( 'Complete the earlier steps to preview this credential.', 'certloom' ) . '</p></div><a href="' . esc_url( admin_url( 'admin.php?page=certloom-templates' ) ) . '" class="button button-small">' . esc_html__( 'Manage designs', 'certloom' ) . '</a></div><div class="certloom-issue-preview-stage" data-preview-stage><div class="certloom-issue-preview-empty"><span class="dashicons dashicons-awards" aria-hidden="true"></span><p>' . esc_html__( 'Live preview will appear here.', 'certloom' ) . '</p></div></div></div></section>';
	}

	/** @return void */
	private function render_authority_step( $issuer, $signer ) {
		echo '<section class="certloom-issue-step" data-issue-step="5" data-step-key="authority" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 6 of 8', 'certloom' ) . '</span><h2>' . esc_html( sprintf( /* translators: 1: Issuer terminology, 2: Signer terminology. */ __( '%1$s and %2$s', 'certloom' ), $issuer, $signer ) ) . '</h2><p>' . esc_html__( 'Program-owned authority is inherited automatically. Add or override a signer only when this credential needs it.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-authority-card" data-inherited-issuer hidden><span class="dashicons dashicons-building" aria-hidden="true"></span><div><small>' . esc_html__( 'Inherited authority', 'certloom' ) . '</small><strong data-inherited-issuer-name></strong><p>' . esc_html__( 'This comes from the selected Program and cannot conflict with it during issuance.', 'certloom' ) . '</p></div></div>';
		echo '<div data-independent-issuer hidden>';
		$this->render_async_picker( 'issuer', $issuer, sprintf( /* translators: %s: Issuer terminology. */ __( 'Search %s…', 'certloom' ), $issuer ), false );
		echo '</div>';
		$this->render_async_picker( 'signer', $signer, sprintf( /* translators: %s: Signer terminology. */ __( 'Search %s…', 'certloom' ), $signer ), false );
		echo '</section>';
	}

	/** @return void */
	private function render_email_step() {
		echo '<section class="certloom-issue-step" data-issue-step="6" data-step-key="email" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 7 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Email delivery', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose what should happen after the credential commits. Nothing is sent during issuance.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-email-options"><button type="button" class="is-active" data-email-mode="review"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><strong>' . esc_html__( 'Review email next', 'certloom' ) . '</strong><small>' . esc_html__( 'Issue first, then open Email Delivery to preview and confirm sending.', 'certloom' ) . '</small></button><button type="button" data-email-mode="later"><span class="dashicons dashicons-clock" aria-hidden="true"></span><strong>' . esc_html__( 'Do not email now', 'certloom' ) . '</strong><small>' . esc_html__( 'You can email this credential later from its credential record.', 'certloom' ) . '</small></button></div>';
		echo '<label class="certloom-issue-field" data-email-design-field><span>' . esc_html__( 'Email design', 'certloom' ) . '</span><select data-email-template><option value="automatic">' . esc_html__( 'Automatic — use email hierarchy', 'certloom' ) . '</option></select><small>' . esc_html__( 'An explicit selection is a one-send override; it does not change Design Rules.', 'certloom' ) . '</small></label><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=certloom-email-designs' ) ) . '">' . esc_html__( 'Manage email designs', 'certloom' ) . '</a></section>';
	}

	/** @return void */
	private function render_review_step() {
		echo '<section class="certloom-issue-step" data-issue-step="7" data-step-key="review" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 8 of 8', 'certloom' ) . '</span><h2>' . esc_html__( 'Review and issue', 'certloom' ) . '</h2><p>' . esc_html__( 'Certloom builds this review on the server. Final Issue revalidates the same fingerprint before committing.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-review-state" data-review-state><div class="certloom-issue-empty"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><h3>' . esc_html__( 'Server review not built yet', 'certloom' ) . '</h3><p>' . esc_html__( 'Complete the earlier steps, then build the final Review.', 'certloom' ) . '</p><button type="button" class="button button-primary" data-build-review>' . esc_html__( 'Build final review', 'certloom' ) . '</button></div></div>';
		echo '<div class="certloom-issue-review-content" data-review-content hidden></div>';
		echo '<details class="certloom-issue-technical" data-review-technical hidden><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><div data-review-technical-body></div></details>';
		echo '<div class="certloom-issue-review-actions" data-review-actions hidden><button type="button" class="button button-primary button-hero" data-issue-confirm>' . esc_html__( 'Issue credential', 'certloom' ) . '</button><p>' . esc_html__( 'This creates the immutable credential Version. It does not send email automatically.', 'certloom' ) . '</p></div>';
		echo '<div class="certloom-issue-receipt" data-issue-receipt hidden></div></section>';
	}

	/** @return void */
	private function render_footer() {
		echo '<footer class="certloom-issue-footer"><button type="button" class="button" data-step-prev disabled>' . esc_html__( 'Back', 'certloom' ) . '</button><div class="certloom-issue-footer-status" data-step-status>' . esc_html__( 'Step 1 of 8', 'certloom' ) . '</div><button type="button" class="button button-primary" data-step-next>' . esc_html__( 'Continue', 'certloom' ) . '</button></footer>';
	}

	/** @return void */
	private function render_summary( $program, $batch ) {
		echo '<aside class="certloom-issue-summary" data-issue-summary><div class="certloom-issue-summary-head"><span class="certloom-eyebrow">' . esc_html__( 'Live summary', 'certloom' ) . '</span><h2>' . esc_html__( 'Credential', 'certloom' ) . '</h2></div><dl><div><dt>' . esc_html__( 'Recipient', 'certloom' ) . '</dt><dd data-summary-recipient>—</dd></div><div><dt>' . esc_html( $program ) . '</dt><dd data-summary-program>—</dd></div><div><dt>' . esc_html( $batch ) . '</dt><dd data-summary-batch>—</dd></div><div><dt>' . esc_html__( 'Title', 'certloom' ) . '</dt><dd data-summary-title>—</dd></div><div><dt>' . esc_html__( 'Certificate', 'certloom' ) . '</dt><dd data-summary-certificate>' . esc_html__( 'Details only', 'certloom' ) . '</dd></div><div><dt>' . esc_html__( 'Email', 'certloom' ) . '</dt><dd data-summary-email>' . esc_html__( 'Review next', 'certloom' ) . '</dd></div></dl><div class="certloom-issue-summary-progress"><span data-summary-progress-bar></span></div><p data-summary-progress-text>' . esc_html__( 'Step 1 of 8', 'certloom' ) . '</p></aside>';
	}

	/** @return void */
	private function render_async_picker( $kind, $label, $placeholder, $required = false, $quick_label = '' ) {
		echo '<div class="certloom-async-picker" data-picker="' . esc_attr( $kind ) . '"><label><span>' . esc_html( $label ) . ( $required ? ' <em>*</em>' : '' ) . '</span><div class="certloom-async-picker-control"><input type="search" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '" data-picker-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" data-picker-value><button type="button" class="button certloom-picker-clear" data-picker-clear hidden aria-label="' . esc_attr( sprintf( /* translators: %s: picker label. */ __( 'Clear %s', 'certloom' ), $label ) ) . '">×</button></div></label><div class="certloom-picker-selected" data-picker-selected hidden></div><div class="certloom-picker-results" data-picker-results role="listbox" hidden></div><div class="certloom-picker-status" data-picker-status aria-live="polite"></div>';
		if ( '' !== $quick_label ) { echo '<button type="button" class="button button-small" data-quick-open="' . esc_attr( $kind ) . '">' . esc_html( $quick_label ) . '</button>'; }
		echo '</div>';
	}

	/** @return void */
	private function render_quick_dialogs( $program, $batch ) {
		echo '<dialog class="certloom-issue-dialog" data-quick-dialog="program"><form method="dialog" data-quick-form="program"><header><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Add %s', 'certloom' ), $program ) ) . '</h2></div><button type="button" data-quick-close class="button-link" aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></header><label><span>' . esc_html__( 'Title', 'certloom' ) . '</span><input type="text" name="title" required maxlength="255"></label><label><span>' . esc_html__( 'Code', 'certloom' ) . '</span><input type="text" name="code" maxlength="100"></label><p class="description">' . esc_html__( 'Designs inherit automatically. After creation, use its Designs card or Design Rules if this Program needs an override.', 'certloom' ) . '</p><div class="certloom-dialog-actions"><button type="button" data-quick-close class="button">' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add and use', 'certloom' ) . '</button></div></form></dialog>';
		echo '<dialog class="certloom-issue-dialog" data-quick-dialog="batch"><form method="dialog" data-quick-form="batch"><header><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html( sprintf( /* translators: %s: Batch terminology. */ __( 'Add %s', 'certloom' ), $batch ) ) . '</h2></div><button type="button" data-quick-close class="button-link" aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></header><label><span>' . esc_html__( 'Name', 'certloom' ) . '</span><input type="text" name="name" required maxlength="255"></label><label><span>' . esc_html__( 'Code', 'certloom' ) . '</span><input type="text" name="code" maxlength="100"></label><div class="certloom-dialog-actions"><button type="button" data-quick-close class="button">' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add and use', 'certloom' ) . '</button></div></form></dialog>';
	}

	/** @return void */
	private function input( $field, $label, $type = 'text', $required = false ) {
		echo '<label class="certloom-issue-field"><span>' . esc_html( $label ) . ( $required ? ' <em>*</em>' : '' ) . '</span><input type="' . esc_attr( $type ) . '" data-field="' . esc_attr( $field ) . '"' . ( $required ? ' required' : '' ) . '></label>';
	}

	/** @return void */
	private function require_capability() {
		\Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) || current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return; }
		wp_die( esc_html__( 'You do not have permission to issue credentials.', 'certloom' ) );
	}
}
