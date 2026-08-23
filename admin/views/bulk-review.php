<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped to the rendering method.
$certloom_bulk_count   = count( (array) $selected_records );
$certloom_bulk_preview = isset( $bulk_preview ) && is_array( $bulk_preview ) ? $bulk_preview : null;
$certloom_bulk_statuses = Ecapla_CertVerify_Fields::get_editable_statuses();
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-bulk-review-screen">
	<section class="certloom-page-hero" aria-labelledby="certloom-bulk-title">
		<div>
			<span class="certloom-eyebrow"><?php esc_html_e( 'Safe bulk management', 'certloom' ); ?></span>
			<h1 id="certloom-bulk-title"><?php esc_html_e( 'Review bulk change', 'certloom' ); ?></h1>
			<p><?php echo esc_html( sprintf( _n( '%d certificate is frozen in this review session.', '%d certificates are frozen in this review session.', $certloom_bulk_count, 'certloom' ), $certloom_bulk_count ) ); ?></p>
		</div>
		<div class="certloom-page-actions">
			<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Cancel', 'certloom' ); ?></a>
		</div>
	</section>

	<div class="certloom-callout certloom-callout-info">
		<span class="dashicons dashicons-lock" aria-hidden="true"></span>
		<div><strong><?php esc_html_e( 'Selection is frozen before mutation', 'certloom' ); ?></strong><p><?php esc_html_e( 'Changing filters or registry contents does not silently add certificates to this action. You review the exact operation and eligible count before commit.', 'certloom' ); ?></p></div>
	</div>

	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div>
	<?php endif; ?>

	<div class="certloom-bulk-layout">
		<section class="certloom-panel" aria-labelledby="certloom-bulk-change-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Step 1', 'certloom' ); ?></span><h2 id="certloom-bulk-change-title"><?php esc_html_e( 'Define the change', 'certloom' ); ?></h2></div></div>
			<form method="post" action="" class="certloom-bulk-form">
				<?php wp_nonce_field( 'certverify_bulk_preview' ); ?>
				<input type="hidden" name="bulk_token" value="<?php echo esc_attr( $token ); ?>" />
				<div class="certloom-form-grid">
					<div class="certloom-form-field certloom-form-field-wide">
						<label for="certloom-bulk-operation"><strong><?php esc_html_e( 'Bulk operation', 'certloom' ); ?></strong></label>
						<select id="certloom-bulk-operation" name="bulk_operation" data-certloom-bulk-operation required>
							<option value=""><?php esc_html_e( 'Choose a change…', 'certloom' ); ?></option>
							<option value="set_status"><?php esc_html_e( 'Change workflow status', 'certloom' ); ?></option>
							<option value="set_expiry"><?php esc_html_e( 'Set expiry date', 'certloom' ); ?></option>
							<option value="clear_expiry"><?php esc_html_e( 'Clear expiry date', 'certloom' ); ?></option>
							<option value="set_custom_field"><?php esc_html_e( 'Set custom-field value', 'certloom' ); ?></option>
							<option value="clear_custom_field"><?php esc_html_e( 'Clear custom-field value', 'certloom' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Program and batch assignment stay unavailable until the normalized-domain acceptance gate is approved; Certloom will not emulate those relationships in legacy custom fields.', 'certloom' ); ?></p>
					</div>

					<div class="certloom-bulk-operation-fields" data-certloom-bulk-fields="set_status">
						<div class="certloom-form-field"><label for="certloom-bulk-status"><?php esc_html_e( 'Workflow status', 'certloom' ); ?></label><select id="certloom-bulk-status" name="bulk_status"><?php foreach ( $certloom_bulk_statuses as $key => $definition ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $definition['label'] ); ?></option><?php endforeach; ?></select></div>
						<div class="certloom-form-field certloom-form-field-wide"><label><input type="checkbox" name="bulk_status_reason_set" value="1" /> <?php esc_html_e( 'Replace status reason on every eligible certificate', 'certloom' ); ?></label><textarea name="bulk_status_reason" rows="3" maxlength="10000" placeholder="<?php esc_attr_e( 'Optional lifecycle reason', 'certloom' ); ?>"></textarea></div>
					</div>

					<div class="certloom-bulk-operation-fields" data-certloom-bulk-fields="set_expiry">
						<div class="certloom-form-field"><label for="certloom-bulk-expiry-date"><?php esc_html_e( 'Expiry date', 'certloom' ); ?></label><input id="certloom-bulk-expiry-date" type="date" name="bulk_expiry_date" /></div>
						<p class="description"><?php esc_html_e( 'Certificates whose issue date is later than this expiry date will be skipped and reported before commit.', 'certloom' ); ?></p>
					</div>

					<div class="certloom-bulk-operation-fields" data-certloom-bulk-fields="set_custom_field,clear_custom_field">
						<div class="certloom-form-field"><label for="certloom-bulk-field-key"><?php esc_html_e( 'Custom field', 'certloom' ); ?></label><select id="certloom-bulk-field-key" name="bulk_field_key"><option value=""><?php esc_html_e( 'Choose a field…', 'certloom' ); ?></option><?php foreach ( $bulk_fields as $field ) : ?><option value="<?php echo esc_attr( $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></option><?php endforeach; ?></select></div>
						<div class="certloom-form-field certloom-form-field-wide" data-certloom-bulk-value><label for="certloom-bulk-field-value"><?php esc_html_e( 'Value', 'certloom' ); ?></label><textarea id="certloom-bulk-field-value" name="bulk_field_value" rows="3"></textarea></div>
					</div>
				</div>
				<?php submit_button( __( 'Review change', 'certloom' ), 'primary', 'certverify_bulk_preview', false ); ?>
			</form>
		</section>

		<aside class="certloom-panel" aria-labelledby="certloom-bulk-selection-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Frozen selection', 'certloom' ); ?></span><h2 id="certloom-bulk-selection-title"><?php esc_html_e( 'Selected certificates', 'certloom' ); ?></h2></div></div>
			<ul class="certloom-bulk-selection-list">
			<?php foreach ( array_slice( $selected_records, 0, 25 ) as $record ) : ?>
				<li><strong><?php echo esc_html( (string) $record['cert_number'] ); ?></strong><span><?php echo esc_html( (string) $record['holder_name'] ); ?></span></li>
			<?php endforeach; ?>
			</ul>
			<?php if ( $certloom_bulk_count > 25 ) : ?><p class="description"><?php echo esc_html( sprintf( __( 'And %d more selected certificates.', 'certloom' ), $certloom_bulk_count - 25 ) ); ?></p><?php endif; ?>
		</aside>
	</div>

	<?php if ( $certloom_bulk_preview ) : ?>
		<?php
		$certloom_reviewed_operation = $certloom_bulk_preview['operation'];
		$certloom_reviewed_type = sanitize_key( (string) ( $certloom_reviewed_operation['type'] ?? '' ) );
		$certloom_reviewed_label = __( 'Reviewed bulk change', 'certloom' );
		if ( 'set_status' === $certloom_reviewed_type ) {
			$certloom_reviewed_status = $certloom_bulk_statuses[ $certloom_reviewed_operation['status'] ]['label'] ?? ucfirst( (string) $certloom_reviewed_operation['status'] );
			$certloom_reviewed_label = sprintf( __( 'Set workflow status to %s', 'certloom' ), $certloom_reviewed_status );
			if ( ! empty( $certloom_reviewed_operation['reason_set'] ) ) {
				$certloom_reviewed_label .= ' · ' . __( 'replace status reason', 'certloom' );
			}
		} elseif ( 'set_expiry' === $certloom_reviewed_type ) {
			$certloom_reviewed_label = sprintf( __( 'Set expiry date to %s', 'certloom' ), Ecapla_CertVerify_Validator::format_date( $certloom_reviewed_operation['expiry_date'] ) );
		} elseif ( 'clear_expiry' === $certloom_reviewed_type ) {
			$certloom_reviewed_label = __( 'Clear expiry date', 'certloom' );
		} elseif ( in_array( $certloom_reviewed_type, array( 'set_custom_field', 'clear_custom_field' ), true ) ) {
			$certloom_reviewed_field = $bulk_fields[ $certloom_reviewed_operation['field_key'] ]['label'] ?? $certloom_reviewed_operation['field_key'];
			$certloom_reviewed_label = 'set_custom_field' === $certloom_reviewed_type ? sprintf( __( 'Set custom field: %s', 'certloom' ), $certloom_reviewed_field ) : sprintf( __( 'Clear custom field: %s', 'certloom' ), $certloom_reviewed_field );
		}
		?>
		<section class="certloom-panel certloom-bulk-commit-panel" aria-labelledby="certloom-bulk-commit-title">
			<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Step 2', 'certloom' ); ?></span><h2 id="certloom-bulk-commit-title"><?php esc_html_e( 'Commit reviewed change', 'certloom' ); ?></h2></div></div>
			<div class="certloom-reviewed-operation"><span><?php esc_html_e( 'Reviewed operation', 'certloom' ); ?></span><strong><?php echo esc_html( $certloom_reviewed_label ); ?></strong></div>
			<div class="certloom-bulk-summary-grid">
				<div><span><?php esc_html_e( 'Selected', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( $certloom_bulk_count ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Eligible', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( (array) $certloom_bulk_preview['eligible'] ) ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Skipped', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( (array) $certloom_bulk_preview['skipped'] ) ) ); ?></strong></div>
			</div>
			<?php if ( ! empty( $certloom_bulk_preview['skipped'] ) ) : ?>
				<details class="certloom-bulk-skipped"><summary><?php esc_html_e( 'Review skipped certificates', 'certloom' ); ?></summary><ul><?php foreach ( $certloom_bulk_preview['skipped'] as $skip ) : ?><li><strong><?php echo esc_html( $skip['cert_number'] ?: sprintf( '#%d', absint( $skip['id'] ) ) ); ?></strong> — <?php echo esc_html( $skip['reason'] ); ?></li><?php endforeach; ?></ul></details>
			<?php endif; ?>
			<?php if ( ! empty( $certloom_bulk_preview['eligible'] ) ) : ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'certverify_bulk_commit' ); ?>
					<input type="hidden" name="bulk_token" value="<?php echo esc_attr( $token ); ?>" />
					<p><strong><?php esc_html_e( 'This commit changes only the eligible certificates shown by this frozen review. The selection and operation cannot be altered by this form.', 'certloom' ); ?></strong></p>
					<?php submit_button( __( 'Commit bulk change', 'certloom' ), 'primary', 'certverify_bulk_commit', false, array( 'data-ecapla-confirm' => __( 'Apply this reviewed change to every eligible selected certificate?', 'certloom' ) ) ); ?>
				</form>
			<?php else : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'No selected certificate is eligible for this reviewed change. Nothing can be committed.', 'certloom' ); ?></p></div>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</div>
