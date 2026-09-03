<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$certloom_context_nonce = wp_create_nonce( \Certloom\Infrastructure\Admin\ScopedFieldAdminAjax::NONCE_ACTION );
$simple      = \Certloom\Infrastructure\Admin\AdminUiMode::is_simple(); // Compatibility copy choice only; technical capability uses progressive disclosure below.
$tutor       = isset( $tutor ) && is_array( $tutor ) ? $tutor : array();
$learndash   = isset( $learndash ) && is_array( $learndash ) ? $learndash : array();
$woocommerce = isset( $woocommerce ) && is_array( $woocommerce ) ? $woocommerce : array();
$coursera    = isset( $coursera ) && is_array( $coursera ) ? $coursera : array();
$webhooks    = isset( $webhooks ) && is_array( $webhooks ) ? $webhooks : array();
$credly      = isset( $credly ) && is_array( $credly ) ? $credly : array();

/** @param array<int,array<string,mixed>> $items */
$certloom_index = static function ( array $items, $id_key, $label_key ) {
	$out = array();
	foreach ( $items as $item ) {
		if ( is_array( $item ) ) { $out[ absint( $item[ $id_key ] ?? 0 ) ] = (string) ( $item[ $label_key ] ?? '' ); }
	}
	return $out;
};
$tutor_course_labels     = $certloom_index( (array) ( $tutor['courses'] ?? array() ), 'id', 'title' );
$tutor_program_labels    = $certloom_index( (array) ( $tutor['programs'] ?? array() ), 'id', 'title' );
$ld_course_labels        = $certloom_index( (array) ( $learndash['courses'] ?? array() ), 'id', 'title' );
$ld_program_labels       = $certloom_index( (array) ( $learndash['programs'] ?? array() ), 'id', 'title' );
$woo_product_labels      = $certloom_index( (array) ( $woocommerce['products'] ?? array() ), 'id', 'title' );
$woo_program_labels      = $certloom_index( (array) ( $woocommerce['programs'] ?? array() ), 'id', 'title' );
$coursera_program_labels = $certloom_index( (array) ( $coursera['programs'] ?? array() ), 'id', 'title' );
$webhook_program_labels  = $certloom_index( (array) ( $webhooks['programs'] ?? array() ), 'id', 'title' );
$credly_program_labels  = $certloom_index( (array) ( $credly['programs'] ?? array() ), 'id', 'title' );
$certloom_integration_batch_ids = array();
foreach ( array( (array) ( $tutor['mappings'] ?? array() ), (array) ( $learndash['mappings'] ?? array() ), (array) ( $woocommerce['mappings'] ?? array() ), (array) ( $coursera['mappings'] ?? array() ), (array) ( $webhooks['sources'] ?? array() ) ) as $rows ) {
	foreach ( $rows as $row ) { if ( is_array( $row ) && ! empty( $row['batch_id'] ) ) { $certloom_integration_batch_ids[] = absint( $row['batch_id'] ); } }
}
$certloom_batch_labels = ( new \Certloom\Infrastructure\Repository\BatchRepository() )->labels_by_ids( $certloom_integration_batch_ids );
if ( is_wp_error( $certloom_batch_labels ) ) { $certloom_batch_labels = array(); }
$certloom_mapping_target_label = static function ( $program_label, $batch_id, $legacy_template_id = 0 ) use ( $certloom_batch_labels ) {
	$batch_id = absint( $batch_id );
	$target = ! $batch_id
		? sprintf( /* translators: %s: Program name. */ __( '%s · Program context · design follows hierarchy', 'certloom' ), (string) $program_label )
		: sprintf(
			/* translators: 1: Program name, 2: Batch name. */
			__( '%1$s → %2$s · design follows hierarchy', 'certloom' ),
			(string) $program_label,
			(string) ( $certloom_batch_labels[ $batch_id ] ?? sprintf( /* translators: %d: Batch ID. */ __( 'Batch #%d', 'certloom' ), $batch_id ) )
		);
	if ( absint( $legacy_template_id ) ) {
		$target .= ' · ' . __( 'Legacy design fallback retained until this mapping is re-saved', 'certloom' );
	}
	return $target;
};

