<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php echo $certificate ? esc_html__( 'Edit certificate', 'certverify' ) : esc_html__( 'Add certificate', 'certverify' ); ?></h1>

	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div>
	<?php endif; ?>
	<?php if ( $certificate && Ecapla_CertVerify_Database::is_legacy_expired( $certificate ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'This is a legacy Expired record whose original status source is unknown. It will remain Expired until you deliberately choose Valid, Suspended, or Withdrawn and update the certificate.', 'certverify' ); ?></p></div>
	<?php elseif ( $certificate && 'expired' === Ecapla_CertVerify_Database::effective_status( $certificate ) ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'This certificate is Expired because its expiry date has passed. Extend the expiry date to renew it; Suspended and Withdrawn remain manual statuses.', 'certverify' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=certverify' ) ); ?>" class="ecapla-certverify-admin-form">
		<?php wp_nonce_field( 'certverify_save_certificate' ); ?>
		<input type="hidden" name="cert_id" value="<?php echo esc_attr( $certificate['id'] ?? '' ); ?>" />
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $field ) :
				$is_core = ! empty( $field['column'] );
				$name    = $is_core ? $field['key'] : 'cf_' . $field['key'];
				$value   = $is_core ? ( $certificate[ $field['key'] ] ?? '' ) : ( $certificate['custom_fields'][ $field['key'] ] ?? '' );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
					<td>
						<?php if ( 'status' === $field['type'] ) : ?>
							<select name="status" id="status" required>
								<?php if ( $certificate && Ecapla_CertVerify_Database::is_legacy_expired( $certificate ) ) : ?>
									<option value="expired" <?php selected( $value, 'expired' ); ?>><?php esc_html_e( 'Expired — legacy review required', 'certverify' ); ?></option>
								<?php endif; ?>
								<?php foreach ( Ecapla_CertVerify_Fields::get_editable_statuses() as $key => $status ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value ?: 'valid', $key ); ?>><?php echo esc_html( $status['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( 'date' === $field['type'] ) : ?>
							<input type="date" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
						<?php elseif ( 'textarea' === $field['type'] ) : ?>
							<textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
						<?php elseif ( 'number' === $field['type'] ) : ?>
							<input type="number" step="any" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
						<?php else : ?>
							<input type="text" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text"<?php if ( 'cert_number' === $field['key'] ) : ?> maxlength="100" required<?php endif; ?><?php if ( 'holder_name' === $field['key'] ) : ?> maxlength="255"<?php endif; ?> />
						<?php endif; ?>
						<?php if ( 'cert_number' === $field['key'] ) : ?>
							<p class="description"><?php esc_html_e( 'Visitors must enter the complete certificate number. Letter case is ignored; spaces, punctuation, and hyphens must match. The value is unique and limited to 100 characters.', 'certverify' ); ?></p>
						<?php elseif ( 'status' === $field['type'] ) : ?>
							<p class="description"><?php esc_html_e( 'Valid records automatically display as Expired after their expiry date. Suspended and Withdrawn remain manual decisions. Legacy Expired records remain protected until explicitly reviewed.', 'certverify' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php submit_button( $certificate ? __( 'Update certificate', 'certverify' ) : __( 'Add certificate', 'certverify' ), 'primary', 'certverify_save' ); ?>
	</form>
</div>
