<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Access & settings', 'certverify' ); ?></h1>

	<?php if ( isset( $_GET['ecapla_certverify_settings_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'certverify' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_role_created'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Certificate Manager role created with least-privilege access.', 'certverify' ); ?></p></div><?php endif; ?>

	<h2><?php esc_html_e( 'Who can manage certificates', 'certverify' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Administrators always retain access. Grant the dedicated CertVerify capability only to roles that genuinely need certificate-management access.', 'certverify' ); ?></p>
	<?php if ( ! get_role( Ecapla_CertVerify_Permissions::MANAGER_ROLE ) ) : ?>
		<form method="post" action="" class="ecapla-certverify-inline-form"><?php wp_nonce_field( 'certverify_create_manager_role' ); ?><?php submit_button( __( 'Create Certificate Manager role', 'certverify' ), 'secondary', 'certverify_create_manager_role', false ); ?><p class="description"><?php esc_html_e( 'Creates a minimal role with read access and CertVerify management only.', 'certverify' ); ?></p></form>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'certverify_save_settings' ); ?>
		<table class="widefat ecapla-certverify-roles-table"><thead><tr><th><?php esc_html_e( 'Role', 'certverify' ); ?></th><th><?php esc_html_e( 'Can manage certificates', 'certverify' ); ?></th></tr></thead><tbody>
			<?php foreach ( Ecapla_CertVerify_Permissions::get_all_roles() as $role_key => $role_info ) : ?><tr><td><?php echo esc_html( translate_user_role( $role_info['name'] ) ); ?></td><td><?php if ( 'administrator' === $role_key ) : ?><input type="checkbox" checked disabled aria-label="<?php esc_attr_e( 'Administrator access is always enabled', 'certverify' ); ?>" /><?php else : ?><input type="checkbox" name="role_access[<?php echo esc_attr( $role_key ); ?>]" value="1" <?php checked( Ecapla_CertVerify_Permissions::role_has_capability( $role_key ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: WordPress role name. */ __( 'Allow %s to manage certificates', 'certverify' ), translate_user_role( $role_info['name'] ) ) ); ?>" /><?php endif; ?></td></tr><?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Branding', 'certverify' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="accent_color"><?php esc_html_e( 'Default accent color', 'certverify' ); ?></label></th><td><input type="text" name="accent_color" id="accent_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'accent_color', '#0f6e56' ) ); ?>" class="regular-text" placeholder="#0f6e56" /><p class="description"><?php esc_html_e( 'Used by the shortcode and as the default for new Elementor widgets.', 'certverify' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Credit line', 'certverify' ); ?></th><td><label><input type="checkbox" name="show_credit" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'show_credit', false ) ); ?> /> <?php esc_html_e( 'Show a public credit line beneath new shortcode output', 'certverify' ); ?></label><p class="description"><?php esc_html_e( 'This is optional and disabled by default for WordPress.org compliance.', 'certverify' ); ?></p></td></tr>
			<tr><th scope="row"><label for="credit_text"><?php esc_html_e( 'Credit text', 'certverify' ); ?></label></th><td><input type="text" name="credit_text" id="credit_text" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_text', __( 'Verification powered by CertVerify.', 'certverify' ) ) ); ?>" class="large-text" /></td></tr>
			<tr><th scope="row"><label for="credit_color"><?php esc_html_e( 'Credit text color', 'certverify' ); ?></label></th><td><input type="text" name="credit_color" id="credit_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_color', '' ) ); ?>" class="regular-text" placeholder="#667085" /></td></tr>
			<tr><th scope="row"><label for="credit_size"><?php esc_html_e( 'Credit text size', 'certverify' ); ?></label></th><td><input type="number" name="credit_size" id="credit_size" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_size', '' ) ); ?>" min="8" max="24" class="small-text" /> px</td></tr>
			<tr><th scope="row"><label for="credit_align"><?php esc_html_e( 'Credit alignment', 'certverify' ); ?></label></th><td><select name="credit_align" id="credit_align"><?php $align = Ecapla_CertVerify_Settings::get( 'credit_align', 'center' ); ?><option value="left" <?php selected( $align, 'left' ); ?>><?php esc_html_e( 'Left', 'certverify' ); ?></option><option value="center" <?php selected( $align, 'center' ); ?>><?php esc_html_e( 'Center', 'certverify' ); ?></option><option value="right" <?php selected( $align, 'right' ); ?>><?php esc_html_e( 'Right', 'certverify' ); ?></option></select></td></tr>
		</table>

		<h2><?php esc_html_e( 'Status guide wording', 'certverify' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Customize the explanatory text displayed in the optional public status guide.', 'certverify' ); ?></p>
		<table class="form-table" role="presentation"><?php foreach ( Ecapla_CertVerify_Fields::get_statuses() as $key => $status ) : ?><tr><th scope="row"><label for="status_desc_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $status['label'] ); ?></label></th><td><textarea name="status_desc[<?php echo esc_attr( $key ); ?>]" id="status_desc_<?php echo esc_attr( $key ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $status['desc'] ); ?></textarea></td></tr><?php endforeach; ?></table>

		<h2><?php esc_html_e( 'Data retention', 'certverify' ); ?></h2>
		<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'delete_data_on_uninstall', false ) ); ?> /> <?php esc_html_e( 'Permanently delete certificates, settings, and field definitions when the plugin is uninstalled', 'certverify' ); ?></label>
		<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Leave this disabled to preserve certificate data and settings. Scheduled tasks, temporary data, and plugin capabilities are cleaned up during uninstall in either mode.', 'certverify' ); ?></p>

		<?php submit_button( __( 'Save settings', 'certverify' ), 'primary', 'certverify_save_settings' ); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Expiry behavior', 'certverify' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Expired is calculated automatically from each certificate’s expiry date. Renewing the expiry date immediately returns the certificate to Valid unless its manual status is Suspended or Withdrawn. No scheduled synchronization task is required.', 'certverify' ); ?></p>

	<hr />
	<h2><?php esc_html_e( 'Privacy note', 'certverify' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Fields marked “Show on public result” are returned through a public exact-match REST endpoint. Avoid publishing sensitive personal data. Certificate numbers entered in a browser may also appear in server logs, browser history, analytics, and cache layers.', 'certverify' ); ?></p>

	<hr />
	<h2><?php esc_html_e( 'Ecapla support', 'certverify' ); ?></h2>
	<p class="ecapla-certverify-support-actions">
		<a class="button button-secondary" href="https://ecapla.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit ecapla.com', 'certverify' ); ?></a>
		<a class="button button-primary" href="https://ecapla.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Report an issue', 'certverify' ); ?></a>
	</p>
	<p class="description"><?php esc_html_e( 'The issue-report link currently opens Ecapla’s website. It can be pointed to a dedicated support page after that page is published.', 'certverify' ); ?></p>
</div>
