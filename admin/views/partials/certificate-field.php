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
$ecapla_certverify_exposure  = isset( $field['public_exposure'] ) ? sanitize_key( (string) $field['public_exposure'] ) : ( ! empty( $field['show_on_frontend'] ) ? 'both' : 'private' );
$ecapla_certverify_public    = ! $ecapla_certverify_locked && 'private' !== $ecapla_certverify_exposure;
$ecapla_certverify_sensitivity = isset( $field['sensitivity'] ) ? sanitize_key( (string) $field['sensitivity'] ) : 'personal';
?>
<div class="certloom-form-field certloom-form-field-<?php echo esc_attr( $field['key'] ); ?>">
	<div class="certloom-form-label-row">
		<label for="<?php echo esc_attr( $ecapla_certverify_name ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
		<?php if ( $ecapla_certverify_locked ) : ?>
			<span class="certloom-visibility-pill is-internal"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Internal only', 'certloom' ); ?></span>
		<?php elseif ( $ecapla_certverify_public ) : ?>
			<span class="certloom-visibility-pill is-public"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php echo esc_html( 'verification' === $ecapla_certverify_exposure ? __( 'Verification only', 'certloom' ) : ( 'profile' === $ecapla_certverify_exposure ? __( 'Profile only', 'certloom' ) : __( 'Verification + profile', 'certloom' ) ) ); ?></span>
		<?php else : ?>
			<span class="certloom-visibility-pill is-private"><span class="dashicons dashicons-hidden" aria-hidden="true"></span><?php esc_html_e( 'Private', 'certloom' ); ?></span>
		<?php endif; ?>
		<?php if ( ! $ecapla_certverify_locked && 'public_safe' !== $ecapla_certverify_sensitivity ) : ?><span class="certloom-sensitivity-pill is-<?php echo esc_attr( $ecapla_certverify_sensitivity ); ?>"><?php echo esc_html( Ecapla_CertVerify_Fields::get_sensitivity_options()[ $ecapla_certverify_sensitivity ] ?? ucfirst( $ecapla_certverify_sensitivity ) ); ?></span><?php endif; ?>
	</div>

	<?php if ( 'visibility' === $field['type'] ) : ?>
	<select id="<?php echo esc_attr( $ecapla_certverify_name ); ?>" name="<?php echo esc_attr( $ecapla_certverify_name ); ?>">
		<?php $certloom_visibility_value = '' !== (string) $ecapla_certverify_value ? (string) $ecapla_certverify_value : 'public'; ?>
		<?php foreach ( Ecapla_CertVerify_Public_Policy::visibility_options() as $certloom_visibility_key => $certloom_visibility_label ) : ?>
			<option value="<?php echo esc_attr( $certloom_visibility_key ); ?>" <?php selected( $certloom_visibility_value, $certloom_visibility_key ); ?>><?php echo esc_html( $certloom_visibility_label ); ?></option>
		<?php endforeach; ?>
	</select>
	<p class="description"><?php esc_html_e( 'Public profile exposes verification and the Credential Profile. Verification only hides the richer profile. Private hides the credential from guest verification/profile presentation. Existing records default to Public profile for backward compatibility.', 'certloom' ); ?></p>
<?php elseif ( 'status' === $field['type'] ) : ?>
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
		<input type="text" name="<?php echo esc_attr( $ecapla_certverify_name ); ?>" id="<?php echo esc_attr( $ecapla_certverify_name ); ?>" value="<?php echo esc_attr( $ecapla_certverify_value ); ?>" class="regular-text"<?php if ( 'cert_number' === $field['key'] ) : ?> maxlength="100"<?php if ( empty( $ecapla_certverify_auto_numbering_available ) ) : ?> required<?php endif; ?><?php endif; ?><?php if ( 'holder_name' === $field['key'] ) : ?> maxlength="255"<?php endif; ?> />
	<?php endif; ?>

	<?php if ( 'cert_number' === $field['key'] ) : ?>
		<?php if ( ! empty( $ecapla_certverify_auto_numbering_available ) ) : ?>
			<p class="description"><?php esc_html_e( 'Enter a certificate number for a deliberate manual override, or leave this blank to generate one using Settings → Certificate numbering. Automatic numbering applies only to new credentials and never renumbers saved records.', 'certloom' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Generation preview:', 'certloom' ); ?></strong> <code><?php echo esc_html( (string) $ecapla_certverify_numbering_preview['certificate_number'] ); ?></code> <?php esc_html_e( '(not reserved until save)', 'certloom' ); ?></p>
		<?php elseif ( ! empty( $ecapla_certverify_numbering_enabled ) && isset( $ecapla_certverify_numbering_preview ) && is_wp_error( $ecapla_certverify_numbering_preview ) ) : ?>
			<p class="description ecapla-certverify-warning-text"><strong><?php esc_html_e( 'Automatic numbering is unavailable:', 'certloom' ); ?></strong> <?php echo esc_html( $ecapla_certverify_numbering_preview->get_error_message() ); ?> <?php esc_html_e( 'Enter a certificate number manually or repair the numbering policy/state in Settings before saving.', 'certloom' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Visitors must enter the complete certificate number. Letter case is ignored; spaces, punctuation, and hyphens must match. The value is unique and limited to 100 characters.', 'certloom' ); ?></p>
		<?php endif; ?>
	<?php elseif ( 'status' === $field['type'] ) : ?>
		<p class="description"><?php esc_html_e( 'Valid records automatically display as Expired after their expiry date. Suspended and Withdrawn remain manual workflow decisions.', 'certloom' ); ?></p>
	<?php elseif ( 'status_reason' === $field['key'] ) : ?>
		<p class="description"><?php esc_html_e( 'Explain a suspension, withdrawal, renewal, or other lifecycle decision. Public visibility is controlled in Field Manager.', 'certloom' ); ?></p>
	<?php elseif ( 'internal_notes' === $field['key'] ) : ?>
		<p class="description"><?php esc_html_e( 'Administrator-only notes. Certloom permanently locks this field out of public verification output.', 'certloom' ); ?></p>
	<?php endif; ?>
</div>
