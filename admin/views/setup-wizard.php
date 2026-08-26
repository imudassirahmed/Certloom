<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped to the rendering method.
$certloom_setup_state = $setup_summary['state'];
$certloom_required_ready = ! empty( $setup_summary['organization_ready'] ) && ! empty( $setup_summary['page_ready'] );
$certloom_setup_test_url = '';
if ( ! empty( $setup_summary['page_ready'] ) ) {
	$certloom_setup_page_url = get_permalink( absint( $certloom_setup_state['verification_page_id'] ) );
	if ( $certloom_setup_page_url ) {
		$certloom_setup_test_url = ! empty( $setup_summary['sample_cert_number'] )
			? Ecapla_CertVerify_Public_Router::verification_url( (string) $setup_summary['sample_cert_number'] )
			: $certloom_setup_page_url;
	}
}
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-setup-screen">
	<section class="certloom-page-hero" aria-labelledby="certloom-setup-title">
		<div><span class="certloom-eyebrow"><?php esc_html_e( 'First-run setup', 'certloom' ); ?></span><h1 id="certloom-setup-title"><?php esc_html_e( 'Set up Certloom', 'certloom' ); ?></h1><p><?php esc_html_e( 'Configure the essentials without an intrusive automatic redirect. You can return to this checklist at any time.', 'certloom' ); ?></p></div>
		<?php if ( ! empty( $setup_summary['completed'] ) ) : ?><span class="certloom-setup-complete-badge"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Setup complete', 'certloom' ); ?></span><?php endif; ?>
	</section>

	<?php if ( isset( $_GET['ecapla_certverify_setup_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setup profile saved.', 'certloom' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_setup_page_created'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Verification page created and linked.', 'certloom' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_setup_complete'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Certloom setup marked complete.', 'certloom' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; ?>

	<div class="certloom-setup-grid">
		<section class="certloom-panel certloom-setup-profile" aria-labelledby="certloom-setup-profile-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Required', 'certloom' ); ?></span><h2 id="certloom-setup-profile-title"><?php esc_html_e( 'Organization & verification', 'certloom' ); ?></h2></div></div>
			<form method="post" action="">
				<?php wp_nonce_field( 'certverify_onboarding_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="certloom-setup-organization"><?php esc_html_e( 'Organization name', 'certloom' ); ?></label></th><td><input id="certloom-setup-organization" type="text" class="regular-text" maxlength="200" name="organization_name" value="<?php echo esc_attr( $certloom_setup_state['organization_name'] ); ?>" required /><p class="description"><?php esc_html_e( 'This is setup profile information only. Full multi-issuer brand records remain in ORG-01 and will not be faked in legacy certificate data.', 'certloom' ); ?></p></td></tr>
					<tr><th scope="row"><label for="verification_page_id"><?php esc_html_e( 'Verification page', 'certloom' ); ?></label></th><td><?php wp_dropdown_pages( array( 'name' => 'verification_page_id', 'id' => 'verification_page_id', 'selected' => absint( $certloom_setup_state['verification_page_id'] ), 'show_option_none' => __( '— Choose a page —', 'certloom' ), 'option_none_value' => 0 ) ); ?><p class="description"><?php esc_html_e( 'Choose a page where you have placed the Certloom block or shortcode, or create a standard verification page below.', 'certloom' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Verification test', 'certloom' ); ?></th><td><label><input type="checkbox" name="verification_tested" value="1" <?php checked( ! empty( $certloom_setup_state['verification_tested'] ) ); ?> /> <?php esc_html_e( 'I tested a certificate on the public verification page.', 'certloom' ); ?></label><?php if ( $certloom_setup_test_url ) : ?> <a href="<?php echo esc_url( $certloom_setup_test_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification test', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php endif; ?><p class="description"><?php esc_html_e( 'When a registry record exists, the test link pre-fills one certificate number. Confirm the public result before marking this step complete.', 'certloom' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Appearance review', 'certloom' ); ?></th><td><label><input type="checkbox" name="appearance_reviewed" value="1" <?php checked( ! empty( $certloom_setup_state['appearance_reviewed'] ) ); ?> /> <?php esc_html_e( 'I reviewed the block, shortcode and Elementor display options.', 'certloom' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Save setup profile', 'certloom' ), 'primary', 'certverify_onboarding_save', false ); ?>
			</form>
			<?php if ( empty( $setup_summary['page_ready'] ) ) : ?>
				<form method="post" action="" class="certloom-setup-create-page">
					<?php wp_nonce_field( 'certverify_onboarding_create_page' ); ?>
					<?php submit_button( __( 'Create verification page', 'certloom' ), 'secondary', 'certverify_onboarding_create_page', false ); ?>
					<p class="description"><?php esc_html_e( 'Creates a published “Verify a Certificate” page containing the [certloom] shortcode. It never sends certificate data off-site.', 'certloom' ); ?></p>
				</form>
			<?php endif; ?>
		</section>

		<section class="certloom-panel" aria-labelledby="certloom-setup-checklist-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Checklist', 'certloom' ); ?></span><h2 id="certloom-setup-checklist-title"><?php esc_html_e( 'Recommended setup path', 'certloom' ); ?></h2><p><?php echo esc_html( sprintf( __( '%1$d of %2$d recommended steps complete.', 'certloom' ), absint( $setup_summary['progress_completed'] ), absint( $setup_summary['progress_total'] ) ) ); ?></p></div></div>
			<ol class="certloom-setup-checklist">
				<li class="<?php echo ! empty( $setup_summary['organization_ready'] ) ? 'is-done' : ''; ?>"><span class="dashicons <?php echo ! empty( $setup_summary['organization_ready'] ) ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><div><strong><?php esc_html_e( '1. Organization', 'certloom' ); ?></strong><p><?php esc_html_e( 'Record the issuer name used during setup.', 'certloom' ); ?></p></div></li>
				<li class="<?php echo ! empty( $setup_summary['fields_ready'] ) ? 'is-done' : ''; ?>"><span class="dashicons <?php echo ! empty( $setup_summary['fields_ready'] ) ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><div><strong><?php esc_html_e( '2. Certificate fields', 'certloom' ); ?></strong><p><?php echo esc_html( sprintf( __( '%d active fields are available.', 'certloom' ), absint( $setup_summary['field_count'] ) ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-fields' ) ); ?>"><?php esc_html_e( 'Review Field Manager', 'certloom' ); ?></a></p></div></li>
				<li class="<?php echo ! empty( $setup_summary['page_ready'] ) ? 'is-done' : ''; ?>"><span class="dashicons <?php echo ! empty( $setup_summary['page_ready'] ) ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><div><strong><?php esc_html_e( '3. Verification page', 'certloom' ); ?></strong><p><?php esc_html_e( 'Link or create the public page visitors use to verify credentials.', 'certloom' ); ?></p></div></li>
				<li class="<?php echo ! empty( $setup_summary['registry_ready'] ) ? 'is-done' : ''; ?>"><span class="dashicons <?php echo ! empty( $setup_summary['registry_ready'] ) ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><div><strong><?php esc_html_e( '4. First certificate', 'certloom' ); ?></strong><p><?php echo esc_html( sprintf( _n( '%d certificate is in the registry.', '%d certificates are in the registry.', absint( $setup_summary['certificate_count'] ), 'certloom' ), absint( $setup_summary['certificate_count'] ) ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>"><?php esc_html_e( 'Add one', 'certloom' ); ?></a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-import' ) ); ?>"><?php esc_html_e( 'Import', 'certloom' ); ?></a></p></div></li>
				<li class="<?php echo ! empty( $setup_summary['verification_tested'] ) ? 'is-done' : ''; ?>"><span class="dashicons <?php echo ! empty( $setup_summary['verification_tested'] ) ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><div><strong><?php esc_html_e( '5. Test verification', 'certloom' ); ?></strong><p><?php if ( $certloom_setup_test_url ) : ?><a href="<?php echo esc_url( $certloom_setup_test_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the public verification page and confirm a real result.', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php else : ?><?php esc_html_e( 'Choose a verification page and add a certificate before testing the complete public flow.', 'certloom' ); ?><?php endif; ?></p></div></li>
				<li class="<?php echo ! empty( $setup_summary['appearance_ready'] ) ? 'is-done' : ''; ?>"><span class="dashicons <?php echo ! empty( $setup_summary['appearance_ready'] ) ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><div><strong><?php esc_html_e( '6. Display options', 'certloom' ); ?></strong><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-frontend' ) ); ?>"><?php esc_html_e( 'Review the block, shortcode and six Elementor designs.', 'certloom' ); ?></a></p></div></li>
			</ol>
			<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Advanced credential workflows stay on the roadmap', 'certloom' ); ?></strong><p><?php esc_html_e( 'Local verification QR and Code 128 are available now. The versioned Template/preset/assignment foundation, canonical Preview, and DSN-01 through DSN-06 Designer foundations are implemented behind acceptance gates, including explicit certloom-layout v1/v2→v3 upgrade boundaries for barcode and canonical list authoring. DSN-07 through DSN-08, authoritative PDF/image publishing, issuance and automation remain later milestones. Setup does not present unavailable future features as if they already work.', 'certloom' ); ?></p></div></div>
		</section>
	</div>

	<section class="certloom-panel certloom-setup-finish">
		<div><span class="certloom-eyebrow"><?php esc_html_e( 'Finish', 'certloom' ); ?></span><h2><?php esc_html_e( 'Complete first-run setup', 'certloom' ); ?></h2><p><?php esc_html_e( 'Only the organization name and a valid verification page are required. The remaining checklist items are recommended and stay available after completion.', 'certloom' ); ?></p></div>
		<form method="post" action="">
			<?php wp_nonce_field( 'certverify_onboarding_complete' ); ?>
			<?php submit_button( __( 'Mark setup complete', 'certloom' ), 'primary', 'certverify_onboarding_complete', false, $certloom_required_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
	</section>
</div>
