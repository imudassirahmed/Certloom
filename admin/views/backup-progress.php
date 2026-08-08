<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$ecapla_certverify_percent      = absint( $backup_progress['percent'] ?? 0 );
$ecapla_certverify_processed    = absint( $backup_progress['processed_chunks'] ?? 0 );
$ecapla_certverify_total        = absint( $backup_progress['total_chunks'] ?? 0 );
$ecapla_certverify_restore_mode = 'merge' === ( $backup_progress['restore_mode'] ?? '' ) ? 'merge' : 'replace';
$ecapla_certverify_is_merge     = 'merge' === $ecapla_certverify_restore_mode;
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php echo esc_html( $ecapla_certverify_is_merge ? __( 'Merging Certloom backup', 'certloom' ) : __( 'Restoring backup snapshot', 'certloom' ) ); ?></h1>
	<section class="ecapla-certverify-admin-card ecapla-certloom-import-progress" aria-live="polite">
		<h2><?php echo esc_html( $ecapla_certverify_is_merge ? __( 'Preparing a safe registry merge', 'certloom' ) : __( 'Preparing an exact registry replacement', 'certloom' ) ); ?></h2>
		<p>
			<?php
			echo esc_html(
				$ecapla_certverify_is_merge
					? __( 'Certloom is validating and merging one bounded backup chunk at a time. Current-only certificates and current configuration remain in place.', 'certloom' )
					: __( 'Certloom is validating and writing one bounded backup chunk at a time. The completed snapshot will replace the current registry and configuration only after every chunk is ready.', 'certloom' )
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Certificate and configuration changes are temporarily paused during this restore. Public certificate verification remains available.', 'certloom' ); ?></p>
		<div class="ecapla-certverify-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $ecapla_certverify_percent ); ?>">
			<span style="width: <?php echo esc_attr( $ecapla_certverify_percent ); ?>%;"></span>
		</div>
		<p>
			<strong><?php echo esc_html( $ecapla_certverify_percent ); ?>%</strong> —
			<?php
			printf(
				/* translators: 1: processed backup chunks, 2: total backup chunks. */
				esc_html__( '%1$s of %2$s backup chunks prepared', 'certloom' ),
				esc_html( number_format_i18n( $ecapla_certverify_processed ) ),
				esc_html( number_format_i18n( $ecapla_certverify_total ) )
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-certverify-auto-continue="1">
			<input type="hidden" name="action" value="ecapla_certverify_backup_continue" />
			<input type="hidden" name="backup_token" value="<?php echo esc_attr( $backup_token ); ?>" />
			<?php wp_nonce_field( 'ecapla_certverify_backup_continue' ); ?>
			<noscript><p><?php submit_button( __( 'Continue restore', 'certloom' ), 'primary', 'submit', false ); ?></p></noscript>
		</form>
	</section>
</div>
