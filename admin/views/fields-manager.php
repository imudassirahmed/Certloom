<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
$certloom_field_usage = ( new \Certloom\Infrastructure\Repository\ScopedFieldUsageRepository() )->all_usage();
$certloom_field_usage_error = is_wp_error( $certloom_field_usage ) ? $certloom_field_usage : null;
if ( $certloom_field_usage_error ) { $certloom_field_usage = array(); }
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-fields-screen">
	<section class="certloom-page-hero" aria-labelledby="certloom-fields-title">
		<div>
			<span class="certloom-eyebrow"><?php esc_html_e( 'Data schema', 'certloom' ); ?></span>
			<h1 id="certloom-fields-title"><?php esc_html_e( 'Field manager', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Manage the global field catalog used across Certloom. Program- and Batch-specific field sets are not configured on this screen.', 'certloom' ); ?></p>
		</div>
		<div class="certloom-page-actions">
			<a class="button button-secondary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><span class="dashicons dashicons-awards" aria-hidden="true"></span><?php esc_html_e( 'Open registry', 'certloom' ); ?></a>
		</div>
	</section>

	<?php foreach ( array( 'ecapla_certverify_fields_saved' => __( 'Field order, labels, and visibility saved.', 'certloom' ), 'ecapla_certverify_field_added' => __( 'Field added.', 'certloom' ), 'ecapla_certverify_field_deleted' => __( 'Custom field retired. Existing stored values remain protected and the key cannot be reused.', 'certloom' ), 'ecapla_certverify_field_restored' => __( 'Retired field restored as private. Review it before enabling public visibility.', 'certloom' ), 'ecapla_certverify_fields_retranslated' => __( 'Core field labels were reset for the current site language.', 'certloom' ) ) as $flag => $message ) : ?>
		<?php if ( isset( $_GET[ $flag ] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
	<?php endforeach; ?>
	<?php if ( isset( $_GET['ecapla_certverify_field_purged'] ) ) : ?>
		<?php $ecapla_certverify_purged_rows = isset( $_GET['rows'] ) ? absint( $_GET['rows'] ) : 0; ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of certificate records changed. */
					__( 'Retired field purged. Stored values were permanently removed from %d certificate records.', 'certloom' ),
					$ecapla_certverify_purged_rows
				)
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_purge_progress'] ) ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'A safe purge batch completed. Run Permanently purge values again to continue with the next batch; progress is retained between requests.', 'certloom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; ?>

	<div class="certloom-callout certloom-callout-info">
		<span class="dashicons dashicons-networking" aria-hidden="true"></span>
		<div>
			<strong><?php esc_html_e( 'Current scope: global field catalog', 'certloom' ); ?></strong>
			<p><?php esc_html_e( 'These definitions are shared product-wide. Assign Optional/Required rules from each Program, then use Batch overrides only when a cohort needs to add or tighten those requirements. Templates and issued credentials consume that contract; they do not create a competing field scope.', 'certloom' ); ?></p>
		</div>
	</div>

	<div class="certloom-callout certloom-callout-info">
		<span class="dashicons dashicons-shield" aria-hidden="true"></span>
		<div>
			<strong><?php esc_html_e( 'Privacy-first field schema', 'certloom' ); ?></strong>
			<p><?php esc_html_e( 'Fresh installations publish only certificate number and status. Classify data before exposing it, then choose whether a field may appear in verification, the richer credential profile, both, or neither. Internal fields are forced private server-side.', 'certloom' ); ?></p>
		</div>
	</div>

	<?php if ( $certloom_field_usage_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $certloom_field_usage_error->get_error_message() ); ?> <?php esc_html_e( 'Field retirement is disabled until usage can be verified safely.', 'certloom' ); ?></p></div>
	<?php endif; ?>

	<section class="certloom-panel certloom-fields-panel" aria-labelledby="certloom-active-fields-title">
		<div class="certloom-panel-header">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'Active schema', 'certloom' ); ?></span><h2 id="certloom-active-fields-title"><?php esc_html_e( 'Certificate fields', 'certloom' ); ?></h2></div>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecapla-certverify-inline-form">
					<input type="hidden" name="action" value="ecapla_certverify_retranslate_fields" />
					<?php wp_nonce_field( 'ecapla_certverify_retranslate_fields' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Reset core labels', 'certloom' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<p class="certloom-section-intro"><?php esc_html_e( 'Reorder with drag-and-drop or the keyboard-friendly Move controls. Labels are reused across the registry, editor, imports, exports, and public presentation.', 'certloom' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'certverify_save_fields' ); ?>
			<div id="ecapla-certloom-fields-list" class="ecapla-certloom-fields-list">
				<?php foreach ( Ecapla_CertVerify_Fields::get_manageable_fields() as $index => $field ) : ?>
					<div class="ecapla-certverify-field-row<?php echo ! empty( $field['locked_visibility'] ) ? ' is-internal-field' : ''; ?>">
						<span class="dashicons dashicons-menu ecapla-certverify-drag-handle" draggable="true" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'certloom' ); ?>"></span>
						<input type="hidden" name="fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" />
						<div class="certloom-field-name-cell">
							<label class="screen-reader-text" for="certloom-field-label-<?php echo esc_attr( $index ); ?>"><?php echo esc_html( sprintf( /* translators: %s: field key. */ __( 'Label for %s', 'certloom' ), $field['key'] ) ); ?></label>
							<input id="certloom-field-label-<?php echo esc_attr( $index ); ?>" type="text" name="fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="ecapla-certverify-field-label regular-text" />
							<code><?php echo esc_html( $field['key'] ); ?></code>
							<?php $certloom_usage = $certloom_field_usage[ $field['key'] ] ?? array( 'program_count' => 0, 'batch_count' => 0, 'template_count' => 0, 'referenced' => false ); ?>
							<?php if ( ! empty( $certloom_usage['referenced'] ) ) : ?>
								<small class="description certloom-field-usage"><?php echo esc_html( sprintf( /* translators: %1$d: program count; %2$d: batch count; %3$d: template count. */ __( 'In use: %1$d Programs · %2$d Batch overrides · %3$d Templates', 'certloom' ), absint( $certloom_usage['program_count'] ?? 0 ), absint( $certloom_usage['batch_count'] ?? 0 ), absint( $certloom_usage['template_count'] ?? 0 ) ) ); ?></small>
							<?php endif; ?>
						</div>
						<span class="ecapla-certverify-field-type"><?php echo esc_html( $field['type'] ); ?></span>
						<div class="certloom-field-policy-cell">
							<?php if ( ! empty( $field['locked_visibility'] ) ) : ?>
								<span class="ecapla-certverify-field-visibility certloom-visibility-pill is-internal"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Internal / private', 'certloom' ); ?></span>
								<input type="hidden" name="fields[<?php echo esc_attr( $index ); ?>][sensitivity]" value="internal" /><input type="hidden" name="fields[<?php echo esc_attr( $index ); ?>][public_exposure]" value="private" />
							<?php else : ?>
								<?php if ( Ecapla_CertVerify_Fields::is_required_core_field( $field['key'] ) ) : ?>
									<label><span><?php esc_html_e( 'Sensitivity', 'certloom' ); ?></span><input type="hidden" name="fields[<?php echo esc_attr( $index ); ?>][sensitivity]" value="public_safe" /><span class="certloom-visibility-pill is-public"><?php esc_html_e( 'Public-safe (required)', 'certloom' ); ?></span></label>
								<?php else : ?>
									<label><span><?php esc_html_e( 'Sensitivity', 'certloom' ); ?></span><select name="fields[<?php echo esc_attr( $index ); ?>][sensitivity]">
										<?php foreach ( Ecapla_CertVerify_Fields::get_sensitivity_options() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field['sensitivity'] ?? 'personal', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
									</select></label>
								<?php endif; ?>
								<label><span><?php esc_html_e( 'Public exposure', 'certloom' ); ?></span><select name="fields[<?php echo esc_attr( $index ); ?>][public_exposure]">
									<?php foreach ( Ecapla_CertVerify_Fields::get_public_exposure_options() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field['public_exposure'] ?? ( ! empty( $field['show_on_frontend'] ) ? 'both' : 'private' ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
								</select></label>
							<?php endif; ?>
						</div>
						<div class="ecapla-certverify-field-move-actions">
							<button type="button" class="button-link ecapla-certverify-move-up"><?php esc_html_e( 'Move up', 'certloom' ); ?></button>
							<button type="button" class="button-link ecapla-certverify-move-down"><?php esc_html_e( 'Move down', 'certloom' ); ?></button>
						</div>
						<?php if ( empty( $field['core'] ) ) : ?>
							<div class="ecapla-certverify-field-delete-wrap">
								<?php if ( $certloom_field_usage_error ) : ?>
									<span class="description"><?php esc_html_e( 'Usage check unavailable', 'certloom' ); ?></span>
								<?php elseif ( ! empty( $certloom_usage['referenced'] ) ) : ?>
									<span class="certloom-visibility-pill is-internal"><?php esc_html_e( 'In use', 'certloom' ); ?></span>
								<?php else : ?>
									<button type="submit" form="ecapla-certverify-delete-field-form" name="field_key" value="<?php echo esc_attr( $field['key'] ); ?>" class="button-link-delete" data-ecapla-confirm="<?php esc_attr_e( 'Retire this custom field? Existing issued history remains protected and the key will be reserved.', 'certloom' ); ?>"><?php esc_html_e( 'Retire', 'certloom' ); ?></button>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<span class="ecapla-certverify-core-field"><?php esc_html_e( 'Core field', 'certloom' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php submit_button( __( 'Save field layout', 'certloom' ), 'primary', 'certverify_save_fields' ); ?>
		</form>
	</section>

	<form id="ecapla-certverify-delete-field-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="ecapla_certverify_delete_field" />
		<?php wp_nonce_field( 'ecapla_certverify_delete_field' ); ?>
	</form>

	<div class="certloom-fields-lower-grid">
		<section class="certloom-panel certloom-fields-panel" aria-labelledby="certloom-add-field-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Extend the schema', 'certloom' ); ?></span><h2 id="certloom-add-field-title"><?php esc_html_e( 'Add a custom field', 'certloom' ); ?></h2></div></div>
			<p class="certloom-section-intro"><?php esc_html_e( 'New custom fields are personal and private by default. Classify them deliberately before exposing them in verification or Credential Profile output.', 'certloom' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'certverify_add_field' ); ?>
				<div class="certloom-form-stack">
					<div class="certloom-form-field"><label for="new_field_label"><?php esc_html_e( 'Label', 'certloom' ); ?></label><input type="text" name="new_field_label" id="new_field_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Accreditation body', 'certloom' ); ?>" required /></div>
					<div class="certloom-form-field"><label for="new_field_type"><?php esc_html_e( 'Type', 'certloom' ); ?></label><select name="new_field_type" id="new_field_type"><option value="text"><?php esc_html_e( 'Text', 'certloom' ); ?></option><option value="textarea"><?php esc_html_e( 'Long text', 'certloom' ); ?></option><option value="date"><?php esc_html_e( 'Date', 'certloom' ); ?></option><option value="number"><?php esc_html_e( 'Number', 'certloom' ); ?></option></select></div>
				</div>
				<?php submit_button( __( 'Add field', 'certloom' ), 'secondary', 'certverify_add_field' ); ?>
			</form>
		</section>

		<section class="certloom-panel certloom-fields-panel" aria-labelledby="certloom-retired-fields-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Historical safety', 'certloom' ); ?></span><h2 id="certloom-retired-fields-title"><?php esc_html_e( 'Retired custom fields', 'certloom' ); ?></h2></div></div>
			<p class="certloom-section-intro"><?php esc_html_e( 'Retired keys remain reserved so old private data cannot be accidentally exposed by a newly created field.', 'certloom' ); ?></p>

			<?php $retired_fields = Ecapla_CertVerify_Fields::get_retired_fields(); ?>
			<?php if ( empty( $retired_fields ) ) : ?>
				<div class="ecapla-certverify-empty-state"><p><?php esc_html_e( 'No retired custom fields.', 'certloom' ); ?></p></div>
			<?php else : ?>
				<div class="ecapla-certverify-retired-fields">
					<?php foreach ( $retired_fields as $field ) : ?>
						<div class="ecapla-certverify-retired-field">
							<div><strong><?php echo esc_html( $field['label'] ); ?></strong><code><?php echo esc_html( $field['key'] ); ?></code><span class="ecapla-certverify-field-type"><?php echo esc_html( $field['type'] ); ?></span></div>
							<div class="ecapla-certverify-retired-actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="ecapla_certverify_restore_field" /><input type="hidden" name="field_key" value="<?php echo esc_attr( $field['key'] ); ?>" /><?php wp_nonce_field( 'ecapla_certverify_restore_field' ); ?><button type="submit" class="button button-secondary"><?php esc_html_e( 'Restore privately', 'certloom' ); ?></button>
								</form>
								<?php if ( current_user_can( 'manage_options' ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="ecapla_certverify_purge_field" /><input type="hidden" name="field_key" value="<?php echo esc_attr( $field['key'] ); ?>" /><?php wp_nonce_field( 'ecapla_certverify_purge_field' ); ?><button type="submit" class="button-link-delete" data-ecapla-confirm="<?php esc_attr_e( 'Permanently remove this field and all stored values from every certificate? This cannot be undone.', 'certloom' ); ?>"><?php esc_html_e( 'Permanently purge values', 'certloom' ); ?></button>
									</form>
								<?php else : ?><span class="description"><?php esc_html_e( 'Only an administrator can permanently purge values.', 'certloom' ); ?></span><?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>
