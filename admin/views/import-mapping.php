<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Map CSV columns', 'certverify' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Confirm which certificate field each CSV column represents. Columns set to Skip are ignored. Certificate number must be mapped.', 'certverify' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( 'certverify_import_process' ); ?>
		<input type="hidden" name="import_token" value="<?php echo esc_attr( $token ); ?>" />
		<div class="ecapla-certverify-table-scroll">
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'CSV column', 'certverify' ); ?></th><th><?php esc_html_e( 'Map to', 'certverify' ); ?></th><?php foreach ( $preview as $preview_index => $preview_row ) : ?><th><?php printf( /* translators: %d: preview row number. */ esc_html__( 'Example %d', 'certverify' ), (int) $preview_index + 1 ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
					<?php foreach ( $header as $col_index => $column_name ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $column_name ); ?></th>
							<td>
								<label class="screen-reader-text" for="map-<?php echo esc_attr( $col_index ); ?>"><?php printf( /* translators: %s: CSV column heading. */ esc_html__( 'Map %s', 'certverify' ), esc_html( $column_name ) ); ?></label>
								<select id="map-<?php echo esc_attr( $col_index ); ?>" name="map[<?php echo esc_attr( $col_index ); ?>]">
									<option value=""><?php esc_html_e( 'Skip', 'certverify' ); ?></option>
									<?php foreach ( $fields as $field ) : ?><option value="<?php echo esc_attr( $field['key'] ); ?>" <?php selected( $guessed_mapping[ $col_index ] ?? '', $field['key'] ); ?>><?php echo esc_html( $field['label'] ); ?></option><?php endforeach; ?>
								</select>
							</td>
							<?php foreach ( $preview as $preview_row ) : ?><td><?php echo esc_html( $preview_row[ $col_index ] ?? '' ); ?></td><?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="ecapla-certverify-import-options">
			<label><input type="checkbox" name="update_existing" value="1" /> <?php esc_html_e( 'Update records when the certificate number already exists', 'certverify' ); ?></label>
			<p class="description"><?php esc_html_e( 'Only mapped, non-empty columns are changed. Unmapped fields and stored custom-field data remain untouched.', 'certverify' ); ?></p>
			<label><input type="checkbox" name="clear_empty" value="1" /> <?php esc_html_e( 'Treat blank mapped cells as instructions to clear existing values', 'certverify' ); ?></label>
			<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Use the blank-cell option carefully. It is off by default to prevent accidental data loss.', 'certverify' ); ?></p>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<label><input type="checkbox" name="historical_restore" value="1" /> <?php esc_html_e( 'Historical restore mode', 'certverify' ); ?></label>
				<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Use only for trusted CertVerify backups that contain explicitly restored Expired records. New Expired rows are stored as protected legacy data for administrator review.', 'certverify' ); ?></p>
			<?php endif; ?>
		</div>
		<?php submit_button( __( 'Run import', 'certverify' ), 'primary', 'certverify_import_process' ); ?>
	</form>
</div>
