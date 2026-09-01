<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-restore-flow">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Backup & Restore', 'certloom' ); ?></h1>
	<div class="certloom-product-notice-rail" aria-live="polite">
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['backup_notice'] ) ) : ?>
		<?php $certloom_backup_notice = sanitize_key( wp_unslash( $_GET['backup_notice'] ) ); ?>
		<?php if ( in_array( $certloom_backup_notice, array( 'settings-saved', 'storage-verified', 'backup-created', 'backup-deleted', 'restore-tested', 'google-connected', 'google-settings-saved', 'google-tested', 'google-disconnected', 'google-uploaded', 'google-downloaded', 'google-deleted' ), true ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				if ( 'settings-saved' === $certloom_backup_notice ) { esc_html_e( 'Automatic backup settings saved.', 'certloom' ); }
				elseif ( 'storage-verified' === $certloom_backup_notice ) { esc_html_e( 'Private backup storage tested successfully.', 'certloom' ); }
				elseif ( 'backup-created' === $certloom_backup_notice ) { esc_html_e( 'Backup created, verified, and stored privately.', 'certloom' ); }
				elseif ( 'restore-tested' === $certloom_backup_notice ) { esc_html_e( 'Restore test passed without changing live Certloom data.', 'certloom' ); }
				elseif ( 'google-connected' === $certloom_backup_notice ) { esc_html_e( 'Google Drive connected. Test the destination before enabling automatic copies.', 'certloom' ); }
				elseif ( 'google-settings-saved' === $certloom_backup_notice ) { esc_html_e( 'Google Drive backup settings saved.', 'certloom' ); }
				elseif ( 'google-tested' === $certloom_backup_notice ) { esc_html_e( 'Google Drive passed a real upload, verification, and delete test.', 'certloom' ); }
				elseif ( 'google-disconnected' === $certloom_backup_notice ) { esc_html_e( 'Google Drive disconnected from Certloom. Existing Drive files were left untouched.', 'certloom' ); }
				elseif ( 'google-uploaded' === $certloom_backup_notice ) { esc_html_e( 'Backup copied to Google Drive and verified.', 'certloom' ); }
				elseif ( 'google-downloaded' === $certloom_backup_notice ) { esc_html_e( 'Google Drive backup downloaded, verified, analyzed, and saved in local private storage.', 'certloom' ); }
				elseif ( 'google-deleted' === $certloom_backup_notice ) { esc_html_e( 'Google Drive backup deleted.', 'certloom' ); }
				else { esc_html_e( 'Private backup deleted.', 'certloom' ); }
				?>
			</p></div>
		<?php endif; ?>
	<?php endif; ?>
	</div>
	<p class="certloom-recovery-intro"><?php esc_html_e( 'Protect Certloom, restore a backup, or bring certificates in from CSV from one place.', 'certloom' ); ?></p>
	<nav class="certloom-recovery-quickstart" aria-label="<?php esc_attr_e( 'Backup and restore actions', 'certloom' ); ?>"><a class="button button-primary" href="#certloom-backups"><?php esc_html_e( 'Your backups', 'certloom' ); ?></a><a class="button" href="#certloom-restore"><?php esc_html_e( 'Restore a backup', 'certloom' ); ?></a><a class="button" href="#certloom-csv-import"><?php esc_html_e( 'Import certificates', 'certloom' ); ?></a></nav>

	<section class="certloom-recovery-intake" data-certloom-recovery-intake aria-labelledby="certloom-recovery-intake-title">
		<div><span class="certloom-recovery-step-kicker"><?php esc_html_e( 'Import / restore guide', 'certloom' ); ?></span><h2 id="certloom-recovery-intake-title"><?php esc_html_e( 'Bring data into Certloom', 'certloom' ); ?></h2><p><?php esc_html_e( 'Choose what you are bringing in. Certloom will take you to the right file step, review the file before changes, and ask for confirmation only when needed.', 'certloom' ); ?></p></div>
		<ol class="certloom-recovery-steps" aria-label="<?php esc_attr_e( 'Import and restore steps', 'certloom' ); ?>"><li class="is-current" data-certloom-recovery-step="1"><?php esc_html_e( '1. Choose', 'certloom' ); ?></li><li data-certloom-recovery-step="2"><?php esc_html_e( '2. Review file', 'certloom' ); ?></li><li data-certloom-recovery-step="3"><?php esc_html_e( '3. Confirm', 'certloom' ); ?></li></ol>
		<div class="certloom-recovery-intake-actions"><button type="button" class="button" data-certloom-recovery-choice="backup" aria-controls="ecapla-certverify-backup-file"><?php esc_html_e( 'Restore a Certloom backup', 'certloom' ); ?></button><button type="button" class="button" data-certloom-recovery-choice="csv" aria-controls="ecapla-certloom-import-file"><?php esc_html_e( 'Import certificates from CSV', 'certloom' ); ?></button></div>
		<p class="description" data-certloom-recovery-intake-status aria-live="polite"><?php esc_html_e( 'Choose Backup or CSV to continue.', 'certloom' ); ?></p>
	</section>

	<div class="ecapla-certverify-admin-grid certloom-recovery-grid">
		<section id="certloom-csv-import" class="ecapla-certverify-admin-card certloom-recovery-card certloom-recovery-import">
			<h2><?php esc_html_e( 'Import certificates', 'certloom' ); ?></h2>
			<p><?php esc_html_e( 'CSV is intended for spreadsheet editing and tabular transfers. It is not a complete site backup. Upload a file, review automatic column mapping, and decide how existing certificate numbers should be handled.', 'certloom' ); ?></p>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Maximum file size: 5 MB', 'certloom' ); ?></li>
				<li><?php printf( /* translators: %d: maximum number of rows per import. */ esc_html__( 'Maximum certificate rows per import: %d', 'certloom' ), (int) Ecapla_CertVerify_Import::MAX_ROWS ); ?></li>
				<li><?php esc_html_e( 'Accepted format: UTF-8 CSV with one header row', 'certloom' ); ?></li>
				<li><?php esc_html_e( 'Spreadsheet-formula characters in exported CSV files are neutralized for safety and may not round-trip as literal leading characters. Use Complete backup for lossless restoration.', 'certloom' ); ?></li>
			</ul>
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'certverify_import_analyze' ); ?>
				<label for="ecapla-certloom-import-file" class="screen-reader-text"><?php esc_html_e( 'Choose CSV file', 'certloom' ); ?></label>
				<input id="ecapla-certloom-import-file" type="file" name="import_file" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Review CSV', 'certloom' ), 'primary', 'certverify_import_analyze' ); ?>
			</form>
		</section>

		<section class="ecapla-certverify-admin-card certloom-recovery-card certloom-recovery-csv-tools">
			<h2><?php esc_html_e( 'CSV downloads', 'certloom' ); ?></h2>
			<p><?php esc_html_e( 'Download a template shaped by the active field layout, or export tabular certificate data for spreadsheet use.', 'certloom' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=certloom-import&action=template' ), 'certverify_template' ) ); ?>"><?php esc_html_e( 'Download import template', 'certloom' ); ?></a></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=certloom-import&action=export' ), 'certverify_export' ) ); ?>"><?php esc_html_e( 'Export certificate CSV', 'certloom' ); ?></a></p>
		</section>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<section id="certloom-backups" class="ecapla-certverify-admin-card certloom-backup-center certloom-recovery-card">
				<h2><?php esc_html_e( 'Your backups', 'certloom' ); ?></h2>
				<p><?php esc_html_e( 'Create complete Certloom backups, keep automatic copies in private storage, and optionally copy verified backups to Google Drive.', 'certloom' ); ?></p>
				<p><strong><?php esc_html_e( 'Stored locally:', 'certloom' ); ?></strong> <?php esc_html_e( 'Private backup storage on this site', 'certloom' ); ?></p>
				<p class="description"><?php esc_html_e( 'Before Restore, CSV import, reviewed bulk changes, bulk deletion, or permanent retired-field purge, Certloom creates a verified safety backup and stops the destructive operation if that backup cannot be created. Automatic safety backups have their own retention limit and never replace backups you create manually.', 'certloom' ); ?></p>

				<?php if ( is_wp_error( $backup_storage_state ) ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( $backup_storage_state->get_error_message() ); ?></p></div>
				<?php endif; ?>

				<div class="certloom-backup-actions">
					<p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecapla_certverify_backup_download' ), 'ecapla_certverify_backup_download' ) ); ?>"><?php esc_html_e( 'Download fresh backup', 'certloom' ); ?></a></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="certloom_backup_test_storage" />
						<?php wp_nonce_field( 'certloom_backup_test_storage' ); ?>
						<?php submit_button( __( 'Test storage', 'certloom' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="certloom_backup_run_now" />
						<?php wp_nonce_field( 'certloom_backup_run_now' ); ?>
						<?php submit_button( __( 'Back up now', 'certloom' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<h3><?php esc_html_e( 'Automatic backups', 'certloom' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-backup-settings-form">
					<input type="hidden" name="action" value="certloom_backup_settings" />
					<?php wp_nonce_field( 'certloom_backup_settings' ); ?>
					<label class="certloom-backup-setting" for="certloom-backup-schedule"><strong><?php esc_html_e( 'Schedule', 'certloom' ); ?></strong><select id="certloom-backup-schedule" name="backup_schedule"><option value="off" <?php selected( $scheduled_backup_state['schedule'] ?? 'off', 'off' ); ?>><?php esc_html_e( 'Off', 'certloom' ); ?></option><option value="daily" <?php selected( $scheduled_backup_state['schedule'] ?? 'off', 'daily' ); ?>><?php esc_html_e( 'Daily', 'certloom' ); ?></option><option value="weekly" <?php selected( $scheduled_backup_state['schedule'] ?? 'off', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'certloom' ); ?></option></select></label>
					<label class="certloom-backup-setting" for="certloom-backup-retention"><strong><?php esc_html_e( 'Automatic retention', 'certloom' ); ?></strong><span class="certloom-backup-number-with-unit"><input id="certloom-backup-retention" type="number" min="1" max="60" step="1" name="backup_retention" value="<?php echo esc_attr( absint( $scheduled_backup_state['retention'] ?? 14 ) ); ?>" /><span><?php esc_html_e( 'backups', 'certloom' ); ?></span></span></label>
					<div class="certloom-backup-settings-actions"><?php submit_button( __( 'Save backup settings', 'certloom' ), 'secondary', 'submit', false ); ?></div>
				</form>
				<?php $certloom_backup_last = (array) ( $scheduled_backup_state['last_result'] ?? array() ); ?>
				<ul class="ul-disc certloom-backup-status">
					<li><strong><?php esc_html_e( 'Last run:', 'certloom' ); ?></strong> <?php echo esc_html( ! empty( $certloom_backup_last['ran_ts'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $certloom_backup_last['ran_ts'] ) ) : __( 'Not run yet', 'certloom' ) ); ?></li>
					<li><strong><?php esc_html_e( 'Last result:', 'certloom' ); ?></strong> <?php echo esc_html( ! empty( $certloom_backup_last['status'] ) ? ( 'success' === $certloom_backup_last['status'] ? __( 'Verified', 'certloom' ) : __( 'Needs attention', 'certloom' ) ) : __( '—', 'certloom' ) ); ?></li>
					<li><strong><?php esc_html_e( 'Next scheduled run:', 'certloom' ); ?></strong> <?php echo esc_html( ! empty( $scheduled_backup_state['next_run'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $scheduled_backup_state['next_run'] ) ) : __( 'Not scheduled', 'certloom' ) ); ?></li>
				</ul>

				<?php
				$certloom_backup_per_page = 50;
				$certloom_backup_page = isset( $_GET['certloom_backup_page'] ) ? max( 1, absint( wp_unslash( $_GET['certloom_backup_page'] ) ) ) : 1;
				$certloom_backup_total = count( (array) $saved_backups );
				$certloom_backup_pages = max( 1, (int) ceil( $certloom_backup_total / $certloom_backup_per_page ) );
				$certloom_backup_page = min( $certloom_backup_page, $certloom_backup_pages );
				$certloom_visible_backups = array_slice( (array) $saved_backups, ( $certloom_backup_page - 1 ) * $certloom_backup_per_page, $certloom_backup_per_page );
				?>
				<?php if ( ! empty( $saved_backups ) ) : ?>
					<h3><?php esc_html_e( 'Saved backups', 'certloom' ); ?></h3>
					<div class="certloom-backup-table-wrap">
					<table class="widefat striped certloom-backup-table">
						<thead><tr><th><?php esc_html_e( 'Created', 'certloom' ); ?></th><th><?php esc_html_e( 'Type', 'certloom' ); ?></th><th><?php esc_html_e( 'Certificates', 'certloom' ); ?></th><th><?php esc_html_e( 'Size', 'certloom' ); ?></th><th><?php esc_html_e( 'Integrity', 'certloom' ); ?></th><th><?php esc_html_e( 'Restore test', 'certloom' ); ?></th><th><?php esc_html_e( 'Actions', 'certloom' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $certloom_visible_backups as $certloom_saved_backup ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $certloom_saved_backup['created_ts'] ) ) ); ?></td>
								<td><?php echo esc_html( 'scheduled' === $certloom_saved_backup['purpose'] ? __( 'Scheduled', 'certloom' ) : ( 'safety' === $certloom_saved_backup['purpose'] ? __( 'Safety', 'certloom' ) : __( 'Manual', 'certloom' ) ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( absint( $certloom_saved_backup['records'] ) ) ); ?></td>
								<td><?php echo esc_html( size_format( absint( $certloom_saved_backup['bytes'] ) ) ); ?></td>
								<td><?php esc_html_e( 'Verified at creation', 'certloom' ); ?></td>
								<?php $certloom_restore_test = \Certloom\Infrastructure\Backup\RestoreTest::latest_for( $certloom_saved_backup['id'] ); ?>
								<td><?php echo esc_html( ! empty( $certloom_restore_test['tested_ts'] ) && hash_equals( (string) $certloom_saved_backup['file_sha256'], (string) ( $certloom_restore_test['file_sha256'] ?? '' ) ) ? sprintf( /* translators: %s: date/time of last restore test. */ __( 'Passed %s', 'certloom' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $certloom_restore_test['tested_ts'] ) ) ) : __( 'Not tested', 'certloom' ) ); ?></td>
								<td class="certloom-backup-row-actions">
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'certloom_backup_saved_download', 'backup_id' => $certloom_saved_backup['id'] ), admin_url( 'admin-post.php' ) ), 'certloom_backup_saved_download_' . $certloom_saved_backup['id'] ) ); ?>"><?php esc_html_e( 'Download', 'certloom' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'certloom_backup_saved_review', 'backup_id' => $certloom_saved_backup['id'] ), admin_url( 'admin-post.php' ) ), 'certloom_backup_saved_review_' . $certloom_saved_backup['id'] ) ); ?>"><?php esc_html_e( 'Review restore', 'certloom' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-inline-form">
										<input type="hidden" name="action" value="certloom_backup_saved_test_restore" />
										<input type="hidden" name="backup_id" value="<?php echo esc_attr( $certloom_saved_backup['id'] ); ?>" />
										<?php wp_nonce_field( 'certloom_backup_saved_test_restore_' . $certloom_saved_backup['id'] ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Test restore', 'certloom' ); ?></button>
									</form>
									<?php if ( ! empty( $google_drive_state['connected'] ) && 'safety' !== $certloom_saved_backup['purpose'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-inline-form">
										<input type="hidden" name="action" value="certloom_google_drive_upload" />
										<input type="hidden" name="backup_id" value="<?php echo esc_attr( $certloom_saved_backup['id'] ); ?>" />
										<?php wp_nonce_field( 'certloom_google_drive_upload_' . $certloom_saved_backup['id'] ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Copy to Google Drive', 'certloom' ); ?></button>
									</form>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-inline-form">
										<input type="hidden" name="action" value="certloom_backup_saved_delete" />
										<input type="hidden" name="backup_id" value="<?php echo esc_attr( $certloom_saved_backup['id'] ); ?>" />
										<?php wp_nonce_field( 'certloom_backup_saved_delete_' . $certloom_saved_backup['id'] ); ?>
										<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this private backup?', 'certloom' ) ); ?>');"><?php esc_html_e( 'Delete', 'certloom' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php if ( $certloom_backup_pages > 1 ) : ?>
						<nav class="certloom-table-pagination" aria-label="<?php esc_attr_e( 'Saved backup pages', 'certloom' ); ?>">
							<?php if ( $certloom_backup_page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'certloom_backup_page', $certloom_backup_page - 1 ) . '#certloom-backups' ); ?>"><?php esc_html_e( 'Previous', 'certloom' ); ?></a><?php endif; ?>
							<span><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages. */ __( 'Page %1$d of %2$d', 'certloom' ), $certloom_backup_page, $certloom_backup_pages ) ); ?></span>
							<?php if ( $certloom_backup_page < $certloom_backup_pages ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'certloom_backup_page', $certloom_backup_page + 1 ) . '#certloom-backups' ); ?>"><?php esc_html_e( 'Next', 'certloom' ); ?></a><?php endif; ?>
						</nav>
					<?php endif; ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No private backups have been saved yet. Use Back up now or enable a schedule.', 'certloom' ); ?></p>
				<?php endif; ?>


				<hr />
				<h3><?php esc_html_e( 'Google Drive', 'certloom' ); ?></h3>
				<p><?php esc_html_e( 'Google Drive is an optional second copy of verified Certloom backups. Local private storage remains the recovery source; Google Drive never becomes the source of credential data.', 'certloom' ); ?></p>
				<?php $certloom_google_redirect = $google_drive_state['redirect_uri'] ?? ''; ?>
				<?php if ( is_wp_error( $certloom_google_redirect ) ) : ?><div class="notice notice-warning inline"><p><?php echo esc_html( $certloom_google_redirect->get_error_message() ); ?></p></div><?php endif; ?>
				<?php if ( empty( $google_drive_state['connected'] ) ) : ?>
					<?php $certloom_google_client_ready = \Certloom\Infrastructure\Backup\GoogleDriveOAuth::has_client_credentials(); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-google-drive-connect" target="certloomGoogleDriveOAuth" data-certloom-google-popup-form data-certloom-google-refresh-on-success="1">
						<input type="hidden" name="action" value="certloom_google_drive_connect" /><input type="hidden" name="certloom_google_return_mode" value="popup" />
						<?php wp_nonce_field( 'certloom_google_drive_connect' ); ?>
						<details class="certloom-google-advanced"><summary><?php esc_html_e( 'Advanced: use your own Google app', 'certloom' ); ?></summary><p class="description"><?php esc_html_e( 'Normally, Connect with Google uses Ecapla’s secure OAuth relay only for the Google authorization handshake. If your organization requires its own Google Cloud app, configure or reuse that site-owned fallback here.', 'certloom' ); ?></p><p><label for="certloom-google-client-id"><strong><?php esc_html_e( 'Google OAuth client ID', 'certloom' ); ?></strong></label><br /><input id="certloom-google-client-id" type="text" class="regular-text" name="google_client_id" value="<?php echo esc_attr( (string) ( $google_drive_state['client_id'] ?? '' ) ); ?>" autocomplete="off" /></p><p><label for="certloom-google-client-secret"><strong><?php esc_html_e( 'Google OAuth client secret', 'certloom' ); ?></strong></label><br /><input id="certloom-google-client-secret" type="password" class="regular-text" name="google_client_secret" value="" autocomplete="new-password" /><br /><span class="description"><?php esc_html_e( 'Leave blank to reuse the securely stored secret when a site-owned app is already configured.', 'certloom' ); ?></span></p><?php if ( ! is_wp_error( $certloom_google_redirect ) ) : ?><p class="description"><strong><?php esc_html_e( 'Site-owned redirect URI:', 'certloom' ); ?></strong> <code><?php echo esc_html( (string) $certloom_google_redirect ); ?></code></p><?php endif; ?><p><button type="submit" class="button" name="google_connection_mode" value="direct" data-certloom-google-connect><?php echo esc_html( $certloom_google_client_ready ? __( 'Connect with site-owned Google app', 'certloom' ) : __( 'Save app & connect', 'certloom' ) ); ?></button></p></details>
						<p class="certloom-google-connect-row"><button type="submit" class="button button-secondary" name="google_connection_mode" value="relay" data-certloom-google-connect><?php esc_html_e( 'Connect with Google', 'certloom' ); ?></button><span role="status" aria-live="polite" data-certloom-google-popup-feedback></span></p>
					</form>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Connection:', 'certloom' ); ?></strong> <?php esc_html_e( 'Connected', 'certloom' ); ?></p>
					<div class="certloom-backup-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_google_drive_test" /><?php wp_nonce_field( 'certloom_google_drive_test' ); ?><button type="submit" class="button"><?php esc_html_e( 'Test Google Drive', 'certloom' ); ?></button></form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_google_drive_disconnect" /><?php wp_nonce_field( 'certloom_google_drive_disconnect' ); ?><button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Google Drive from Certloom? Existing Google Drive backup files will not be deleted.', 'certloom' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'certloom' ); ?></button></form>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-backup-settings-form">
						<input type="hidden" name="action" value="certloom_google_drive_settings" /><?php wp_nonce_field( 'certloom_google_drive_settings' ); ?>
						<p><label><input type="checkbox" name="google_drive_enabled" value="1" <?php checked( ! empty( $google_drive_state['enabled'] ) ); ?> /> <strong><?php esc_html_e( 'Copy scheduled backups to Google Drive', 'certloom' ); ?></strong></label></p>
						<p><label for="certloom-google-drive-retention"><strong><?php esc_html_e( 'Google Drive retention', 'certloom' ); ?></strong></label><br /><input id="certloom-google-drive-retention" type="number" min="1" max="60" name="google_drive_retention" value="<?php echo esc_attr( absint( $google_drive_state['retention'] ?? 14 ) ); ?>" /> <span><?php esc_html_e( 'scheduled backups', 'certloom' ); ?></span></p>
						<?php submit_button( __( 'Save Google Drive settings', 'certloom' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php $certloom_google_last = (array) ( $google_drive_state['last_result'] ?? array() ); ?>
					<?php if ( ! empty( $certloom_google_last['ran_ts'] ) ) : ?><p class="description"><strong><?php esc_html_e( 'Last Google Drive copy:', 'certloom' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $certloom_google_last['ran_ts'] ) ) ); ?> — <?php echo esc_html( 'success' === ( $certloom_google_last['status'] ?? '' ) ? __( 'Verified', 'certloom' ) : __( 'Needs attention', 'certloom' ) ); ?></p><?php endif; ?>
					<?php
					$certloom_remote_per_page = 50;
					$certloom_remote_page = isset( $_GET['certloom_drive_page'] ) ? max( 1, absint( wp_unslash( $_GET['certloom_drive_page'] ) ) ) : 1;
					$certloom_remote_items = (array) ( $google_drive_state['remote'] ?? array() );
					$certloom_remote_total = count( $certloom_remote_items );
					$certloom_remote_pages = max( 1, (int) ceil( $certloom_remote_total / $certloom_remote_per_page ) );
					$certloom_remote_page = min( $certloom_remote_page, $certloom_remote_pages );
					$certloom_visible_remote = array_slice( $certloom_remote_items, ( $certloom_remote_page - 1 ) * $certloom_remote_per_page, $certloom_remote_per_page );
					?>
					<?php if ( ! empty( $google_drive_state['remote'] ) ) : ?>
						<h4><?php esc_html_e( 'Google Drive copies', 'certloom' ); ?></h4>
						<div class="certloom-backup-table-wrap"><table class="widefat striped certloom-backup-table"><thead><tr><th><?php esc_html_e( 'Created', 'certloom' ); ?></th><th><?php esc_html_e( 'Type', 'certloom' ); ?></th><th><?php esc_html_e( 'Size', 'certloom' ); ?></th><th><?php esc_html_e( 'Status', 'certloom' ); ?></th><th><?php esc_html_e( 'Actions', 'certloom' ); ?></th></tr></thead><tbody>
						<?php foreach ( $certloom_visible_remote as $certloom_remote_backup ) : ?><tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $certloom_remote_backup['created_ts'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( 'scheduled' === ( $certloom_remote_backup['purpose'] ?? '' ) ? __( 'Scheduled', 'certloom' ) : __( 'Manual', 'certloom' ) ); ?></td>
							<td><?php echo esc_html( size_format( absint( $certloom_remote_backup['bytes'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( 'verified' === ( $certloom_remote_backup['status'] ?? '' ) ? __( 'Verified', 'certloom' ) : ( 'failed' === ( $certloom_remote_backup['status'] ?? '' ) ? __( 'Needs attention', 'certloom' ) : __( 'Waiting to upload', 'certloom' ) ) ); ?></td>
							<td class="certloom-backup-row-actions">
								<?php if ( 'verified' === ( $certloom_remote_backup['status'] ?? '' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-inline-form"><input type="hidden" name="action" value="certloom_google_drive_download_local" /><input type="hidden" name="remote_id" value="<?php echo esc_attr( $certloom_remote_backup['id'] ); ?>" /><?php wp_nonce_field( 'certloom_google_drive_download_local_' . $certloom_remote_backup['id'] ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Save locally', 'certloom' ); ?></button></form><?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-inline-form"><input type="hidden" name="action" value="certloom_google_drive_delete" /><input type="hidden" name="remote_id" value="<?php echo esc_attr( $certloom_remote_backup['id'] ); ?>" /><?php wp_nonce_field( 'certloom_google_drive_delete_' . $certloom_remote_backup['id'] ); ?><button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Delete this Google Drive backup?', 'certloom' ) ); ?>');"><?php esc_html_e( 'Delete', 'certloom' ); ?></button></form>
							</td></tr><?php endforeach; ?>
						</tbody></table></div>
						<?php if ( $certloom_remote_pages > 1 ) : ?>
							<nav class="certloom-table-pagination" aria-label="<?php esc_attr_e( 'Google Drive backup pages', 'certloom' ); ?>">
								<?php if ( $certloom_remote_page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'certloom_drive_page', $certloom_remote_page - 1 ) . '#certloom-backups' ); ?>"><?php esc_html_e( 'Previous', 'certloom' ); ?></a><?php endif; ?>
								<span><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages. */ __( 'Page %1$d of %2$d', 'certloom' ), $certloom_remote_page, $certloom_remote_pages ) ); ?></span>
								<?php if ( $certloom_remote_page < $certloom_remote_pages ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'certloom_drive_page', $certloom_remote_page + 1 ) . '#certloom-backups' ); ?>"><?php esc_html_e( 'Next', 'certloom' ); ?></a><?php endif; ?>
							</nav>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>

				<hr />
				<h3 id="certloom-restore"><?php esc_html_e( 'Restore a backup', 'certloom' ); ?></h3>
				<p><?php esc_html_e( 'Choose a Certloom backup and review what it contains before anything changes. When you restore, Certloom first creates a fresh safety backup of your current data.', 'certloom' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ecapla_certverify_backup_analyze" />
					<?php wp_nonce_field( 'ecapla_certverify_backup_analyze' ); ?>
					<label for="ecapla-certverify-backup-file"><strong><?php esc_html_e( 'Review a backup before restoring', 'certloom' ); ?></strong></label><br />
					<input id="ecapla-certverify-backup-file" type="file" name="backup_file" accept=".cvbackup,.json,application/x-ndjson,application/json" required />
					<?php submit_button( __( 'Review backup', 'certloom' ), 'secondary', 'submit', false ); ?>
				</form>
			</section>
		<?php endif; ?>
	</div>

	<?php if ( current_user_can( 'manage_options' ) && is_array( $backup_preview ) ) : ?>
		<section class="ecapla-certverify-admin-card ecapla-certverify-backup-review certloom-recovery-review">
			<h2><?php esc_html_e( 'Review restore', 'certloom' ); ?></h2>
			<p><?php esc_html_e( 'Nothing has been changed yet. Review this dry-run summary before applying the backup.', 'certloom' ); ?></p>
			<table class="widefat striped" role="presentation">
				<tbody>
					<tr><th><?php esc_html_e( 'Certificates in backup', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['records'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Certificates currently stored', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['current_records'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'New certificate numbers', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['new_records'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Existing certificate conflicts', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['conflicts'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Current-only certificates', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['current_only'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Active field definitions', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['fields'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Retired field definitions', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['retired_fields'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Backup-only custom fields', 'certloom' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $backup_preview['backup_only_fields'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Backup created', 'certloom' ); ?></th><td><?php echo esc_html( $backup_preview['generated_at'] ?? '' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Source plugin version', 'certloom' ); ?></th><td><?php echo esc_html( $backup_preview['plugin_version'] ?? '' ); ?></td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecapla_certverify_backup_apply" />
				<input type="hidden" name="backup_token" value="<?php echo esc_attr( $backup_token ); ?>" />
				<?php wp_nonce_field( 'ecapla_certverify_backup_apply' ); ?>
				<fieldset>
					<legend><strong><?php esc_html_e( 'Restore mode', 'certloom' ); ?></strong></legend>
					<label><input type="radio" name="restore_mode" value="replace" checked /> <strong><?php esc_html_e( 'Replace current Certloom data', 'certloom' ); ?></strong></label>
					<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Recreates the backup snapshot exactly. Current-only certificates are removed, and the backup’s fields, retired fields, and settings replace the current configuration.', 'certloom' ); ?></p>
					<label><input type="radio" name="restore_mode" value="merge" /> <strong><?php esc_html_e( 'Merge certificates into the current registry', 'certloom' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'Keeps current-only certificates and current settings. Backup-only custom-field definitions are retained as private retired fields so their stored values are not exposed automatically.', 'certloom' ); ?></p>
				</fieldset>
				<fieldset>
					<legend><strong><?php esc_html_e( 'Merge conflict handling', 'certloom' ); ?></strong></legend>
					<label><input type="radio" name="conflict_policy" value="skip" checked /> <?php esc_html_e( 'Keep the existing certificate and skip the backup record', 'certloom' ); ?></label><br />
					<label><input type="radio" name="conflict_policy" value="update" /> <?php esc_html_e( 'Replace the existing certificate with the backup record', 'certloom' ); ?></label>
					<p class="description"><?php esc_html_e( 'This choice applies only to Merge mode. Replace mode always uses the backup copy.', 'certloom' ); ?></p>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Certloom will create and verify a safety backup immediately before restoring.', 'certloom' ); ?></p>
				<button type="submit" class="button button-primary" data-ecapla-confirm="<?php echo esc_attr__( 'Restore this backup now? Certloom will create a safety backup first. You can cancel here without changing anything.', 'certloom' ); ?>"><?php esc_html_e( 'Restore backup', 'certloom' ); ?></button>
			</form>
		</section>
	<?php endif; ?>
</div>
