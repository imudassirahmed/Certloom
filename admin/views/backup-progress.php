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
	<h1><?php echo esc_html( $ecapla_certverify_is_merge ? __( 'Restoring backup', 'certloom' ) : __( 'Restoring backup', 'certloom' ) ); ?></h1>
	<section class="ecapla-certverify-admin-card ecapla-certloom-import-progress" aria-live="polite">
		<h2><?php echo esc_html( $ecapla_certverify_is_merge ? __( 'Restoring your certificates', 'certloom' ) : __( 'Restoring your certificates', 'certloom' ) ); ?></h2>
		<p>
			<?php
			echo esc_html(
				$ecapla_certverify_is_merge
					? __( 'Certloom is safely combining the backup with your current certificates. Your current settings remain in place.', 'certloom' )
					: __( 'Certloom is safely preparing the backup before replacing your current certificates and settings.', 'certloom' )
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
				/* translators: 1: completed restore steps, 2: total restore steps. */
				esc_html__( '%1$s of %2$s restore steps complete', 'certloom' ),
				esc_html( number_format_i18n( $ecapla_certverify_processed ) ),
				esc_html( number_format_i18n( $ecapla_certverify_total ) )
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-certverify-auto-continue="1">
			<input type="hidden" name="action" value="ecapla_certverify_backup_continue" />
			<input type="hidden" name="backup_token" value="<?php echo esc_attr( $backup_token ); ?>" />
			<?php wp_nonce_field( 'ecapla_certverify_backup_continue' ); ?>
			<p class="certloom-restore-continuation"><button type="submit" class="button button-secondary" data-certverify-continue-button><?php esc_html_e( 'Continue restore', 'certloom' ); ?></button> <span class="description" data-certverify-auto-continue-status aria-live="polite"><?php esc_html_e( 'Certloom will continue automatically. If this page does not move forward, use Continue restore.', 'certloom' ); ?></span></p>
		</form>
	</section>
</div>
