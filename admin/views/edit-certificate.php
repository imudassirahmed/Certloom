<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
$ecapla_certverify_field_map = Ecapla_CertVerify_Fields::get_field_map();
$ecapla_certverify_main_fields = array_values(
	array_filter(
		$fields,
		static function ( $field ) {
			return ! in_array( $field['key'], array( 'status', 'status_reason', 'internal_notes' ), true );
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
$ecapla_certverify_effective_status = $certificate ? Ecapla_CertVerify_Database::effective_status( $certificate ) : 'valid';
$ecapla_certverify_statuses = Ecapla_CertVerify_Fields::get_statuses();
$ecapla_certverify_effective_label = isset( $ecapla_certverify_statuses[ $ecapla_certverify_effective_status ] ) ? $ecapla_certverify_statuses[ $ecapla_certverify_effective_status ]['label'] : ucfirst( $ecapla_certverify_effective_status );
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-certificate-editor">
	<section class="certloom-page-hero certloom-editor-hero" aria-labelledby="certloom-editor-title">
		<div>
			<a class="certloom-back-link" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><span aria-hidden="true">←</span> <?php esc_html_e( 'Back to registry', 'certloom' ); ?></a>
			<span class="certloom-eyebrow"><?php echo $certificate ? esc_html__( 'Registry record', 'certloom' ) : esc_html__( 'New registry record', 'certloom' ); ?></span>
			<h1 id="certloom-editor-title"><?php echo $certificate ? esc_html__( 'Edit certificate', 'certloom' ) : esc_html__( 'Add certificate', 'certloom' ); ?></h1>
			<p><?php echo $certificate ? esc_html__( 'Update certificate data while preserving Certloom’s authoritative verification rules.', 'certloom' ) : esc_html__( 'Create a certificate record in the current authoritative registry.', 'certloom' ); ?></p>
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

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>" class="ecapla-certverify-admin-form certloom-editor-form">
		<?php wp_nonce_field( 'certverify_save_certificate' ); ?>
		<input type="hidden" name="cert_id" value="<?php echo esc_attr( $certificate['id'] ?? '' ); ?>" />

		<div class="certloom-editor-layout">
			<div class="certloom-editor-main">
				<section class="certloom-panel certloom-editor-section" aria-labelledby="certloom-certificate-details-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Certificate data', 'certloom' ); ?></span><h2 id="certloom-certificate-details-title"><?php esc_html_e( 'Certificate details', 'certloom' ); ?></h2></div></div>
					<p class="certloom-section-intro"><?php esc_html_e( 'Field labels and public/private visibility come directly from Field Manager, so the editor and verifier share one schema.', 'certloom' ); ?></p>
					<div class="certloom-form-grid">
						<?php foreach ( $ecapla_certverify_main_fields as $field ) : ?>
							<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?>
						<?php endforeach; ?>
					</div>
				</section>
			</div>

			<aside class="certloom-editor-sidebar" aria-label="<?php esc_attr_e( 'Certificate lifecycle and internal data', 'certloom' ); ?>">
				<section class="certloom-panel certloom-editor-section" aria-labelledby="certloom-lifecycle-title">
					<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Lifecycle', 'certloom' ); ?></span><h2 id="certloom-lifecycle-title"><?php esc_html_e( 'Status & reason', 'certloom' ); ?></h2></div></div>
					<div class="certloom-form-stack">
						<?php foreach ( $ecapla_certverify_lifecycle_fields as $field ) : ?>
							<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?>
						<?php endforeach; ?>
					</div>
				</section>

				<?php if ( $ecapla_certverify_internal_field ) : $field = $ecapla_certverify_internal_field; ?>
					<section class="certloom-panel certloom-editor-section certloom-internal-panel" aria-labelledby="certloom-internal-title">
						<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Private administration', 'certloom' ); ?></span><h2 id="certloom-internal-title"><?php esc_html_e( 'Internal notes', 'certloom' ); ?></h2></div><span class="dashicons dashicons-lock" aria-hidden="true"></span></div>
						<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/certificate-field.php'; ?>
					</section>
				<?php endif; ?>
			</aside>
		</div>

		<div class="certloom-editor-actions">
			<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Cancel', 'certloom' ); ?></a>
			<?php submit_button( $certificate ? __( 'Update certificate', 'certloom' ) : __( 'Add certificate', 'certloom' ), 'primary', 'certverify_save', false ); ?>
		</div>
	</form>
</div>
