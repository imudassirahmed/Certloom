<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_templates = is_array( $email_templates ?? null ) ? $email_templates : array();
$email_rules     = is_array( $email_rules ?? null ) ? $email_rules : array();
$programs        = is_array( $programs ?? null ) ? $programs : array();
$source_status   = is_array( $source_status ?? null ) ? $source_status : array();
$destination_status = is_array( $destination_status ?? null ) ? $destination_status : array();
$context_nonce   = wp_create_nonce( \Certloom\Infrastructure\Admin\ScopedFieldAdminAjax::NONCE_ACTION );
$notice          = isset( $_GET['certloom_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected notice only.
$error           = isset( $_GET['certloom_error'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected notice only.

$template_names = array();
foreach ( $email_templates as $template ) {
	if ( is_array( $template ) ) {
		$template_names[ (string) ( $template['id'] ?? '' ) ] = (string) ( $template['name'] ?? '' );
	}
}
$program_names = array();
foreach ( $programs as $program ) {
	if ( is_array( $program ) ) {
		$program_names[ absint( $program['id'] ?? 0 ) ] = (string) ( $program['title'] ?? '' );
	}
}
$source_labels = array(
	'any'          => __( 'Any issuance source', 'certloom' ),
	'manual_admin' => __( 'Manual issuance', 'certloom' ),
	'bulk_admin'   => __( 'Bulk issuance', 'certloom' ),
	'tutor_lms'    => __( 'Tutor LMS', 'certloom' ),
	'learndash'    => __( 'LearnDash', 'certloom' ),
	'woocommerce'  => __( 'WooCommerce', 'certloom' ),
	'coursera'     => __( 'Coursera', 'certloom' ),
	'webhook'      => __( 'Webhook / API', 'certloom' ),
);
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-flow-workspace certloom-automations-workspace">
	<section class="certloom-workspace-hero" aria-labelledby="certloom-automations-title">
		<div class="certloom-workspace-hero-copy">
			<span class="certloom-eyebrow"><?php esc_html_e( 'Delivery & follow-up', 'certloom' ); ?></span>
			<h1 id="certloom-automations-title"><?php esc_html_e( 'Automations', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Decide what should happen after Certloom successfully issues a credential. Manual, bulk, LMS and commerce sources all finish through the same credential workflow.', 'certloom' ); ?></p>
		</div>
		<div class="certloom-workspace-hero-actions">
			<a class="button button-primary" href="#certloom-new-rule"><?php esc_html_e( 'Create automation', 'certloom' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-integrations' ) ); ?>"><?php esc_html_e( 'Connect a source', 'certloom' ); ?></a>
		</div>
	</section>

	<?php if ( $notice ) : ?><div class="notice notice-success is-dismissible certloom-owned-notice"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	<?php if ( $error ) : ?><div class="notice notice-error certloom-owned-notice"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>

	<section class="certloom-flow-map" aria-label="<?php esc_attr_e( 'Credential automation flow', 'certloom' ); ?>">
		<div class="certloom-flow-step"><span class="certloom-flow-step-number">1</span><div><strong><?php esc_html_e( 'A person qualifies', 'certloom' ); ?></strong><small><?php esc_html_e( 'Manual, bulk, LMS or purchase', 'certloom' ); ?></small></div></div>
		<span class="certloom-flow-arrow" aria-hidden="true">→</span>
		<div class="certloom-flow-step"><span class="certloom-flow-step-number">2</span><div><strong><?php esc_html_e( 'Certloom issues', 'certloom' ); ?></strong><small><?php esc_html_e( 'Recipient → Program → Credential', 'certloom' ); ?></small></div></div>
		<span class="certloom-flow-arrow" aria-hidden="true">→</span>
		<div class="certloom-flow-step"><span class="certloom-flow-step-number">3</span><div><strong><?php esc_html_e( 'Automation runs', 'certloom' ); ?></strong><small><?php esc_html_e( 'Deliver or trigger follow-up', 'certloom' ); ?></small></div></div>
	</section>

	<div class="certloom-workspace-grid certloom-workspace-grid-main">
		<section class="certloom-workspace-card" aria-labelledby="certloom-rules-title">
			<header class="certloom-workspace-card-head">
				<div><span class="certloom-eyebrow"><?php esc_html_e( 'Automatic actions', 'certloom' ); ?></span><h2 id="certloom-rules-title"><?php esc_html_e( 'Your automations', 'certloom' ); ?></h2><p><?php esc_html_e( 'Rules run only after issuance succeeds. They never create or alter credential truth.', 'certloom' ); ?></p></div>
				<span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( $email_rules ) ) ); ?></span>
			</header>

			<?php if ( $email_rules ) : ?>
				<div class="certloom-clean-list">
				<?php foreach ( $email_rules as $rule ) :
					$template_id = (string) ( $rule['template_id'] ?? '' );
					$program_id  = absint( $rule['program_id'] ?? 0 );
					$source_type = (string) ( $rule['source_type'] ?? 'any' );
				?>
					<article class="certloom-clean-list-row">
						<div class="certloom-clean-list-main">
							<div class="certloom-clean-list-title"><strong><?php echo esc_html( (string) $rule['name'] ); ?></strong><span class="certloom-status-dot <?php echo ! empty( $rule['active'] ) ? 'is-on' : ''; ?>" aria-hidden="true"></span></div>
							<p><?php
								printf(
									/* translators: 1: email template name, 2: Program name, 3: issuance source. */
									esc_html__( 'Send “%1$s” · %2$s · %3$s', 'certloom' ),
									esc_html( $template_names[ $template_id ] ?? $template_id ),
									esc_html( $program_id ? ( $program_names[ $program_id ] ?? __( 'Selected Program', 'certloom' ) ) : __( 'Any Program', 'certloom' ) ),
									esc_html( $source_labels[ $source_type ] ?? $source_type )
								);
							?></p>
						</div>
						<span class="certloom-state-pill <?php echo ! empty( $rule['active'] ) ? 'is-success' : ''; ?>"><?php echo ! empty( $rule['active'] ) ? esc_html__( 'On', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="certloom_automation_rule_delete"><input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule['id'] ); ?>"><?php wp_nonce_field( 'certloom_automation_rule_delete_' . (string) $rule['id'] ); ?>
							<button class="button button-small" type="submit"><?php esc_html_e( 'Remove', 'certloom' ); ?></button>
						</form>
					</article>
				<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="certloom-premium-empty-state certloom-empty-compact"><span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span><h3><?php esc_html_e( 'Nothing is automated yet', 'certloom' ); ?></h3><p><?php esc_html_e( 'Credentials still work normally. Add your first rule when you want delivery to happen automatically.', 'certloom' ); ?></p><a href="#certloom-new-rule" class="button button-primary"><?php esc_html_e( 'Create first automation', 'certloom' ); ?></a></div>
			<?php endif; ?>
		</section>

		<aside class="certloom-workspace-card certloom-source-summary" aria-labelledby="certloom-source-summary-title">
			<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Sources', 'certloom' ); ?></span><h2 id="certloom-source-summary-title"><?php esc_html_e( 'Connected systems', 'certloom' ); ?></h2><p><?php esc_html_e( 'Every source feeds the same Recipient and Program model.', 'certloom' ); ?></p></div></header>
			<div class="certloom-source-mini-list">
			<?php foreach ( $source_status as $source ) : if ( ! is_array( $source ) ) { continue; } ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-integrations' ) ); ?>"><span class="certloom-source-icon dashicons <?php echo esc_attr( (string) ( $source['icon'] ?? 'dashicons-admin-plugins' ) ); ?>" aria-hidden="true"></span><span><strong><?php echo esc_html( (string) ( $source['label'] ?? '' ) ); ?></strong><small><?php echo esc_html( (string) ( $source['status'] ?? '' ) ); ?></small></span><span aria-hidden="true">→</span></a>
			<?php endforeach; ?>
			</div>
			<?php if ( $destination_status ) : ?>
			<div class="certloom-source-summary-divider" aria-hidden="true"></div>
			<div class="certloom-destination-summary-head"><span class="certloom-eyebrow"><?php esc_html_e( 'Destinations', 'certloom' ); ?></span><p><?php esc_html_e( 'Destinations run only after Certloom has issued the credential.', 'certloom' ); ?></p></div>
			<div class="certloom-source-mini-list certloom-destination-mini-list">
			<?php foreach ( $destination_status as $destination ) : if ( ! is_array( $destination ) ) { continue; } ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-integrations#certloom-credly-setup' ) ); ?>"><span class="certloom-source-icon dashicons <?php echo esc_attr( (string) ( $destination['icon'] ?? 'dashicons-share' ) ); ?>" aria-hidden="true"></span><span><strong><?php echo esc_html( (string) ( $destination['label'] ?? '' ) ); ?></strong><small><?php echo esc_html( (string) ( $destination['status'] ?? '' ) ); ?></small></span><span aria-hidden="true">→</span></a>
			<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<a class="certloom-inline-link" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-integrations' ) ); ?>"><?php esc_html_e( 'Manage integrations', 'certloom' ); ?> <span aria-hidden="true">→</span></a>
		</aside>
	</div>

	<section id="certloom-new-rule" class="certloom-workspace-card certloom-setup-card" aria-labelledby="certloom-new-rule-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'New rule', 'certloom' ); ?></span><h2 id="certloom-new-rule-title"><?php esc_html_e( 'When a credential is issued…', 'certloom' ); ?></h2><p><?php esc_html_e( 'Choose the message to send and optionally narrow it to one Program or source.', 'certloom' ); ?></p></div></header>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form">
			<input type="hidden" name="action" value="certloom_automation_rule_save"><?php wp_nonce_field( 'certloom_automation_rule_save' ); ?>
			<div class="certloom-form-grid certloom-form-grid-2">
				<label class="certloom-field-control"><span><?php esc_html_e( 'Automation name', 'certloom' ); ?></span><input type="text" name="name" required maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Send course completion email', 'certloom' ); ?>"></label>
				<label class="certloom-field-control"><span><?php esc_html_e( 'Email template', 'certloom' ); ?></span><select name="template_id" required><?php foreach ( $email_templates as $template ) : if ( empty( $template['active'] ) ) { continue; } ?><option value="<?php echo esc_attr( (string) $template['id'] ); ?>"><?php echo esc_html( (string) $template['name'] ); ?></option><?php endforeach; ?></select></label>
				<label class="certloom-field-control"><span><?php esc_html_e( 'Program', 'certloom' ); ?></span><select name="program_id" data-certloom-context-lookup="program" data-certloom-context-program-status="active" data-certloom-context-nonce="<?php echo esc_attr( $context_nonce ); ?>" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search Programs…', 'certloom' ); ?>"><option value="0"><?php esc_html_e( 'Any Program', 'certloom' ); ?></option><?php foreach ( $programs as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
				<label class="certloom-field-control"><span><?php esc_html_e( 'Issued through', 'certloom' ); ?></span><select name="source_type"><?php foreach ( $source_labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			</div>
			<label class="certloom-switch-row"><input type="checkbox" name="active" value="1" checked><span><strong><?php esc_html_e( 'Turn on now', 'certloom' ); ?></strong><small><?php esc_html_e( 'You can remove or replace the rule later.', 'certloom' ); ?></small></span></label>
			<div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save automation', 'certloom' ); ?></button></div>
		</form>
	</section>

	<section class="certloom-workspace-card" aria-labelledby="certloom-email-templates-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Reusable messages', 'certloom' ); ?></span><h2 id="certloom-email-templates-title"><?php esc_html_e( 'Email templates', 'certloom' ); ?></h2><p><?php esc_html_e( 'Keep different messages for degrees, training, awards or different Programs. Each message uses the recipient and credential details that were saved when the credential was issued.', 'certloom' ); ?></p></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( $email_templates ) ) ); ?></span></header>
		<div class="certloom-template-card-grid">
		<?php foreach ( $email_templates as $template ) : ?>
			<details class="certloom-edit-card">
				<summary><span class="certloom-edit-card-title"><strong><?php echo esc_html( (string) $template['name'] ); ?></strong><small><?php echo ! empty( $template['active'] ) ? esc_html__( 'Available for delivery', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></small></span><span class="certloom-edit-card-action"><?php esc_html_e( 'Edit', 'certloom' ); ?> <span aria-hidden="true">↓</span></span></summary>
				<div class="certloom-edit-card-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form">
					<input type="hidden" name="action" value="certloom_automation_template_save"><input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $template['id'] ); ?>"><?php wp_nonce_field( 'certloom_automation_template_save' ); ?>
					<label class="certloom-field-control"><span><?php esc_html_e( 'Template name', 'certloom' ); ?></span><input name="name" required maxlength="120" value="<?php echo esc_attr( (string) $template['name'] ); ?>"></label>
					<label class="certloom-field-control"><span><?php esc_html_e( 'Subject', 'certloom' ); ?></span><input name="subject" required maxlength="200" value="<?php echo esc_attr( (string) $template['subject'] ); ?>"></label>
					<label class="certloom-field-control"><span><?php esc_html_e( 'Message', 'certloom' ); ?></span><textarea rows="7" name="html_body" required><?php echo esc_textarea( (string) $template['html_body'] ); ?></textarea></label>
					<details class="certloom-technical-details"><summary><?php esc_html_e( 'Plain-text fallback', 'certloom' ); ?></summary><label class="certloom-field-control"><span class="screen-reader-text"><?php esc_html_e( 'Plain-text fallback', 'certloom' ); ?></span><textarea rows="6" name="text_body" required><?php echo esc_textarea( (string) $template['text_body'] ); ?></textarea></label></details>
					<label class="certloom-switch-row"><input type="checkbox" name="active" value="1" <?php checked( ! empty( $template['active'] ) ); ?>><span><strong><?php esc_html_e( 'Available for delivery', 'certloom' ); ?></strong></span></label>
					<p class="certloom-token-note"><?php esc_html_e( 'You can use recipient name, Program, issuer, credential number, issue/expiry dates, grade and verification link placeholders.', 'certloom' ); ?></p>
					<div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save template', 'certloom' ); ?></button></div>
				</form>
				<?php if ( \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository::DEFAULT_ID !== (string) $template['id'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-destructive-inline"><input type="hidden" name="action" value="certloom_automation_template_delete"><input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $template['id'] ); ?>"><?php wp_nonce_field( 'certloom_automation_template_delete_' . (string) $template['id'] ); ?><button class="button-link-delete" type="submit"><?php esc_html_e( 'Delete template', 'certloom' ); ?></button></form><?php endif; ?>
				</div>
			</details>
		<?php endforeach; ?>
		</div>

		<details class="certloom-create-drawer">
			<summary><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Create another email template', 'certloom' ); ?></summary>
			<div class="certloom-create-drawer-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form">
				<input type="hidden" name="action" value="certloom_automation_template_save"><?php wp_nonce_field( 'certloom_automation_template_save' ); ?>
				<div class="certloom-form-grid certloom-form-grid-2"><label class="certloom-field-control"><span><?php esc_html_e( 'Template name', 'certloom' ); ?></span><input name="name" required maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Graduation email', 'certloom' ); ?>"></label><label class="certloom-field-control"><span><?php esc_html_e( 'Subject', 'certloom' ); ?></span><input name="subject" required maxlength="200" value="<?php esc_attr_e( 'Your {{program_title}} credential', 'certloom' ); ?>"></label></div>
				<label class="certloom-field-control"><span><?php esc_html_e( 'Message', 'certloom' ); ?></span><textarea rows="7" name="html_body" required>&lt;p&gt;Hello {{recipient_name}},&lt;/p&gt;&lt;p&gt;Your credential for {{program_title}} has been issued.&lt;/p&gt;&lt;p&gt;{{verification_link}}&lt;/p&gt;</textarea></label>
				<input type="hidden" name="text_body" value="Hello {{recipient_name}},&#10;&#10;Your credential for {{program_title}} has been issued.&#10;{{verification_url}}"><input type="hidden" name="active" value="1">
				<div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Create template', 'certloom' ); ?></button></div>
			</form>
			</div>
		</details>
	</section>
</div>
