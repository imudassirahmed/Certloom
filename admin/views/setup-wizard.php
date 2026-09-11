<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped to the rendering method.
$certloom_draft = is_array( $wizard_draft ) ? $wizard_draft : array();
$certloom_step_keys = array_keys( $wizard_steps );
$certloom_step_index = array_search( $wizard_step, $certloom_step_keys, true );
$certloom_step_index = false === $certloom_step_index ? 0 : (int) $certloom_step_index;
$certloom_next_step = isset( $certloom_step_keys[ $certloom_step_index + 1 ] ) ? $certloom_step_keys[ $certloom_step_index + 1 ] : 'finish';
$certloom_prev_step = $certloom_step_index > 0 ? $certloom_step_keys[ $certloom_step_index - 1 ] : '';
$certloom_step_url = static function ( $step ) { return add_query_arg( array( 'page' => 'certloom-setup', 'step' => sanitize_key( $step ) ), admin_url( 'admin.php' ) ); };
$certloom_profile = isset( $wizard_draft['usage_profile'] ) ? $wizard_engine->normalize_profile( $wizard_draft['usage_profile'] ) : 'general_other';
$certloom_organization = array_key_exists( 'organization_name', $certloom_draft ) ? (string) $certloom_draft['organization_name'] : (string) ( $setup_summary['state']['organization_name'] ?? '' );
$certloom_organization_website = (string) ( $certloom_draft['organization_website'] ?? '' );
$certloom_organization_contact = (string) ( $certloom_draft['organization_contact'] ?? '' );
$certloom_issue_date = (string) ( $certloom_draft['issue_date'] ?? '' );
if ( '' === $certloom_issue_date ) { $certloom_issue_date = current_time( 'Y-m-d' ); }
$certloom_first_credential_id = absint( $certloom_draft['first_credential_id'] ?? 0 );
$certloom_first_credential_url = (string) ( $certloom_draft['first_credential_url'] ?? '' );
$certloom_hierarchy = is_array( $wizard_recommendation['hierarchy'] ?? null ) ? $wizard_recommendation['hierarchy'] : array();
$certloom_checklist = is_array( $wizard_recommendation['checklist'] ?? null ) ? $wizard_recommendation['checklist'] : array();
$certloom_progress_steps = array( 'profile', 'organization', 'fields', 'appearance', 'issue', 'finish' );
$certloom_completed_steps = is_array( $wizard_state['wizard_completed_steps'] ?? null ) ? $wizard_state['wizard_completed_steps'] : array();
$certloom_skipped_steps = is_array( $wizard_state['wizard_skipped_steps'] ?? null ) ? $wizard_state['wizard_skipped_steps'] : array();
$certloom_dashboard_url = admin_url( 'admin.php?page=' . Ecapla_CertVerify_Admin::DASHBOARD_PAGE );
$certloom_issue_url = admin_url( 'admin.php?page=certloom-issuance' );
?>
<div class="certloom-setup-v2 certloom-admin-shell" data-certloom-setup-v2 data-certloom-step="<?php echo esc_attr( $wizard_step ); ?>">
	<header class="certloom-setup-v2-topbar">
		<a class="certloom-setup-v2-brand" href="<?php echo esc_url( $certloom_dashboard_url ); ?>" aria-label="<?php esc_attr_e( 'Certloom Dashboard', 'certloom' ); ?>">
			<img class="certloom-setup-v2-brand-symbol" src="<?php echo esc_url( ECAPLA_CERTVERIFY_URL . 'admin/assets/certloom-brand-mark.png' ); ?>" alt="">
			<img class="certloom-setup-v2-brand-wordmark" src="<?php echo esc_url( ECAPLA_CERTVERIFY_URL . 'admin/assets/certloom-brand-wordmark.png' ); ?>" alt="">
		</a>
		<a class="button certloom-setup-v2-exit" href="<?php echo esc_url( $certloom_dashboard_url ); ?>"><?php esc_html_e( 'Exit setup', 'certloom' ); ?></a>
	</header>

	<?php if ( 'welcome' !== $wizard_step ) : ?>
		<nav class="certloom-setup-v2-progress" aria-label="<?php esc_attr_e( 'Setup progress', 'certloom' ); ?>">
			<?php foreach ( $certloom_progress_steps as $index => $step_key ) :
				$is_current = $step_key === $wizard_step;
				$is_done = in_array( $step_key, $certloom_completed_steps, true ) || ( 'finish' === $step_key && $certloom_first_credential_id );
				$is_skipped = ! $is_current && in_array( $step_key, $certloom_skipped_steps, true );
				$can_visit = $is_done || $is_current || array_search( $step_key, $certloom_progress_steps, true ) < array_search( $wizard_step, $certloom_progress_steps, true );
				$progress_class = $is_current ? 'is-current' : ( $is_skipped ? 'is-skipped' : ( $is_done ? 'is-done' : '' ) );
			?>
				<?php if ( $can_visit ) : ?><a class="certloom-setup-v2-progress-step <?php echo esc_attr( $progress_class ); ?>" href="<?php echo esc_url( $certloom_step_url( $step_key ) ); ?>" <?php echo $is_current ? 'aria-current="step"' : ''; ?>><?php else : ?><span class="certloom-setup-v2-progress-step"><?php endif; ?>
					<span class="certloom-setup-v2-progress-number"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<span class="certloom-setup-v2-progress-label"><?php echo esc_html( $wizard_steps[ $step_key ] ); ?></span>
				<?php echo $can_visit ? '</a>' : '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup selected above. ?>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<div class="certloom-setup-v2-notices" role="region" aria-label="<?php esc_attr_e( 'Setup notices', 'certloom' ); ?>">
		<?php if ( isset( $_GET['certloom_wizard_saved'] ) ) : ?><div class="notice notice-info inline certloom-setup-v2-notice"><p><span class="dashicons dashicons-saved" aria-hidden="true"></span><?php esc_html_e( 'Setup progress saved.', 'certloom' ); ?></p></div><?php endif; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice. ?>
		<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error notice. ?>
	</div>

	<main class="certloom-setup-v2-main">
		<?php if ( 'welcome' === $wizard_step ) : ?>
			<section class="certloom-setup-v2-welcome" aria-labelledby="certloom-setup-v2-title">
				<div class="certloom-setup-v2-welcome-mark" aria-hidden="true"><img src="<?php echo esc_url( ECAPLA_CERTVERIFY_URL . 'admin/assets/certloom-brand-mark.png' ); ?>" alt=""></div>
				<p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Certloom setup', 'certloom' ); ?></p>
				<h1 id="certloom-setup-v2-title"><?php esc_html_e( 'Set up your first credential.', 'certloom' ); ?></h1>
				<p class="certloom-setup-v2-lede"><?php esc_html_e( 'Choose how you use Certloom, pick the fields and appearance you need, then prepare your first real credential. Programs, Batches and Signers are optional power tools, not setup requirements.', 'certloom' ); ?></p>
				<ul class="certloom-setup-v2-welcome-points">
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Your choices are resumable.', 'certloom' ); ?></li>
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Nothing is auto-created behind your back.', 'certloom' ); ?></li>
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'You can change advanced settings later.', 'certloom' ); ?></li>
				</ul>
				<form method="post" class="certloom-setup-v2-welcome-action"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="welcome"><input type="hidden" name="wizard_next_step" value="profile"><button class="button button-primary button-hero" type="submit"><?php esc_html_e( 'Start setup', 'certloom' ); ?></button></form>
			</section>

		<?php elseif ( 'profile' === $wizard_step ) : ?>
			<section class="certloom-setup-v2-step" aria-labelledby="certloom-setup-v2-title">
				<div class="certloom-setup-v2-heading"><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Step 1 of 6', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'What are you using Certloom for?', 'certloom' ); ?></h1><p><?php esc_html_e( 'This changes recommended fields, examples and help. It does not create a different product mode or hide any capability.', 'certloom' ); ?></p></div>
				<form method="post" id="certloom-wizard-step-form" class="certloom-setup-v2-form" data-certloom-profile-form><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="profile"><input type="hidden" name="wizard_next_step" value="organization">
					<div class="certloom-setup-v2-profile-grid">
						<?php foreach ( $wizard_profiles as $key => $profile ) : $profile_rec = $wizard_engine->recommend( $key ); $profile_hierarchy = is_wp_error( $profile_rec ) ? array() : (array) ( $profile_rec['hierarchy'] ?? array() ); ?><label class="certloom-setup-v2-profile-card" data-certloom-profile-card data-hierarchy-issuer="<?php echo esc_attr( $profile_hierarchy['issuer'] ?? '' ); ?>" data-hierarchy-program="<?php echo esc_attr( $profile_hierarchy['program'] ?? '' ); ?>" data-hierarchy-batch="<?php echo esc_attr( $profile_hierarchy['batch'] ?? '' ); ?>" data-hierarchy-signer="<?php echo esc_attr( $profile_hierarchy['signer'] ?? '' ); ?>"><input type="radio" name="usage_profile" value="<?php echo esc_attr( $key ); ?>" <?php checked( $certloom_profile, $key ); ?> required><span class="dashicons <?php echo esc_attr( $profile['icon'] ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $profile['label'] ); ?></strong><span><?php echo esc_html( $profile['description'] ); ?></span></label><?php endforeach; ?>
					</div>
					<details class="certloom-setup-v2-model-help" data-certloom-profile-help><summary><?php esc_html_e( 'How Certloom organizes this profile', 'certloom' ); ?></summary><div data-certloom-profile-hierarchy>
						<p><strong><?php esc_html_e( 'Issuer', 'certloom' ); ?></strong> — <span data-certloom-hierarchy="issuer"><?php echo esc_html( $certloom_hierarchy['issuer'] ?? '' ); ?></span>.</p>
						<p><strong><?php esc_html_e( 'Program', 'certloom' ); ?></strong> — <span data-certloom-hierarchy="program"><?php echo esc_html( $certloom_hierarchy['program'] ?? '' ); ?></span>.</p>
						<p><strong><?php esc_html_e( 'Batch', 'certloom' ); ?></strong> — <span data-certloom-hierarchy="batch"><?php echo esc_html( $certloom_hierarchy['batch'] ?? '' ); ?></span>.</p>
						<p><strong><?php esc_html_e( 'Signer', 'certloom' ); ?></strong> — <span data-certloom-hierarchy="signer"><?php echo esc_html( $certloom_hierarchy['signer'] ?? '' ); ?></span>.</p>
					</div></details>
					<div class="certloom-setup-v2-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Continue', 'certloom' ); ?></button><button class="button button-link certloom-setup-v2-skip" type="submit" name="certloom_setup_wizard_action" value="skip_step" formnovalidate><?php esc_html_e( 'Skip for now', 'certloom' ); ?></button></div>
				</form>
			</section>

		<?php elseif ( 'organization' === $wizard_step ) : ?>
			<section class="certloom-setup-v2-step" aria-labelledby="certloom-setup-v2-title">
				<div class="certloom-setup-v2-heading"><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Step 2 of 6', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'Your organization', 'certloom' ); ?></h1><p><?php esc_html_e( 'This becomes your default organization identity. You can add separate Issuers later.', 'certloom' ); ?></p></div>
				<form method="post" id="certloom-wizard-step-form" class="certloom-setup-v2-form"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="organization"><input type="hidden" name="wizard_next_step" value="fields">
					<label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Organization name', 'certloom' ); ?></span><input type="text" name="organization_name" value="<?php echo esc_attr( $certloom_organization ); ?>" maxlength="200" autocomplete="organization" required></label>
					<div class="certloom-setup-v2-field"><span><?php esc_html_e( 'Logo', 'certloom' ); ?></span><div class="certloom-setup-v2-media certloom-setup-v2-organization-logo" data-certloom-media-control data-certloom-media-types="image/png,image/jpeg,image/webp"><input type="hidden" name="organization_logo_id" value="<?php echo esc_attr( $wizard_organization_logo_id ); ?>" data-certloom-media-id><div class="certloom-setup-v2-media-preview" data-certloom-media-preview role="status" aria-live="polite"><?php if ( $wizard_organization_logo_url ) : ?><img src="<?php echo esc_url( $wizard_organization_logo_url ); ?>" alt=""><span><?php esc_html_e( 'Organization logo selected', 'certloom' ); ?></span><?php else : ?><span><?php esc_html_e( 'No logo selected', 'certloom' ); ?></span><?php endif; ?></div><div class="certloom-setup-v2-media-actions"><button type="button" class="button" data-certloom-media-select aria-haspopup="dialog"><?php esc_html_e( 'Choose logo', 'certloom' ); ?></button><button type="button" class="button-link certloom-setup-v2-remove-media" data-certloom-media-clear <?php echo $wizard_organization_logo_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'certloom' ); ?></button></div></div></div>
					<div class="certloom-setup-v2-field-row"><label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Website', 'certloom' ); ?> <small><?php esc_html_e( 'Optional', 'certloom' ); ?></small></span><input type="url" name="organization_website" value="<?php echo esc_attr( $certloom_organization_website ); ?>" autocomplete="url"></label><label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Contact', 'certloom' ); ?> <small><?php esc_html_e( 'Optional', 'certloom' ); ?></small></span><input type="text" name="organization_contact" value="<?php echo esc_attr( $certloom_organization_contact ); ?>" maxlength="240"></label></div>
					<div class="certloom-setup-v2-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Continue', 'certloom' ); ?></button><button class="button button-link certloom-setup-v2-skip" type="submit" name="certloom_setup_wizard_action" value="skip_step" formnovalidate><?php esc_html_e( 'Skip for now', 'certloom' ); ?></button></div>
				</form>
			</section>

		<?php elseif ( 'fields' === $wizard_step ) : ?>
			<section class="certloom-setup-v2-step" aria-labelledby="certloom-setup-v2-title">
				<div class="certloom-setup-v2-heading"><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Step 3 of 6', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'Choose credential fields', 'certloom' ); ?></h1><p><?php echo esc_html( sprintf( /* translators: %s: profile name. */ __( 'Recommended for %s. Uncheck anything you do not need, or add your own field.', 'certloom' ), $wizard_recommendation['profile_label'] ?? __( 'this profile', 'certloom' ) ) ); ?></p></div>
				<form method="post" id="certloom-wizard-step-form" class="certloom-setup-v2-form" data-certloom-fields-form><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="fields"><input type="hidden" name="wizard_next_step" value="appearance">
					<div class="certloom-setup-v2-field-options">
						<?php foreach ( (array) ( $wizard_recommendation['recommended_fields'] ?? array() ) as $field ) : $field_key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $field_key ) { continue; } ?><label><input type="checkbox" name="selected_fields[]" value="<?php echo esc_attr( $field_key ); ?>" <?php checked( in_array( $field_key, $wizard_selected_fields, true ) ); ?>><span><?php echo esc_html( $field['label'] ?? $field_key ); ?></span></label><?php endforeach; ?>
					</div>
					<div class="certloom-setup-v2-custom-fields" data-certloom-custom-fields>
						<h2><?php esc_html_e( 'Custom fields', 'certloom' ); ?></h2>
						<div data-certloom-custom-field-list><?php foreach ( $wizard_custom_fields as $custom_field ) : ?><div class="certloom-setup-v2-custom-field"><input type="text" name="custom_fields[]" value="<?php echo esc_attr( $custom_field ); ?>" maxlength="100"><button type="button" class="button-link" data-certloom-custom-field-remove><?php esc_html_e( 'Remove', 'certloom' ); ?></button></div><?php endforeach; ?></div>
						<div class="certloom-setup-v2-custom-field-add"><input type="text" data-certloom-custom-field-new maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Accreditation reference', 'certloom' ); ?>"><button type="button" class="button" data-certloom-custom-field-add><?php esc_html_e( 'Add field', 'certloom' ); ?></button></div>
						<p class="description"><?php esc_html_e( 'Setup saves this as field intent for now. Field-definition persistence is completed with the Setup v2 backend phase.', 'certloom' ); ?></p>
					</div>
					<div class="certloom-setup-v2-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Continue', 'certloom' ); ?></button><button class="button button-link certloom-setup-v2-skip" type="submit" name="certloom_setup_wizard_action" value="skip_step" formnovalidate><?php esc_html_e( 'Skip for now', 'certloom' ); ?></button></div>
				</form>
			</section>

		<?php elseif ( 'appearance' === $wizard_step ) : ?>
			<section class="certloom-setup-v2-step" aria-labelledby="certloom-setup-v2-title">
				<div class="certloom-setup-v2-heading"><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Step 4 of 6', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'How should credentials appear?', 'certloom' ); ?></h1><p><?php esc_html_e( 'Certificate artwork is optional. A details-only credential is still a real, verifiable credential.', 'certloom' ); ?></p></div>
				<form method="post" id="certloom-wizard-step-form" class="certloom-setup-v2-form" data-certloom-appearance-form><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="appearance"><input type="hidden" name="wizard_next_step" value="issue">
					<div class="certloom-setup-v2-presentation-grid">
						<label><input type="radio" name="presentation_mode" value="details_only" <?php checked( 'details_only', $wizard_presentation_mode ); ?>><strong><?php esc_html_e( 'Details only', 'certloom' ); ?></strong><span><?php esc_html_e( 'Show credential details, verification and status without certificate artwork.', 'certloom' ); ?></span></label>
						<label><input type="radio" name="presentation_mode" value="image" <?php checked( 'image', $wizard_presentation_mode ); ?>><strong><?php esc_html_e( 'Upload a certificate image', 'certloom' ); ?></strong><span><?php esc_html_e( 'Use a ready-made PNG or JPEG instead of a reusable Certloom design.', 'certloom' ); ?></span></label>
						<label><input type="radio" name="presentation_mode" value="design" <?php checked( 'design', $wizard_presentation_mode ); ?>><strong><?php esc_html_e( 'Use a Certloom design', 'certloom' ); ?></strong><span><?php esc_html_e( 'Choose a reusable published design and customize it later if needed.', 'certloom' ); ?></span></label>
					</div>
					<section class="certloom-setup-v2-presentation-panel" data-certloom-presentation-panel="image" <?php echo 'image' === $wizard_presentation_mode ? '' : 'hidden'; ?>><h2><?php esc_html_e( 'Certificate image', 'certloom' ); ?></h2><div class="certloom-setup-v2-media certloom-certificate-image-control" data-certloom-media-control data-certloom-media-types="image/png,image/jpeg"><input type="hidden" name="certificate_image_id" value="<?php echo esc_attr( $wizard_certificate_image_id ); ?>" data-certloom-media-id><div class="certloom-setup-v2-media-preview" data-certloom-media-preview role="status" aria-live="polite"><?php if ( $wizard_certificate_image_url ) : ?><img src="<?php echo esc_url( $wizard_certificate_image_url ); ?>" alt=""><span><?php esc_html_e( 'Certificate image selected', 'certloom' ); ?></span><?php else : ?><span><?php esc_html_e( 'No certificate image selected', 'certloom' ); ?></span><?php endif; ?></div><div class="certloom-setup-v2-media-actions"><button type="button" class="button" data-certloom-media-select aria-haspopup="dialog"><?php esc_html_e( 'Choose PNG/JPEG', 'certloom' ); ?></button><button type="button" class="button-link certloom-setup-v2-remove-media" data-certloom-media-clear <?php echo $wizard_certificate_image_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'certloom' ); ?></button></div></div></section>
					<section class="certloom-setup-v2-presentation-panel" data-certloom-presentation-panel="design" <?php echo 'design' === $wizard_presentation_mode ? '' : 'hidden'; ?>><div class="certloom-setup-v2-panel-head"><div><h2><?php esc_html_e( 'Choose a design', 'certloom' ); ?></h2><p><?php esc_html_e( 'Certloom includes 10 starter designs for setup. More designs may be available in Design Center; publish any design there to use it here later. You can also create your own certificate design with Certloom Designer.', 'certloom' ); ?></p></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-templates&design_view=templates' ) ); ?>"><?php esc_html_e( 'Open Design Center', 'certloom' ); ?></a></div>
						<?php if ( $wizard_template_candidates ) : ?><div class="certloom-setup-v2-design-picker"><select name="design_template_id" id="certloom-wizard-template"><option value="0"><?php esc_html_e( 'Choose a published design', 'certloom' ); ?></option><?php foreach ( $wizard_template_candidates as $candidate ) : ?><option value="<?php echo esc_attr( absint( $candidate['id'] ?? 0 ) ); ?>" <?php selected( $wizard_selected_template_id, absint( $candidate['id'] ?? 0 ) ); ?>><?php echo esc_html( (string) ( $candidate['name'] ?? __( 'Untitled design', 'certloom' ) ) ); ?></option><?php endforeach; ?></select></div><?php else : ?><p class="certloom-setup-v2-empty-inline"><?php esc_html_e( 'No published starter designs are available in this review build yet. You can continue with Details only or upload an image, then publish a design from Design Center later.', 'certloom' ); ?></p><?php endif; ?>
						<div class="certloom-wizard-template-preview" data-certloom-wizard-template-preview data-preview-nonce="<?php echo esc_attr( $wizard_template_preview_nonce ); ?>"><div class="certloom-wizard-template-preview-meta"><strong data-certloom-template-preview-name><?php echo esc_html( is_array( $wizard_template ) ? (string) ( $wizard_template['name'] ?? '' ) : __( 'No design selected', 'certloom' ) ); ?></strong><p data-certloom-template-preview-description><?php echo esc_html( $wizard_template_preview_error ); ?></p></div><div class="certloom-wizard-template-preview-canvas" data-certloom-template-preview-canvas><?php echo wp_kses_post( $wizard_template_preview_html ); ?></div></div>
					</section>
					<div class="certloom-setup-v2-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Continue', 'certloom' ); ?></button><button class="button button-link certloom-setup-v2-skip" type="submit" name="certloom_setup_wizard_action" value="skip_step" formnovalidate><?php esc_html_e( 'Skip for now', 'certloom' ); ?></button></div>
				</form>
			</section>

		<?php elseif ( 'issue' === $wizard_step ) : ?>
			<section class="certloom-setup-v2-step" aria-labelledby="certloom-setup-v2-title">
				<div class="certloom-setup-v2-heading"><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Step 5 of 6', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'Prepare your first credential', 'certloom' ); ?></h1><p><?php esc_html_e( 'Only the essentials are required here. Programs, Batches, Issuers and Signers stay out of the required first-run path.', 'certloom' ); ?></p></div>
				<form method="post" id="certloom-wizard-step-form" class="certloom-setup-v2-form certloom-setup-v2-issue-form"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="issue"><input type="hidden" name="wizard_next_step" value="finish">
					<label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Recipient', 'certloom' ); ?></span><input type="text" name="first_recipient_name" value="<?php echo esc_attr( (string) ( $certloom_draft['first_recipient_name'] ?? '' ) ); ?>" autocomplete="name" required placeholder="<?php esc_attr_e( 'Person or organization name', 'certloom' ); ?>"></label>
					<label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Recipient email', 'certloom' ); ?> <small><?php esc_html_e( 'Optional', 'certloom' ); ?></small></span><input type="email" name="first_recipient_email" value="<?php echo esc_attr( (string) ( $certloom_draft['first_recipient_email'] ?? '' ) ); ?>" autocomplete="email"></label>
					<label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Credential title', 'certloom' ); ?></span><input type="text" name="credential_title" value="<?php echo esc_attr( (string) ( $certloom_draft['credential_title'] ?? '' ) ); ?>" maxlength="191" required placeholder="<?php esc_attr_e( 'e.g. ISO 9001 Certification', 'certloom' ); ?>"></label>
					<label class="certloom-setup-v2-field"><span><?php esc_html_e( 'Issue date', 'certloom' ); ?></span><input type="date" name="issue_date" value="<?php echo esc_attr( $certloom_issue_date ); ?>" required></label>
					<div class="certloom-setup-v2-issue-summary"><div><span><?php esc_html_e( 'Profile', 'certloom' ); ?></span><strong><?php echo esc_html( $wizard_recommendation['profile_label'] ?? '' ); ?></strong></div><div><span><?php esc_html_e( 'Presentation', 'certloom' ); ?></span><strong><?php echo esc_html( 'image' === $wizard_presentation_mode ? __( 'Certificate image', 'certloom' ) : ( 'design' === $wizard_presentation_mode ? __( 'Certloom design', 'certloom' ) : __( 'Details only', 'certloom' ) ) ); ?></strong></div></div>
					<?php if ( ! $wizard_first_issuance_ready ) : ?><div class="certloom-callout certloom-callout-info certloom-setup-v2-backend-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Standalone issuance connection is still being implemented', 'certloom' ); ?></strong><p><?php echo esc_html( $wizard_first_issuance_reason ); ?></p><p><a href="<?php echo esc_url( $certloom_issue_url ); ?>"><?php esc_html_e( 'Open the full Issue credential screen', 'certloom' ); ?></a></p></div></div><?php endif; ?>
					<div class="certloom-setup-v2-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save first credential draft', 'certloom' ); ?></button><button class="button button-link certloom-setup-v2-skip" type="submit" name="certloom_setup_wizard_action" value="skip_step" formnovalidate><?php esc_html_e( 'Do this later', 'certloom' ); ?></button></div>
				</form>
			</section>

		<?php else : ?>
			<section class="certloom-setup-v2-step certloom-setup-v2-finish" aria-labelledby="certloom-setup-v2-title">
				<?php if ( $certloom_first_credential_id ) : ?>
					<div class="certloom-setup-v2-finish-mark is-ready"><span class="dashicons dashicons-yes" aria-hidden="true"></span></div><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Setup complete', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'Your first credential is ready.', 'certloom' ); ?></h1><p><?php esc_html_e( 'The credential record is authoritative. Continue with the next setup tasks that matter for your profile.', 'certloom' ); ?></p>
					<?php if ( $certloom_first_credential_url ) : ?><div class="certloom-setup-v2-copy-row"><input type="text" readonly value="<?php echo esc_attr( $certloom_first_credential_url ); ?>" aria-label="<?php esc_attr_e( 'Verification URL', 'certloom' ); ?>"><button type="button" class="button" data-copy-text="<?php echo esc_attr( $certloom_first_credential_url ); ?>"><?php esc_html_e( 'Copy link', 'certloom' ); ?></button><a class="button button-primary" href="<?php echo esc_url( $certloom_first_credential_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View credential', 'certloom' ); ?></a></div><?php endif; ?>
					<form method="post" class="certloom-setup-v2-restart"><?php wp_nonce_field( 'certloom_setup_wizard' ); ?><input type="hidden" name="certloom_setup_wizard_action" value="restart"><button type="submit" class="button"><?php esc_html_e( 'Run setup again', 'certloom' ); ?></button></form>
				<?php else : ?>
					<div class="certloom-setup-v2-finish-mark"><span class="dashicons dashicons-edit-page" aria-hidden="true"></span></div><p class="certloom-setup-v2-kicker"><?php esc_html_e( 'Setup draft saved', 'certloom' ); ?></p><h1 id="certloom-setup-v2-title"><?php esc_html_e( 'One required step remains: issue the first real credential.', 'certloom' ); ?></h1><p><?php esc_html_e( 'Your profile, organization, fields, appearance and first-credential draft are saved. Certloom has not created placeholder hierarchy records or recorded onboarding as finished.', 'certloom' ); ?></p><div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Why one required step remains', 'certloom' ); ?></strong><p><?php echo esc_html( $wizard_first_issuance_reason ); ?></p></div></div><div class="certloom-setup-v2-actions"><a class="button button-primary" href="<?php echo esc_url( $certloom_step_url( 'issue' ) ); ?>"><?php esc_html_e( 'Back to first credential', 'certloom' ); ?></a><a class="button" href="<?php echo esc_url( $certloom_dashboard_url ); ?>"><?php esc_html_e( 'Continue to Dashboard for now', 'certloom' ); ?></a></div>
				<?php endif; ?>
				<?php if ( $certloom_checklist ) : ?><div class="certloom-setup-v2-next"><h2><?php esc_html_e( 'Recommended next steps', 'certloom' ); ?></h2><ul><?php foreach ( $certloom_checklist as $item ) : ?><li><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
			</section>
		<?php endif; ?>
	</main>

	<?php if ( ! in_array( $wizard_step, array( 'welcome', 'finish' ), true ) ) : ?>
		<footer class="certloom-setup-v2-footer">
			<div><?php if ( $certloom_prev_step ) : ?><a class="button" href="<?php echo esc_url( $certloom_step_url( $certloom_prev_step ) ); ?>"><?php esc_html_e( 'Back', 'certloom' ); ?></a><?php endif; ?></div>
			<button type="submit" class="button-link certloom-setup-v2-save-exit" form="certloom-wizard-step-form" name="wizard_finish_later" value="1"><?php esc_html_e( 'Save & finish later', 'certloom' ); ?></button>
		</footer>
	<?php endif; ?>
</div>
