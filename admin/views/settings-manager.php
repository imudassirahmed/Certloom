<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
$certloom_show_diagnostics = current_user_can( 'manage_options' );
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-settings-workspace">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<section class="certloom-page-hero certloom-settings-hero" aria-labelledby="certloom-settings-title">
		<div><span class="certloom-eyebrow"><?php esc_html_e( 'Configuration', 'certloom' ); ?></span><h1 id="certloom-settings-title"><?php esc_html_e( 'Settings', 'certloom' ); ?></h1><p><?php esc_html_e( 'Control access, numbering, branding, verification, retention and operational guidance from one place.', 'certloom' ); ?></p></div>
	</section>
	<div class="certloom-settings-tools">
		<label class="certloom-settings-search-label" for="certloom-settings-search"><span><?php esc_html_e( 'Find a setting', 'certloom' ); ?></span><input id="certloom-settings-search" type="search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search settings sections', 'certloom' ); ?>" data-empty-label="<?php esc_attr_e( 'No settings sections match.', 'certloom' ); ?>" /></label>
		<nav class="certloom-settings-local-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'certloom' ); ?>">
			<a href="#certloom-settings-access"><?php esc_html_e( 'Access', 'certloom' ); ?></a>
			<a href="#certloom-settings-numbering"><?php esc_html_e( 'Numbering', 'certloom' ); ?></a>
			<a href="#certloom-settings-branding"><?php esc_html_e( 'Brand', 'certloom' ); ?></a>
			<a href="#certloom-settings-verification"><?php esc_html_e( 'Verification', 'certloom' ); ?></a>
			<a href="#certloom-settings-retention"><?php esc_html_e( 'Retention', 'certloom' ); ?></a>
			<a href="#certloom-settings-guidance"><?php esc_html_e( 'Guidance & support', 'certloom' ); ?></a>
			<?php if ( $certloom_show_diagnostics ) : ?><a href="#certloom-system-status"><?php esc_html_e( 'Diagnostics', 'certloom' ); ?></a><?php endif; ?>
		</nav>
		<div id="certloom-settings-search-results" class="certloom-settings-search-results" aria-live="polite" hidden></div>
	</div>

	<?php if ( isset( $_GET['ecapla_certverify_settings_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'certloom' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_role_created'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Certificate Manager role created with least-privilege access.', 'certloom' ); ?></p></div><?php endif; ?>

	<?php if ( $certloom_show_diagnostics ) : ?>
	<?php if ( isset( $_GET['certloom_database_proof'] ) ) : ?>
		<?php $certloom_database_proof_state = sanitize_key( wp_unslash( $_GET['certloom_database_proof'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_database_proof_state ? 'notice-success' : ( 'failed' === $certloom_database_proof_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_database_proof_state ? __( 'The isolated database proof passed.', 'certloom' ) : ( 'failed' === $certloom_database_proof_state ? __( 'The isolated database proof found one or more requirements that are not ready.', 'certloom' ) : __( 'The isolated database proof completed with one or more checks that still require external confirmation.', 'certloom' ) ) ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['certloom_domain_acceptance_proof'] ) ) : ?>
		<?php $certloom_domain_acceptance_state = sanitize_key( wp_unslash( $_GET['certloom_domain_acceptance_proof'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_domain_acceptance_state ? 'notice-success' : ( 'failed' === $certloom_domain_acceptance_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_domain_acceptance_state ? __( 'The gated Credential Series acceptance proof passed.', 'certloom' ) : ( 'failed' === $certloom_domain_acceptance_state ? __( 'The gated Credential Series acceptance proof found one or more requirements that are not ready.', 'certloom' ) : __( 'The gated Credential Series acceptance proof completed with one or more checks that still require review.', 'certloom' ) ) ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['certloom_legacy_migration_preflight'] ) ) : ?>
		<?php $certloom_legacy_preflight_state = sanitize_key( wp_unslash( $_GET['certloom_legacy_migration_preflight'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_legacy_preflight_state ? 'notice-success' : ( 'failed' === $certloom_legacy_preflight_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_legacy_preflight_state ? __( 'The populated legacy migration preflight passed.', 'certloom' ) : ( 'failed' === $certloom_legacy_preflight_state ? __( 'The populated legacy migration preflight found rows or source conditions that require review.', 'certloom' ) : ( 'stale' === $certloom_legacy_preflight_state ? __( 'The populated legacy migration preflight became stale because its frozen source boundary changed. Run it again from a fresh boundary.', 'certloom' ) : __( 'The populated legacy migration preflight is still running. Continue the next bounded slice below.', 'certloom' ) ) ) ); ?>
		</p></div>
	<?php endif; ?>


	<?php if ( isset( $_GET['certloom_staging_migration_rehearsal'] ) ) : ?>
		<?php $certloom_staging_rehearsal_state = sanitize_key( wp_unslash( $_GET['certloom_staging_migration_rehearsal'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_staging_rehearsal_state ? 'notice-success' : ( 'failed' === $certloom_staging_rehearsal_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_staging_rehearsal_state ? __( 'The staging populated-migration commit rehearsal passed.', 'certloom' ) : ( 'failed' === $certloom_staging_rehearsal_state ? __( 'The staging populated-migration commit rehearsal stopped safely and requires review.', 'certloom' ) : __( 'The staging populated-migration commit rehearsal is still running. Continue the next bounded slice below.', 'certloom' ) ) ); ?>
		</p></div>
	<?php endif; ?>
	<?php endif; ?>

	<h2 id="certloom-settings-access"><?php esc_html_e( 'Who can manage certificates', 'certloom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Administrators always retain access. Grant the dedicated Certloom capability only to roles that genuinely need certificate-management access.', 'certloom' ); ?></p>
	<?php if ( ! get_role( Ecapla_CertVerify_Permissions::MANAGER_ROLE ) ) : ?>
		<form method="post" action="" class="ecapla-certverify-inline-form"><?php wp_nonce_field( 'certverify_create_manager_role' ); ?><?php submit_button( __( 'Create Certificate Manager role', 'certloom' ), 'secondary', 'certverify_create_manager_role', false ); ?><p class="description"><?php esc_html_e( 'Creates a minimal role with read access and Certloom management only.', 'certloom' ); ?></p></form>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'certverify_save_settings' ); ?>
		<table class="widefat ecapla-certverify-roles-table"><thead><tr><th><?php esc_html_e( 'Role', 'certloom' ); ?></th><th><?php esc_html_e( 'Can manage certificates', 'certloom' ); ?></th></tr></thead><tbody>
			<?php foreach ( Ecapla_CertVerify_Permissions::get_all_roles() as $role_key => $role_info ) : ?><tr><td><?php echo esc_html( translate_user_role( $role_info['name'] ) ); ?></td><td><?php if ( 'administrator' === $role_key ) : ?><input type="checkbox" checked disabled aria-label="<?php esc_attr_e( 'Administrator access is always enabled', 'certloom' ); ?>" /><?php else : ?><input type="checkbox" name="role_access[<?php echo esc_attr( $role_key ); ?>]" value="1" <?php checked( Ecapla_CertVerify_Permissions::role_has_capability( $role_key ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: WordPress role name. */ __( 'Allow %s to manage certificates', 'certloom' ), translate_user_role( $role_info['name'] ) ) ); ?>" /><?php endif; ?></td></tr><?php endforeach; ?>
		</tbody></table>

		<?php
		$certloom_numbering_policy     = Ecapla_CertVerify_Numbering::policy();
		$certloom_numbering_mode       = (string) $certloom_numbering_policy['numbering_mode'];
		$certloom_numbering_generated  = 'manual' !== $certloom_numbering_mode;
		$certloom_numbering_sequential = 'sequential' === $certloom_numbering_mode;
		$certloom_numbering_next       = $certloom_numbering_sequential ? Ecapla_CertVerify_Numbering::current_next_sequence() : null;
		$certloom_numbering_preview    = $certloom_numbering_generated ? Ecapla_CertVerify_Numbering::preview() : null;
		?>
		<h2 id="certloom-settings-numbering"><?php esc_html_e( 'Certificate numbering', 'certloom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Choose how Certloom creates the human certificate number for a new administrator-created credential when the number field is left blank. Existing issued numbers are never changed automatically, and CSV imports still require explicit certificate numbers.', 'certloom' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="numbering_mode"><?php esc_html_e( 'Default numbering policy', 'certloom' ); ?></label></th><td><select name="numbering_mode" id="numbering_mode"><option value="manual" <?php selected( $certloom_numbering_mode, 'manual' ); ?>><?php esc_html_e( 'Manual certificate numbers', 'certloom' ); ?></option><option value="sequential" <?php selected( $certloom_numbering_mode, 'sequential' ); ?>><?php esc_html_e( 'Sequential when number is blank', 'certloom' ); ?></option><option value="random" <?php selected( $certloom_numbering_mode, 'random' ); ?>><?php esc_html_e( 'Random non-guessable when blank', 'certloom' ); ?></option><option value="uuid" <?php selected( $certloom_numbering_mode, 'uuid' ); ?>><?php esc_html_e( 'UUID v4 when blank', 'certloom' ); ?></option></select><p class="description"><?php esc_html_e( 'Manual remains the upgrade default. In any generated mode, entering a number explicitly is a deliberate manual override. Random uses 100 bits of local cryptographic randomness; UUID uses RFC-4122 version 4.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="numbering_prefix"><?php esc_html_e( 'Prefix', 'certloom' ); ?></label></th><td><input type="text" name="numbering_prefix" id="numbering_prefix" maxlength="32" value="<?php echo esc_attr( $certloom_numbering_policy['numbering_prefix'] ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Applied to Sequential, Random, and UUID generated numbers. Up to 32 letters, numbers, periods, underscores, or hyphens. Include your own separator, for example CERT-.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="numbering_suffix"><?php esc_html_e( 'Suffix', 'certloom' ); ?></label></th><td><input type="text" name="numbering_suffix" id="numbering_suffix" maxlength="32" value="<?php echo esc_attr( $certloom_numbering_policy['numbering_suffix'] ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Optional fixed suffix applied to all generated strategies. Include its separator if required.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="numbering_start"><?php esc_html_e( 'Sequence start / floor', 'certloom' ); ?></label></th><td><input type="number" name="numbering_start" id="numbering_start" min="1" max="999999999999" step="1" value="<?php echo esc_attr( $certloom_numbering_policy['numbering_start'] ); ?>" class="small-text" /><p class="description"><?php esc_html_e( 'Sequential mode only. Used for the first allocation and as a one-way minimum. Lowering this later never rewinds an already-used sequence.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="numbering_padding"><?php esc_html_e( 'Minimum sequence digits', 'certloom' ); ?></label></th><td><input type="number" name="numbering_padding" id="numbering_padding" min="1" max="12" step="1" value="<?php echo esc_attr( $certloom_numbering_policy['numbering_padding'] ); ?>" class="small-text" /><p class="description"><?php esc_html_e( 'Sequential mode only. For example, 6 formats sequence 42 as 000042. Larger sequence values are never truncated.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Current sequence state', 'certloom' ); ?></th><td><?php if ( ! $certloom_numbering_sequential ) : ?><span class="description"><?php esc_html_e( 'Not used by the selected strategy.', 'certloom' ); ?></span><?php elseif ( is_wp_error( $certloom_numbering_next ) ) : ?><strong class="certloom-text-danger"><?php echo esc_html( $certloom_numbering_next->get_error_message() ); ?></strong><?php else : ?><code><?php echo esc_html( (string) $certloom_numbering_next ); ?></code><?php endif; ?><p class="description"><?php esc_html_e( 'Sequential mode advances this durable state atomically. Random and UUID modes are stateless and use local cryptographic randomness for every generated number.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Non-mutating preview', 'certloom' ); ?></th><td><?php if ( ! $certloom_numbering_generated ) : ?><span class="description"><?php esc_html_e( 'Manual mode does not generate a preview.', 'certloom' ); ?></span><?php elseif ( is_wp_error( $certloom_numbering_preview ) ) : ?><strong class="certloom-text-danger"><?php echo esc_html( $certloom_numbering_preview->get_error_message() ); ?></strong><?php else : ?><code><?php echo esc_html( (string) $certloom_numbering_preview['certificate_number'] ); ?></code><?php if ( ! empty( $certloom_numbering_preview['skipped_collisions'] ) ) : ?> <span class="description"><?php echo esc_html( sprintf( /* translators: %d: skipped collisions. */ _n( '%d existing collision would be skipped.', '%d existing collisions would be skipped.', absint( $certloom_numbering_preview['skipped_collisions'] ), 'certloom' ), absint( $certloom_numbering_preview['skipped_collisions'] ) ) ); ?></span><?php endif; ?><?php endif; ?><p class="description"><?php esc_html_e( 'Preview never reserves a number. Sequential previews may advance before save; Random and UUID previews are samples and the saved credential receives a freshly generated value.', 'certloom' ); ?></p></td></tr>
		</table>
		<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Program and template sequences remain gated.', 'certloom' ); ?></strong> <?php esc_html_e( 'Historical credential records do not own accepted Program or Template relationships, so Certloom will not simulate scoped sequences with custom fields. The allocator contract is designed so those scopes can be added after their authoritative relationships are accepted.', 'certloom' ); ?></p></div>

		<h2 id="certloom-settings-branding"><?php esc_html_e( 'Branding', 'certloom' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="accent_color"><?php esc_html_e( 'Default accent color', 'certloom' ); ?></label></th><td><input type="text" name="accent_color" id="accent_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'accent_color', '#1450e0' ) ); ?>" class="regular-text" placeholder="#1450e0" /><p class="description"><?php esc_html_e( 'Used by the shortcode and as the default for new Elementor widgets.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Clean verification branding', 'certloom' ); ?></th><td><label><input type="checkbox" name="verification_branding_enabled" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'verification_branding_enabled', false ) ); ?> /> <?php esc_html_e( 'Show organization branding on Certloom-owned clean verification and credential-profile routes', 'certloom' ); ?></label><p class="description"><?php esc_html_e( 'This does not override shortcode or Elementor design settings.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="verification_brand_name"><?php esc_html_e( 'Verification brand name', 'certloom' ); ?></label></th><td><input type="text" name="verification_brand_name" id="verification_brand_name" maxlength="120" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'verification_brand_name', '' ) ); ?>" class="large-text" /><p class="description"><?php esc_html_e( 'Leave blank to use the organization configured during Certloom setup, then the WordPress site name.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="verification_brand_tagline"><?php esc_html_e( 'Verification brand tagline', 'certloom' ); ?></label></th><td><input type="text" name="verification_brand_tagline" id="verification_brand_tagline" maxlength="240" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'verification_brand_tagline', '' ) ); ?>" class="large-text" /></td></tr>
			<tr><th scope="row"><label for="verification_surface_color"><?php esc_html_e( 'Clean-page surface color', 'certloom' ); ?></label></th><td><input type="text" name="verification_surface_color" id="verification_surface_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'verification_surface_color', '#ffffff' ) ); ?>" class="regular-text" placeholder="#ffffff" /></td></tr>
			<tr><th scope="row"><label for="verification_text_color"><?php esc_html_e( 'Clean-page text color', 'certloom' ); ?></label></th><td><input type="text" name="verification_text_color" id="verification_text_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'verification_text_color', '#01133d' ) ); ?>" class="regular-text" placeholder="#01133d" /></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Brand mark', 'certloom' ); ?></th><td><label><input type="checkbox" name="verification_use_site_icon" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'verification_use_site_icon', false ) ); ?> /> <?php esc_html_e( 'Use the local WordPress Site Icon on clean Certloom routes', 'certloom' ); ?></label></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Local verification QR', 'certloom' ); ?></th><td><label><input type="checkbox" name="verification_qr_enabled" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'verification_qr_enabled', true ) ); ?> /> <?php esc_html_e( 'Show the locally generated verification QR after a successful public verification and on public Credential Profiles', 'certloom' ); ?></label><p class="description"><?php esc_html_e( 'The QR encodes only the canonical Certloom verification URL. Secondary proof codes and private credential data are never embedded. Generation is fully local and does not call a remote QR service.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'QR center logo', 'certloom' ); ?></th><td><label><input type="checkbox" name="verification_qr_logo_enabled" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'verification_qr_logo_enabled', false ) ); ?> /> <?php esc_html_e( 'Use the local WordPress Site Icon as a small center mark in verification QR codes', 'certloom' ); ?></label><p class="description"><?php esc_html_e( 'Logo QR uses high error correction and only a bounded local PNG/JPEG Site Icon from WordPress uploads. If the Site Icon cannot be verified safely, Certloom falls back to the plain scan-safe QR rather than fetching or embedding a remote image.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Local Code 128 barcode', 'certloom' ); ?></th><td><label><input type="checkbox" name="verification_barcode_enabled" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'verification_barcode_enabled', false ) ); ?> /> <?php esc_html_e( 'Show a locally generated Code 128 barcode for compatible certificate numbers after verification and on public Credential Profiles', 'certloom' ); ?></label><p class="description"><?php esc_html_e( 'The barcode represents the existing human certificate number and does not create a new lookup route or identifier. Printable ASCII certificate numbers are supported; unsupported values simply omit the barcode.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Credit line', 'certloom' ); ?></th><td><label><input type="checkbox" name="show_credit" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'show_credit', false ) ); ?> /> <?php esc_html_e( 'Show a public credit line beneath new shortcode output', 'certloom' ); ?></label><p class="description"><?php esc_html_e( 'This is optional and disabled by default for WordPress.org compliance.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="credit_text"><?php esc_html_e( 'Credit text', 'certloom' ); ?></label></th><td><input type="text" name="credit_text" id="credit_text" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_text', __( 'Verification powered by Certloom.', 'certloom' ) ) ); ?>" class="large-text" /></td></tr>
			<tr><th scope="row"><label for="credit_color"><?php esc_html_e( 'Credit text color', 'certloom' ); ?></label></th><td><input type="text" name="credit_color" id="credit_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_color', '' ) ); ?>" class="regular-text" placeholder="#667085" /></td></tr>
			<tr><th scope="row"><label for="credit_size"><?php esc_html_e( 'Credit text size', 'certloom' ); ?></label></th><td><input type="number" name="credit_size" id="credit_size" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_size', '' ) ); ?>" min="8" max="24" class="small-text" /> px</td></tr>
			<tr><th scope="row"><label for="credit_align"><?php esc_html_e( 'Credit alignment', 'certloom' ); ?></label></th><td><select name="credit_align" id="credit_align"><?php $align = Ecapla_CertVerify_Settings::get( 'credit_align', 'center' ); ?><option value="left" <?php selected( $align, 'left' ); ?>><?php esc_html_e( 'Left', 'certloom' ); ?></option><option value="center" <?php selected( $align, 'center' ); ?>><?php esc_html_e( 'Center', 'certloom' ); ?></option><option value="right" <?php selected( $align, 'right' ); ?>><?php esc_html_e( 'Right', 'certloom' ); ?></option></select></td></tr>
		</table>

		<h2 id="certloom-settings-verification"><?php esc_html_e( 'Status guide wording', 'certloom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Customize the explanatory text displayed in the optional public status guide.', 'certloom' ); ?></p>
		<table class="form-table" role="presentation"><?php foreach ( Ecapla_CertVerify_Fields::get_statuses() as $key => $status ) : ?><tr><th scope="row"><label for="status_desc_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $status['label'] ); ?></label></th><td><textarea name="status_desc[<?php echo esc_attr( $key ); ?>]" id="status_desc_<?php echo esc_attr( $key ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $status['desc'] ); ?></textarea></td></tr><?php endforeach; ?></table>

		<h2 id="certloom-settings-retention"><?php esc_html_e( 'Data retention', 'certloom' ); ?></h2>
		<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'delete_data_on_uninstall', false ) ); ?> /> <?php esc_html_e( 'Permanently delete certificates, settings, and field definitions when the plugin is uninstalled', 'certloom' ); ?></label>
		<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Leave this disabled to preserve certificate data and settings. Scheduled tasks, temporary data, and plugin capabilities are cleaned up during uninstall in either mode.', 'certloom' ); ?></p>
		<p><label for="privacy_erasure_policy"><strong><?php esc_html_e( 'WordPress personal-data erasure policy', 'certloom' ); ?></strong></label><br />
		<select name="privacy_erasure_policy" id="privacy_erasure_policy">
			<option value="retain" <?php selected( Ecapla_CertVerify_Settings::get( 'privacy_erasure_policy', 'retain' ), 'retain' ); ?>><?php esc_html_e( 'Retain and require administrator review (safest default)', 'certloom' ); ?></option>
			<option value="anonymize_mutable_recipient" <?php selected( Ecapla_CertVerify_Settings::get( 'privacy_erasure_policy', 'retain' ), 'anonymize_mutable_recipient' ); ?>><?php esc_html_e( 'Anonymize mutable Recipient profiles; retain immutable credential history', 'certloom' ); ?></option>
		</select></p>
		<p class="description"><?php esc_html_e( 'This setting affects WordPress Tools → Erase Personal Data. It never rewrites issued certificate Versions or audit Events. Legacy certificate records and issuer/signer identities are retained for explicit administrator review.', 'certloom' ); ?></p>
		<p><label><input type="checkbox" name="recipient_user_sync_enabled" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'recipient_user_sync_enabled', true ) ); ?> /> <strong><?php esc_html_e( 'Keep WordPress users connected to Recipient profiles', 'certloom' ); ?></strong></label></p>
		<p class="description"><?php esc_html_e( 'When enabled, new WordPress users receive an exact user-ID-linked Recipient profile and later profile changes keep its name/email current. Certloom never claims ownership by matching email or name, and organizations can still exist as Recipients without WordPress accounts.', 'certloom' ); ?></p>

		<?php submit_button( __( 'Save settings', 'certloom' ), 'primary', 'certverify_save_settings' ); ?>
	</form>

	<section id="certloom-settings-guidance" class="certloom-settings-guidance" aria-labelledby="certloom-settings-guidance-title">
		<div class="certloom-settings-guidance-head"><span class="certloom-eyebrow"><?php esc_html_e( 'Reference', 'certloom' ); ?></span><h2 id="certloom-settings-guidance-title"><?php esc_html_e( 'Guidance & support', 'certloom' ); ?></h2><p><?php esc_html_e( 'Important behavior and support information, kept separate from editable settings.', 'certloom' ); ?></p></div>
		<div class="certloom-settings-info-grid">
			<article class="certloom-settings-info-card" aria-labelledby="certloom-settings-expiry">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span><div><h3 id="certloom-settings-expiry"><?php esc_html_e( 'Expiry behavior', 'certloom' ); ?></h3><p><?php esc_html_e( 'Expired is calculated automatically from each credential’s expiry date. Changing the expiry to a future date returns it to Valid unless its manual status is Suspended or Withdrawn. No scheduled synchronization task is required.', 'certloom' ); ?></p></div>
			</article>
			<article class="certloom-settings-info-card is-caution" aria-labelledby="certloom-settings-privacy">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span><div><h3 id="certloom-settings-privacy"><?php esc_html_e( 'Public-data privacy', 'certloom' ); ?></h3><p><?php esc_html_e( 'Fields marked “Show on public result” can be returned by public verification. Do not publish sensitive personal data. Credential numbers entered in a browser may also appear in normal server logs, browser history, analytics and cache layers.', 'certloom' ); ?></p></div>
			</article>
			<article class="certloom-settings-info-card is-support" aria-labelledby="certloom-settings-support">
				<span class="dashicons dashicons-sos" aria-hidden="true"></span><div><h3 id="certloom-settings-support"><?php esc_html_e( 'Support', 'certloom' ); ?></h3><p><?php esc_html_e( 'Open the Certloom website for product information, or start a support email with the Certloom issue subject already filled in.', 'certloom' ); ?></p><div class="certloom-settings-support-actions"><a class="button" href="https://ecapla.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit ecapla.com', 'certloom' ); ?></a><a class="button button-primary" href="mailto:support@ecapla.com?subject=Certloom%20%E2%80%93%20Report%20an%20Issue"><?php esc_html_e( 'Report an issue', 'certloom' ); ?></a></div></div>
			</article>
		</div>
	</section>

	<?php if ( $certloom_show_diagnostics ) : ?>
	<details id="certloom-system-status" class="certloom-technical-details certloom-settings-diagnostics"<?php echo ( isset( $_GET['certloom_database_proof'] ) || isset( $_GET['certloom_domain_acceptance_proof'] ) || isset( $_GET['certloom_legacy_migration_preflight'] ) || isset( $_GET['certloom_staging_migration_rehearsal'] ) ) ? ' open' : ''; ?>>
		<summary><span><strong><?php esc_html_e( 'Diagnostics & maintenance', 'certloom' ); ?></strong><small><?php esc_html_e( 'System status, database proofs, migration checks and staging-only rehearsals.', 'certloom' ); ?></small></span></summary>
		<div class="certloom-settings-diagnostics-body">
	<h2 id="certloom-settings-system-status"><?php esc_html_e( 'System status', 'certloom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Read-only local diagnostics for Certloom. Nothing in this report is sent to Ecapla, and it intentionally excludes certificate data, site URLs, filesystem paths, database names, user data, and plugin inventories.', 'certloom' ); ?></p>
	<?php $certloom_system_checks = Ecapla_CertVerify_System_Status::get_checks(); ?>
	<table class="widefat striped" role="table">
		<thead><tr><th scope="col"><?php esc_html_e( 'Check', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Notes', 'certloom' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $certloom_system_checks as $certloom_check ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $certloom_check['label'] ); ?></th>
				<td><strong><?php echo esc_html( 'good' === $certloom_check['status'] ? __( 'Ready', 'certloom' ) : ( 'bad' === $certloom_check['status'] ? __( 'Action needed', 'certloom' ) : __( 'Review', 'certloom' ) ) ); ?></strong></td>
				<td><?php echo esc_html( $certloom_check['value'] ); ?></td>
				<td><?php echo esc_html( $certloom_check['note'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><label for="certloom_system_report"><strong><?php esc_html_e( 'Copyable support report', 'certloom' ); ?></strong></label></p>
	<textarea id="certloom_system_report" class="large-text code" rows="12" readonly><?php echo esc_textarea( Ecapla_CertVerify_System_Status::get_text_report() ); ?></textarea>


	<h3 id="certloom-settings-db-proof"><?php esc_html_e( 'Isolated database proof', 'certloom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'This administrator-triggered proof creates one empty Certloom scratch table, exercises WordPress dbDelta(), InnoDB, indexes, uniqueness, transaction rollback/commit, and—when the host permits a second connection—row-lock contention. It never reads certificate records, never creates the gated credential-domain tables, never changes credential authority, and removes the scratch table before returning.', 'certloom' ); ?></p>
	<form method="post" action="" class="ecapla-certverify-inline-form">
		<?php wp_nonce_field( 'certverify_run_database_proof' ); ?>
		<?php submit_button( __( 'Run isolated database proof', 'certloom' ), 'secondary', 'certverify_run_database_proof', false ); ?>
	</form>
	<?php $certloom_database_proof = Ecapla_CertVerify_Database_Proof::get_last_result(); ?>
	<?php if ( is_array( $certloom_database_proof ) ) : ?>
		<table class="widefat striped" role="table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Database proof check', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'certloom' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( (array) $certloom_database_proof['checks'] as $certloom_proof_check ) : ?>
				<?php if ( ! is_array( $certloom_proof_check ) ) { continue; } ?>
				<tr>
					<th scope="row"><?php echo esc_html( $certloom_proof_check['label'] ?? '' ); ?></th>
					<td><strong><?php echo esc_html( 'pass' === ( $certloom_proof_check['status'] ?? '' ) ? __( 'Passed', 'certloom' ) : ( 'fail' === ( $certloom_proof_check['status'] ?? '' ) ? __( 'Failed', 'certloom' ) : __( 'Review', 'certloom' ) ) ); ?></strong></td>
					<td><?php echo esc_html( $certloom_proof_check['message'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><label for="certloom_database_proof_report"><strong><?php esc_html_e( 'Copyable database-proof report', 'certloom' ); ?></strong></label></p>
		<textarea id="certloom_database_proof_report" class="large-text code" rows="12" readonly><?php echo esc_textarea( Ecapla_CertVerify_Database_Proof::get_text_report( $certloom_database_proof ) ); ?></textarea>
	<?php endif; ?>

	<h3 id="certloom-settings-domain-proof"><?php esc_html_e( 'Gated Credential Series acceptance proof', 'certloom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'This stronger proof is available only after a fresh passing isolated database proof. It deliberately provisions/validates Certloom’s additive future Credential Series tables, then exercises the real repositories, immutable Version constraints, lifecycle events, Status Service/verification, and synthetic legacy-status parity inside one rollback-only transaction. It does not read individual live certificate rows, does not migrate the live registry, does not change credential authority, and leaves no synthetic credential rows behind.', 'certloom' ); ?></p>
	<?php $certloom_domain_acceptance_ready = Ecapla_CertVerify_Database_Proof::is_current_passed(); ?>
	<form method="post" action="" class="ecapla-certverify-inline-form">
		<?php wp_nonce_field( 'certverify_run_domain_acceptance_proof' ); ?>
		<?php submit_button( __( 'Run gated-domain acceptance proof', 'certloom' ), 'secondary', 'certverify_run_domain_acceptance_proof', false, $certloom_domain_acceptance_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>
	<?php if ( ! $certloom_domain_acceptance_ready ) : ?><p class="description"><strong><?php esc_html_e( 'Run a fresh passing isolated database proof first.', 'certloom' ); ?></strong></p><?php endif; ?>
	<?php $certloom_domain_acceptance_proof = \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::get_last_result(); ?>
	<?php if ( is_array( $certloom_domain_acceptance_proof ) ) : ?>
		<table class="widefat striped" role="table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Domain acceptance check', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'certloom' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( (array) $certloom_domain_acceptance_proof['checks'] as $certloom_domain_check ) : ?>
				<?php if ( ! is_array( $certloom_domain_check ) ) { continue; } ?>
				<tr>
					<th scope="row"><?php echo esc_html( $certloom_domain_check['label'] ?? '' ); ?></th>
					<td><strong><?php echo esc_html( 'pass' === ( $certloom_domain_check['status'] ?? '' ) ? __( 'Passed', 'certloom' ) : ( 'fail' === ( $certloom_domain_check['status'] ?? '' ) ? __( 'Failed', 'certloom' ) : __( 'Review', 'certloom' ) ) ); ?></strong></td>
					<td><?php echo esc_html( $certloom_domain_check['message'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><label for="certloom_domain_acceptance_report"><strong><?php esc_html_e( 'Copyable gated-domain proof report', 'certloom' ); ?></strong></label></p>
		<textarea id="certloom_domain_acceptance_report" class="large-text code" rows="14" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::get_text_report( $certloom_domain_acceptance_proof ) ); ?></textarea>
	<?php endif; ?>

	<h3 id="certloom-settings-migration-preflight"><?php esc_html_e( 'Populated legacy migration preflight', 'certloom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'After the real gated-domain proof passes, this read-only rehearsal scans the actual populated Certloom 1.5 registry through the final migration mapper and Status Service. It runs in bounded resumable slices, performs a second fingerprint-verification pass, never writes Credential Series rows, never starts official migration, and never changes credential authority. The local error table contains only numeric legacy row IDs plus migration-review messages; the copyable report excludes certificate numbers, names and custom-field values.', 'certloom' ); ?></p>
	<?php $certloom_legacy_preflight_ready = \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::is_current_passed(); ?>
	<?php $certloom_legacy_preflight_proof = \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::get_last_result(); ?>
	<?php $certloom_legacy_preflight_evidence = \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::evidence_state(); ?>
	<form method="post" action="" class="ecapla-certverify-inline-form">
		<?php wp_nonce_field( 'certverify_run_legacy_migration_preflight_proof' ); ?>
		<?php submit_button( 'running' === $certloom_legacy_preflight_evidence ? __( 'Continue migration preflight', 'certloom' ) : __( 'Run populated migration preflight', 'certloom' ), 'secondary', 'certverify_run_legacy_migration_preflight_proof', false, $certloom_legacy_preflight_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>
	<?php if ( ! $certloom_legacy_preflight_ready ) : ?><p class="description"><strong><?php esc_html_e( 'Run a fresh passing gated Credential Series acceptance proof first.', 'certloom' ); ?></strong></p><?php endif; ?>
	<?php if ( is_array( $certloom_legacy_preflight_proof ) ) : ?>
		<?php $certloom_legacy_total = absint( $certloom_legacy_preflight_proof['source_count'] ?? 0 ); ?>
		<?php $certloom_legacy_mapped = absint( $certloom_legacy_preflight_proof['total'] ?? 0 ); ?>
		<?php $certloom_legacy_verified = absint( $certloom_legacy_preflight_proof['verify_total'] ?? 0 ); ?>
		<table class="widefat striped" role="table">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'State', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $certloom_legacy_preflight_evidence ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Phase', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( sanitize_key( (string) ( $certloom_legacy_preflight_proof['phase'] ?? '' ) ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows mapped', 'certloom' ); ?></th><td><?php echo esc_html( sprintf( '%1$d / %2$d', $certloom_legacy_mapped, $certloom_legacy_total ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows fingerprint-verified', 'certloom' ); ?></th><td><?php echo esc_html( sprintf( '%1$d / %2$d', $certloom_legacy_verified, $certloom_legacy_total ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows ready', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_legacy_preflight_proof['ready'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows requiring review', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_legacy_preflight_proof['errors'] ?? 0 ) ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( ! empty( $certloom_legacy_preflight_proof['error_examples'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Local bounded error examples', 'certloom' ); ?></strong></p>
			<table class="widefat striped" role="table"><thead><tr><th><?php esc_html_e( 'Legacy row ID', 'certloom' ); ?></th><th><?php esc_html_e( 'Migration review message', 'certloom' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $certloom_legacy_preflight_proof['error_examples'] as $certloom_legacy_error ) : if ( ! is_array( $certloom_legacy_error ) ) { continue; } ?>
				<tr><td><?php echo esc_html( absint( $certloom_legacy_error['legacy_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $certloom_legacy_error['message'] ?? '' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<p><label for="certloom_legacy_preflight_report"><strong><?php esc_html_e( 'Copyable migration-preflight report', 'certloom' ); ?></strong></label></p>
		<textarea id="certloom_legacy_preflight_report" class="large-text code" rows="12" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::get_text_report( $certloom_legacy_preflight_proof ) ); ?></textarea>
	<?php endif; ?>


	<?php if ( \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::environment_allowed() ) : ?>
		<h3 id="certloom-settings-staging-migration"><?php esc_html_e( 'Staging populated-migration commit rehearsal', 'certloom' ); ?></h3>
		<p class="description"><strong><?php esc_html_e( 'Staging/local only — this intentionally writes the real future-domain tables.', 'certloom' ); ?></strong> <?php esc_html_e( 'Use this only on a disposable or restorable staging/local copy. It drives Certloom’s production migration coordinator against the real populated historical credential store, verifies every migrated credential, then replays every source row through the real writer to prove idempotency. It never changes credential authority, but it deliberately leaves the migrated future-domain rows in place for later restore/cutover rehearsal. Reclone or restore the staging database if you need to start over.', 'certloom' ); ?></p>
		<?php $certloom_staging_rehearsal_enabled = \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::explicitly_enabled(); ?>
		<?php $certloom_staging_rehearsal = \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::get_last_result(); ?>
		<?php $certloom_staging_rehearsal_evidence = \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::evidence_state(); ?>
		<?php if ( ! $certloom_staging_rehearsal_enabled ) : ?>
			<p class="description"><?php esc_html_e( 'To opt in on this non-production copy, add this line to wp-config.php before WordPress loads Certloom:', 'certloom' ); ?></p>
			<p><code>define( 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL', true );</code></p>
		<?php else : ?>
			<?php $certloom_staging_rehearsal_can_run = 'passed' !== $certloom_staging_rehearsal_evidence && 'failed' !== $certloom_staging_rehearsal_evidence && ( 'running' === $certloom_staging_rehearsal_evidence || \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::is_current_passed() ); ?>
			<form method="post" action="" class="ecapla-certverify-inline-form">
				<?php wp_nonce_field( 'certverify_run_staging_migration_rehearsal' ); ?>
				<?php submit_button( 'running' === $certloom_staging_rehearsal_evidence ? __( 'Continue staging migration rehearsal', 'certloom' ) : __( 'Run staging migration commit rehearsal', 'certloom' ), 'secondary', 'certverify_run_staging_migration_rehearsal', false, $certloom_staging_rehearsal_can_run ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<?php if ( 'not_run' === $certloom_staging_rehearsal_evidence && ! \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::is_current_passed() ) : ?><p class="description"><strong><?php esc_html_e( 'Run fresh passing database, gated-domain, and populated migration-preflight proofs first.', 'certloom' ); ?></strong></p><?php endif; ?>
			<?php if ( 'failed' === $certloom_staging_rehearsal_evidence ) : ?><p class="description"><strong><?php esc_html_e( 'This rehearsal intentionally has no destructive reset button. Review the failure and reclone/restore the staging database before trying a new rehearsal.', 'certloom' ); ?></strong></p><?php endif; ?>
		<?php endif; ?>
		<?php if ( is_array( $certloom_staging_rehearsal ) ) : ?>
			<table class="widefat striped" role="table"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'State', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $certloom_staging_rehearsal_evidence ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Phase', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', sanitize_key( (string) ( $certloom_staging_rehearsal['phase'] ?? '' ) ) ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Frozen legacy rows', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_rehearsal['source_count'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Official preflight mapped', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_rehearsal['official_preflight_total'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Official preflight fingerprint-verified', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_rehearsal['official_verify_total'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Migration writes', 'certloom' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: migrated rows, 2: idempotently skipped rows. */ __( '%1$d migrated, %2$d skipped', 'certloom' ), absint( $certloom_staging_rehearsal['migrated'] ?? 0 ), absint( $certloom_staging_rehearsal['skipped'] ?? 0 ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Target parity verified', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_rehearsal['verified'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Idempotency replay checked', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_rehearsal['idempotency_checked'] ?? 0 ) ); ?></td></tr>
			</tbody></table>
			<?php if ( ! empty( $certloom_staging_rehearsal['error_examples'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Local bounded rehearsal errors', 'certloom' ); ?></strong></p>
				<table class="widefat striped" role="table"><thead><tr><th><?php esc_html_e( 'Legacy row ID', 'certloom' ); ?></th><th><?php esc_html_e( 'Review message', 'certloom' ); ?></th></tr></thead><tbody>
				<?php foreach ( (array) $certloom_staging_rehearsal['error_examples'] as $certloom_staging_error ) : if ( ! is_array( $certloom_staging_error ) ) { continue; } ?>
					<tr><td><?php echo esc_html( absint( $certloom_staging_error['legacy_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $certloom_staging_error['message'] ?? '' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
			<p><label for="certloom_staging_migration_report"><strong><?php esc_html_e( 'Copyable staging-migration rehearsal report', 'certloom' ); ?></strong></label></p>
			<textarea id="certloom_staging_migration_report" class="large-text code" rows="14" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::get_text_report( $certloom_staging_rehearsal ) ); ?></textarea>
		<?php endif; ?>
	<?php endif; ?>


	<?php if ( \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::environment_allowed() ) : ?>
		<h3 id="certloom-settings-staging-restore"><?php esc_html_e( 'Staging populated backup/restore rehearsal', 'certloom' ); ?></h3>
		<p class="description"><strong><?php esc_html_e( 'Staging/local only — this intentionally exercises real future-domain backup and restore promotion.', 'certloom' ); ?></strong> <?php esc_html_e( 'Use only on a disposable or restorable non-production copy after the populated migration rehearsal passes. Certloom fingerprints the complete migrated graph, builds a real private domain backup, proves Replace parity, proves an identical Merge inserts zero new records, and forces a post-swap configuration mismatch to exercise the production rollback path. Credential authority never changes. A successful rehearsal leaves the populated domain graph intact for the later cutover rehearsal.', 'certloom' ); ?></p>
		<?php $certloom_staging_restore_enabled = \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::explicitly_enabled(); ?>
		<?php $certloom_staging_restore = \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::get_last_result(); ?>
		<?php $certloom_staging_restore_evidence = \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::evidence_state(); ?>
		<?php if ( ! $certloom_staging_restore_enabled ) : ?>
			<p class="description"><?php esc_html_e( 'To opt in on this disposable non-production copy, add this line to wp-config.php before WordPress loads Certloom:', 'certloom' ); ?></p>
			<p><code>define( 'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL', true );</code></p>
		<?php else : ?>
			<?php $certloom_staging_restore_can_run = 'passed' !== $certloom_staging_restore_evidence && 'failed' !== $certloom_staging_restore_evidence && ( 'running' === $certloom_staging_restore_evidence || \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::is_current_passed() ); ?>
			<form method="post" action="" class="ecapla-certverify-inline-form">
				<?php wp_nonce_field( 'certverify_run_staging_restore_rehearsal' ); ?>
				<?php submit_button( 'running' === $certloom_staging_restore_evidence ? __( 'Continue staging restore rehearsal', 'certloom' ) : __( 'Run staging backup/restore rehearsal', 'certloom' ), 'secondary', 'certverify_run_staging_restore_rehearsal', false, $certloom_staging_restore_can_run ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<?php if ( 'not_run' === $certloom_staging_restore_evidence && ! \Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal::is_current_passed() ) : ?><p class="description"><strong><?php esc_html_e( 'Run a fresh passing populated staging migration rehearsal first.', 'certloom' ); ?></strong></p><?php endif; ?>
			<?php if ( 'failed' === $certloom_staging_restore_evidence ) : ?><p class="description"><strong><?php esc_html_e( 'This destructive staging proof intentionally has no reset button. Preserve the failure evidence and reclone/restore staging before another rehearsal.', 'certloom' ); ?></strong></p><?php endif; ?>
		<?php endif; ?>
		<?php if ( is_array( $certloom_staging_restore ) ) : ?>
			<table class="widefat striped" role="table"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'State', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $certloom_staging_restore_evidence ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Phase', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', sanitize_key( (string) ( $certloom_staging_restore['phase'] ?? '' ) ) ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Migrated legacy rows', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_restore['source_count'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Baseline future-domain records', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_restore['baseline_records'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Generated backup records', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_restore['backup_records'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Replace parity', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_restore['replace_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Merge parity', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_restore['merge_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Forced rollback parity', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_restore['rollback_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
			</tbody></table>
			<p><label for="certloom_staging_restore_report"><strong><?php esc_html_e( 'Copyable staging-restore rehearsal report', 'certloom' ); ?></strong></label></p>
			<textarea id="certloom_staging_restore_report" class="large-text code" rows="14" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::get_text_report( $certloom_staging_restore ) ); ?></textarea>
		<?php endif; ?>
	<?php endif; ?>


	<?php if ( \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::environment_allowed() ) : ?>
		<h3 id="certloom-settings-staging-cutover"><?php esc_html_e( 'Staging final authority-cutover rehearsal', 'certloom' ); ?></h3>
		<p class="description"><strong><?php esc_html_e( 'Staging/local only — this temporarily exercises the real authority switch and then rolls it back.', 'certloom' ); ?></strong> <?php esc_html_e( 'Use only on a disposable/restorable non-production copy after the populated migration and backup/restore rehearsals pass. Certloom fingerprints the complete future-domain graph, runs the production cutover coordinator with a staging-only readiness policy, proves the historical credential store becomes read-only, proves Series public verification can become ready, then disables Series verification, restores the original credential authority, and fingerprints the graph again. Normalized-domain release authority remains closed throughout; this rehearsal uses only its staging-specific readiness policy.', 'certloom' ); ?></p>
		<?php $certloom_staging_cutover_enabled = \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::explicitly_enabled(); ?>
		<?php $certloom_staging_cutover = \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::get_last_result(); ?>
		<?php $certloom_staging_cutover_evidence = \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::evidence_state(); ?>
		<?php if ( ! $certloom_staging_cutover_enabled ) : ?>
			<p class="description"><?php esc_html_e( 'To opt in on this disposable non-production copy, add this line to wp-config.php before WordPress loads Certloom:', 'certloom' ); ?></p>
			<p><code>define( 'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL', true );</code></p>
		<?php else : ?>
			<?php $certloom_staging_cutover_can_run = 'passed' !== $certloom_staging_cutover_evidence && 'failed' !== $certloom_staging_cutover_evidence && ( 'running' === $certloom_staging_cutover_evidence || \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::is_current_passed() ); ?>
			<form method="post" action="" class="ecapla-certverify-inline-form">
				<?php wp_nonce_field( 'certverify_run_staging_cutover_rehearsal' ); ?>
				<?php submit_button( 'running' === $certloom_staging_cutover_evidence ? __( 'Continue staging cutover rehearsal', 'certloom' ) : __( 'Run final cutover rehearsal', 'certloom' ), 'secondary', 'certverify_run_staging_cutover_rehearsal', false, $certloom_staging_cutover_can_run ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<?php if ( 'not_run' === $certloom_staging_cutover_evidence && ! \Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal::is_current_passed() ) : ?><p class="description"><strong><?php esc_html_e( 'Run a fresh passing populated staging backup/restore rehearsal first.', 'certloom' ); ?></strong></p><?php endif; ?>
			<?php if ( 'failed' === $certloom_staging_cutover_evidence ) : ?><p class="description"><strong><?php esc_html_e( 'Preserve the failure evidence and reclone/restore staging before attempting another final authority rehearsal.', 'certloom' ); ?></strong></p><?php endif; ?>
		<?php endif; ?>
		<?php if ( is_array( $certloom_staging_cutover ) ) : ?>
			<table class="widefat striped" role="table"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'State', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $certloom_staging_cutover_evidence ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Phase', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', sanitize_key( (string) ( $certloom_staging_cutover['phase'] ?? '' ) ) ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Frozen legacy rows', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_cutover['source_count'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Future-domain records fingerprinted', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_staging_cutover['baseline_records'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Committed receipt parity', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_cutover['receipt_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Legacy write guard', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_cutover['legacy_guard_blocked'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Series verification gate', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_cutover['public_gate_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Legacy authority restored', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_cutover['rollback_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Graph unchanged', 'certloom' ); ?></th><td><?php echo esc_html( ! empty( $certloom_staging_cutover['final_verified'] ) ? __( 'Passed', 'certloom' ) : __( 'Not yet', 'certloom' ) ); ?></td></tr>
			</tbody></table>
			<p><label for="certloom_staging_cutover_report"><strong><?php esc_html_e( 'Copyable staging-cutover rehearsal report', 'certloom' ); ?></strong></label></p>
			<textarea id="certloom_staging_cutover_report" class="large-text code" rows="14" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal::get_text_report( $certloom_staging_cutover ) ); ?></textarea>
		<?php endif; ?>
	<?php endif; ?>
		</div>
	</details>
	<?php endif; ?>

</div>
