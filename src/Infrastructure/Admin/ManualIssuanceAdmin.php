<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Short Program issuance popup and compatible advanced issuance page.
 * Owns routing, assets and markup; IssueCredentialRest and
 * ManualIssuanceService own data, validation and canonical issuance.
 */
final class ManualIssuanceAdmin {
	const PAGE = 'certloom-issuance';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_footer', array( $this, 'popup' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page( null, __( 'Issue credential', 'certloom' ), __( 'Issue credential', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, self::PAGE, array( $this, 'render' ) );
	}

	/** @return void */
	public function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::PAGE, 'certloom', 'certloom-domain-programs', 'certloom-domain-credentials', 'certloom-domain-recipients', 'certloom-domain-batches' ), true ) ) { return; }
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) && ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return; }
		$profile = UsageProfilePresentation::active_profile();
		$terms = UsageProfilePresentation::terms( $profile );
		$program = (string) ( $terms['program']['singular'] ?? __( 'Program', 'certloom' ) );
		$batch = (string) ( $terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) );
		$asset = self::PAGE === $page ? 'issue-credential' : 'program-issuance';
		if ( self::PAGE === $page ) { wp_enqueue_media(); }
		$handle = 'certloom-' . $asset;
		wp_enqueue_style( $handle, CERTLOOM_URL . 'admin/assets/' . $asset . '.css', array( 'certloom-admin-foundation' ), (string) filemtime( CERTLOOM_PATH . 'admin/assets/' . $asset . '.css' ) );
		wp_enqueue_script( $handle, CERTLOOM_URL . 'admin/assets/' . $asset . '.js', array( 'ecapla-certverify-admin' ), (string) filemtime( CERTLOOM_PATH . 'admin/assets/' . $asset . '.js' ), true );
		wp_localize_script( $handle, 'certloomIssueCredential', array(
			'actorId' => get_current_user_id(),
			'programBootstrapUrl' => rest_url( 'certloom/v1/admin/issue/program-bootstrap' ),
			'enrollmentsUrl' => rest_url( 'certloom/v1/admin/issue/enrollments' ),
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
			'programsUrl' => admin_url( 'admin.php?page=certloom-domain-programs' ),
			'recipientsUrl' => admin_url( 'admin.php?page=certloom-domain-recipients' ),
			'deepLink' => array(
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link context.
				'recipient_id' => isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link context.
				'program_id' => isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link context.
				'batch_id' => isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0,
			),
			'i18n' => array(
				'selectRecipient' => __( 'Choose a recipient to continue.', 'certloom' ),
				'selectProgram' => __( 'Choose a Program to continue.', 'certloom' ),
				'currentSettings' => __( 'Checking current settings…', 'certloom' ),
				'reviewCredential' => __( 'Review credential', 'certloom' ),
				'confirmIssue' => __( 'Issue credential', 'certloom' ),
				'uncertainCommit' => __( 'The server response was interrupted. Retry this same issuance to safely check its result.', 'certloom' ),
				'retryIssue' => __( 'Check issuance result', 'certloom' ),
				'retryReview' => __( 'Review again', 'certloom' ),
				'retryLoading' => __( 'Retry loading', 'certloom' ),
				'issuedSuccess' => __( 'Credential issued', 'certloom' ),
				'issuedNext' => __( 'The credential is ready. Configured automations handle delivery; you can also review an email below.', 'certloom' ),
				'noExpiry' => __( 'No expiry', 'certloom' ),
				'detailsOnly' => __( 'Details only — no certificate design is assigned', 'certloom' ),
				'inheritedDesign' => __( 'Certificate design inherited from your settings', 'certloom' ),
				'chosenRecipient' => __( 'Selected recipient', 'certloom' ),
				'changeRecipient' => __( 'Change recipient', 'certloom' ),
				'changeProgram' => __( 'Change Program', 'certloom' ),
				'programDefaults' => __( 'Use Program settings', 'certloom' ),
				'noBatch' => __( 'No Batch selected', 'certloom' ),
				'discardIssuance' => __( 'Discard this recipient selection and start another issuance?', 'certloom' ),
				'missingDetails' => __( 'Complete missing details in the customer profile or connected source, then review again.', 'certloom' ),
				'enrollmentRequired' => __( 'Choose the enrollment for this credential.', 'certloom' ),
				'recipientStep' => __( '1 · Recipient', 'certloom' ),
				'reviewStep' => __( '2 · Review', 'certloom' ),
				'finishedStep' => __( 'Complete', 'certloom' ),
				'back' => __( 'Back', 'certloom' ),
				'program' => $program,
				'batch' => $batch,
				'certificate' => __( 'Certificate', 'certloom' ),
				'validity' => __( 'Valid from / expiry', 'certloom' ),
				'signing' => __( 'Signing', 'certloom' ),
				'configuredSigning' => __( 'Uses configured signing rules', 'certloom' ),
				'pagePrevious' => __( 'Previous matches', 'certloom' ),
				'pageNext' => __( 'Next matches', 'certloom' ),
				'chooseBatch' => sprintf( __( 'Choose %s', 'certloom' ), $batch ),
				'changeBatch' => sprintf( __( 'Change %s', 'certloom' ), $batch ),
				'matches' => __( 'matches', 'certloom' ),
				'newNameRequired' => __( 'Enter the recipient name.', 'certloom' ),
				'emailInvalid' => __( 'Enter a valid email address.', 'certloom' ),
				'batchRequired' => sprintf( __( 'Choose a %s for this Program before reviewing.', 'certloom' ), $batch ),
				'expiryRequired' => __( 'This Program requires an expiry date for renewal.', 'certloom' ),
				'expiryOptional' => __( 'This Program leaves expiry to each issuance. Blank means no expiry.', 'certloom' ),
				/* translators: %s: PDF, PNG or JPEG file format. */
				'downloadFormat' => __( 'Download %s', 'certloom' ),
				'loading' => __( 'Loading…', 'certloom' ),
				'searching' => __( 'Searching…', 'certloom' ),
				'noMatches' => __( 'No matching choices.', 'certloom' ),
				/* translators: %s: Recipient record number, distinguishing identical names. */
				'recipientReference' => __( 'Recipient #%s', 'certloom' ),
				'manualEmail' => __( 'Manual email', 'certloom' ),
				'manualEmailLater' => __( 'Prepare later', 'certloom' ),
				'manualEmailReviewNext' => __( 'Review next', 'certloom' ),
				'loadFailed' => __( 'Certloom could not load this step. Try again.', 'certloom' ),
				'previewWaiting' => __( 'Complete Recipient and Credential details to load the live preview.', 'certloom' ),
				'previewUpdating' => __( 'Updating preview…', 'certloom' ),
				'reviewing' => __( 'Building server review…', 'certloom' ),
				'issuing' => __( 'Issuing credential…', 'certloom' ),
				'programTitleHint' => sprintf( /* translators: %s: active Program terminology. */ __( 'Prefilled from the selected %s. Edit it freely for this credential only.', 'certloom' ), $program ),
				'customTitleHint' => sprintf( /* translators: %s: active Program terminology. */ __( 'Custom for this credential. The %s title is unchanged.', 'certloom' ), $program ),
				'programChangedBatch' => sprintf( /* translators: 1: active Batch terminology, 2: active Program terminology. */ __( '%1$s cleared because the %2$s changed. Loading the current %1$s choices…', 'certloom' ), $batch, $program ),
				'chooseProgramFirst' => sprintf( /* translators: 1: active Program terminology, 2: active Batch terminology. */ __( 'Choose a %1$s before selecting a %2$s.', 'certloom' ), $program, $batch ),
				'imageType' => __( 'Choose a PNG or JPEG image.', 'certloom' ),
				'repeatingLimit' => __( 'Repeating credential data is limited to 50 rows.', 'certloom' ),
				'reviewChanged' => __( 'Something changed after Review. Review again before issuing.', 'certloom' ),
				'showOnVerification' => __( 'Show on verification', 'certloom' ),
				'verificationIncludeHint' => __( 'Include this value on the public verification screen for this credential.', 'certloom' ),
				'verificationPrivate' => __( 'Private on verification', 'certloom' ),
				'verificationPrivateHint' => __( 'Field privacy settings keep this value off public verification.', 'certloom' ),
				'managedFieldSource' => __( 'Complete this value in customer account details, its Program default or the connected source. An issuance-only value cannot replace it.', 'certloom' ),
				'recipientValueMissing' => __( 'This required value is missing from the Recipient profile.', 'certloom' ),
				'refreshRecipientValue' => __( 'Refresh value', 'certloom' ),
				'editRecipient' => __( 'Edit Recipient', 'certloom' ),
				'publicVerificationValues' => __( 'Additional public verification fields', 'certloom' ),
				'noReusableFields' => __( 'No reusable credential fields are configured for this context. Add one-off details only when needed.', 'certloom' ),
				'verificationFieldsReview' => __( 'Verification fields', 'certloom' ),
			),
		) );
	}

	/** @return void */
	public function render() {
		$this->require_capability();
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-issue-app-shell"><section class="certloom-page-hero certloom-issue-app-hero"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential operations', 'certloom' ) . '</span><h1>' . esc_html__( 'Issue credential', 'certloom' ) . '</h1></div></section>';
		( new AdminProductNavigation() )->render_current_subnav();
		$this->app();
		echo '</div>';
	}

	/** Short Program flow; the legacy route retains its compatible advanced editor. */
	public function popup() {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // Read-only page routing.
		if ( ! in_array( $page, array( 'certloom', 'certloom-domain-programs', 'certloom-domain-credentials', 'certloom-domain-recipients', 'certloom-domain-batches' ), true ) ) { return; }
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) && ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return; }
		( new ProgramIssuancePopup() )->render();
	}

	private function app() {
		$profile = UsageProfilePresentation::active_profile();
		$terms = UsageProfilePresentation::terms( $profile );
		$program = (string) ( $terms['program']['singular'] ?? __( 'Program', 'certloom' ) );
		$batch = (string) ( $terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) );
		$issuer = (string) ( $terms['issuer']['singular'] ?? __( 'Issuer', 'certloom' ) );
		$signer = (string) ( $terms['signer']['singular'] ?? __( 'Signer', 'certloom' ) );

		echo '<div class="certloom-issue-app" data-certloom-issue-app data-program-term="' . esc_attr( $program ) . '" data-batch-term="' . esc_attr( $batch ) . '" data-issuer-term="' . esc_attr( $issuer ) . '" data-signer-term="' . esc_attr( $signer ) . '">';
		echo '<div class="certloom-issue-loading" data-issue-loading><span class="spinner is-active" aria-hidden="true"></span><strong>' . esc_html__( 'Preparing issuance workspace…', 'certloom' ) . '</strong></div>';
		echo '<div class="certloom-issue-app-body" data-issue-body hidden>';
		$this->render_steps( $program, $batch, $issuer, $signer );
		$this->render_quick_dialogs( $program, $batch );
		$this->render_summary( $program, $batch );
		echo '</div>';
		echo '<div class="certloom-issue-global-message" data-issue-message role="status" aria-live="polite" hidden></div>';
		echo '<noscript><div class="notice notice-error inline"><p>' . esc_html( sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( 'Issue Credential requires JavaScript because %1$s/%2$s lookup, live certificate preview and server Review are loaded through the authenticated WordPress REST API.', 'certloom' ), $program, $batch ) ) . '</p></div></noscript>';
		echo '</div>';
	}


	/** @return void */
	private function render_steps( $program, $batch, $issuer, $signer ) {
		$steps = array(
			array( 'recipient', __( 'Recipient', 'certloom' ), __( 'Who receives it', 'certloom' ), __( 'Recipient', 'certloom' ) ),
			array( 'context', __( 'Context', 'certloom' ), sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( '%1$s & %2$s', 'certloom' ), $program, $batch ), sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( '%1$s & %2$s', 'certloom' ), $program, $batch ) ),
			array( 'details', __( 'Details', 'certloom' ), __( 'Credential & data', 'certloom' ), __( 'Credential details', 'certloom' ) ),
			array( 'certificate', __( 'Certificate', 'certloom' ), __( 'Design & signing', 'certloom' ), __( 'Certificate and signing', 'certloom' ) ),
			array( 'email', __( 'Email', 'certloom' ), __( 'Delivery', 'certloom' ), __( 'Email', 'certloom' ) ),
			array( 'review', __( 'Review', 'certloom' ), __( 'Confirm & issue', 'certloom' ), __( 'Review & issue', 'certloom' ) ),
		);
		echo '<main class="certloom-issue-workspace">';
		echo '<nav class="certloom-issue-stepper" aria-label="' . esc_attr__( 'Issue credential steps', 'certloom' ) . '"><ol>';
		foreach ( $steps as $index => $step ) {
			echo '<li><button type="button" data-issue-step-button="' . esc_attr( (string) $index ) . '" aria-label="' . esc_attr( $step[3] ) . '"' . ( 0 === $index ? ' class="is-current" aria-current="step"' : '' ) . '><span class="certloom-issue-step-number">' . esc_html( (string) ( $index + 1 ) ) . '</span><span><strong>' . esc_html( $step[1] ) . '</strong><small>' . esc_html( $step[2] ) . '</small></span></button></li>';
		}
		echo '</ol></nav>';

		echo '<form class="certloom-issue-form" data-issue-form novalidate>';
		$this->render_recipient_step();
		$this->render_context_step( $program, $batch, $issuer );
		$this->render_credential_step( $program, $batch );
		$this->render_certificate_step( $program, $issuer, $signer );
		$this->render_email_step();
		$this->render_review_step();
		$this->render_footer();
		echo '</form></main>';
	}

	/** @return void */
	private function render_recipient_step() {
		echo '<section class="certloom-issue-step is-current" data-issue-step="0" data-step-key="recipient"><header><span class="certloom-eyebrow">' . esc_html__( 'Step 1 of 6', 'certloom' ) . '</span><h2>' . esc_html__( 'Who receives this credential?', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose an existing Recipient or create a reusable Recipient profile without leaving issuance.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-segmented" role="group" aria-label="' . esc_attr__( 'Recipient type', 'certloom' ) . '"><button type="button" class="is-active" data-recipient-mode="existing" aria-pressed="true">' . esc_html__( 'Existing recipient', 'certloom' ) . '</button><button type="button" data-recipient-mode="new" aria-pressed="false">' . esc_html__( 'New recipient', 'certloom' ) . '</button></div>';
		$this->render_async_picker( 'recipient', __( 'Recipient', 'certloom' ), __( 'Search by name, email or external ID…', 'certloom' ), true );
		echo '<div class="certloom-issue-new-recipient" data-new-recipient hidden><div class="certloom-issue-grid certloom-issue-grid-2">';
		$this->input( 'new_recipient.display_name', __( 'Display name', 'certloom' ), 'text', true );
		$this->input( 'new_recipient.email', __( 'Email', 'certloom' ), 'email', false );
		echo '</div><details class="certloom-issue-disclosure"><summary>' . esc_html__( 'More recipient details', 'certloom' ) . '<span>' . esc_html__( 'Optional', 'certloom' ) . '</span></summary><div class="certloom-issue-grid certloom-issue-grid-2">';
		$this->input( 'new_recipient.first_name', __( 'First name', 'certloom' ) );
		$this->input( 'new_recipient.middle_name', __( 'Middle name', 'certloom' ) );
		$this->input( 'new_recipient.last_name', __( 'Last name', 'certloom' ) );
		$this->input( 'new_recipient.external_id', __( 'External learner / customer ID', 'certloom' ) );
		echo '</div>';
		$this->render_async_picker( 'wp_user', __( 'WordPress account', 'certloom' ), __( 'Search WordPress users by name, email or login…', 'certloom' ), false );
		echo '</details><p class="description">' . esc_html__( 'Only the display name is required. Add an email address if you plan to send this credential later. Other recipient details can be added at any time.', 'certloom' ) . '</p></div></section>';
	}

	/** @return void */
	private function render_context_step( $program, $batch, $issuer ) {
		echo '<section class="certloom-issue-step" data-issue-step="1" data-step-key="context" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 2 of 6', 'certloom' ) . '</span><h2>' . esc_html__( 'Choose credential context', 'certloom' ) . '</h2><p>' . esc_html( sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( 'Use a reusable %1$s and optional %2$s when they apply, or issue an independent credential.', 'certloom' ), $program, $batch ) ) . '</p></header>';
		echo '<div class="certloom-segmented" role="group" aria-label="' . esc_attr__( 'Credential basis', 'certloom' ) . '"><button type="button" class="is-active" data-credential-basis="program" aria-pressed="true">' . esc_html( $program ) . '</button><button type="button" data-credential-basis="independent" aria-pressed="false">' . esc_html__( 'Independent credential', 'certloom' ) . '</button></div>';
		echo '<div data-program-context>';
		$this->render_async_picker( 'program', $program, sprintf( /* translators: %s: Program terminology. */ __( 'Search %s…', 'certloom' ), $program ), true, current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) ? __( 'Add', 'certloom' ) . ' ' . $program : '' );
		$this->render_async_picker( 'batch', $batch, sprintf( /* translators: %s: Batch terminology. */ __( 'Search %s…', 'certloom' ), $batch ), false, current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ? __( 'Add', 'certloom' ) . ' ' . $batch : '' );
		echo '<div class="certloom-issue-context-note" data-program-context-note hidden></div>';
		echo '<div class="certloom-issue-context-owner"><span class="dashicons dashicons-building" aria-hidden="true"></span><div><strong>' . esc_html( sprintf( /* translators: 1: Issuer terminology, 2: Program terminology. */ __( '%1$s is managed on the %2$s', 'certloom' ), $issuer, $program ) ) . '</strong><p>' . esc_html( sprintf( /* translators: 1: Issuer terminology, 2: Program terminology. */ __( 'The issuing organization is inherited during issuance. Change or add the %1$s from the selected %2$s instead of overriding it here.', 'certloom' ), $issuer, $program ) ) . '</p></div><a class="button button-small" data-manage-program target="_blank" rel="noopener noreferrer" href="' . esc_url( admin_url( 'admin.php?page=certloom-domain-programs' ) ) . '">' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Manage %s', 'certloom' ), $program ) ) . '</a></div></div>';
		echo '<div class="certloom-issue-independent-note" data-independent-note hidden><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><p>' . esc_html( sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( 'No %1$s or %2$s relationship will be stored. Choose an issuer here only when this independent credential needs one.', 'certloom' ), $program, $batch ) ) . '</p><div data-independent-issuer>';
		$this->render_async_picker( 'issuer', $issuer, sprintf( /* translators: %s: Issuer terminology. */ __( 'Search %s…', 'certloom' ), $issuer ), false );
		echo '</div></div></div>';
		echo '</section>';
	}

	/** @return void */
	private function render_credential_step( $program, $batch ) {
		echo '<section class="certloom-issue-step" data-issue-step="2" data-step-key="details" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 3 of 6', 'certloom' ) . '</span><h2>' . esc_html__( 'Credential details', 'certloom' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Set the title and lifecycle, then complete only the credential data this %s or certificate design needs.', 'certloom' ), $program ) ) . '</p></header>';
		echo '<div class="certloom-issue-grid certloom-issue-grid-2"><label class="certloom-issue-field certloom-issue-field-span"><span>' . esc_html__( 'Credential title', 'certloom' ) . ' <em>*</em></span><input type="text" data-field="credential_title" maxlength="255" required aria-required="true"><small data-title-hint>' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Choose a %s to prefill its title, or type a custom title.', 'certloom' ), $program ) ) . '</small></label>';
		echo '<label class="certloom-issue-field certloom-issue-lifecycle-field"><span>' . esc_html__( 'Effective date', 'certloom' ) . ' <em>*</em></span><input type="date" data-field="valid_from" required aria-required="true"><small class="certloom-issue-lifecycle-help" aria-hidden="true">&nbsp;</small></label>';
		echo '<label class="certloom-issue-field certloom-issue-lifecycle-field"><span>' . esc_html__( 'Validity', 'certloom' ) . '</span><select data-field="expiry_mode"><option value="program">' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Use %s default', 'certloom' ), $program ) ) . '</option><option value="permanent">' . esc_html__( 'No expiry', 'certloom' ) . '</option><option value="date">' . esc_html__( 'Specific expiry date', 'certloom' ) . '</option></select><small data-validity-summary></small></label>';
		echo '<label class="certloom-issue-field" data-expiry-date-field hidden><span>' . esc_html__( 'Expiry date', 'certloom' ) . '</span><input type="date" data-field="expires_at"></label>';
		echo '<label class="certloom-issue-field" data-display-number-field hidden><span>' . esc_html__( 'Certificate number', 'certloom' ) . ' <em>*</em></span><input type="text" data-field="display_number" maxlength="191" required aria-required="true"><small>' . esc_html__( 'Your numbering policy requires a manual number.', 'certloom' ) . '</small></label></div>';
		echo '<div class="certloom-issue-inline-section certloom-issue-data-inline"><div class="certloom-issue-subhead"><div><h3>' . esc_html__( 'Credential data', 'certloom' ) . '</h3><p>' . esc_html__( 'Fields configured for this credential context appear here. Choose which eligible values should also appear on public verification.', 'certloom' ) . '</p></div></div>';
		echo '<div class="certloom-issue-data-section" data-scoped-fields><div class="certloom-issue-empty is-compact"><p>' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Choose a %s or continue with an independent credential to configure credential data.', 'certloom' ), $program ) ) . '</p></div></div>';
		echo '<div class="certloom-issue-data-section" data-design-required-fields hidden><div class="certloom-issue-subhead"><div><h3>' . esc_html__( 'Required by certificate design', 'certloom' ) . '</h3><p>' . esc_html__( 'Missing values detected from the live certificate design appear here.', 'certloom' ) . '</p></div></div><div data-design-required-list></div></div>';
		echo '<div class="certloom-issue-data-section" data-repeating-section hidden><div class="certloom-issue-subhead"><div><h3>' . esc_html__( 'Subjects & results', 'certloom' ) . '</h3><p>' . esc_html__( 'Add transcript-style rows only when this credential needs them.', 'certloom' ) . '</p></div></div><div data-repeating-rowsets></div></div>';
		echo '<details class="certloom-issue-disclosure"><summary>' . esc_html__( 'Additional one-off credential data', 'certloom' ) . '<span>' . esc_html__( 'Optional', 'certloom' ) . '</span></summary><div class="certloom-issue-oneoff" data-oneoff-fields><div data-oneoff-list></div><button type="button" class="button" data-add-oneoff>' . esc_html__( 'Add one-off field', 'certloom' ) . '</button><p class="description">' . esc_html( sprintf( /* translators: 1: Program terminology, 2: Batch terminology. */ __( 'These values are frozen only on this credential and do not change the %1$s, %2$s or Recipient profile.', 'certloom' ), $program, $batch ) ) . '</p></div></details></div>';
		echo '<div class="certloom-issue-fast-review"><div><strong>' . esc_html__( 'Ready with the essentials?', 'certloom' ) . '</strong><p>' . esc_html__( 'Certificate artwork, signing and manual email review are optional. Configured automations may still send after issuance.', 'certloom' ) . '</p></div><button type="button" class="button" data-review-now>' . esc_html__( 'Review now', 'certloom' ) . '</button></div>';
		echo '</section>';
	}

	/** @return void */
	private function render_certificate_step( $program, $issuer, $signer ) {
		echo '<section class="certloom-issue-step" data-issue-step="3" data-step-key="certificate" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 4 of 6', 'certloom' ) . '</span><h2>' . esc_html__( 'Certificate and signing', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose how the credential appears, then review its issuing authority and signer.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-presentation-options"><button type="button" data-presentation="details_only" class="is-active" aria-pressed="true"><span class="dashicons dashicons-id-alt" aria-hidden="true"></span><strong>' . esc_html__( 'Credential details only', 'certloom' ) . '</strong><small>' . esc_html__( 'Public verification without a certificate artwork file.', 'certloom' ) . '</small></button><button type="button" data-presentation="image" aria-pressed="false"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><strong>' . esc_html__( 'Uploaded certificate image', 'certloom' ) . '</strong><small>' . esc_html__( 'Use one PNG or JPEG image for this credential.', 'certloom' ) . '</small></button><button type="button" data-presentation="design" aria-pressed="false"><span class="dashicons dashicons-art" aria-hidden="true"></span><strong>' . esc_html__( 'Certloom design', 'certloom' ) . '</strong><small>' . esc_html__( 'Resolve inheritance or assign a published certificate design.', 'certloom' ) . '</small></button></div>';
		echo '<div class="certloom-issue-image-controls" data-image-controls hidden><button type="button" class="button" data-choose-image>' . esc_html__( 'Choose PNG/JPEG', 'certloom' ) . '</button><button type="button" class="button-link-delete" data-clear-image hidden>' . esc_html__( 'Remove image', 'certloom' ) . '</button><div data-image-selection></div></div>';
		echo '<div class="certloom-issue-design-controls" data-design-controls hidden><div class="certloom-segmented"><button type="button" class="is-active" data-design-source="inherited" aria-pressed="true">' . esc_html__( 'Use inherited design', 'certloom' ) . '</button><button type="button" data-design-source="assigned" aria-pressed="false">' . esc_html__( 'Assign for this credential', 'certloom' ) . '</button></div><div data-assigned-design hidden>';
		$this->render_async_picker( 'template', __( 'Certificate design', 'certloom' ), __( 'Search published certificate designs…', 'certloom' ), true );
		echo '</div><p class="description" data-design-source-summary></p></div>';
		echo '<div class="certloom-issue-inline-section certloom-issue-signing"><div class="certloom-issue-subhead"><div><h3>' . esc_html__( 'Issuing authority and signer', 'certloom' ) . '</h3><p>' . esc_html( sprintf( /* translators: 1: Issuer terminology, 2: Program terminology, 3: Signer terminology. */ __( 'For %2$s credentials, the %1$s is inherited. For independent credentials, use the %1$s chosen earlier. Add a %3$s only when needed.', 'certloom' ), $issuer, $program, $signer ) ) . '</p></div></div>';
		echo '<div class="certloom-issue-authority-card" data-inherited-issuer hidden><span class="dashicons dashicons-building" aria-hidden="true"></span><div><small>' . esc_html__( 'Inherited authority', 'certloom' ) . '</small><strong data-inherited-issuer-name></strong><p>' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Managed on the selected %s; issuance cannot contradict it.', 'certloom' ), $program ) ) . '</p></div></div>';
		$this->render_async_picker( 'signer', $signer, sprintf( /* translators: %s: Signer terminology. */ __( 'Search %s…', 'certloom' ), $signer ), false );
		echo '</div>';
		echo '<div class="certloom-issue-preview-card"><div class="certloom-issue-preview-head"><div><h3>' . esc_html__( 'Live certificate preview', 'certloom' ) . '</h3><p data-preview-status>' . esc_html__( 'Complete the earlier details to preview this credential.', 'certloom' ) . '</p></div><a href="' . esc_url( admin_url( 'admin.php?page=certloom-templates' ) ) . '" class="button button-small certloom-issue-external-link" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Manage designs', 'certloom' ) . '</a></div><div class="certloom-issue-preview-stage" data-preview-stage><div class="certloom-issue-preview-empty"><span class="dashicons dashicons-awards" aria-hidden="true"></span><p>' . esc_html__( 'Live preview will appear here.', 'certloom' ) . '</p></div></div></div></section>';
	}

	/** @return void */
	private function render_email_step() {
		echo '<section class="certloom-issue-step" data-issue-step="4" data-step-key="email" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 5 of 6', 'certloom' ) . '</span><h2>' . esc_html__( 'Manual email follow-up', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose whether to review a manual email after issuing. Existing automation rules run separately and may send an email.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-email-options"><button type="button" data-email-mode="review" aria-pressed="false"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><strong>' . esc_html__( 'Review a manual email next', 'certloom' ) . '</strong><small>' . esc_html__( 'Issue first, then open Email Delivery to preview and confirm sending.', 'certloom' ) . '</small></button><button type="button" class="is-active" data-email-mode="later" aria-pressed="true"><span class="dashicons dashicons-clock" aria-hidden="true"></span><strong>' . esc_html__( 'Prepare a manual email later', 'certloom' ) . '</strong><small>' . esc_html__( 'You can prepare a manual email later from this credential. Existing automations are unaffected.', 'certloom' ) . '</small></button></div>';
		echo '<div class="certloom-issue-field-action-cluster" data-email-design-field hidden><div class="certloom-issue-field-action-heading"><label for="certloom-issue-email-template">' . esc_html__( 'Email design', 'certloom' ) . '</label><a class="button button-small certloom-issue-external-link" target="_blank" rel="noopener noreferrer" href="' . esc_url( admin_url( 'admin.php?page=certloom-email-designs' ) ) . '">' . esc_html__( 'Manage email designs', 'certloom' ) . '</a></div><select id="certloom-issue-email-template" data-email-template><option value="automatic">' . esc_html__( 'Automatic — use email hierarchy', 'certloom' ) . '</option></select><small>' . esc_html__( 'An explicit selection is a one-send override; it does not change Design Rules.', 'certloom' ) . '</small></div></section>';
	}

	/** @return void */
	private function render_review_step() {
		echo '<section class="certloom-issue-step" data-issue-step="5" data-step-key="review" hidden><header><span class="certloom-eyebrow">' . esc_html__( 'Step 6 of 6', 'certloom' ) . '</span><h2>' . esc_html__( 'Review and issue', 'certloom' ) . '</h2><p>' . esc_html__( 'Check the final credential details before issuing. Certloom revalidates them when you confirm.', 'certloom' ) . '</p></header>';
		echo '<div class="certloom-issue-review-state" data-review-state><div class="certloom-issue-empty"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><h3>' . esc_html__( 'Review your credential', 'certloom' ) . '</h3><p>' . esc_html__( 'Certloom checks your choices against the current records before issuing.', 'certloom' ) . '</p><button type="button" class="button button-primary" data-build-review>' . esc_html__( 'Refresh review', 'certloom' ) . '</button></div></div>';
		echo '<div class="certloom-issue-review-content" data-review-content hidden></div>';
		echo '<details class="certloom-issue-technical" data-review-technical hidden><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><div data-review-technical-body></div></details>';
		echo '<div class="certloom-issue-review-actions" data-review-actions hidden><button type="button" class="button button-primary button-hero" data-issue-confirm>' . esc_html__( 'Issue credential', 'certloom' ) . '</button><p>' . esc_html__( 'Issuing creates an immutable credential version. Manual email requires a separate review; configured automations may run after issuance.', 'certloom' ) . '</p></div>';
		echo '<div class="certloom-issue-receipt" data-issue-receipt hidden></div></section>';
	}

	/** @return void */
	private function render_footer() {
		echo '<footer class="certloom-issue-footer"><button type="button" class="button" data-step-prev disabled>' . esc_html__( 'Back', 'certloom' ) . '</button><div class="certloom-issue-footer-status" data-step-status>' . esc_html__( 'Step 1 of 6', 'certloom' ) . '</div><button type="button" class="button button-primary" data-step-next>' . esc_html__( 'Continue', 'certloom' ) . '</button></footer>';
	}

	/** @return void */
	private function render_summary( $program, $batch ) {
		echo '<aside class="certloom-issue-summary" data-issue-summary><div class="certloom-issue-summary-head"><span class="certloom-eyebrow">' . esc_html__( 'Live summary', 'certloom' ) . '</span><h2>' . esc_html__( 'Credential', 'certloom' ) . '</h2></div><dl><div><dt>' . esc_html__( 'Recipient', 'certloom' ) . '</dt><dd data-summary-recipient>—</dd></div><div><dt>' . esc_html( $program ) . '</dt><dd data-summary-program>—</dd></div><div><dt>' . esc_html( $batch ) . '</dt><dd data-summary-batch>—</dd></div><div><dt>' . esc_html__( 'Title', 'certloom' ) . '</dt><dd data-summary-title>—</dd></div><div><dt>' . esc_html__( 'Certificate', 'certloom' ) . '</dt><dd data-summary-certificate>' . esc_html__( 'Details only', 'certloom' ) . '</dd></div><div><dt>' . esc_html__( 'Manual email', 'certloom' ) . '</dt><dd data-summary-email>' . esc_html__( 'Prepare later', 'certloom' ) . '</dd></div></dl><div class="certloom-issue-summary-progress"><span data-summary-progress-bar></span></div><p data-summary-progress-text>' . esc_html__( 'Step 1 of 6', 'certloom' ) . '</p></aside>';
	}

	/** @return void */
	private function render_async_picker( $kind, $label, $placeholder, $required = false, $quick_label = '' ) {
		$input_id = 'certloom-issue-picker-' . sanitize_html_class( $kind );
		echo '<div class="certloom-async-picker" data-picker="' . esc_attr( $kind ) . '"><div class="certloom-picker-heading"><label for="' . esc_attr( $input_id ) . '"><span>' . esc_html( $label ) . ( $required ? ' <em>*</em>' : '' ) . '</span></label>';
		if ( '' !== $quick_label ) { echo '<button type="button" class="button button-small certloom-picker-quick-add" data-quick-open="' . esc_attr( $kind ) . '">' . esc_html( $quick_label ) . '</button>'; }
		echo '</div><div class="certloom-async-picker-control"><input id="' . esc_attr( $input_id ) . '" type="search" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '" data-picker-search aria-autocomplete="list" aria-expanded="false"><input type="hidden" data-picker-value><button type="button" class="button certloom-picker-clear" data-picker-clear hidden aria-label="' . esc_attr( sprintf( /* translators: %s: picker label. */ __( 'Clear %s', 'certloom' ), $label ) ) . '">×</button></div><div class="certloom-picker-selected" data-picker-selected hidden></div><div class="certloom-picker-results" data-picker-results role="listbox" hidden></div><div class="certloom-picker-status" data-picker-status aria-live="polite"></div></div>';
	}

	/** @return void */
	private function render_quick_dialogs( $program, $batch ) {
		echo '<dialog class="certloom-issue-dialog" data-quick-dialog="program" aria-labelledby="certloom-quick-program-title"><form method="dialog" data-quick-form="program"><header><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2 id="certloom-quick-program-title">' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Add %s', 'certloom' ), $program ) ) . '</h2></div><button type="button" data-quick-close class="button-link" aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></header><label><span>' . esc_html__( 'Title', 'certloom' ) . ' <em>*</em></span><input type="text" name="title" required aria-required="true" maxlength="255"></label><label><span>' . esc_html__( 'Code', 'certloom' ) . ' <small>' . esc_html__( 'Optional', 'certloom' ) . '</small></span><input type="text" name="code" maxlength="100"></label><p class="description">' . esc_html( sprintf( /* translators: %s: Program terminology. */ __( 'Designs inherit automatically. After creation, use its Designs card or Design Rules if this %s needs an override.', 'certloom' ), $program ) ) . '</p><div class="certloom-dialog-actions"><button type="button" data-quick-close class="button">' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add and use', 'certloom' ) . '</button></div></form></dialog>';
		echo '<dialog class="certloom-issue-dialog" data-quick-dialog="batch" aria-labelledby="certloom-quick-batch-title"><form method="dialog" data-quick-form="batch"><header><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2 id="certloom-quick-batch-title">' . esc_html( sprintf( /* translators: %s: Batch terminology. */ __( 'Add %s', 'certloom' ), $batch ) ) . '</h2></div><button type="button" data-quick-close class="button-link" aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></header><label><span>' . esc_html__( 'Name', 'certloom' ) . ' <em>*</em></span><input type="text" name="name" required aria-required="true" maxlength="255"></label><label><span>' . esc_html__( 'Code', 'certloom' ) . ' <small>' . esc_html__( 'Optional — generated when blank', 'certloom' ) . '</small></span><input type="text" name="code" maxlength="100"></label><div class="certloom-dialog-actions"><button type="button" data-quick-close class="button">' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add and use', 'certloom' ) . '</button></div></form></dialog>';
	}

	/** @return void */
	private function input( $field, $label, $type = 'text', $required = false ) {
		echo '<label class="certloom-issue-field"><span>' . esc_html( $label ) . ( $required ? ' <em>*</em>' : ' <small>' . esc_html__( 'Optional', 'certloom' ) . '</small>' ) . '</span><input type="' . esc_attr( $type ) . '" data-field="' . esc_attr( $field ) . '"' . ( $required ? ' required aria-required="true"' : '' ) . '></label>';
	}

	/** @return void */
	private function require_capability() {
		\Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) || current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return; }
		wp_die( esc_html__( 'You do not have permission to issue credentials.', 'certloom' ) );
	}
}
