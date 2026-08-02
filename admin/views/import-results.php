<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
$results = is_array( $results ) ? $results : array();
$results = wp_parse_args(
	$results,
	array(
		'created'  => 0,
		'updated'  => 0,
		'restored' => 0,
		'skipped'  => 0,
		'removed'  => 0,
		'retained' => 0,
		'errors'   => array(),
	)
);
$ecapla_certverify_backup_restored = ! empty( $_GET['backup_restored'] );
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1><?php echo esc_html( $ecapla_certverify_backup_restored ? __( 'Backup restore complete', 'certverify' ) : __( 'Import complete', 'certverify' ) ); ?></h1>
	<div class="ecapla-certverify-stat-grid">
		<div class="ecapla-certverify-stat"><strong><?php echo absint( $results['created'] ); ?></strong><span><?php esc_html_e( 'Created', 'certverify' ); ?></span></div>
		<div class="ecapla-certverify-stat"><strong><?php echo absint( $results['updated'] ); ?></strong><span><?php esc_html_e( 'Updated', 'certverify' ); ?></span></div>
		<div class="ecapla-certverify-stat"><strong><?php echo absint( $results['restored'] ); ?></strong><span><?php esc_html_e( 'Historical records restored', 'certverify' ); ?></span></div>
		<div class="ecapla-certverify-stat"><strong><?php echo absint( $results['skipped'] ); ?></strong><span><?php esc_html_e( 'Skipped', 'certverify' ); ?></span></div>
		<?php if ( $ecapla_certverify_backup_restored ) : ?>
			<div class="ecapla-certverify-stat"><strong><?php echo absint( $results['removed'] ); ?></strong><span><?php esc_html_e( 'Current-only records removed', 'certverify' ); ?></span></div>
			<div class="ecapla-certverify-stat"><strong><?php echo absint( $results['retained'] ); ?></strong><span><?php esc_html_e( 'Current-only records retained', 'certverify' ); ?></span></div>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $results['errors'] ) ) : ?>
		<h2><?php esc_html_e( 'Rows requiring attention', 'certverify' ); ?></h2>
		<div class="ecapla-certverify-error-list"><ul><?php foreach ( array_slice( $results['errors'], 0, 100 ) as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
		<?php if ( count( $results['errors'] ) > 100 ) : ?><p class="description"><?php esc_html_e( 'Only the first 100 row messages are shown.', 'certverify' ); ?></p><?php endif; ?>
	<?php endif; ?>
	<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=certverify' ) ); ?>"><?php esc_html_e( 'View certificates', 'certverify' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certverify-import' ) ); ?>"><?php esc_html_e( 'Run another import', 'certverify' ); ?></a></p>
</div>
