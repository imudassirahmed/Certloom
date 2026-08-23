<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
$ecapla_certverify_field_map = Ecapla_CertVerify_Fields::get_field_map();
$ecapla_certverify_overview_fields = array();
foreach ( array( 'cert_number', 'issue_date', 'expiry_date' ) as $ecapla_certverify_key ) {
	if ( isset( $ecapla_certverify_field_map[ $ecapla_certverify_key ] ) ) {
		$ecapla_certverify_overview_fields[] = $ecapla_certverify_field_map[ $ecapla_certverify_key ];
	}
}
$ecapla_certverify_recipient_field = $ecapla_certverify_field_map['holder_name'] ?? null;
$ecapla_certverify_custom_fields = array_values(
	array_filter(
		$fields,
		static function ( $field ) {
			return ! in_array( $field['key'], array( 'cert_number', 'holder_name', 'issue_date', 'expiry_date', 'status', 'status_reason', 'internal_notes', '__certloom_profile_visibility' ), true );
		}
	)
);
$ecapla_certverify_lifecycle_fields = array();
foreach ( array( 'status', 'status_reason' ) as $ecapla_certverify_key ) {
	if ( isset( $ecapla_certverify_field_map[ $ecapla_certverify_key ] ) ) {
		$ecapla_certverify_lifecycle_fields[] = $ecapla_certverify_field_map[ $ecapla_certverify_key ];
	}
}
$ecapla_certverify_internal_field = $ecapla_certverify_field_map['internal_notes'] ?? null;
$ecapla_certverify_visibility_field = $ecapla_certverify_field_map['__certloom_profile_visibility'] ?? null;
$ecapla_certverify_effective_status = $certificate ? Ecapla_CertVerify_Database::effective_status( $certificate ) : 'valid';
$ecapla_certverify_statuses = Ecapla_CertVerify_Fields::get_statuses();
$ecapla_certverify_effective_label = isset( $ecapla_certverify_statuses[ $ecapla_certverify_effective_status ] ) ? $ecapla_certverify_statuses[ $ecapla_certverify_effective_status ]['label'] : ucfirst( $ecapla_certverify_effective_status );
$ecapla_certverify_setup_state = Ecapla_CertVerify_Onboarding::get_state();
$ecapla_certverify_verification_url = $certificate ? Ecapla_CertVerify_Public_Router::verification_url( (string) ( $certificate['cert_number'] ?? '' ) ) : '';
$ecapla_certverify_profile_url = $certificate && Ecapla_CertVerify_Public_Policy::MODE_PROFILE === Ecapla_CertVerify_Public_Policy::guest_mode( $certificate ) ? Ecapla_CertVerify_Public_Router::profile_url( (string) ( $certificate['cert_number'] ?? '' ) ) : '';
$ecapla_certverify_public_field_count = count( Ecapla_CertVerify_Fields::get_visible_fields( 'verification' ) );
$ecapla_certverify_profile_field_count = count( Ecapla_CertVerify_Fields::get_visible_fields( 'profile' ) );
$ecapla_certverify_datetime_format = trim( (string) get_option( 'date_format', 'F j, Y' ) . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
$ecapla_certverify_created_label = $certificate && ! empty( $certificate['created_at'] ) ? mysql2date( $ecapla_certverify_datetime_format, (string) $certificate['created_at'], true ) : '';
$ecapla_certverify_updated_label = $certificate && ! empty( $certificate['updated_at'] ) ? mysql2date( $ecapla_certverify_datetime_format, (string) $certificate['updated_at'], true ) : '';
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-certificate-editor">
	<section class="certloom-page-hero certloom-editor-hero" aria-labelledby="certloom-editor-title">
		<div>
			<a class="certloom-back-link" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'Back to credentials', 'certloom' ); ?></a>
			<span class="certloom-eyebrow"><?php echo $certificate ? esc_html__( 'Credential workspace', 'certloom' ) : esc_html__( 'New registry credential', 'certloom' ); ?></span>
			<h1 id="certloom-editor-title"><?php echo $certificate ? esc_html__( 'Credential details', 'certloom' ) : esc_html__( 'Add credential', 'certloom' ); ?></h1>
			<p><?php echo $certificate ? esc_html__( 'Review the current authoritative record by responsibility area without changing Certloom’s verification truth.', 'certloom' ) : esc_html__( 'Create a credential record in the current authoritative registry.', 'certloom' ); ?></p>
		</div>
		<?php if ( $certificate ) : ?>
			<div class="certloom-editor-status-card" aria-label="<?php esc_attr_e( 'Current certificate status', 'certloom' ); ?>">
				<span class="ecapla-certverify-badge ecapla-certverify-badge-<?php echo esc_attr( $ecapla_certverify_effective_status ); ?>"><?php echo esc_html( $ecapla_certverify_effective_label ); ?></span>
				<strong><?php echo esc_html( (string) ( $certificate['cert_number'] ?? '' ) ); ?></strong>
				<small><?php esc_html_e( 'Current public status', 'certloom' ); ?></small>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div>
	<?php endif; ?>
	<?php if ( $certificate && Ecapla_CertVerify_Database::is_legacy_expired( $certificate ) ) : ?>
		<div class="certloom-callout certloom-callout-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Legacy status review required', 'certloom' ); ?></strong><p><?php esc_html_e( 'This historical Expired record stays Expired until you deliberately choose Valid, Suspended, or Withdrawn and save it.', 'certloom' ); ?></p></div></div>
	<?php elseif ( $certificate && 'expired' === $ecapla_certverify_effective_status ) : ?>
		<div class="certloom-callout certloom-callout-warning"><span class="dashicons dashicons-clock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Expired by date', 'certloom' ); ?></strong><p><?php esc_html_e( 'The expiry date is in the past. Extend the date to renew the certificate; manual Suspended and Withdrawn statuses continue to take precedence.', 'certloom' ); ?></p></div></div>
	<?php endif; ?>

	<nav class="certloom-workspace-nav" aria-label="<?php esc_attr_e( 'Credential workspace sections', 'certloom' ); ?>">
		<a href="#certloom-workspace-overview"><?php esc_html_e( 'Overview', 'certloom' ); ?></a>
		<a href="#certloom-workspace-recipient"><?php esc_html_e( 'Recipient', 'certloom' ); ?></a>
		<a href="#certloom-workspace-program"><?php esc_html_e( 'Program', 'certloom' ); ?></a>
		<a href="#certloom-workspace-status"><?php esc_html_e( 'Status', 'certloom' ); ?></a>
		<a href="#certloom-workspace-custom"><?php esc_html_e( 'Custom Fields', 'certloom' ); ?></a>
		<a href="#certloom-workspace-verification"><?php esc_html_e( 'Verification', 'certloom' ); ?></a>
		<a href="#certloom-workspace-activity"><?php esc_html_e( 'Activity', 'certloom' ); ?></a>
		<a href="#certloom-workspace-actions"><?php esc_html_e( 'Actions', 'certloom' ); ?></a>
	</nav>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>" class="ecapla-certverify-admin-form certloom-editor-form">
		<?php wp_nonce_field( 'certverify_save_certificate' ); ?>
		<input type="hidden" name="cert_id" value="<?php echo esc_attr( $certificate['id'] ?? '' ); ?>" />

		<div class="certloom-editor-layout">
			<div class="certloom-editor-main">
				<section id="certloom-workspace-overview" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-overview-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Overview', 'certloom' ); ?></span><h2 id="certloom-overview-title"><?php esc_html_e( 'Credential identity & dates', 'certloom' ); ?></h2></div></div>
					<div class="certloom-form-grid">
						<?php foreach ( $ecapla_certverify_overview_fields as $field ) : ?><?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?><?php endforeach; ?>
					</div>
				</section>

				<section id="certloom-workspace-recipient" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-recipient-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Recipient', 'certloom' ); ?></span><h2 id="certloom-recipient-title"><?php esc_html_e( 'Current certificate holder', 'certloom' ); ?></h2></div></div>
					<?php if ( $ecapla_certverify_recipient_field ) : $field = $ecapla_certverify_recipient_field; ?><?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?><?php endif; ?>
					<p class="description certloom-gated-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'First-class Recipient identity, WordPress-user links and credential history are implemented in the normalized domain but remain production acceptance-gated. This live editor does not invent those relationships in legacy data.', 'certloom' ); ?></p>
				</section>

				<section id="certloom-workspace-program" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-program-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Program', 'certloom' ); ?></span><h2 id="certloom-program-title"><?php esc_html_e( 'Program relationship', 'certloom' ); ?></h2></div><span class="dashicons dashicons-lock" aria-hidden="true"></span></div>
					<p><?php esc_html_e( 'Standalone Programs are already implemented behind the normalized-domain acceptance gate. Program assignment is intentionally unavailable in the legacy authority instead of being simulated with a custom field.', 'certloom' ); ?></p>
				</section>

				<section id="certloom-workspace-custom" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-custom-fields-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Custom Fields', 'certloom' ); ?></span><h2 id="certloom-custom-fields-title"><?php esc_html_e( 'Credential-specific data', 'certloom' ); ?></h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-fields' ) ); ?>" class="certloom-inline-link"><?php esc_html_e( 'Manage fields', 'certloom' ); ?></a></div>
					<p class="certloom-section-intro"><?php esc_html_e( 'Labels and public/private visibility come from Field Manager, so editing and verification use the same schema.', 'certloom' ); ?></p>
					<?php if ( $ecapla_certverify_custom_fields ) : ?><div class="certloom-form-grid"><?php foreach ( $ecapla_certverify_custom_fields as $field ) : ?><?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?><?php endforeach; ?></div><?php else : ?><p class="certloom-empty-state"><?php esc_html_e( 'No additional custom fields are active.', 'certloom' ); ?></p><?php endif; ?>
				</section>

				<section id="certloom-workspace-verification" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-verification-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Verification', 'certloom' ); ?></span><h2 id="certloom-verification-title"><?php esc_html_e( 'Public verification', 'certloom' ); ?></h2></div></div>
					<p><?php echo esc_html( sprintf( _n( '%d field is currently approved for public verification output.', '%d fields are currently approved for public verification output.', $ecapla_certverify_public_field_count, 'certloom' ), $ecapla_certverify_public_field_count ) ); ?></p>
					<?php if ( $ecapla_certverify_visibility_field ) : $field = $ecapla_certverify_visibility_field; ?><div class="certloom-verification-policy"><?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?></div><?php endif; ?>
					<p><?php echo esc_html( sprintf( _n( '%d field is approved for Credential Profile output.', '%d fields are approved for Credential Profile output.', $ecapla_certverify_profile_field_count, 'certloom' ), $ecapla_certverify_profile_field_count ) ); ?></p>
					<div class="certloom-page-actions">
						<?php if ( $ecapla_certverify_verification_url ) : ?><a class="button button-secondary" href="<?php echo esc_url( $ecapla_certverify_verification_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification result', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php else : ?><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-setup' ) ); ?>"><?php esc_html_e( 'Configure verification page', 'certloom' ); ?></a><?php endif; ?>
						<?php if ( $ecapla_certverify_profile_url ) : ?><a class="button button-secondary" href="<?php echo esc_url( $ecapla_certverify_profile_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open credential profile', 'certloom' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'certloom' ); ?></span></a><?php endif; ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-fields' ) ); ?>"><?php esc_html_e( 'Review public fields', 'certloom' ); ?></a>
					</div>
				</section>

				<section id="certloom-workspace-activity" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-activity-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Activity', 'certloom' ); ?></span><h2 id="certloom-activity-title"><?php esc_html_e( 'Current record timeline', 'certloom' ); ?></h2></div></div>
					<?php if ( $certificate ) : ?><dl class="certloom-record-facts"><div><dt><?php esc_html_e( 'Created', 'certloom' ); ?></dt><dd><?php echo esc_html( $ecapla_certverify_created_label ?: __( 'Not recorded', 'certloom' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Last updated', 'certloom' ); ?></dt><dd><?php echo esc_html( $ecapla_certverify_updated_label ?: __( 'Not recorded', 'certloom' ) ); ?></dd></div></dl><?php else : ?><p><?php esc_html_e( 'Activity becomes available after the credential is saved.', 'certloom' ); ?></p><?php endif; ?>
					<p class="description certloom-gated-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Immutable lifecycle Event history is implemented in the normalized credential domain and remains acceptance-gated. Created/updated timestamps shown here are the truthful limits of the current live registry.', 'certloom' ); ?></p>
				</section>
			</div>

			<aside class="certloom-editor-sidebar" aria-label="<?php esc_attr_e( 'Credential lifecycle and internal data', 'certloom' ); ?>">
				<section id="certloom-workspace-status" class="certloom-panel certloom-editor-section" aria-labelledby="certloom-lifecycle-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Status', 'certloom' ); ?></span><h2 id="certloom-lifecycle-title"><?php esc_html_e( 'Status & reason', 'certloom' ); ?></h2></div></div>
					<div class="certloom-form-stack"><?php foreach ( $ecapla_certverify_lifecycle_fields as $field ) : ?><?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?><?php endforeach; ?></div>
				</section>

				<?php if ( $ecapla_certverify_internal_field ) : $field = $ecapla_certverify_internal_field; ?>
					<section class="certloom-panel certloom-editor-section certloom-internal-panel" aria-labelledby="certloom-internal-title">
						<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Private administration', 'certloom' ); ?></span><h2 id="certloom-internal-title"><?php esc_html_e( 'Internal notes', 'certloom' ); ?></h2></div><span class="dashicons dashicons-lock" aria-hidden="true"></span></div>
						<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?>
					</section>
				<?php endif; ?>
			</aside>
		</div>

		<div id="certloom-workspace-actions" class="certloom-editor-actions">
			<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Cancel', 'certloom' ); ?></a>
			<?php submit_button( $certificate ? __( 'Update credential', 'certloom' ) : __( 'Add credential', 'certloom' ), 'primary', 'certverify_save', false ); ?>
		</div>
	</form>
</div>
