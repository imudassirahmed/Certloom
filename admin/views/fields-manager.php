<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Field manager', 'certverify' ); ?></h1>

	<?php foreach ( array( 'ecapla_certverify_fields_saved' => __( 'Field order, labels, and visibility saved.', 'certverify' ), 'ecapla_certverify_field_added' => __( 'Field added.', 'certverify' ), 'ecapla_certverify_field_deleted' => __( 'Custom field retired. Existing stored values remain protected and the key cannot be reused.', 'certverify' ), 'ecapla_certverify_field_restored' => __( 'Retired field restored as private. Review it before enabling public visibility.', 'certverify' ), 'ecapla_certverify_fields_retranslated' => __( 'Core field labels were reset for the current site language.', 'certverify' ) ) as $flag => $message ) : ?>
		<?php if ( isset( $_GET[ $flag ] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
	<?php endforeach; ?>
	<?php if ( isset( $_GET['ecapla_certverify_field_purged'] ) ) : ?>
		<?php $ecapla_certverify_purged_rows = isset( $_GET['rows'] ) ? absint( $_GET['rows'] ) : 0; ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of certificate records changed. */
					__( 'Retired field purged. Stored values were permanently removed from %d certificate records.', 'certverify' ),
					$ecapla_certverify_purged_rows
				)
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_purge_progress'] ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'A safe purge batch completed. Run Permanently purge values again to continue with the next batch; progress is retained between requests.', 'certverify' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; ?>

	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecapla-certverify-inline-form">
			<input type="hidden" name="action" value="ecapla_certverify_retranslate_fields" />
			<?php wp_nonce_field( 'ecapla_certverify_retranslate_fields' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Reset core labels to current site language', 'certverify' ); ?></button>
		</form>
	<?php endif; ?>

	<p class="description"><?php esc_html_e( 'Reorder fields with drag-and-drop or the Move up/Move down buttons. Fresh installations publish only the certificate number and status; review and enable any additional public fields deliberately. Core database fields remain available in the editor so certificate data cannot become inaccessible.', 'certverify' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( 'certverify_save_fields' ); ?>
		<div id="ecapla-certverify-fields-list" class="ecapla-certverify-fields-list">
			<?php foreach ( Ecapla_CertVerify_Fields::get_fields() as $index => $field ) : ?>
				<div class="ecapla-certverify-field-row">
					<span class="dashicons dashicons-menu ecapla-certverify-drag-handle" draggable="true" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'certverify' ); ?>"></span>
					<input type="hidden" name="fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" />
					<input type="text" name="fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="ecapla-certverify-field-label regular-text" />
					<span class="ecapla-certverify-field-type"><?php echo esc_html( $field['type'] ); ?></span>
					<label class="ecapla-certverify-field-visibility"><input type="checkbox" name="fields[<?php echo esc_attr( $index ); ?>][show_on_frontend]" value="1" <?php checked( ! empty( $field['show_on_frontend'] ) ); ?> /> <?php esc_html_e( 'Show on public result', 'certverify' ); ?></label>
					<div class="ecapla-certverify-field-move-actions">
						<button type="button" class="button-link ecapla-certverify-move-up"><?php esc_html_e( 'Move up', 'certverify' ); ?></button>
						<button type="button" class="button-link ecapla-certverify-move-down"><?php esc_html_e( 'Move down', 'certverify' ); ?></button>
					</div>
					<?php if ( empty( $field['core'] ) ) : ?>
						<div class="ecapla-certverify-field-delete-wrap">
							<button type="submit" form="ecapla-certverify-delete-field-form" name="field_key" value="<?php echo esc_attr( $field['key'] ); ?>" class="button-link-delete" data-ecapla-confirm="<?php esc_attr_e( 'Retire this custom field? Existing stored values will remain protected and the key will be reserved.', 'certverify' ); ?>"><?php esc_html_e( 'Remove', 'certverify' ); ?></button>
						</div>
					<?php else : ?>
						<span class="ecapla-certverify-core-field"><?php esc_html_e( 'Core field', 'certverify' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php submit_button( __( 'Save field layout', 'certverify' ), 'primary', 'certverify_save_fields' ); ?>
	</form>

	<form id="ecapla-certverify-delete-field-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="ecapla_certverify_delete_field" />
		<?php wp_nonce_field( 'ecapla_certverify_delete_field' ); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Add a custom field', 'certverify' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Custom fields are stored with each certificate and can be positioned anywhere. New custom fields are private by default; enable public visibility only after reviewing the data they contain.', 'certverify' ); ?></p>
	<form method="post" action="">
		<?php wp_nonce_field( 'certverify_add_field' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="new_field_label"><?php esc_html_e( 'Label', 'certverify' ); ?></label></th><td><input type="text" name="new_field_label" id="new_field_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Accreditation body', 'certverify' ); ?>" required /></td></tr>
			<tr><th scope="row"><label for="new_field_type"><?php esc_html_e( 'Type', 'certverify' ); ?></label></th><td><select name="new_field_type" id="new_field_type"><option value="text"><?php esc_html_e( 'Text', 'certverify' ); ?></option><option value="textarea"><?php esc_html_e( 'Long text', 'certverify' ); ?></option><option value="date"><?php esc_html_e( 'Date', 'certverify' ); ?></option><option value="number"><?php esc_html_e( 'Number', 'certverify' ); ?></option></select></td></tr>
		</table>
		<?php submit_button( __( 'Add field', 'certverify' ), 'secondary', 'certverify_add_field' ); ?>
	</form>

	<?php $retired_fields = Ecapla_CertVerify_Fields::get_retired_fields(); ?>
	<hr />
	<h2><?php esc_html_e( 'Retired custom fields', 'certverify' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Removing a custom field hides it without discarding historical values. Retired keys stay reserved so old data cannot be exposed accidentally by a newly created field. Restore a field to use its values again, or permanently purge those values when deletion is intentional.', 'certverify' ); ?></p>

	<?php if ( empty( $retired_fields ) ) : ?>
		<div class="ecapla-certverify-empty-state"><p><?php esc_html_e( 'No retired custom fields.', 'certverify' ); ?></p></div>
	<?php else : ?>
		<div class="ecapla-certverify-retired-fields">
			<?php foreach ( $retired_fields as $field ) : ?>
				<div class="ecapla-certverify-retired-field">
					<div>
						<strong><?php echo esc_html( $field['label'] ); ?></strong>
						<code><?php echo esc_html( $field['key'] ); ?></code>
						<span class="ecapla-certverify-field-type"><?php echo esc_html( $field['type'] ); ?></span>
					</div>
					<div class="ecapla-certverify-retired-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ecapla_certverify_restore_field" />
							<input type="hidden" name="field_key" value="<?php echo esc_attr( $field['key'] ); ?>" />
							<?php wp_nonce_field( 'ecapla_certverify_restore_field' ); ?>
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Restore privately', 'certverify' ); ?></button>
						</form>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="ecapla_certverify_purge_field" />
								<input type="hidden" name="field_key" value="<?php echo esc_attr( $field['key'] ); ?>" />
								<?php wp_nonce_field( 'ecapla_certverify_purge_field' ); ?>
								<button type="submit" class="button-link-delete" data-ecapla-confirm="<?php esc_attr_e( 'Permanently remove this field and all stored values from every certificate? This cannot be undone.', 'certverify' ); ?>"><?php esc_html_e( 'Permanently purge values', 'certverify' ); ?></button>
							</form>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Only an administrator can permanently purge values.', 'certverify' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
