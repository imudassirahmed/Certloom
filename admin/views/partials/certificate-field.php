<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ecapla_certverify_is_column = ! empty( $field['column'] );
$ecapla_certverify_name      = $ecapla_certverify_is_column ? $field['key'] : 'cf_' . $field['key'];
$ecapla_certverify_value     = $ecapla_certverify_is_column
	? ( $certificate[ $field['key'] ] ?? '' )
	: ( $certificate['custom_fields'][ $field['key'] ] ?? '' );
$ecapla_certverify_locked    = ! empty( $field['locked_visibility'] );
$ecapla_certverify_public    = ! $ecapla_certverify_locked && ! empty( $field['show_on_frontend'] );
?>
<div class="certloom-form-field certloom-form-field-<?php echo esc_attr( $field['key'] ); ?>">
	<div class="certloom-form-label-row">
		<label for="<?php echo esc_attr( $ecapla_certverify_name ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
		<?php if ( $ecapla_certverify_locked ) : ?>
			<span class="certloom-visibility-pill is-internal"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Internal only', 'certloom' ); ?></span>
		<?php elseif ( $ecapla_certverify_public ) : ?>
			<span class="certloom-visibility-pill is-public"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Public result', 'certloom' ); ?></span>
		<?php else : ?>
			<span class="certloom-visibility-pill is-private"><span class="dashicons dashicons-hidden" aria-hidden="true"></span><?php esc_html_e( 'Private', 'certloom' ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( 'status' === $field['type'] ) : ?>
		<select name="status" id="status" required>
			<?php if ( $certificate && Ecapla_CertVerify_Database::is_legacy_expired( $certificate ) ) : ?>
				<option value="expired" <?php selected( $ecapla_certverify_value, 'expired' ); ?>><?php esc_html_e( 'Expired — legacy review required', 'certloom' ); ?></option>
			<?php endif; ?>
			<?php foreach ( Ecapla_CertVerify_Fields::get_editable_statuses() as $key => $status ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ecapla_certverify_value ?: 'valid', $key ); ?>><?php echo esc_html( $status['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
	<?php elseif ( 'date' === $field['type'] ) : ?>
		<input type="date" name="<?php echo esc_attr( $ecapla_certverify_name ); ?>" id="<?php echo esc_attr( $ecapla_certverify_name ); ?>" value="<?php echo esc_attr( $ecapla_certverify_value ); ?>" />
	<?php elseif ( 'textarea' === $field['type'] ) : ?>
		<textarea name="<?php echo esc_attr( $ecapla_certverify_name ); ?>" id="<?php echo esc_attr( $ecapla_certverify_name ); ?>" rows="<?php echo 'internal_notes' === $field['key'] ? '6' : '4'; ?>" class="large-text"><?php echo esc_textarea( $ecapla_certverify_value ); ?></textarea>
	<?php elseif ( 'number' === $field['type'] ) : ?>
		<input type="number" step="any" name="<?php echo esc_attr( $ecapla_certverify_name ); ?>" id="<?php echo esc_attr( $ecapla_certverify_name ); ?>" value="<?php echo esc_attr( $ecapla_certverify_value ); ?>" class="regular-text" />
	<?php else : ?>
		<input type="text" name="<?php echo esc_attr( $ecapla_certverify_name ); ?>" id="<?php echo esc_attr( $ecapla_certverify_name ); ?>" value="<?php echo esc_attr( $ecapla_certverify_value ); ?>" class="regular-text"<?php if ( 'cert_number' === $field['key'] ) : ?> maxlength="100" required<?php endif; ?><?php if ( 'holder_name' === $field['key'] ) : ?> maxlength="255"<?php endif; ?> />
	<?php endif; ?>

	<?php if ( 'cert_number' === $field['key'] ) : ?>
		<p class="description"><?php esc_html_e( 'Visitors must enter the complete certificate number. Letter case is ignored; spaces, punctuation, and hyphens must match. The value is unique and limited to 100 characters.', 'certloom' ); ?></p>
	<?php elseif ( 'status' === $field['type'] ) : ?>
		<p class="description"><?php esc_html_e( 'Valid records automatically display as Expired after their expiry date. Suspended and Withdrawn remain manual workflow decisions.', 'certloom' ); ?></p>
	<?php elseif ( 'status_reason' === $field['key'] ) : ?>
		<p class="description"><?php esc_html_e( 'Explain a suspension, withdrawal, renewal, or other lifecycle decision. Public visibility is controlled in Field Manager.', 'certloom' ); ?></p>
	<?php elseif ( 'internal_notes' === $field['key'] ) : ?>
		<p class="description"><?php esc_html_e( 'Administrator-only notes. Certloom permanently locks this field out of public verification output.', 'certloom' ); ?></p>
	<?php endif; ?>
</div>
