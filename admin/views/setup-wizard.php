<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped to the rendering method.
$certloom_setup_state = $setup_summary['state'];
$certloom_wizard_completed = ! empty( $setup_summary['wizard_completed'] );
$certloom_draft = $wizard_draft;
$certloom_step_keys = array_keys( $wizard_steps );
$certloom_step_index = array_search( $wizard_step, $certloom_step_keys, true );
$certloom_step_index = false === $certloom_step_index ? 0 : (int) $certloom_step_index;
$certloom_next_step = isset( $certloom_step_keys[ $certloom_step_index + 1 ] ) ? $certloom_step_keys[ $certloom_step_index + 1 ] : 'review';
$certloom_prev_step = $certloom_step_index > 0 ? $certloom_step_keys[ $certloom_step_index - 1 ] : '';
$certloom_purposes = $wizard_engine->purposes();
$certloom_subcategories = $wizard_engine->subcategories();
$certloom_selected_purpose = isset( $certloom_draft['purpose'] ) ? (string) $certloom_draft['purpose'] : 'custom_other';
$certloom_selected_subcategory = isset( $certloom_draft['subcategory'] ) ? (string) $certloom_draft['subcategory'] : 'custom';
$certloom_organization = array_key_exists( 'organization_name', $certloom_draft ) ? (string) $certloom_draft['organization_name'] : (string) $certloom_setup_state['organization_name'];
$certloom_page_id = array_key_exists( 'verification_page_id', $certloom_draft ) ? absint( $certloom_draft['verification_page_id'] ) : absint( $certloom_setup_state['verification_page_id'] );
$certloom_numbering = isset( $certloom_draft['numbering_mode'] ) ? (string) $certloom_draft['numbering_mode'] : (string) $wizard_current_settings['numbering_mode'];
$certloom_numbering_prefix = array_key_exists( 'numbering_prefix', $certloom_draft ) ? (string) $certloom_draft['numbering_prefix'] : (string) ( $wizard_current_settings['numbering_prefix'] ?? '' );
$certloom_numbering_suffix = array_key_exists( 'numbering_suffix', $certloom_draft ) ? (string) $certloom_draft['numbering_suffix'] : (string) ( $wizard_current_settings['numbering_suffix'] ?? '' );
$certloom_numbering_start = array_key_exists( 'numbering_start', $certloom_draft ) ? absint( $certloom_draft['numbering_start'] ) : absint( $wizard_current_settings['numbering_start'] ?? 1 );
$certloom_numbering_padding = array_key_exists( 'numbering_padding', $certloom_draft ) ? absint( $certloom_draft['numbering_padding'] ) : absint( $wizard_current_settings['numbering_padding'] ?? 6 );
$certloom_numbering_example = static function ( $mode, $prefix, $suffix, $start, $padding ) {
	$mode = sanitize_key( (string) $mode ); $prefix = (string) $prefix; $suffix = (string) $suffix;
	if ( 'manual' === $mode ) { return __( 'Entered when issuing', 'certloom' ); }
	if ( 'sequential' === $mode ) { return $prefix . str_pad( (string) max( 1, absint( $start ) ), max( 1, min( 12, absint( $padding ) ) ), '0', STR_PAD_LEFT ) . $suffix; }
	if ( 'random' === $mode ) { return $prefix . '8K3M-2Q7P' . $suffix; }
	return $prefix . '550e8400-e29b-41d4-a716-446655440000' . $suffix;
};
$certloom_numbering_current_label = $certloom_numbering_example( $wizard_current_settings['numbering_mode'], $wizard_current_settings['numbering_prefix'] ?? '', $wizard_current_settings['numbering_suffix'] ?? '', $wizard_current_settings['numbering_start'] ?? 1, $wizard_current_settings['numbering_padding'] ?? 6 );
$certloom_numbering_proposed_label = $certloom_numbering_example( $certloom_numbering, $certloom_numbering_prefix, $certloom_numbering_suffix, $certloom_numbering_start, $certloom_numbering_padding );
$certloom_qr = array_key_exists( 'verification_qr_enabled', $certloom_draft ) ? ! empty( $certloom_draft['verification_qr_enabled'] ) : ! empty( $wizard_current_settings['verification_qr_enabled'] );
$certloom_barcode = array_key_exists( 'verification_barcode_enabled', $certloom_draft ) ? ! empty( $certloom_draft['verification_barcode_enabled'] ) : ! empty( $wizard_current_settings['verification_barcode_enabled'] );
$certloom_backup_schedule = isset( $certloom_draft['backup_schedule'] ) ? (string) $certloom_draft['backup_schedule'] : (string) $wizard_backup_settings['schedule'];
$certloom_backup_retention = isset( $certloom_draft['backup_retention'] ) ? absint( $certloom_draft['backup_retention'] ) : absint( $wizard_backup_settings['retention'] );
$certloom_google_connected = ! empty( $wizard_google_drive['connected'] );
$certloom_google_enabled = array_key_exists( 'google_drive_enabled', $certloom_draft ) ? ! empty( $certloom_draft['google_drive_enabled'] ) : ! empty( $wizard_google_drive['enabled'] );
$certloom_google_retention = isset( $certloom_draft['google_drive_retention'] ) ? absint( $certloom_draft['google_drive_retention'] ) : absint( $wizard_google_drive['retention'] ?? 14 );
$certloom_current_template_name = is_array( $wizard_template_assignment ) && ! empty( $wizard_template_assignment['active'] ) ? (string) ( $wizard_template_assignment['template_name'] ?? '' ) : __( 'None', 'certloom' );
$certloom_proposed_template_name = __( 'None', 'certloom' );
if ( $wizard_selected_template_id && is_array( $wizard_template ) ) { $certloom_proposed_template_name = (string) ( $wizard_template['name'] ?? __( 'Selected Template', 'certloom' ) ); }
$certloom_setup_test_url = '';
if ( $certloom_page_id ) {
	$certloom_setup_page_url = get_permalink( $certloom_page_id );
	if ( $certloom_setup_page_url ) {
		$certloom_setup_test_url = ! empty( $setup_summary['sample_cert_number'] ) ? Ecapla_CertVerify_Public_Router::verification_url( (string) $setup_summary['sample_cert_number'] ) : $certloom_setup_page_url;
	}
}
$certloom_step_url = static function ( $step ) { return add_query_arg( array( 'page' => 'certloom-setup', 'step' => sanitize_key( $step ) ), admin_url( 'admin.php' ) ); };
$certloom_sample_verification_url = add_query_arg( 'page', 'certloom-verification-sample', admin_url( 'admin.php' ) );
$certloom_design_library_url = add_query_arg( array( 'page' => 'certloom-templates', 'design_view' => 'templates' ), admin_url( 'admin.php' ) );
$certloom_create_design_url = add_query_arg( array( 'page' => 'certloom-templates', 'design_view' => 'editor' ), admin_url( 'admin.php' ) ) . '#certloom-template-editor';
$certloom_edit_design_url = $wizard_selected_template_id ? add_query_arg( array( 'page' => 'certloom-templates', 'design_view' => 'editor', 'template_id' => $wizard_selected_template_id ), admin_url( 'admin.php' ) ) : '';
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-setup-screen certloom-adaptive-setup">
	<section class="certloom-page-hero" aria-labelledby="certloom-setup-title">
		<div>
			<span class="certloom-eyebrow"><?php esc_html_e( 'Adaptive setup', 'certloom' ); ?></span>
			<?php if ( $certloom_wizard_completed ) : ?>
				<h1 id="certloom-setup-title"><?php esc_html_e( 'Setup complete', 'certloom' ); ?></h1>
				<p><?php esc_html_e( 'Your reviewed setup is active. Return here only when you want to run a fresh setup review.', 'certloom' ); ?></p>
			<?php else : ?>
				<h1 id="certloom-setup-title"><?php esc_html_e( 'Set up Certloom', 'certloom' ); ?></h1>
				<p><?php esc_html_e( 'Choose what you need, review the recommendations, and apply only the settings you approve. You can leave and return at any time.', 'certloom' ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $certloom_wizard_completed ) : ?><span class="certloom-setup-complete-badge"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Complete', 'certloom' ); ?></span><?php endif; ?>
	</section>

	<div class="certloom-notice-rail" role="region" aria-label="<?php esc_attr_e( 'Setup notices', 'certloom' ); ?>">
		<?php if ( isset( $_GET['certloom_wizard_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setup progress saved.', 'certloom' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['certloom_wizard_applied'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Your reviewed setup settings were applied.', 'certloom' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['certloom_wizard_restarted'] ) ) : ?><div class="notice notice-info"><p><?php esc_html_e( 'A new setup review has started. Existing live settings have not been changed.', 'certloom' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['ecapla_certverify_setup_page_created'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Verification page created and linked.', 'certloom' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; ?>
	</div>

	<?php if ( $certloom_wizard_completed ) : ?>
		<section class="certloom-panel certloom-setup-completed-landing" aria-labelledby="certloom-setup-completed-title">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<h2 id="certloom-setup-completed-title"><?php esc_html_e( 'Setup is complete', 'certloom' ); ?></h2>
			<p><?php esc_html_e( 'Your reviewed settings are active. You can return to the Dashboard or start a fresh setup review whenever your organization changes.', 'certloom' ); ?></p>
			<div class="certloom-setup-completed-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'certloom' ); ?></a><form method="post"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="restart"><button class="button button-primary" type="submit"><?php esc_html_e( 'Run setup again', 'certloom' ); ?></button></form></div>
		</section>
	</div>
	<?php return; endif; ?>

	<nav class="certloom-wizard-progress" aria-label="<?php esc_attr_e( 'Setup progress', 'certloom' ); ?>">
		<?php foreach ( $wizard_steps as $key => $label ) :
			$is_current = $key === $wizard_step;
			$is_done = in_array( $key, (array) $certloom_setup_state['wizard_completed_steps'], true );
			$is_skipped = in_array( $key, (array) $certloom_setup_state['wizard_skipped_steps'], true );
		?>
			<a href="<?php echo esc_url( $certloom_step_url( $key ) ); ?>" class="certloom-wizard-progress-step <?php echo $is_current ? 'is-current' : ''; ?> <?php echo $is_done ? 'is-done' : ''; ?> <?php echo $is_skipped ? 'is-skipped' : ''; ?>" <?php echo $is_current ? 'aria-current="step"' : ''; ?>>
				<span><?php echo esc_html( (string) ( array_search( $key, $certloom_step_keys, true ) + 1 ) ); ?></span><?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="certloom-wizard-layout">
		<main class="certloom-panel certloom-wizard-main" aria-labelledby="certloom-wizard-step-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php echo esc_html( sprintf( /* translators: %1$d: current step; %2$d: total steps. */ __( 'Step %1$d of %2$d', 'certloom' ), $certloom_step_index + 1, count( $wizard_steps ) ) ); ?></span><h2 id="certloom-wizard-step-title"><?php echo esc_html( $wizard_steps[ $wizard_step ] ); ?></h2></div></div>

			<?php if ( 'purpose' === $wizard_step ) : ?>
				<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form" data-certloom-purpose-form>
					<?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="purpose"><input type="hidden" name="wizard_next_step" value="organization">
					<div class="certloom-callout certloom-callout-info certloom-wizard-progressive-note"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Start with the essentials', 'certloom' ); ?></strong><p><?php esc_html_e( 'Certloom keeps specialist lifecycle, delivery and integration controls available in context under More settings or Technical details instead of asking you to choose a separate workspace mode.', 'certloom' ); ?></p></div></div>

					<div class="certloom-form-grid">
						<label class="certloom-form-field certloom-form-field-wide"><span><?php esc_html_e( 'What will you use Certloom for?', 'certloom' ); ?></span><select name="purpose" id="certloom-wizard-purpose" required><?php foreach ( $certloom_purposes as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $certloom_selected_purpose, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
						<label class="certloom-form-field certloom-form-field-wide"><span><?php esc_html_e( 'Type of credential or program', 'certloom' ); ?></span><select name="subcategory" id="certloom-wizard-subcategory" required><?php foreach ( $certloom_subcategories as $purpose_key => $options ) : foreach ( $options as $key => $label ) : $certloom_option_recommendation = $wizard_engine->recommend( $purpose_key, $key ); ?><option value="<?php echo esc_attr( $key ); ?>" data-purpose="<?php echo esc_attr( $purpose_key ); ?>" data-terminology="<?php echo esc_attr( is_wp_error( $certloom_option_recommendation ) ? __( 'Credential', 'certloom' ) : $certloom_option_recommendation['terminology'] ); ?>" data-design="<?php echo esc_attr( is_wp_error( $certloom_option_recommendation ) ? __( 'Clear and professional', 'certloom' ) : $certloom_option_recommendation['design_tone'] ); ?>" <?php selected( $certloom_selected_subcategory, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; endforeach; ?></select></label>
						<label class="certloom-form-field certloom-form-field-wide certloom-wizard-scheme" data-certloom-scheme-field><span><?php esc_html_e( 'Scheme or reference (optional)', 'certloom' ); ?></span><input type="text" maxlength="120" name="scheme_reference" value="<?php echo esc_attr( $certloom_draft['scheme_reference'] ?? '' ); ?>"><small><?php esc_html_e( 'Examples can help organize your setup, but Certloom never treats a standard name as proof of compliance.', 'certloom' ); ?></small></label>
					</div>
					<div class="certloom-wizard-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save & continue', 'certloom' ); ?></button></div>
				</form>

			<?php elseif ( 'organization' === $wizard_step ) : ?>
				<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form">
					<?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="organization"><input type="hidden" name="wizard_next_step" value="program">
					<div class="certloom-form-grid">
						<label class="certloom-form-field certloom-form-field-wide"><span><?php esc_html_e( 'Organization name', 'certloom' ); ?></span><input type="text" name="organization_name" maxlength="200" value="<?php echo esc_attr( $certloom_organization ); ?>"><small><?php esc_html_e( 'This is the setup profile name. It does not create or overwrite a gated multi-issuer record.', 'certloom' ); ?></small></label>
						<div class="certloom-form-field certloom-form-field-wide"><label for="verification_page_id"><?php esc_html_e( 'Verification page', 'certloom' ); ?></label><?php wp_dropdown_pages( array( 'name' => 'verification_page_id', 'id' => 'verification_page_id', 'selected' => $certloom_page_id, 'show_option_none' => __( '— Choose later —', 'certloom' ), 'option_none_value' => 0 ) ); ?><small><?php esc_html_e( 'Choose a page with the Certloom block or shortcode. You can skip this and finish it later.', 'certloom' ); ?></small></div>
					</div>
					<div class="certloom-wizard-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save & continue', 'certloom' ); ?></button></div>
				</form>
				<?php if ( ! $certloom_page_id ) : ?><form method="post" class="certloom-wizard-secondary-action"><?php wp_nonce_field( 'certverify_onboarding_create_page' ); ?><button class="button" type="submit" name="certverify_onboarding_create_page" value="1"><?php esc_html_e( 'Create a standard verification page', 'certloom' ); ?></button></form><?php endif; ?>

			<?php elseif ( 'program' === $wizard_step ) : ?>
				<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form">
					<?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="program"><input type="hidden" name="wizard_next_step" value="design">
					<label class="certloom-form-field"><span><?php esc_html_e( 'First Program name', 'certloom' ); ?></span><input type="text" name="program_title" maxlength="191" value="<?php echo esc_attr( $certloom_draft['program_title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Example: Internal Auditor Certification', 'certloom' ); ?>"></label>
					<div class="certloom-wizard-recommendation"><strong><?php esc_html_e( 'Recommended starting profile', 'certloom' ); ?></strong><p><?php echo esc_html( sprintf( /* translators: %1$s: purpose label; %2$s: subcategory label; %3$s: terminology. */ __( '%1$s · %2$s · %3$s', 'certloom' ), $wizard_recommendation['purpose_label'] ?? '', $wizard_recommendation['subcategory_label'] ?? '', $wizard_recommendation['terminology'] ?? '' ) ); ?></p><small><?php echo esc_html( $wizard_recommendation['notice'] ?? '' ); ?></small></div>
					<?php if ( ! empty( $wizard_recommended_fields ) ) : ?><div class="certloom-wizard-recommendation certloom-wizard-field-recommendation"><strong><?php esc_html_e( 'Suggested credential fields', 'certloom' ); ?></strong><p><?php esc_html_e( 'These are optional suggestions for your first Program. Nothing is added automatically.', 'certloom' ); ?></p><ul class="certloom-compact-list"><?php foreach ( $wizard_recommended_fields as $recommended_field ) : ?><li><strong><?php echo esc_html( $recommended_field['label'] ); ?></strong> <span class="description">· <?php echo esc_html( 'required' === $recommended_field['requirement'] ? __( 'Required', 'certloom' ) : __( 'Optional', 'certloom' ) ); ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
					<?php if ( ! $wizard_domain_preview ) : ?><div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Program setup is not available yet', 'certloom' ); ?></strong><p><?php esc_html_e( 'Certloom will keep this Program plan in your setup review and will not create anything until Programs are available on this site.', 'certloom' ); ?></p></div></div><?php else : ?><div class="certloom-wizard-connected-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-domain-programs' ) ); ?>"><?php esc_html_e( 'Open Programs', 'certloom' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-domain-batches' ) ); ?>"><?php esc_html_e( 'Batches', 'certloom' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-domain-issuers' ) ); ?>"><?php esc_html_e( 'Issuers', 'certloom' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-domain-signers' ) ); ?>"><?php esc_html_e( 'Signers', 'certloom' ); ?></a></div><p class="description"><?php esc_html_e( 'Batches, Issuers and Signers are optional setup pieces. Add them now if your Program needs them, or return later.', 'certloom' ); ?></p><?php endif; ?>
					<div class="certloom-wizard-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save & continue', 'certloom' ); ?></button></div>
				</form>

			<?php elseif ( 'design' === $wizard_step ) : ?>
				<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form">
					<?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="design"><input type="hidden" name="wizard_next_step" value="delivery">
					<div class="certloom-wizard-recommendation"><strong><?php esc_html_e( 'Suggested design direction', 'certloom' ); ?></strong><p><?php echo esc_html( $wizard_recommendation['design_tone'] ?? __( 'Clear and professional', 'certloom' ) ); ?></p></div>
					<?php if ( $wizard_domain_preview ) : ?>
						<label class="certloom-form-field certloom-form-field-wide"><span><?php esc_html_e( 'Default certificate design', 'certloom' ); ?></span>
							<select name="design_template_id" id="certloom-wizard-template" data-certloom-context-lookup="template" data-certloom-context-nonce="<?php echo esc_attr( $wizard_context_lookup_nonce ); ?>" data-certloom-context-empty-value="0" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search published Templates…', 'certloom' ); ?>">
								<option value="0" <?php selected( 0, $wizard_selected_template_id ); ?>><?php esc_html_e( 'None — choose designs per Program later', 'certloom' ); ?></option>
								<?php foreach ( $wizard_template_candidates as $candidate ) : $candidate_id = absint( $candidate['id'] ?? 0 ); if ( ! $candidate_id ) { continue; } ?><option value="<?php echo esc_attr( $candidate_id ); ?>" <?php selected( $candidate_id, $wizard_selected_template_id ); ?>><?php echo esc_html( (string) ( $candidate['name'] ?? $candidate['machine_key'] ?? $candidate_id ) ); ?></option><?php endforeach; ?>
							</select>
							<small><?php esc_html_e( 'This becomes the Global fallback. The inheritance chain is Global → Issuer → Program → Batch, and each more-specific selected design overrides the level before it.', 'certloom' ); ?></small>
						</label>
						<section class="certloom-wizard-template-preview" data-certloom-wizard-template-preview data-preview-nonce="<?php echo esc_attr( $wizard_template_preview_nonce ); ?>" aria-live="polite" aria-busy="false">
							<div class="certloom-wizard-template-preview-meta"><strong data-certloom-template-preview-name><?php echo esc_html( $wizard_selected_template_id && is_array( $wizard_template ) ? (string) ( $wizard_template['name'] ?? '' ) : __( 'No default design selected', 'certloom' ) ); ?></strong><p data-certloom-template-preview-description><?php echo esc_html( $wizard_selected_template_id && is_array( $wizard_template ) ? (string) ( $wizard_template['description'] ?? __( 'Published Template', 'certloom' ) ) : __( 'You can assign designs per Program, Batch or Issuer later.', 'certloom' ) ); ?></p></div>
							<div class="certloom-wizard-template-preview-canvas" data-certloom-template-preview-canvas><?php if ( $wizard_template_preview_error ) : ?><p class="notice notice-warning inline"><span><?php echo esc_html( $wizard_template_preview_error ); ?></span></p><?php else : ?><?php echo $wizard_template_preview_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Canonical internal RenderPlan presenter escapes every operation. ?><?php endif; ?></div>
						</section>
					<?php else : ?>
						<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Default design selection is protected', 'certloom' ); ?></strong><p><?php esc_html_e( 'You can browse and prepare Templates now, but the Wizard will not create a Template assignment until canonical Template administration is available.', 'certloom' ); ?></p></div></div>
					<?php endif; ?>
					<label class="certloom-form-field"><span><?php esc_html_e( 'Design note (optional)', 'certloom' ); ?></span><input type="text" name="design_note" maxlength="240" value="<?php echo esc_attr( $certloom_draft['design_note'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Example: formal blue certificate with organization logo', 'certloom' ); ?>"></label>
					<div class="certloom-wizard-connected-actions"><a class="button" href="<?php echo esc_url( $certloom_design_library_url ); ?>"><?php esc_html_e( 'Open designs', 'certloom' ); ?></a><a class="button" href="<?php echo esc_url( $certloom_create_design_url ); ?>"><?php esc_html_e( 'Add design', 'certloom' ); ?></a><?php if ( $certloom_edit_design_url ) : ?><a class="button button-primary" href="<?php echo esc_url( $certloom_edit_design_url ); ?>"><?php esc_html_e( 'Edit / publish selected design', 'certloom' ); ?></a><?php endif; ?></div>
					<div class="certloom-wizard-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save & continue', 'certloom' ); ?></button></div>
				</form>

			<?php elseif ( 'delivery' === $wizard_step ) : ?>
				<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form" data-certloom-numbering-form>
					<?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="delivery"><input type="hidden" name="wizard_next_step" value="backup">
					<div class="certloom-form-grid">
						<label class="certloom-form-field"><span><?php esc_html_e( 'Certificate numbering', 'certloom' ); ?></span><select name="numbering_mode" data-certloom-numbering-mode><option value="manual" <?php selected( $certloom_numbering, 'manual' ); ?>><?php esc_html_e( 'Manual', 'certloom' ); ?></option><option value="sequential" <?php selected( $certloom_numbering, 'sequential' ); ?>><?php esc_html_e( 'Sequential', 'certloom' ); ?></option><option value="random" <?php selected( $certloom_numbering, 'random' ); ?>><?php esc_html_e( 'Random', 'certloom' ); ?></option><option value="uuid" <?php selected( $certloom_numbering, 'uuid' ); ?>><?php esc_html_e( 'UUID', 'certloom' ); ?></option></select></label>
						<div class="certloom-form-field"><span><?php esc_html_e( 'Public verification', 'certloom' ); ?></span><label><input type="checkbox" name="verification_qr_enabled" value="1" <?php checked( $certloom_qr ); ?>> <?php esc_html_e( 'Show verification QR', 'certloom' ); ?></label><label><input type="checkbox" name="verification_barcode_enabled" value="1" <?php checked( $certloom_barcode ); ?>> <?php esc_html_e( 'Show Code 128 barcode', 'certloom' ); ?></label></div>
						<div class="certloom-numbering-format certloom-form-field-wide" data-certloom-numbering-format>
							<div class="certloom-form-grid certloom-form-grid-compact"><label class="certloom-form-field"><span><?php esc_html_e( 'Prefix', 'certloom' ); ?></span><input type="text" name="numbering_prefix" maxlength="32" value="<?php echo esc_attr( $certloom_numbering_prefix ); ?>" data-certloom-numbering-prefix></label><label class="certloom-form-field"><span><?php esc_html_e( 'Suffix', 'certloom' ); ?></span><input type="text" name="numbering_suffix" maxlength="32" value="<?php echo esc_attr( $certloom_numbering_suffix ); ?>" data-certloom-numbering-suffix></label><label class="certloom-form-field" data-certloom-sequential-only><span><?php esc_html_e( 'Starting number', 'certloom' ); ?></span><input type="number" name="numbering_start" min="1" max="999999999999" value="<?php echo esc_attr( $certloom_numbering_start ); ?>" data-certloom-numbering-start></label><label class="certloom-form-field" data-certloom-sequential-only><span><?php esc_html_e( 'Minimum digits', 'certloom' ); ?></span><input type="number" name="numbering_padding" min="1" max="12" value="<?php echo esc_attr( $certloom_numbering_padding ); ?>" data-certloom-numbering-padding></label></div>
							<div class="certloom-numbering-example"><span><?php esc_html_e( 'Example', 'certloom' ); ?></span><code data-certloom-numbering-example><?php echo esc_html( $certloom_numbering_proposed_label ); ?></code></div>
						</div>
						<div class="certloom-form-field certloom-form-field-wide certloom-wizard-review-reminders"><span><?php esc_html_e( 'Review the public experience', 'certloom' ); ?></span><p class="description"><?php esc_html_e( 'You can review a safe sample before any real credential exists. Testing a real issued credential is useful later, but it is not required to finish setup.', 'certloom' ); ?></p><div class="certloom-wizard-connected-actions"><a class="button" href="<?php echo esc_url( $certloom_sample_verification_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview sample result', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php if ( $certloom_page_id && get_permalink( $certloom_page_id ) ) : ?><a class="button" href="<?php echo esc_url( get_permalink( $certloom_page_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification page', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php endif; ?><?php if ( ! empty( $setup_summary['sample_cert_number'] ) && $certloom_setup_test_url ) : ?><a class="button" href="<?php echo esc_url( $certloom_setup_test_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Test real credential', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php endif; ?></div><label><input type="checkbox" name="appearance_reviewed" value="1" <?php checked( ! empty( $certloom_draft['appearance_reviewed'] ) || ! empty( $certloom_setup_state['appearance_reviewed'] ) ); ?>> <?php esc_html_e( 'I reviewed the verification appearance.', 'certloom' ); ?></label><label><input type="checkbox" name="verification_tested" value="1" <?php checked( ! empty( $certloom_draft['verification_tested'] ) || ! empty( $certloom_setup_state['verification_tested'] ) ); ?>> <?php esc_html_e( 'I tested an issued credential (optional).', 'certloom' ); ?></label></div>
					</div>
					<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Specialist options stay connected', 'certloom' ); ?></strong><p><?php esc_html_e( 'Privacy, Portal, lifecycle, language, delivery and integration details remain editable in their existing Settings, Program Profile and Integrations screens. The Wizard does not duplicate them.', 'certloom' ); ?></p></div></div>
					<div class="certloom-wizard-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save & continue', 'certloom' ); ?></button></div>
				</form>

			<?php elseif ( 'backup' === $wizard_step ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="certloom-wizard-google-connect-form" target="certloomGoogleDriveOAuth" data-certloom-google-popup-form><input type="hidden" name="action" value="certloom_google_drive_connect"><input type="hidden" name="certloom_google_return_mode" value="popup"><?php wp_nonce_field( 'certloom_google_drive_connect' ); ?></form>
				<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form">
					<?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="backup"><input type="hidden" name="wizard_next_step" value="review">
					<div class="certloom-form-grid"><label class="certloom-form-field"><span><?php esc_html_e( 'Automatic local backups', 'certloom' ); ?></span><select name="backup_schedule"><option value="off" <?php selected( $certloom_backup_schedule, 'off' ); ?>><?php esc_html_e( 'Off', 'certloom' ); ?></option><option value="daily" <?php selected( $certloom_backup_schedule, 'daily' ); ?>><?php esc_html_e( 'Daily', 'certloom' ); ?></option><option value="weekly" <?php selected( $certloom_backup_schedule, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'certloom' ); ?></option></select></label><label class="certloom-form-field"><span><?php esc_html_e( 'Keep scheduled backups', 'certloom' ); ?></span><input type="number" name="backup_retention" min="1" max="60" value="<?php echo esc_attr( $certloom_backup_retention ); ?>"><small><?php esc_html_e( 'Manual and safety backups are never silently removed by this setting.', 'certloom' ); ?></small></label></div>
					<section class="certloom-wizard-google-card" data-certloom-google-card>
						<div class="certloom-wizard-google-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Optional second copy', 'certloom' ); ?></span><h3><?php esc_html_e( 'Google Drive', 'certloom' ); ?></h3><p><?php esc_html_e( 'Keep verified backup copies in your own Google Drive. Local private storage remains the recovery source.', 'certloom' ); ?></p></div><span class="certloom-google-status <?php echo $certloom_google_connected ? 'is-connected' : 'is-disconnected'; ?>" data-certloom-google-status><?php echo esc_html( $certloom_google_connected ? __( 'Connected', 'certloom' ) : __( 'Not connected', 'certloom' ) ); ?></span></div>
						<?php if ( ! $certloom_google_connected ) : ?>
							<details class="certloom-google-advanced"><summary><?php esc_html_e( 'Use your own Google app', 'certloom' ); ?></summary><p class="description"><?php esc_html_e( 'Normally, Connect with Google uses Ecapla’s secure OAuth relay only for the Google authorization handshake. If your organization requires its own Google Cloud app, configure or reuse that site-owned fallback here.', 'certloom' ); ?></p><div class="certloom-form-grid"><label class="certloom-form-field"><span><?php esc_html_e( 'Google OAuth client ID', 'certloom' ); ?></span><input type="text" name="google_client_id" form="certloom-wizard-google-connect-form" value="<?php echo esc_attr( (string) ( $wizard_google_drive['client_id'] ?? '' ) ); ?>" autocomplete="off"></label><label class="certloom-form-field"><span><?php esc_html_e( 'Google OAuth client secret', 'certloom' ); ?></span><input type="password" name="google_client_secret" form="certloom-wizard-google-connect-form" autocomplete="new-password"><small><?php esc_html_e( 'Leave blank to reuse the securely stored secret when a site-owned app is already configured.', 'certloom' ); ?></small></label></div><p class="description"><strong><?php esc_html_e( 'Site-owned redirect URI:', 'certloom' ); ?></strong> <code><?php echo esc_html( (string) ( $wizard_google_drive['redirect_uri'] ?? '' ) ); ?></code></p><button class="button" type="submit" form="certloom-wizard-google-connect-form" name="google_connection_mode" value="direct" data-certloom-google-connect><?php echo esc_html( $wizard_google_client_ready ? __( 'Connect with site-owned Google app', 'certloom' ) : __( 'Save app & connect', 'certloom' ) ); ?></button></details>
							<button class="button certloom-google-connect-button" type="submit" form="certloom-wizard-google-connect-form" name="google_connection_mode" value="relay" data-certloom-google-connect><?php esc_html_e( 'Connect with Google', 'certloom' ); ?></button><span class="certloom-google-popup-feedback" role="status" aria-live="polite" data-certloom-google-popup-feedback></span>
						<?php else : ?><p class="certloom-google-connected-copy"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Google Drive is connected to this WordPress site.', 'certloom' ); ?></p><?php endif; ?>
						<div class="certloom-form-grid certloom-google-backup-preferences"><label class="certloom-form-field"><span><?php esc_html_e( 'Automatic Drive copies', 'certloom' ); ?></span><label><input type="checkbox" name="google_drive_enabled" value="1" data-certloom-google-enable <?php checked( $certloom_google_enabled ); ?> <?php disabled( ! $certloom_google_connected ); ?>> <?php esc_html_e( 'Copy scheduled backups to Google Drive', 'certloom' ); ?></label></label><label class="certloom-form-field"><span><?php esc_html_e( 'Keep Drive backups', 'certloom' ); ?></span><input type="number" name="google_drive_retention" min="1" max="60" value="<?php echo esc_attr( $certloom_google_retention ); ?>"><small><?php esc_html_e( 'Number of verified scheduled Drive copies to retain.', 'certloom' ); ?></small></label></div>
					</section>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-import' ) ); ?>"><?php esc_html_e( 'Open Backup & Restore', 'certloom' ); ?></a></p>
					<div class="certloom-wizard-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Review configuration', 'certloom' ); ?></button></div>
				</form>

			<?php else : ?>
				<?php
				$review_rows = array(
					array( __( 'Organization', 'certloom' ), (string) $certloom_setup_state['organization_name'], $certloom_organization, __( 'Administrator choice', 'certloom' ) ),
					array( __( 'Purpose', 'certloom' ), __( 'Not set separately', 'certloom' ), (string) ( $wizard_recommendation['purpose_label'] ?? '' ), __( 'Suggested from your setup purpose', 'certloom' ) ),
					array( __( 'First Program', 'certloom' ), $wizard_domain_preview ? __( 'Managed in Programs', 'certloom' ) : __( 'Program setup unavailable', 'certloom' ), (string) ( $certloom_draft['program_title'] ?? __( 'Not specified', 'certloom' ) ), __( 'Saved as a setup plan until Programs are available', 'certloom' ) ),
					array( __( 'Default certificate design', 'certloom' ), $certloom_current_template_name, $certloom_proposed_template_name, $wizard_domain_preview ? __( 'Used as the default design', 'certloom' ) : __( 'Design setup is not available yet', 'certloom' ) ),
					array( __( 'Certificate numbering', 'certloom' ), ucfirst( (string) $wizard_current_settings['numbering_mode'] ) . ' · ' . $certloom_numbering_current_label, ucfirst( $certloom_numbering ) . ' · ' . $certloom_numbering_proposed_label, __( 'Administrator choice', 'certloom' ) ),
					array( __( 'Verification QR', 'certloom' ), ! empty( $wizard_current_settings['verification_qr_enabled'] ) ? __( 'On', 'certloom' ) : __( 'Off', 'certloom' ), $certloom_qr ? __( 'On', 'certloom' ) : __( 'Off', 'certloom' ), __( 'Administrator choice', 'certloom' ) ),
					array( __( 'Code 128 barcode', 'certloom' ), ! empty( $wizard_current_settings['verification_barcode_enabled'] ) ? __( 'On', 'certloom' ) : __( 'Off', 'certloom' ), $certloom_barcode ? __( 'On', 'certloom' ) : __( 'Off', 'certloom' ), __( 'Administrator choice', 'certloom' ) ),
					array( __( 'Automatic backups', 'certloom' ), (string) $wizard_backup_settings['schedule'], $certloom_backup_schedule, __( 'Administrator choice', 'certloom' ) ),
					array( __( 'Backup retention', 'certloom' ), (string) $wizard_backup_settings['retention'], (string) $certloom_backup_retention, __( 'Administrator choice', 'certloom' ) ),
					array( __( 'Google Drive copies', 'certloom' ), ! empty( $wizard_google_drive['enabled'] ) ? __( 'On', 'certloom' ) : __( 'Off', 'certloom' ), $certloom_google_enabled ? __( 'On', 'certloom' ) : __( 'Off', 'certloom' ), $certloom_google_connected ? __( 'Connected Google Drive', 'certloom' ) : __( 'Connect Google Drive before enabling', 'certloom' ) ),
					array( __( 'Google Drive retention', 'certloom' ), (string) ( $wizard_google_drive['retention'] ?? 14 ), (string) $certloom_google_retention, __( 'Administrator choice', 'certloom' ) ),
				);
				?>
				<p><?php esc_html_e( 'Review current settings beside your proposed choices. Certloom recommendations are clearly separated and are never applied just because you selected a purpose.', 'certloom' ); ?></p>
				<div class="certloom-wizard-review-table-wrap"><table class="widefat striped certloom-wizard-review-table"><thead><tr><th><?php esc_html_e( 'Setting', 'certloom' ); ?></th><th><?php esc_html_e( 'Current', 'certloom' ); ?></th><th><?php esc_html_e( 'Proposed', 'certloom' ); ?></th><th><?php esc_html_e( 'Source', 'certloom' ); ?></th></tr></thead><tbody><?php foreach ( $review_rows as $row ) : ?><tr class="<?php echo (string) $row[1] !== (string) $row[2] ? 'has-change' : ''; ?>"><th scope="row"><?php echo esc_html( $row[0] ); ?></th><td><?php echo esc_html( '' === (string) $row[1] ? '—' : $row[1] ); ?></td><td><?php echo esc_html( '' === (string) $row[2] ? '—' : $row[2] ); ?></td><td><?php echo esc_html( $row[3] ); ?></td></tr><?php endforeach; ?></tbody></table></div>
				<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-shield" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Only ready settings will be applied', 'certloom' ); ?></strong><p><?php esc_html_e( 'This review applies only the settings shown above. Design, Google Drive, credential downloads and other protected capabilities keep their normal availability and safety checks.', 'certloom' ); ?></p></div></div>
				<form method="post" id="certloom-wizard-review-form" class="certloom-wizard-form"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="apply_review"><div class="certloom-wizard-actions"><button class="button button-primary button-hero" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Apply the reviewed setup changes now?', 'certloom' ); ?>"><?php esc_html_e( 'Apply reviewed settings', 'certloom' ); ?></button></div></form>
			<?php endif; ?>

			<?php if ( 'review' !== $wizard_step ) : ?>
				<div class="certloom-wizard-footer-actions">
					<?php if ( $certloom_prev_step ) : ?><a class="button" href="<?php echo esc_url( $certloom_step_url( $certloom_prev_step ) ); ?>"><?php esc_html_e( 'Back', 'certloom' ); ?></a><?php endif; ?>
					<form method="post"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="skip_step"><input type="hidden" name="wizard_step" value="<?php echo esc_attr( $wizard_step ); ?>"><input type="hidden" name="wizard_next_step" value="<?php echo esc_attr( $certloom_next_step ); ?>"><button type="submit" class="button button-link"><?php esc_html_e( 'Skip for now', 'certloom' ); ?></button></form>
					<button type="submit" class="button-link certloom-wizard-save-exit" form="certloom-wizard-step-form" name="wizard_finish_later" value="1"><?php esc_html_e( 'Save & finish later', 'certloom' ); ?></button>
				</div>
			<?php endif; ?>
		</main>

		<aside class="certloom-panel certloom-wizard-summary" aria-labelledby="certloom-wizard-summary-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Your setup', 'certloom' ); ?></span><h2 id="certloom-wizard-summary-title"><?php esc_html_e( 'Recommendations', 'certloom' ); ?></h2></div></div>
			<dl class="certloom-wizard-summary-list"><div><dt><?php esc_html_e( 'Purpose', 'certloom' ); ?></dt><dd data-certloom-wizard-summary-purpose><?php echo esc_html( $wizard_recommendation['purpose_label'] ?? __( 'Custom / Other', 'certloom' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Type', 'certloom' ); ?></dt><dd data-certloom-wizard-summary-type><?php echo esc_html( $wizard_recommendation['subcategory_label'] ?? __( 'Custom', 'certloom' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Terminology', 'certloom' ); ?></dt><dd data-certloom-wizard-summary-terminology><?php echo esc_html( $wizard_recommendation['terminology'] ?? __( 'Credential', 'certloom' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Design direction', 'certloom' ); ?></dt><dd data-certloom-wizard-summary-design><?php echo esc_html( $wizard_recommendation['design_tone'] ?? __( 'Clear and professional', 'certloom' ) ); ?></dd></div></dl>
			<p class="description"><?php esc_html_e( 'Recommendations are editable starting points. They do not assert accreditation, legal compliance, certification status, or identical rules across standards.', 'certloom' ); ?></p>
			<?php if ( $certloom_wizard_completed ) : ?><form method="post" class="certloom-wizard-restart"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="restart"><button class="button" type="submit"><?php esc_html_e( 'Review setup again', 'certloom' ); ?></button><p class="description"><?php esc_html_e( 'Starts a fresh draft. Existing live settings stay unchanged until you review and apply a new configuration.', 'certloom' ); ?></p></form><?php endif; ?>
		</aside>
	</div>
</div>