$notice_key = isset( $_GET['certloom_notice'] ) ? sanitize_key( wp_unslash( $_GET['certloom_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected notice only.
$error_text = isset( $_GET['certloom_error'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected notice only.
$notices = array(
	// Tutor's original admin contract used generic mapping_saved/mapping_deleted keys.
	// Keep those exact redirect keys valid while provider-specific keys remain supported.
	'mapping_saved'               => __( 'Course mapping saved.', 'certloom' ),
	'mapping_deleted'             => __( 'Course mapping removed.', 'certloom' ),
	'tutor_mapping_saved'         => __( 'Course mapping saved.', 'certloom' ),
	'tutor_mapping_deleted'       => __( 'Course mapping removed.', 'certloom' ),
	'learndash_mapping_saved'     => __( 'Course mapping saved.', 'certloom' ),
	'learndash_mapping_deleted'   => __( 'Course mapping removed.', 'certloom' ),
	'woocommerce_mapping_saved'   => __( 'Product mapping saved.', 'certloom' ),
	'woocommerce_mapping_deleted' => __( 'Product mapping removed.', 'certloom' ),
	'coursera_connection_rotated' => __( 'Coursera connection credentials generated. Copy the one-time secret now.', 'certloom' ),
	'coursera_disconnected'       => __( 'Coursera xAPI connection disconnected.', 'certloom' ),
	'coursera_mapping_saved'      => __( 'Coursera course mapping saved.', 'certloom' ),
	'coursera_mapping_deleted'    => __( 'Coursera course mapping removed.', 'certloom' ),
	'webhook_source_created'      => __( 'Webhook source created. Copy the one-time signing secret now.', 'certloom' ),
	'webhook_secret_rotated'      => __( 'Webhook signing secret rotated. Copy the new secret now.', 'certloom' ),
	'webhook_source_deleted'      => __( 'Webhook source removed.', 'certloom' ),
	'credly_connected'            => __( 'Credly connection saved securely.', 'certloom' ),
	'credly_disconnected'         => __( 'Credly disconnected. Existing Certloom credentials are unchanged.', 'certloom' ),
	'credly_mapping_saved'        => __( 'Credly publishing rule saved.', 'certloom' ),
	'credly_mapping_deleted'      => __( 'Credly publishing rule removed.', 'certloom' ),
);
$coursera_credentials = is_array( $coursera['credentials'] ?? null ) ? $coursera['credentials'] : array();
$coursera_configured  = ! empty( $coursera_credentials['configured'] );
$coursera_mappings    = (array) ( $coursera['mappings'] ?? array() );
$webhook_sources       = (array) ( $webhooks['sources'] ?? array() );
$credly_connection   = is_array( $credly['connection'] ?? null ) ? $credly['connection'] : array();
$credly_configured   = ! empty( $credly_connection['configured'] );
$credly_mappings     = (array) ( $credly['mappings'] ?? array() );
$credly_recent       = (array) ( $credly['recent'] ?? array() );
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-flow-workspace certloom-integrations-workspace">
	<section class="certloom-workspace-hero" aria-labelledby="certloom-integrations-title">
		<div class="certloom-workspace-hero-copy">
			<span class="certloom-eyebrow"><?php esc_html_e( 'Sources', 'certloom' ); ?></span>
			<h1 id="certloom-integrations-title"><?php esc_html_e( 'Integrations', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Connect the systems where people qualify. Every source uses the same Recipient → Program → credential workflow.', 'certloom' ); ?></p>
		</div>
		<div class="certloom-workspace-hero-actions">
			<a class="button button-primary" href="#certloom-source-cards"><?php esc_html_e( 'Choose a source', 'certloom' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-automations' ) ); ?>"><?php esc_html_e( 'Set up delivery', 'certloom' ); ?></a>
		</div>
	</section>

	<?php if ( $error_text ) : ?><div class="notice notice-error certloom-owned-notice"><p><?php echo esc_html( $error_text ); ?></p></div><?php endif; ?>
	<?php if ( isset( $notices[ $notice_key ] ) ) : ?><div class="notice notice-success is-dismissible certloom-owned-notice"><p><?php echo esc_html( $notices[ $notice_key ] ); ?></p></div><?php endif; ?>

	<section class="certloom-flow-map" aria-label="<?php esc_attr_e( 'Integration credential flow', 'certloom' ); ?>">
		<div class="certloom-flow-step"><span class="certloom-flow-step-number">1</span><div><strong><?php esc_html_e( 'Source confirms eligibility', 'certloom' ); ?></strong><small><?php esc_html_e( 'Completion, purchase or another trusted event', 'certloom' ); ?></small></div></div><span class="certloom-flow-arrow" aria-hidden="true">→</span>
		<div class="certloom-flow-step"><span class="certloom-flow-step-number">2</span><div><strong><?php esc_html_e( 'Certloom resolves the Recipient', 'certloom' ); ?></strong><small><?php esc_html_e( 'Explicit account or External ID — no name/email guessing', 'certloom' ); ?></small></div></div><span class="certloom-flow-arrow" aria-hidden="true">→</span>
		<div class="certloom-flow-step"><span class="certloom-flow-step-number">3</span><div><strong><?php esc_html_e( 'Issue once, automate next', 'certloom' ); ?></strong><small><?php esc_html_e( 'One Certloom credential, then optional delivery', 'certloom' ); ?></small></div></div>
	</section>

	<div class="certloom-callout certloom-callout-info certloom-integration-design-rule"><span class="dashicons dashicons-art" aria-hidden="true"></span><div><strong><?php esc_html_e( 'One design hierarchy everywhere', 'certloom' ); ?></strong><p><?php esc_html_e( 'Automatic sources choose a Program and, optionally, a Batch. They do not pin a separate certificate design. Design resolves Global → Issuer → Program → Batch at issuance time; the most specific selected design wins. If a level is set to inherit, Certloom continues up the chain.', 'certloom' ); ?></p></div></div>

	<div id="certloom-source-cards" class="certloom-integration-source-grid">
		<?php
		$sources = array(
			'tutor'       => array( 'label' => __( 'Tutor LMS', 'certloom' ), 'icon' => 'dashicons-welcome-learn-more', 'data' => $tutor, 'desc' => __( 'Issue when a mapped Tutor course is completed.', 'certloom' ) ),
			'learndash'   => array( 'label' => __( 'LearnDash', 'certloom' ), 'icon' => 'dashicons-welcome-learn-more', 'data' => $learndash, 'desc' => __( 'Issue when a mapped LearnDash course is completed.', 'certloom' ) ),
			'woocommerce' => array( 'label' => __( 'WooCommerce', 'certloom' ), 'icon' => 'dashicons-cart', 'data' => $woocommerce, 'desc' => __( 'Issue after an authenticated customer completes an order containing a mapped product.', 'certloom' ) ),
		);
		foreach ( $sources as $source_key => $source ) :
			$status    = is_array( $source['data']['status'] ?? null ) ? $source['data']['status'] : array();
			$available = ! empty( $status['available'] );
			$count     = absint( $status['active_mapping_count'] ?? 0 );
		?>
		<article class="certloom-integration-source-card <?php echo $available ? 'is-available' : ''; ?>">
			<div class="certloom-integration-source-card-top"><span class="certloom-source-icon dashicons <?php echo esc_attr( $source['icon'] ); ?>" aria-hidden="true"></span><span class="certloom-state-pill <?php echo $available ? 'is-success' : ''; ?>"><?php echo $available ? esc_html__( 'Available', 'certloom' ) : esc_html__( 'Not detected', 'certloom' ); ?></span></div>
			<h2><?php echo esc_html( $source['label'] ); ?></h2><p><?php echo esc_html( $source['desc'] ); ?></p>
			<div class="certloom-integration-source-meta"><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong><span><?php esc_html_e( 'active mappings', 'certloom' ); ?></span></div>
			<?php if ( $available ) : ?><a class="certloom-inline-link" href="#certloom-<?php echo esc_attr( $source_key ); ?>-setup"><?php esc_html_e( 'Configure', 'certloom' ); ?> <span aria-hidden="true">→</span></a><?php else : ?><span class="certloom-muted-link"><?php esc_html_e( 'Install or activate the source plugin to connect it.', 'certloom' ); ?></span><?php endif; ?>
		</article>
		<?php endforeach; ?>

		<article class="certloom-integration-source-card <?php echo $coursera_configured ? 'is-available' : 'is-external'; ?>">
			<div class="certloom-integration-source-card-top"><span class="certloom-source-icon dashicons dashicons-cloud" aria-hidden="true"></span><span class="certloom-state-pill <?php echo $coursera_configured ? 'is-success' : ''; ?>"><?php echo $coursera_configured ? esc_html__( 'Connected', 'certloom' ) : esc_html__( 'Set up connection', 'certloom' ); ?></span></div>
			<h2><?php esc_html_e( 'Coursera', 'certloom' ); ?></h2><p><?php esc_html_e( 'Receive verified course-completion xAPI events and link learners through their explicit External ID.', 'certloom' ); ?></p>
			<div class="certloom-integration-source-meta"><strong><?php echo esc_html( number_format_i18n( count( $coursera_mappings ) ) ); ?></strong><span><?php esc_html_e( 'course mappings', 'certloom' ); ?></span></div>
			<a class="certloom-inline-link" href="#certloom-coursera-setup"><?php esc_html_e( 'Configure', 'certloom' ); ?> <span aria-hidden="true">→</span></a>
		</article>

		<article class="certloom-integration-source-card <?php echo $webhook_sources ? 'is-available' : 'is-external'; ?>">
			<div class="certloom-integration-source-card-top"><span class="certloom-source-icon dashicons dashicons-rest-api" aria-hidden="true"></span><span class="certloom-state-pill <?php echo $webhook_sources ? 'is-success' : ''; ?>"><?php echo $webhook_sources ? esc_html__( 'Ready', 'certloom' ) : esc_html__( 'Create endpoint', 'certloom' ); ?></span></div>
			<h2><?php esc_html_e( 'Webhook / API', 'certloom' ); ?></h2><p><?php esc_html_e( 'Connect another trusted system with a signed completion webhook. The sender provides only an event ID, Recipient External ID and completion time.', 'certloom' ); ?></p>
			<div class="certloom-integration-source-meta"><strong><?php echo esc_html( number_format_i18n( count( $webhook_sources ) ) ); ?></strong><span><?php esc_html_e( 'endpoints', 'certloom' ); ?></span></div>
			<a class="certloom-inline-link" href="#certloom-webhook-setup"><?php esc_html_e( 'Configure', 'certloom' ); ?> <span aria-hidden="true">→</span></a>
		</article>

	</div>

	<section class="certloom-integration-destinations" aria-labelledby="certloom-destinations-title">
		<div class="certloom-section-intro certloom-integration-section-intro">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'After issuance', 'certloom' ); ?></span><h2 id="certloom-destinations-title"><?php esc_html_e( 'Publishing destinations', 'certloom' ); ?></h2><p><?php esc_html_e( 'Destinations never decide who earned a credential. Certloom issues first, then an optional destination can publish the same achievement elsewhere.', 'certloom' ); ?></p></div>
		</div>
		<div class="certloom-integration-destination-grid">
			<article class="certloom-integration-source-card certloom-integration-destination-card <?php echo $credly_configured ? 'is-available' : 'is-external'; ?>">
				<div class="certloom-integration-source-card-top"><span class="certloom-source-icon dashicons dashicons-awards" aria-hidden="true"></span><span class="certloom-state-pill <?php echo $credly_configured ? 'is-success' : ''; ?>"><?php echo $credly_configured ? esc_html__( 'Connected', 'certloom' ) : esc_html__( 'Not connected', 'certloom' ); ?></span></div>
				<h2><?php esc_html_e( 'Credly', 'certloom' ); ?></h2><p><?php esc_html_e( 'Publish a Certloom credential to a mapped Credly badge after Certloom has issued it. Certloom keeps the primary credential record.', 'certloom' ); ?></p>
				<div class="certloom-integration-source-meta"><strong><?php echo esc_html( number_format_i18n( count( $credly_mappings ) ) ); ?></strong><span><?php esc_html_e( 'publishing rules', 'certloom' ); ?></span></div>
				<a class="certloom-inline-link" href="#certloom-credly-setup"><?php esc_html_e( 'Manage publishing', 'certloom' ); ?> <span aria-hidden="true">→</span></a>
			</article>
		</div>
	</section>

	<?php if ( ! empty( $tutor['status']['available'] ) ) : ?>
	<section id="certloom-tutor-setup" class="certloom-workspace-card certloom-integration-config-card" aria-labelledby="certloom-tutor-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Learning source', 'certloom' ); ?></span><h2 id="certloom-tutor-title"><?php esc_html_e( 'Tutor LMS', 'certloom' ); ?></h2><p><?php esc_html_e( 'Map a Tutor course to a Program and optional Batch. The certificate design follows the shared inheritance hierarchy automatically.', 'certloom' ); ?></p></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( (array) ( $tutor['mappings'] ?? array() ) ) ) ); ?></span></header>
		<?php if ( ! empty( $tutor['error'] ) && is_wp_error( $tutor['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $tutor['error']->get_error_message() ); ?></p></div>
		<?php elseif ( ! empty( $tutor['configuration_ready'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-integration-map-form"><input type="hidden" name="action" value="certloom_tutor_lms_mapping_save"><?php wp_nonce_field( 'certloom_tutor_lms_mapping_save' ); ?><div class="certloom-form-grid certloom-form-grid-3">
			<label class="certloom-field-control"><span><?php esc_html_e( 'Tutor course', 'certloom' ); ?></span><select name="course_id" required data-certloom-context-lookup="tutor_course" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a course', 'certloom' ); ?></option><?php foreach ( (array) ( $tutor['courses'] ?? array() ) as $course ) : ?><option value="<?php echo esc_attr( absint( $course['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $course['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) ( $tutor['programs'] ?? array() ) as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Batch (optional)', 'certloom' ); ?></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search Batches in this Program…', 'certloom' ); ?>"><option value="0"><?php esc_html_e( 'No Batch — use Program context', 'certloom' ); ?></option></select><small><?php esc_html_e( 'Optional. A selected Batch applies its field rules and, when set, its more-specific design.', 'certloom' ); ?></small></label>
		</div><input type="hidden" name="certificate_threshold" value="100"><input type="hidden" name="expiry_mode" value="program"><label class="certloom-switch-row"><input type="checkbox" name="active" value="1" checked><span><strong><?php esc_html_e( 'Issue automatically after course completion', 'certloom' ); ?></strong></span></label><div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save mapping', 'certloom' ); ?></button></div></form>
		<?php endif; ?>
		<?php if ( ! empty( $tutor['mappings'] ) ) : ?><div class="certloom-mapping-list"><?php foreach ( (array) $tutor['mappings'] as $mapping ) : $id = absint( $mapping['course_id'] ?? 0 ); ?><article class="certloom-mapping-row"><div><strong><?php echo esc_html( $tutor_course_labels[ $id ] ?? sprintf( /* translators: %d: course ID. */ __( 'Course %d', 'certloom' ), $id ) ); ?></strong><small><?php echo esc_html( $certloom_mapping_target_label( $tutor_program_labels[ absint( $mapping['program_id'] ?? 0 ) ] ?? __( 'Program', 'certloom' ), absint( $mapping['batch_id'] ?? 0 ), absint( $mapping['template_id'] ?? 0 ) ) ); ?></small></div><span class="certloom-state-pill <?php echo ! empty( $mapping['active'] ) ? 'is-success' : ''; ?>"><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'On', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_tutor_lms_mapping_delete"><input type="hidden" name="course_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'certloom_tutor_lms_mapping_delete_' . $id ); ?><button class="button button-small" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Remove this Tutor LMS mapping? Future completions for this course will no longer issue through this mapping.', 'certloom' ); ?>"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
		<details class="certloom-technical-details"><summary><?php esc_html_e( 'Technical status', 'certloom' ); ?></summary><p class="description"><?php echo esc_html( ! empty( $tutor['status']['authority_ready'] ) ? __( 'Credential-writing authority is available.', 'certloom' ) : __( 'Automatic issuance remains fail-closed until production credential-writing authority is accepted.', 'certloom' ) ); ?></p></details>
	</section>
	<?php endif; ?>

	<?php if ( ! empty( $learndash['status']['available'] ) ) : ?>
	<section id="certloom-learndash-setup" class="certloom-workspace-card certloom-integration-config-card" aria-labelledby="certloom-learndash-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Learning source', 'certloom' ); ?></span><h2 id="certloom-learndash-title"><?php esc_html_e( 'LearnDash', 'certloom' ); ?></h2><p><?php esc_html_e( 'Map a LearnDash course to a Program and optional Batch. The certificate design follows the shared inheritance hierarchy automatically.', 'certloom' ); ?></p></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( (array) ( $learndash['mappings'] ?? array() ) ) ) ); ?></span></header>
		<?php if ( ! empty( $learndash['error'] ) && is_wp_error( $learndash['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $learndash['error']->get_error_message() ); ?></p></div>
		<?php elseif ( ! empty( $learndash['configuration_ready'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-integration-map-form"><input type="hidden" name="action" value="certloom_learndash_mapping_save"><?php wp_nonce_field( 'certloom_learndash_mapping_save' ); ?><div class="certloom-form-grid certloom-form-grid-3">
			<label class="certloom-field-control"><span><?php esc_html_e( 'LearnDash course', 'certloom' ); ?></span><select name="course_id" required data-certloom-context-lookup="learndash_course" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a course', 'certloom' ); ?></option><?php foreach ( (array) ( $learndash['courses'] ?? array() ) as $course ) : ?><option value="<?php echo esc_attr( absint( $course['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $course['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) ( $learndash['programs'] ?? array() ) as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Batch (optional)', 'certloom' ); ?></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search Batches in this Program…', 'certloom' ); ?>"><option value="0"><?php esc_html_e( 'No Batch — use Program context', 'certloom' ); ?></option></select><small><?php esc_html_e( 'Optional. A selected Batch applies its field rules and, when set, its more-specific design.', 'certloom' ); ?></small></label>
		</div><input type="hidden" name="expiry_mode" value="program"><label class="certloom-switch-row"><input type="checkbox" name="active" value="1" checked><span><strong><?php esc_html_e( 'Issue automatically after course completion', 'certloom' ); ?></strong></span></label><div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save mapping', 'certloom' ); ?></button></div></form>
		<?php endif; ?>
		<?php if ( ! empty( $learndash['mappings'] ) ) : ?><div class="certloom-mapping-list"><?php foreach ( (array) $learndash['mappings'] as $mapping ) : $id = absint( $mapping['course_id'] ?? 0 ); ?><article class="certloom-mapping-row"><div><strong><?php echo esc_html( $ld_course_labels[ $id ] ?? sprintf( /* translators: %d: course ID. */ __( 'Course %d', 'certloom' ), $id ) ); ?></strong><small><?php echo esc_html( $certloom_mapping_target_label( $ld_program_labels[ absint( $mapping['program_id'] ?? 0 ) ] ?? __( 'Program', 'certloom' ), absint( $mapping['batch_id'] ?? 0 ), absint( $mapping['template_id'] ?? 0 ) ) ); ?></small></div><span class="certloom-state-pill <?php echo ! empty( $mapping['active'] ) ? 'is-success' : ''; ?>"><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'On', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_learndash_mapping_delete"><input type="hidden" name="course_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'certloom_learndash_mapping_delete_' . $id ); ?><button class="button button-small" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Remove this LearnDash mapping? Future completions for this course will no longer issue through this mapping.', 'certloom' ); ?>"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
		<details class="certloom-technical-details"><summary><?php esc_html_e( 'Technical status', 'certloom' ); ?></summary><p class="description"><?php echo esc_html( ! empty( $learndash['status']['authority_ready'] ) ? __( 'Credential-writing authority is available.', 'certloom' ) : __( 'Automatic issuance remains fail-closed until production credential-writing authority is accepted.', 'certloom' ) ); ?></p></details>
	</section>
	<?php endif; ?>

	<?php if ( ! empty( $woocommerce['status']['available'] ) ) : ?>
	<section id="certloom-woocommerce-setup" class="certloom-workspace-card certloom-integration-config-card" aria-labelledby="certloom-woocommerce-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Commerce source', 'certloom' ); ?></span><h2 id="certloom-woocommerce-title"><?php esc_html_e( 'WooCommerce', 'certloom' ); ?></h2><p><?php esc_html_e( 'Map a product to a Program and optional Batch. When an authenticated customer completes the order, Certloom can issue the mapped credential to their linked Recipient profile using the shared design hierarchy.', 'certloom' ); ?></p></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( (array) ( $woocommerce['mappings'] ?? array() ) ) ) ); ?></span></header>
		<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Ownership stays explicit', 'certloom' ); ?></strong><p><?php esc_html_e( 'Guest checkout does not create credential ownership. The customer must have a WordPress account explicitly linked to a Certloom Recipient.', 'certloom' ); ?></p></div></div>
		<?php if ( ! empty( $woocommerce['error'] ) && is_wp_error( $woocommerce['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $woocommerce['error']->get_error_message() ); ?></p></div>
		<?php elseif ( ! empty( $woocommerce['configuration_ready'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-integration-map-form"><input type="hidden" name="action" value="certloom_woocommerce_mapping_save"><?php wp_nonce_field( 'certloom_woocommerce_mapping_save' ); ?><div class="certloom-form-grid certloom-form-grid-3">
			<label class="certloom-field-control"><span><?php esc_html_e( 'WooCommerce product', 'certloom' ); ?></span><select name="product_id" required><option value=""><?php esc_html_e( 'Choose a product', 'certloom' ); ?></option><?php foreach ( (array) ( $woocommerce['products'] ?? array() ) as $product ) : ?><option value="<?php echo esc_attr( absint( $product['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $product['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) ( $woocommerce['programs'] ?? array() ) as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Batch (optional)', 'certloom' ); ?></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search Batches in this Program…', 'certloom' ); ?>"><option value="0"><?php esc_html_e( 'No Batch — use Program context', 'certloom' ); ?></option></select><small><?php esc_html_e( 'Optional. A selected Batch applies its field rules and, when set, its more-specific design.', 'certloom' ); ?></small></label>
		</div><input type="hidden" name="expiry_mode" value="program"><label class="certloom-switch-row"><input type="checkbox" name="active" value="1" checked><span><strong><?php esc_html_e( 'Issue automatically after completed order', 'certloom' ); ?></strong></span></label><div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save product mapping', 'certloom' ); ?></button></div></form>
		<?php endif; ?>
		<?php if ( ! empty( $woocommerce['mappings'] ) ) : ?><div class="certloom-mapping-list"><?php foreach ( (array) $woocommerce['mappings'] as $mapping ) : $id = absint( $mapping['product_id'] ?? 0 ); ?><article class="certloom-mapping-row"><div><strong><?php echo esc_html( $woo_product_labels[ $id ] ?? sprintf( /* translators: %d: product ID. */ __( 'Product %d', 'certloom' ), $id ) ); ?></strong><small><?php echo esc_html( $certloom_mapping_target_label( $woo_program_labels[ absint( $mapping['program_id'] ?? 0 ) ] ?? __( 'Program', 'certloom' ), absint( $mapping['batch_id'] ?? 0 ), absint( $mapping['template_id'] ?? 0 ) ) ); ?></small></div><span class="certloom-state-pill <?php echo ! empty( $mapping['active'] ) ? 'is-success' : ''; ?>"><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'On', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_woocommerce_mapping_delete"><input type="hidden" name="product_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'certloom_woocommerce_mapping_delete_' . $id ); ?><button class="button button-small" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Remove this WooCommerce mapping? Future qualifying orders for this product will no longer issue through this mapping.', 'certloom' ); ?>"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
		<details class="certloom-technical-details"><summary><?php esc_html_e( 'Technical status', 'certloom' ); ?></summary><p class="description"><?php echo esc_html( ! empty( $woocommerce['status']['authority_ready'] ) ? __( 'Credential-writing authority is available.', 'certloom' ) : __( 'Automatic issuance remains fail-closed until production credential-writing authority is accepted.', 'certloom' ) ); ?></p></details>
	</section>
	<?php endif; ?>

	<section id="certloom-coursera-setup" class="certloom-workspace-card certloom-integration-config-card certloom-external-setup" aria-labelledby="certloom-coursera-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Learning source · Organization connection', 'certloom' ); ?></span><h2 id="certloom-coursera-title"><?php esc_html_e( 'Coursera', 'certloom' ); ?></h2><p><?php esc_html_e( 'Coursera sends course-completion xAPI events to this WordPress site. Certloom matches the learner using the same External ID stored on their Recipient profile.', 'certloom' ); ?></p></div><span class="certloom-state-pill <?php echo $coursera_configured ? 'is-success' : ''; ?>"><?php echo $coursera_configured ? esc_html__( 'Connected', 'certloom' ) : esc_html__( 'Not connected', 'certloom' ); ?></span></header>

		<div class="certloom-connector-steps">
			<div class="certloom-connector-step"><span>1</span><div><strong><?php esc_html_e( 'Generate connection details', 'certloom' ); ?></strong><small><?php esc_html_e( 'Certloom creates a site-specific Client ID and one-time Client Secret.', 'certloom' ); ?></small></div></div>
			<div class="certloom-connector-step"><span>2</span><div><strong><?php esc_html_e( 'Add them in Coursera xAPI settings', 'certloom' ); ?></strong><small><?php esc_html_e( 'Use the OAuth token URL and xAPI statement URL shown below.', 'certloom' ); ?></small></div></div>
			<div class="certloom-connector-step"><span>3</span><div><strong><?php esc_html_e( 'Map each Coursera course', 'certloom' ); ?></strong><small><?php esc_html_e( 'Course ID → Certloom Program → optional Batch; design inherits automatically.', 'certloom' ); ?></small></div></div>
		</div>

		<?php if ( ! empty( $coursera['one_time_secret'] ) ) : ?>
		<div class="certloom-one-time-secret" role="status"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Copy now', 'certloom' ); ?></span><h3><?php esc_html_e( 'Your Client Secret is shown once', 'certloom' ); ?></h3><p><?php esc_html_e( 'Store it in Coursera now. Certloom stores only a one-way hash and cannot show this same secret again.', 'certloom' ); ?></p></div><div class="certloom-connection-value-grid"><label><span><?php esc_html_e( 'Client ID', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( (string) ( $coursera['one_time_secret']['client_id'] ?? '' ) ); ?>" onclick="this.select();"></label><label><span><?php esc_html_e( 'Client Secret', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( (string) ( $coursera['one_time_secret']['client_secret'] ?? '' ) ); ?>" onclick="this.select();"></label></div></div>
		<?php endif; ?>

		<div class="certloom-connection-panel">
			<div class="certloom-connection-value-grid">
				<label><span><?php esc_html_e( 'OAuth token URL', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( (string) ( $coursera_credentials['token_url'] ?? '' ) ); ?>" onclick="this.select();"></label>
				<label><span><?php esc_html_e( 'xAPI statement URL', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( (string) ( $coursera_credentials['statement_url'] ?? '' ) ); ?>" onclick="this.select();"></label>
				<?php if ( $coursera_configured ) : ?><label><span><?php esc_html_e( 'Client ID', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( (string) ( $coursera_credentials['client_id'] ?? '' ) ); ?>" onclick="this.select();"></label><?php endif; ?>
			</div>
			<div class="certloom-form-actions certloom-connection-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_coursera_rotate"><?php wp_nonce_field( 'certloom_coursera_rotate' ); ?><button type="submit" class="button <?php echo $coursera_configured ? '' : 'button-primary'; ?>"><?php echo $coursera_configured ? esc_html__( 'Rotate connection credentials', 'certloom' ) : esc_html__( 'Generate connection credentials', 'certloom' ); ?></button></form>
				<?php if ( $coursera_configured ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ecapla-confirm="<?php esc_attr_e( 'Disconnect Coursera? Existing course mappings will stay saved but no new completion events can authenticate.', 'certloom' ); ?>"><input type="hidden" name="action" value="certloom_coursera_disconnect"><?php wp_nonce_field( 'certloom_coursera_disconnect' ); ?><button class="button" type="submit"><?php esc_html_e( 'Disconnect', 'certloom' ); ?></button></form><?php endif; ?>
			</div>
		</div>

		<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-id" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Learner identity must be explicit', 'certloom' ); ?></strong><p><?php esc_html_e( 'Set each Coursera learner External ID to the same External ID on their Certloom Recipient profile. Certloom ignores learner email for ownership matching.', 'certloom' ); ?></p></div></div>

		<?php if ( ! empty( $coursera['error'] ) && is_wp_error( $coursera['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $coursera['error']->get_error_message() ); ?></p></div>
		<?php elseif ( ! empty( $coursera['configuration_ready'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-integration-map-form"><input type="hidden" name="action" value="certloom_coursera_mapping_save"><?php wp_nonce_field( 'certloom_coursera_mapping_save' ); ?><div class="certloom-form-grid certloom-form-grid-3">
			<label class="certloom-field-control"><span><?php esc_html_e( 'Coursera course ID', 'certloom' ); ?></span><input type="text" name="course_id" maxlength="191" required placeholder="<?php esc_attr_e( 'e.g. course-slug-or-id', 'certloom' ); ?>"></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) ( $coursera['programs'] ?? array() ) as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
			<label class="certloom-field-control"><span><?php esc_html_e( 'Batch (optional)', 'certloom' ); ?></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search Batches in this Program…', 'certloom' ); ?>"><option value="0"><?php esc_html_e( 'No Batch — use Program context', 'certloom' ); ?></option></select><small><?php esc_html_e( 'Optional. A selected Batch applies its field rules and, when set, its more-specific design.', 'certloom' ); ?></small></label>
		</div><label class="certloom-switch-row"><input type="checkbox" name="active" value="1" checked><span><strong><?php esc_html_e( 'Issue automatically after matching course completion', 'certloom' ); ?></strong></span></label><div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save Coursera mapping', 'certloom' ); ?></button></div></form>
		<?php endif; ?>

		<?php if ( $coursera_mappings ) : ?><div class="certloom-mapping-list"><?php foreach ( $coursera_mappings as $mapping ) : $course_id = (string) ( $mapping['course_id'] ?? '' ); ?><article class="certloom-mapping-row"><div><strong><?php echo esc_html( $course_id ); ?></strong><small><?php echo esc_html( $certloom_mapping_target_label( $coursera_program_labels[ absint( $mapping['program_id'] ?? 0 ) ] ?? __( 'Program', 'certloom' ), absint( $mapping['batch_id'] ?? 0 ), absint( $mapping['template_id'] ?? 0 ) ) ); ?></small></div><span class="certloom-state-pill <?php echo ! empty( $mapping['active'] ) ? 'is-success' : ''; ?>"><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'On', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_coursera_mapping_delete"><input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>"><?php wp_nonce_field( 'certloom_coursera_mapping_delete_' . $course_id ); ?><button class="button button-small" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Remove this Coursera mapping? Future completion statements for this course will no longer issue through this mapping.', 'certloom' ); ?>"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
		<details class="certloom-technical-details"><summary><?php esc_html_e( 'Technical status', 'certloom' ); ?></summary><p class="description"><?php echo esc_html( ! empty( $coursera['status']['authority_ready'] ) ? __( 'Credential-writing authority is available.', 'certloom' ) : __( 'Completion events may be validated during QA, but production automatic issuance remains fail-closed until credential-writing authority is accepted.', 'certloom' ) ); ?></p></details>
	</section>

	<section id="certloom-webhook-setup" class="certloom-workspace-card certloom-integration-config-card certloom-external-setup" aria-labelledby="certloom-webhook-title">
		<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Universal source', 'certloom' ); ?></span><h2 id="certloom-webhook-title"><?php esc_html_e( 'Webhook / API', 'certloom' ); ?></h2><p><?php esc_html_e( 'Use this when another system can tell Certloom that a person qualified, even if Certloom does not have a dedicated adapter for that platform.', 'certloom' ); ?></p></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( $webhook_sources ) ) ); ?></span></header>
		<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Small, signed payload', 'certloom' ); ?></strong><p><?php esc_html_e( 'Certloom accepts only event_id, recipient_external_id and completed_at. Requests must be HMAC-signed and timestamped; names, emails and arbitrary provider payloads are rejected.', 'certloom' ); ?></p></div></div>
		<?php if ( ! empty( $webhooks['one_time_secret'] ) ) : $shown_key = (string) ( $webhooks['one_time_secret']['key'] ?? '' ); ?>
		<div class="certloom-one-time-secret" role="status"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Copy now', 'certloom' ); ?></span><h3><?php esc_html_e( 'Your webhook signing secret is shown once', 'certloom' ); ?></h3><p><?php esc_html_e( 'Store this secret in the sending system. Certloom stores it encrypted and does not show it again.', 'certloom' ); ?></p></div><div class="certloom-connection-value-grid"><label><span><?php esc_html_e( 'Endpoint', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( rest_url( 'certloom/v1/integrations/webhook/' . $shown_key ) ); ?>" onclick="this.select();"></label><label><span><?php esc_html_e( 'Signing secret', 'certloom' ); ?></span><input type="text" readonly value="<?php echo esc_attr( (string) ( $webhooks['one_time_secret']['secret'] ?? '' ) ); ?>" onclick="this.select();"></label></div></div>
		<?php endif; ?>
		<?php if ( ! empty( $webhooks['error'] ) && is_wp_error( $webhooks['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $webhooks['error']->get_error_message() ); ?></p></div>
		<?php elseif ( ! empty( $webhooks['configuration_ready'] ) ) : ?>
		<details class="certloom-create-drawer" <?php echo $webhook_sources ? '' : 'open'; ?>><summary><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Create webhook source', 'certloom' ); ?></summary><div class="certloom-create-drawer-body"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form"><input type="hidden" name="action" value="certloom_webhook_source_create"><?php wp_nonce_field( 'certloom_webhook_source_create' ); ?><div class="certloom-form-grid certloom-form-grid-3"><label class="certloom-field-control"><span><?php esc_html_e( 'Source name', 'certloom' ); ?></span><input name="name" required maxlength="120" placeholder="<?php esc_attr_e( 'e.g. Internal training portal', 'certloom' ); ?>"></label><label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) ( $webhooks['programs'] ?? array() ) as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label><label class="certloom-field-control"><span><?php esc_html_e( 'Batch (optional)', 'certloom' ); ?></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search Batches in this Program…', 'certloom' ); ?>"><option value="0"><?php esc_html_e( 'No Batch — use Program context', 'certloom' ); ?></option></select><small><?php esc_html_e( 'Optional. Webhook issuance uses the same Global → Issuer → Program → Batch design hierarchy as every other source.', 'certloom' ); ?></small></label></div><div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Create secure endpoint', 'certloom' ); ?></button></div></form></div></details>
		<?php endif; ?>
		<?php if ( $webhook_sources ) : ?><div class="certloom-mapping-list certloom-webhook-list"><?php foreach ( $webhook_sources as $source ) : $key = (string) ( $source['key'] ?? '' ); ?><article class="certloom-mapping-row certloom-webhook-row"><div class="certloom-webhook-main"><strong><?php echo esc_html( (string) ( $source['name'] ?? $key ) ); ?></strong><small><?php echo esc_html( $certloom_mapping_target_label( $webhook_program_labels[ absint( $source['program_id'] ?? 0 ) ] ?? __( 'Program', 'certloom' ), absint( $source['batch_id'] ?? 0 ), absint( $source['template_id'] ?? 0 ) ) ); ?></small><code><?php echo esc_html( (string) ( $source['endpoint'] ?? '' ) ); ?></code></div><span class="certloom-state-pill is-success"><?php esc_html_e( 'On', 'certloom' ); ?></span><div class="certloom-row-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_webhook_secret_rotate"><input type="hidden" name="source_key" value="<?php echo esc_attr( $key ); ?>"><?php wp_nonce_field( 'certloom_webhook_secret_rotate_' . $key ); ?><button class="button button-small" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Rotate this webhook secret? The current secret will stop authenticating requests immediately, so update the sending system with the new secret before its next event.', 'certloom' ); ?>"><?php esc_html_e( 'Rotate secret', 'certloom' ); ?></button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ecapla-confirm="<?php esc_attr_e( 'Remove this webhook source? Requests to its endpoint will stop working immediately.', 'certloom' ); ?>"><input type="hidden" name="action" value="certloom_webhook_source_delete"><input type="hidden" name="source_key" value="<?php echo esc_attr( $key ); ?>"><?php wp_nonce_field( 'certloom_webhook_source_delete_' . $key ); ?><button class="button button-small" type="submit"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></div></article><?php endforeach; ?></div><?php endif; ?>
		<details class="certloom-technical-details"><summary><?php esc_html_e( 'Signing format', 'certloom' ); ?></summary><p class="description"><?php esc_html_e( 'Send X-Certloom-Timestamp as the current Unix timestamp and X-Certloom-Signature as sha256=HMAC_SHA256(timestamp + "." + exact_request_body, signing_secret). Requests outside a five-minute window fail closed.', 'certloom' ); ?></p></details>
	</section>

	<section id="certloom-credly-setup" class="certloom-workspace-card certloom-destination-config-card" aria-labelledby="certloom-credly-title">
		<header class="certloom-workspace-card-head">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'Publishing destination', 'certloom' ); ?></span><h2 id="certloom-credly-title"><?php esc_html_e( 'Credly', 'certloom' ); ?></h2><p><?php esc_html_e( 'After Certloom issues the credential, optionally publish a matching badge to your authorized Credly organization. Certloom remains the credential source of truth.', 'certloom' ); ?></p></div>
			<span class="certloom-state-pill <?php echo $credly_configured ? 'is-success' : ''; ?>"><?php echo $credly_configured ? esc_html__( 'Connected', 'certloom' ) : esc_html__( 'Not connected', 'certloom' ); ?></span>
		</header>

		<div class="certloom-connector-steps certloom-destination-steps" aria-label="<?php esc_attr_e( 'Credly publishing setup', 'certloom' ); ?>">
			<div class="certloom-connector-step"><span>1</span><div><strong><?php esc_html_e( 'Connect your issuer account', 'certloom' ); ?></strong><small><?php esc_html_e( 'Use an authorized Credly organization and API token.', 'certloom' ); ?></small></div></div>
			<div class="certloom-connector-step"><span>2</span><div><strong><?php esc_html_e( 'Map each Program', 'certloom' ); ?></strong><small><?php esc_html_e( 'Choose the Credly badge template that matches the Certloom Program.', 'certloom' ); ?></small></div></div>
			<div class="certloom-connector-step"><span>3</span><div><strong><?php esc_html_e( 'Publish after issuance', 'certloom' ); ?></strong><small><?php esc_html_e( 'Only a successfully issued Certloom credential can be published to a destination.', 'certloom' ); ?></small></div></div>
		</div>

		<?php if ( ! empty( $credly['error'] ) && is_wp_error( $credly['error'] ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $credly['error']->get_error_message() ); ?></p></div><?php endif; ?>

		<?php if ( $credly_configured ) : ?>
		<div class="certloom-destination-connection">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'Connected account', 'certloom' ); ?></span><h3><?php esc_html_e( 'Credly publishing is ready', 'certloom' ); ?></h3><p><?php echo esc_html( sprintf( /* translators: 1: environment, 2: Credly organization id. */ __( '%1$s · Organization %2$s', 'certloom' ), 'sandbox' === (string) ( $credly_connection['environment'] ?? '' ) ? __( 'Sandbox', 'certloom' ) : __( 'Production', 'certloom' ), (string) ( $credly_connection['organization_id'] ?? '' ) ) ); ?></p><small><?php esc_html_e( 'The authorization token stays encrypted and is never displayed again.', 'certloom' ); ?></small></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ecapla-confirm="<?php esc_attr_e( 'Disconnect Credly? Existing Certloom credentials and previous publishing history will remain unchanged.', 'certloom' ); ?>"><input type="hidden" name="action" value="certloom_credly_disconnect"><?php wp_nonce_field( 'certloom_credly_disconnect' ); ?><button class="button" type="submit"><?php esc_html_e( 'Disconnect', 'certloom' ); ?></button></form>
		</div>
		<?php else : ?>
		<div class="certloom-connection-panel">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-credential-connection-form" autocomplete="off">
				<input type="hidden" name="action" value="certloom_credly_connection_save"><?php wp_nonce_field( 'certloom_credly_connection_save' ); ?>
				<div class="certloom-form-grid certloom-form-grid-3">
					<label class="certloom-field-control"><span><?php esc_html_e( 'Environment', 'certloom' ); ?></span><select name="environment"><option value="sandbox"><?php esc_html_e( 'Sandbox — recommended for testing', 'certloom' ); ?></option><option value="production"><?php esc_html_e( 'Production', 'certloom' ); ?></option></select></label>
					<label class="certloom-field-control"><span><?php esc_html_e( 'Organization ID', 'certloom' ); ?></span><input type="text" name="organization_id" required maxlength="36" autocomplete="off" placeholder="00000000-0000-4000-8000-000000000000"></label>
					<label class="certloom-field-control"><span><?php esc_html_e( 'Authorization token', 'certloom' ); ?></span><input type="password" name="authorization_token" required maxlength="2048" autocomplete="new-password" spellcheck="false"></label>
				</div>
				<div class="certloom-callout certloom-callout-neutral"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Stored securely on this WordPress site', 'certloom' ); ?></strong><p><?php esc_html_e( 'Certloom encrypts the Credly token at rest and never places it in browser state, publishing history or credential snapshots.', 'certloom' ); ?></p></div></div>
				<div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Connect Credly', 'certloom' ); ?></button></div>
			</form>
		</div>
		<?php endif; ?>

		<?php if ( $credly_configured ) : ?>
		<div class="certloom-section-intro"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Publishing rules', 'certloom' ); ?></span><h3><?php esc_html_e( 'Program → Credly badge', 'certloom' ); ?></h3></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( $credly_mappings ) ) ); ?></span></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-integration-map-form">
			<input type="hidden" name="action" value="certloom_credly_mapping_save"><?php wp_nonce_field( 'certloom_credly_mapping_save' ); ?>
			<div class="certloom-form-grid certloom-form-grid-2">
				<label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-program-status="active" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) ( $credly['programs'] ?? array() ) as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></label>
				<label class="certloom-field-control"><span><?php esc_html_e( 'Credly badge template ID', 'certloom' ); ?></span><input type="text" name="badge_template_id" required maxlength="191" spellcheck="false" placeholder="<?php esc_attr_e( 'Paste the badge template ID from Credly', 'certloom' ); ?>"><small><?php esc_html_e( 'This is Credly’s external badge-template identifier. It is separate from Certloom certificate designs and does not participate in Global → Issuer → Program → Batch inheritance.', 'certloom' ); ?></small></label>
			</div>
			<label class="certloom-switch-row"><input type="checkbox" name="active" value="1" checked><span><strong><?php esc_html_e( 'Publish automatically after issuance', 'certloom' ); ?></strong><small><?php esc_html_e( 'Only newly issued credentials trigger this rule.', 'certloom' ); ?></small></span></label>
			<label class="certloom-switch-row"><input type="checkbox" name="suppress_badge_notification_email" value="1"><span><strong><?php esc_html_e( 'Use Certloom email only', 'certloom' ); ?></strong><small><?php esc_html_e( 'Ask Credly not to send its additional badge notification when Certloom already handles delivery.', 'certloom' ); ?></small></span></label>
			<div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save publishing rule', 'certloom' ); ?></button></div>
		</form>

		<?php if ( $credly_mappings ) : ?><div class="certloom-mapping-list"><?php foreach ( $credly_mappings as $mapping ) : $program_id = absint( $mapping['program_id'] ?? 0 ); ?><article class="certloom-mapping-row"><div><strong><?php echo esc_html( $credly_program_labels[ $program_id ] ?? sprintf( /* translators: %d: Program ID. */ __( 'Program %d', 'certloom' ), $program_id ) ); ?></strong><small><?php echo esc_html( sprintf( /* translators: %s: Credly badge template id. */ __( 'Credly badge template %s', 'certloom' ), (string) ( $mapping['badge_template_id'] ?? '' ) ) ); ?></small></div><span class="certloom-state-pill <?php echo ! empty( $mapping['active'] ) ? 'is-success' : ''; ?>"><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'Automatic', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ecapla-confirm="<?php esc_attr_e( 'Remove this Credly publishing rule? Future credentials for this Program will no longer publish through this rule.', 'certloom' ); ?>"><input type="hidden" name="action" value="certloom_credly_mapping_delete"><input type="hidden" name="program_id" value="<?php echo esc_attr( $program_id ); ?>"><?php wp_nonce_field( 'certloom_credly_mapping_delete_' . $program_id ); ?><button class="button button-small" type="submit"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
		<?php endif; ?>

		<div class="certloom-destination-history">
			<div class="certloom-section-intro"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Recent publishing', 'certloom' ); ?></span><h3><?php esc_html_e( 'Credly delivery history', 'certloom' ); ?></h3></div><span class="certloom-count-chip"><?php echo esc_html( number_format_i18n( count( $credly_recent ) ) ); ?></span></div>
			<?php if ( $credly_recent ) : ?><div class="certloom-clean-list"><?php foreach ( $credly_recent as $delivery ) : $status = sanitize_key( (string) ( $delivery['status'] ?? '' ) ); $status_labels = array( 'preparing' => __( 'Preparing', 'certloom' ), 'queued' => __( 'Waiting', 'certloom' ), 'sending' => __( 'Sending', 'certloom' ), 'succeeded' => __( 'Published', 'certloom' ), 'failed' => __( 'Failed', 'certloom' ), 'uncertain' => __( 'Needs review', 'certloom' ) ); $credential_label = ! empty( $delivery['credential_number'] ) ? sprintf( /* translators: %s: human credential number. */ __( 'Credential %s', 'certloom' ), (string) $delivery['credential_number'] ) : __( 'Credential', 'certloom' ); $delivery_detail = ! empty( $delivery['external_reference'] ) ? sprintf( /* translators: %s: Credly reference. */ __( 'Credly reference %s', 'certloom' ), (string) $delivery['external_reference'] ) : ( ! empty( $delivery['failure_code'] ) ? ( $simple ? __( 'Open Credly to review this publication before trying again.', 'certloom' ) : sprintf( /* translators: %s: failure code. */ __( 'Review reason: %s', 'certloom' ), (string) $delivery['failure_code'] ) ) : __( 'No Credly reference recorded yet.', 'certloom' ) ); ?><article class="certloom-clean-list-row"><div class="certloom-clean-list-main"><div class="certloom-clean-list-title"><strong><?php echo esc_html( $credential_label ); ?></strong></div><p><?php echo esc_html( $delivery_detail ); ?></p></div><span class="certloom-state-pill <?php echo 'succeeded' === $status ? 'is-success' : ''; ?>"><?php echo esc_html( $status_labels[ $status ] ?? ucfirst( $status ) ); ?></span><span class="certloom-muted-link"><?php echo esc_html( (string) ( $delivery['updated_at'] ?? '' ) ); ?></span></article><?php endforeach; ?></div><?php else : ?><div class="certloom-premium-empty-state certloom-empty-compact"><span class="dashicons dashicons-awards" aria-hidden="true"></span><h3><?php esc_html_e( 'Nothing published yet', 'certloom' ); ?></h3><p><?php esc_html_e( 'Publishing history appears here after an issued credential matches an active Credly rule.', 'certloom' ); ?></p></div><?php endif; ?>
		</div>

		<details class="certloom-technical-details"><summary><?php esc_html_e( 'Technical boundary', 'certloom' ); ?></summary><p class="description"><?php esc_html_e( 'Credly is a post-issuance side effect only. Certloom stores privacy-minimized destination evidence separately from immutable credential truth, deduplicates each Credential Version / destination, and never automatically retries an uncertain external result.', 'certloom' ); ?></p></details>
	</section>
</div>
