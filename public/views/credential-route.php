<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
Ecapla_CertVerify_Shortcode::enqueue_assets();
$route  = Ecapla_CertVerify_Public_Router::current_route();
$number = Ecapla_CertVerify_Public_Router::requested_number();
get_header();
?>
<main id="primary" class="certloom-route-main certloom-route-<?php echo esc_attr( $route ); ?>">
	<div class="certloom-route-shell">
		<?php
		if ( 'credential' === $route ) {
			echo Ecapla_CertVerify_Render::credential_profile( $number ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes output.
		} else {
			echo Ecapla_CertVerify_Render::legacy_widget( array( 'show_guide' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes output.
		}
		?>
	</div>
</main>
<?php
get_footer();
