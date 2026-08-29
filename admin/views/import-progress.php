<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php esc_html_e( 'Import in progress', 'certloom' ); ?></h1>
	<section class="ecapla-certverify-admin-card ecapla-certloom-import-progress-card">
		<p><?php esc_html_e( 'Certloom is importing the CSV in safe steps so larger files can finish reliably.', 'certloom' ); ?></p>
		<div class="ecapla-certverify-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( absint( $progress['percent'] ?? 0 ) ); ?>">
			<span style="width:<?php echo esc_attr( absint( $progress['percent'] ?? 0 ) ); ?>%"></span>
		</div>
		<p><strong><?php echo esc_html( absint( $progress['percent'] ?? 0 ) ); ?>%</strong> — <?php printf( /* translators: 1: processed rows, 2: total rows. */ esc_html__( '%1$s of %2$s rows processed', 'certloom' ), esc_html( number_format_i18n( absint( $progress['processed_rows'] ?? 0 ) ) ), esc_html( number_format_i18n( absint( $progress['total_rows'] ?? 0 ) ) ) ); ?></p>
		<form method="post" action="" data-certverify-auto-continue="1">
			<?php wp_nonce_field( 'certverify_import_continue' ); ?>
			<input type="hidden" name="import_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php submit_button( __( 'Continue import', 'certloom' ), 'primary', 'certverify_import_continue', false ); ?>
		</form>
		<noscript><p class="description"><?php esc_html_e( 'JavaScript is disabled. Select Continue import until the progress reaches 100%.', 'certloom' ); ?></p></noscript>
	</section>
</div>
