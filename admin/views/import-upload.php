<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Import, export & backup', 'certverify' ); ?></h1>
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; ?>

	<div class="ecapla-certverify-admin-grid">
		<section class="ecapla-certverify-admin-card">
			<h2><?php esc_html_e( 'Import certificates from CSV', 'certverify' ); ?></h2>
			<p><?php esc_html_e( 'CSV is intended for spreadsheet editing and tabular transfers. It is not a complete site backup. Upload a file, review automatic column mapping, and decide how existing certificate numbers should be handled.', 'certverify' ); ?></p>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Maximum file size: 5 MB', 'certverify' ); ?></li>
				<li><?php printf( /* translators: %d: maximum number of rows per import. */ esc_html__( 'Maximum certificate rows per import: %d', 'certverify' ), (int) Ecapla_CertVerify_Import::MAX_ROWS ); ?></li>
				<li><?php esc_html_e( 'Accepted format: UTF-8 CSV with one header row', 'certverify' ); ?></li>
				<li><?php esc_html_e( 'Spreadsheet-formula characters in exported CSV files are neutralized for safety and may not round-trip as literal leading characters. Use Complete backup for lossless restoration.', 'certverify' ); ?></li>
			</ul>
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'certverify_import_analyze' ); ?>
				<label for="ecapla-certverify-import-file" class="screen-reader-text"><?php esc_html_e( 'Choose CSV file', 'certverify' ); ?></label>
				<input id="ecapla-certverify-import-file" type="file" name="import_file" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Analyze CSV', 'certverify' ), 'primary', 'certverify_import_analyze' ); ?>
			</form>
		</section>

		<section class="ecapla-certverify-admin-card">
			<h2><?php esc_html_e( 'CSV tools', 'certverify' ); ?></h2>
			<p><?php esc_html_e( 'Download a template shaped by the active field layout, or export tabular certificate data for spreadsheet use.', 'certverify' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=certverify-import&action=template' ), 'certverify_template' ) ); ?>"><?php esc_html_e( 'Download import template', 'certverify' ); ?></a></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=certverify-import&action=export' ), 'certverify_export' ) ); ?>"><?php esc_html_e( 'Export certificate CSV', 'certverify' ); ?></a></p>
		</section>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<section class="ecapla-certverify-admin-card">
				<h2><?php esc_html_e( 'Complete backup & restore', 'certverify' ); ?></h2>
				<p><?php esc_html_e( 'Create a lossless, versioned streaming backup containing certificates, active and retired field definitions, settings, workflow source, and original timestamps.', 'certverify' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecapla_certverify_backup_download' ), 'ecapla_certverify_backup_download' ) ); ?>"><?php esc_html_e( 'Download complete backup', 'certverify' ); ?></a></p>
				<hr />
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ecapla_certverify_backup_analyze" />
					<?php wp_nonce_field( 'ecapla_certverify_backup_analyze' ); ?>
					<label for="ecapla-certverify-backup-file"><strong><?php esc_html_e( 'Review a backup before restoring', 'certverify' ); ?></strong></label><br />
					<input id="ecapla-certverify-backup-file" type="file" name="backup_file" accept=".cvbackup,.json,application/x-ndjson,application/json" required />
					<?php submit_button( __( 'Analyze backup', 'certverify' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		<?php endif; ?>
	</div>

	<?php if ( current_user_can( 'manage_options' ) && is_array( $backup_preview ) ) : ?>
		<section class="ecapla-certverify-admin-card ecapla-certverify-backup-review">
			<h2><?php esc_html_e( 'Backup restore review', 'certverify' ); ?></h2>
			<p><?php esc_html_e( 'Nothing has been changed yet. Review this dry-run summary before applying the backup.', 'certverify' ); ?></p>
			<table class="widefat striped" role="presentation">
				<tbody>
					<tr><th><?php esc_html_e( 'Certificates in backup', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['records'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Certificates currently stored', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['current_records'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'New certificate numbers', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['new_records'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Existing certificate conflicts', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['conflicts'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Current-only certificates', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['current_only'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Active field definitions', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['fields'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Retired field definitions', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['retired_fields'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Backup-only custom fields', 'certverify' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['backup_only_fields'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Backup created', 'certverify' ); ?></th><td><?php echo esc_html( $backup_preview['generated_at'] ?? '' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Source plugin version', 'certverify' ); ?></th><td><?php echo esc_html( $backup_preview['plugin_version'] ?? '' ); ?></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecapla_certverify_backup_apply" />
				<input type="hidden" name="backup_token" value="<?php echo esc_attr( $backup_token ); ?>" />
				<?php wp_nonce_field( 'ecapla_certverify_backup_apply' ); ?>
				<fieldset>
					<legend><strong><?php esc_html_e( 'Restore mode', 'certverify' ); ?></strong></legend>
					<label><input type="radio" name="restore_mode" value="replace" checked /> <strong><?php esc_html_e( 'Replace entire registry and configuration', 'certverify' ); ?></strong></label>
					<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Recreates the backup snapshot exactly. Current-only certificates are removed, and the backup’s fields, retired fields, and settings replace the current configuration.', 'certverify' ); ?></p>
					<label><input type="radio" name="restore_mode" value="merge" /> <strong><?php esc_html_e( 'Merge certificates into the current registry', 'certverify' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'Keeps current-only certificates and current settings. Backup-only custom-field definitions are retained as private retired fields so their stored values are not exposed automatically.', 'certverify' ); ?></p>
				</fieldset>
				<fieldset>
					<legend><strong><?php esc_html_e( 'Merge conflict handling', 'certverify' ); ?></strong></legend>
					<label><input type="radio" name="conflict_policy" value="skip" checked /> <?php esc_html_e( 'Keep the existing certificate and skip the backup record', 'certverify' ); ?></label><br />
					<label><input type="radio" name="conflict_policy" value="update" /> <?php esc_html_e( 'Replace the existing certificate with the backup record', 'certverify' ); ?></label>
					<p class="description"><?php esc_html_e( 'This choice applies only to Merge mode. Replace mode always uses the backup copy.', 'certverify' ); ?></p>
				</fieldset>
				<p><label><input type="checkbox" name="confirm_restore" value="1" required /> <?php esc_html_e( 'I understand the selected mode and that CertVerify writes will be temporarily paused while the restore is prepared.', 'certverify' ); ?></label></p>
				<?php submit_button( __( 'Start safe restore', 'certverify' ), 'primary', 'submit', false ); ?>
			</form>
		</section>
	<?php endif; ?>
</div>
