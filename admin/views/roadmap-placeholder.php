<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-roadmap-placeholder">
	<section class="certloom-page-hero" aria-labelledby="certloom-roadmap-workspace-title">
		<div>
			<span class="certloom-eyebrow"><?php echo esc_html( $planned_feature['eyebrow'] ); ?></span>
			<h1 id="certloom-roadmap-workspace-title"><?php echo esc_html( $planned_feature['title'] ); ?></h1>
			<p><?php echo esc_html( $planned_feature['description'] ); ?></p>
		</div>
	</section>
	<section class="certloom-panel certloom-roadmap-state" aria-labelledby="certloom-roadmap-state-title">
		<div class="certloom-panel-header">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'Roadmap state', 'certloom' ); ?></span><h2 id="certloom-roadmap-state-title"><?php echo esc_html( $planned_feature['status'] ); ?></h2></div>
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
		</div>
		<p><?php esc_html_e( 'This screen is deliberately informational. Certloom does not create fake records, hidden compatibility data, or partially supported workflows merely to make a future module look enabled.', 'certloom' ); ?></p>
		<div class="certloom-page-actions">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Open Credentials', 'certloom' ); ?></a>
			<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'certloom' ); ?></a>
		</div>
	</section>
</div>
