<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Access & settings', 'certloom' ); ?></h1>

	<?php if ( isset( $_GET['ecapla_certverify_settings_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'certloom' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_role_created'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Certificate Manager role created with least-privilege access.', 'certloom' ); ?></p></div><?php endif; ?>

	<?php if ( isset( $_GET['certloom_database_proof'] ) ) : ?>
		<?php $certloom_database_proof_state = sanitize_key( wp_unslash( $_GET['certloom_database_proof'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_database_proof_state ? 'notice-success' : ( 'failed' === $certloom_database_proof_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_database_proof_state ? __( 'The isolated database proof passed.', 'certloom' ) : ( 'failed' === $certloom_database_proof_state ? __( 'The isolated database proof found one or more requirements that are not ready.', 'certloom' ) : __( 'The isolated database proof completed with one or more checks that still require external confirmation.', 'certloom' ) ) ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['certloom_domain_acceptance_proof'] ) ) : ?>
		<?php $certloom_domain_acceptance_state = sanitize_key( wp_unslash( $_GET['certloom_domain_acceptance_proof'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_domain_acceptance_state ? 'notice-success' : ( 'failed' === $certloom_domain_acceptance_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_domain_acceptance_state ? __( 'The gated Credential Series acceptance proof passed.', 'certloom' ) : ( 'failed' === $certloom_domain_acceptance_state ? __( 'The gated Credential Series acceptance proof found one or more requirements that are not ready.', 'certloom' ) : __( 'The gated Credential Series acceptance proof completed with one or more checks that still require review.', 'certloom' ) ) ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['certloom_legacy_migration_preflight'] ) ) : ?>
		<?php $certloom_legacy_preflight_state = sanitize_key( wp_unslash( $_GET['certloom_legacy_migration_preflight'] ) ); ?>
		<div class="notice <?php echo 'passed' === $certloom_legacy_preflight_state ? 'notice-success' : ( 'failed' === $certloom_legacy_preflight_state ? 'notice-error' : 'notice-warning' ); ?> is-dismissible"><p>
			<?php echo esc_html( 'passed' === $certloom_legacy_preflight_state ? __( 'The populated legacy migration preflight passed.', 'certloom' ) : ( 'failed' === $certloom_legacy_preflight_state ? __( 'The populated legacy migration preflight found rows or source conditions that require review.', 'certloom' ) : ( 'stale' === $certloom_legacy_preflight_state ? __( 'The populated legacy migration preflight became stale because its frozen source boundary changed. Run it again from a fresh boundary.', 'certloom' ) : __( 'The populated legacy migration preflight is still running. Continue the next bounded slice below.', 'certloom' ) ) ) ); ?>
		</p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Who can manage certificates', 'certloom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Administrators always retain access. Grant the dedicated Certloom capability only to roles that genuinely need certificate-management access.', 'certloom' ); ?></p>
	<?php if ( ! get_role( Ecapla_CertVerify_Permissions::MANAGER_ROLE ) ) : ?>
		<form method="post" action="" class="ecapla-certverify-inline-form"><?php wp_nonce_field( 'certverify_create_manager_role' ); ?><?php submit_button( __( 'Create Certificate Manager role', 'certloom' ), 'secondary', 'certverify_create_manager_role', false ); ?><p class="description"><?php esc_html_e( 'Creates a minimal role with read access and Certloom management only.', 'certloom' ); ?></p></form>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'certverify_save_settings' ); ?>
		<table class="widefat ecapla-certverify-roles-table"><thead><tr><th><?php esc_html_e( 'Role', 'certloom' ); ?></th><th><?php esc_html_e( 'Can manage certificates', 'certloom' ); ?></th></tr></thead><tbody>
			<?php foreach ( Ecapla_CertVerify_Permissions::get_all_roles() as $role_key => $role_info ) : ?><tr><td><?php echo esc_html( translate_user_role( $role_info['name'] ) ); ?></td><td><?php if ( 'administrator' === $role_key ) : ?><input type="checkbox" checked disabled aria-label="<?php esc_attr_e( 'Administrator access is always enabled', 'certloom' ); ?>" /><?php else : ?><input type="checkbox" name="role_access[<?php echo esc_attr( $role_key ); ?>]" value="1" <?php checked( Ecapla_CertVerify_Permissions::role_has_capability( $role_key ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: WordPress role name. */ __( 'Allow %s to manage certificates', 'certloom' ), translate_user_role( $role_info['name'] ) ) ); ?>" /><?php endif; ?></td></tr><?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Branding', 'certloom' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="accent_color"><?php esc_html_e( 'Default accent color', 'certloom' ); ?></label></th><td><input type="text" name="accent_color" id="accent_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'accent_color', '#0f6e56' ) ); ?>" class="regular-text" placeholder="#0f6e56" /><p class="description"><?php esc_html_e( 'Used by the shortcode and as the default for new Elementor widgets.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Credit line', 'certloom' ); ?></th><td><label><input type="checkbox" name="show_credit" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'show_credit', false ) ); ?> /> <?php esc_html_e( 'Show a public credit line beneath new shortcode output', 'certloom' ); ?></label><p class="description"><?php esc_html_e( 'This is optional and disabled by default for WordPress.org compliance.', 'certloom' ); ?></p></td></tr>
			<tr><th scope="row"><label for="credit_text"><?php esc_html_e( 'Credit text', 'certloom' ); ?></label></th><td><input type="text" name="credit_text" id="credit_text" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_text', __( 'Verification powered by Certloom.', 'certloom' ) ) ); ?>" class="large-text" /></td></tr>
			<tr><th scope="row"><label for="credit_color"><?php esc_html_e( 'Credit text color', 'certloom' ); ?></label></th><td><input type="text" name="credit_color" id="credit_color" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_color', '' ) ); ?>" class="regular-text" placeholder="#667085" /></td></tr>
			<tr><th scope="row"><label for="credit_size"><?php esc_html_e( 'Credit text size', 'certloom' ); ?></label></th><td><input type="number" name="credit_size" id="credit_size" value="<?php echo esc_attr( Ecapla_CertVerify_Settings::get( 'credit_size', '' ) ); ?>" min="8" max="24" class="small-text" /> px</td></tr>
			<tr><th scope="row"><label for="credit_align"><?php esc_html_e( 'Credit alignment', 'certloom' ); ?></label></th><td><select name="credit_align" id="credit_align"><?php $align = Ecapla_CertVerify_Settings::get( 'credit_align', 'center' ); ?><option value="left" <?php selected( $align, 'left' ); ?>><?php esc_html_e( 'Left', 'certloom' ); ?></option><option value="center" <?php selected( $align, 'center' ); ?>><?php esc_html_e( 'Center', 'certloom' ); ?></option><option value="right" <?php selected( $align, 'right' ); ?>><?php esc_html_e( 'Right', 'certloom' ); ?></option></select></td></tr>
		</table>

		<h2><?php esc_html_e( 'Status guide wording', 'certloom' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Customize the explanatory text displayed in the optional public status guide.', 'certloom' ); ?></p>
		<table class="form-table" role="presentation"><?php foreach ( Ecapla_CertVerify_Fields::get_statuses() as $key => $status ) : ?><tr><th scope="row"><label for="status_desc_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $status['label'] ); ?></label></th><td><textarea name="status_desc[<?php echo esc_attr( $key ); ?>]" id="status_desc_<?php echo esc_attr( $key ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $status['desc'] ); ?></textarea></td></tr><?php endforeach; ?></table>

		<h2><?php esc_html_e( 'Data retention', 'certloom' ); ?></h2>
		<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( Ecapla_CertVerify_Settings::get( 'delete_data_on_uninstall', false ) ); ?> /> <?php esc_html_e( 'Permanently delete certificates, settings, and field definitions when the plugin is uninstalled', 'certloom' ); ?></label>
		<p class="description ecapla-certverify-warning-text"><?php esc_html_e( 'Leave this disabled to preserve certificate data and settings. Scheduled tasks, temporary data, and plugin capabilities are cleaned up during uninstall in either mode.', 'certloom' ); ?></p>

		<?php submit_button( __( 'Save settings', 'certloom' ), 'primary', 'certverify_save_settings' ); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Expiry behavior', 'certloom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Expired is calculated automatically from each certificate’s expiry date. Renewing the expiry date immediately returns the certificate to Valid unless its manual status is Suspended or Withdrawn. No scheduled synchronization task is required.', 'certloom' ); ?></p>

	<hr />
	<h2><?php esc_html_e( 'Privacy note', 'certloom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Fields marked “Show on public result” are returned through a public exact-match REST endpoint. Avoid publishing sensitive personal data. Certificate numbers entered in a browser may also appear in server logs, browser history, analytics, and cache layers.', 'certloom' ); ?></p>

	<hr />
	<h2><?php esc_html_e( 'System status', 'certloom' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Read-only local diagnostics for Certloom. Nothing in this report is sent to Ecapla, and it intentionally excludes certificate data, site URLs, filesystem paths, database names, user data, and plugin inventories.', 'certloom' ); ?></p>
	<?php $certloom_system_checks = Ecapla_CertVerify_System_Status::get_checks(); ?>
	<table class="widefat striped" role="table">
		<thead><tr><th scope="col"><?php esc_html_e( 'Check', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Value', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Notes', 'certloom' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $certloom_system_checks as $certloom_check ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $certloom_check['label'] ); ?></th>
				<td><strong><?php echo esc_html( 'good' === $certloom_check['status'] ? __( 'Ready', 'certloom' ) : ( 'bad' === $certloom_check['status'] ? __( 'Action needed', 'certloom' ) : __( 'Review', 'certloom' ) ) ); ?></strong></td>
				<td><?php echo esc_html( $certloom_check['value'] ); ?></td>
				<td><?php echo esc_html( $certloom_check['note'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><label for="certloom_system_report"><strong><?php esc_html_e( 'Copyable support report', 'certloom' ); ?></strong></label></p>
	<textarea id="certloom_system_report" class="large-text code" rows="12" readonly><?php echo esc_textarea( Ecapla_CertVerify_System_Status::get_text_report() ); ?></textarea>


	<h3><?php esc_html_e( 'Isolated database proof', 'certloom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'This administrator-triggered proof creates one empty Certloom scratch table, exercises WordPress dbDelta(), InnoDB, indexes, uniqueness, transaction rollback/commit, and—when the host permits a second connection—row-lock contention. It never reads certificate records, never creates the gated credential-domain tables, never changes credential authority, and removes the scratch table before returning.', 'certloom' ); ?></p>
	<form method="post" action="" class="ecapla-certverify-inline-form">
		<?php wp_nonce_field( 'certverify_run_database_proof' ); ?>
		<?php submit_button( __( 'Run isolated database proof', 'certloom' ), 'secondary', 'certverify_run_database_proof', false ); ?>
	</form>
	<?php $certloom_database_proof = Ecapla_CertVerify_Database_Proof::get_last_result(); ?>
	<?php if ( is_array( $certloom_database_proof ) ) : ?>
		<table class="widefat striped" role="table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Database proof check', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'certloom' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( (array) $certloom_database_proof['checks'] as $certloom_proof_check ) : ?>
				<?php if ( ! is_array( $certloom_proof_check ) ) { continue; } ?>
				<tr>
					<th scope="row"><?php echo esc_html( $certloom_proof_check['label'] ?? '' ); ?></th>
					<td><strong><?php echo esc_html( 'pass' === ( $certloom_proof_check['status'] ?? '' ) ? __( 'Passed', 'certloom' ) : ( 'fail' === ( $certloom_proof_check['status'] ?? '' ) ? __( 'Failed', 'certloom' ) : __( 'Review', 'certloom' ) ) ); ?></strong></td>
					<td><?php echo esc_html( $certloom_proof_check['message'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><label for="certloom_database_proof_report"><strong><?php esc_html_e( 'Copyable database-proof report', 'certloom' ); ?></strong></label></p>
		<textarea id="certloom_database_proof_report" class="large-text code" rows="12" readonly><?php echo esc_textarea( Ecapla_CertVerify_Database_Proof::get_text_report( $certloom_database_proof ) ); ?></textarea>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Gated Credential Series acceptance proof', 'certloom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'This stronger proof is available only after a fresh passing isolated database proof. It deliberately provisions/validates Certloom’s additive future Credential Series tables, then exercises the real repositories, immutable Version constraints, lifecycle events, Status Service/verification, and synthetic legacy-status parity inside one rollback-only transaction. It does not read individual live certificate rows, does not migrate the live registry, does not change credential authority, and leaves no synthetic credential rows behind.', 'certloom' ); ?></p>
	<?php $certloom_domain_acceptance_ready = Ecapla_CertVerify_Database_Proof::is_current_passed(); ?>
	<form method="post" action="" class="ecapla-certverify-inline-form">
		<?php wp_nonce_field( 'certverify_run_domain_acceptance_proof' ); ?>
		<?php submit_button( __( 'Run gated-domain acceptance proof', 'certloom' ), 'secondary', 'certverify_run_domain_acceptance_proof', false, $certloom_domain_acceptance_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>
	<?php if ( ! $certloom_domain_acceptance_ready ) : ?><p class="description"><strong><?php esc_html_e( 'Run a fresh passing isolated database proof first.', 'certloom' ); ?></strong></p><?php endif; ?>
	<?php $certloom_domain_acceptance_proof = \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::get_last_result(); ?>
	<?php if ( is_array( $certloom_domain_acceptance_proof ) ) : ?>
		<table class="widefat striped" role="table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Domain acceptance check', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'certloom' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( (array) $certloom_domain_acceptance_proof['checks'] as $certloom_domain_check ) : ?>
				<?php if ( ! is_array( $certloom_domain_check ) ) { continue; } ?>
				<tr>
					<th scope="row"><?php echo esc_html( $certloom_domain_check['label'] ?? '' ); ?></th>
					<td><strong><?php echo esc_html( 'pass' === ( $certloom_domain_check['status'] ?? '' ) ? __( 'Passed', 'certloom' ) : ( 'fail' === ( $certloom_domain_check['status'] ?? '' ) ? __( 'Failed', 'certloom' ) : __( 'Review', 'certloom' ) ) ); ?></strong></td>
					<td><?php echo esc_html( $certloom_domain_check['message'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><label for="certloom_domain_acceptance_report"><strong><?php esc_html_e( 'Copyable gated-domain proof report', 'certloom' ); ?></strong></label></p>
		<textarea id="certloom_domain_acceptance_report" class="large-text code" rows="14" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::get_text_report( $certloom_domain_acceptance_proof ) ); ?></textarea>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Populated legacy migration preflight', 'certloom' ); ?></h3>
	<p class="description"><?php esc_html_e( 'After the real gated-domain proof passes, this read-only rehearsal scans the actual populated Certloom 1.5 registry through the final migration mapper and Status Service. It runs in bounded resumable slices, performs a second fingerprint-verification pass, never writes Credential Series rows, never starts official migration, and never changes credential authority. The local error table contains only numeric legacy row IDs plus migration-review messages; the copyable report excludes certificate numbers, names and custom-field values.', 'certloom' ); ?></p>
	<?php $certloom_legacy_preflight_ready = \Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof::is_current_passed(); ?>
	<?php $certloom_legacy_preflight_proof = \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::get_last_result(); ?>
	<?php $certloom_legacy_preflight_evidence = \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::evidence_state(); ?>
	<form method="post" action="" class="ecapla-certverify-inline-form">
		<?php wp_nonce_field( 'certverify_run_legacy_migration_preflight_proof' ); ?>
		<?php submit_button( 'running' === $certloom_legacy_preflight_evidence ? __( 'Continue migration preflight', 'certloom' ) : __( 'Run populated migration preflight', 'certloom' ), 'secondary', 'certverify_run_legacy_migration_preflight_proof', false, $certloom_legacy_preflight_ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>
	<?php if ( ! $certloom_legacy_preflight_ready ) : ?><p class="description"><strong><?php esc_html_e( 'Run a fresh passing gated Credential Series acceptance proof first.', 'certloom' ); ?></strong></p><?php endif; ?>
	<?php if ( is_array( $certloom_legacy_preflight_proof ) ) : ?>
		<?php $certloom_legacy_total = absint( $certloom_legacy_preflight_proof['source_count'] ?? 0 ); ?>
		<?php $certloom_legacy_mapped = absint( $certloom_legacy_preflight_proof['total'] ?? 0 ); ?>
		<?php $certloom_legacy_verified = absint( $certloom_legacy_preflight_proof['verify_total'] ?? 0 ); ?>
		<table class="widefat striped" role="table">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'State', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $certloom_legacy_preflight_evidence ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Phase', 'certloom' ); ?></th><td><?php echo esc_html( ucfirst( sanitize_key( (string) ( $certloom_legacy_preflight_proof['phase'] ?? '' ) ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows mapped', 'certloom' ); ?></th><td><?php echo esc_html( sprintf( '%1$d / %2$d', $certloom_legacy_mapped, $certloom_legacy_total ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows fingerprint-verified', 'certloom' ); ?></th><td><?php echo esc_html( sprintf( '%1$d / %2$d', $certloom_legacy_verified, $certloom_legacy_total ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows ready', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_legacy_preflight_proof['ready'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Rows requiring review', 'certloom' ); ?></th><td><?php echo esc_html( absint( $certloom_legacy_preflight_proof['errors'] ?? 0 ) ); ?></td></tr>
			</tbody>
		</table>
		<?php if ( ! empty( $certloom_legacy_preflight_proof['error_examples'] ) ) : ?>
			<p><strong><?php esc_html_e( 'Local bounded error examples', 'certloom' ); ?></strong></p>
			<table class="widefat striped" role="table"><thead><tr><th><?php esc_html_e( 'Legacy row ID', 'certloom' ); ?></th><th><?php esc_html_e( 'Migration review message', 'certloom' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $certloom_legacy_preflight_proof['error_examples'] as $certloom_legacy_error ) : if ( ! is_array( $certloom_legacy_error ) ) { continue; } ?>
				<tr><td><?php echo esc_html( absint( $certloom_legacy_error['legacy_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $certloom_legacy_error['message'] ?? '' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<p><label for="certloom_legacy_preflight_report"><strong><?php esc_html_e( 'Copyable migration-preflight report', 'certloom' ); ?></strong></label></p>
		<textarea id="certloom_legacy_preflight_report" class="large-text code" rows="12" readonly><?php echo esc_textarea( \Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof::get_text_report( $certloom_legacy_preflight_proof ) ); ?></textarea>
	<?php endif; ?>

	<hr />
	<h2><?php esc_html_e( 'Ecapla support', 'certloom' ); ?></h2>
	<p class="ecapla-certverify-support-actions">
		<a class="button button-secondary" href="https://ecapla.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit ecapla.com', 'certloom' ); ?></a>
		<a class="button button-primary" href="mailto:support@ecapla.com?subject=Certloom%20%E2%80%93%20Report%20an%20Issue"><?php esc_html_e( 'Report an issue', 'certloom' ); ?></a>
	</p>
	<p class="description"><?php esc_html_e( 'Report an issue opens your email application with a Certloom support subject. The website button opens ecapla.com in a new tab.', 'certloom' ); ?></p>
</div>
