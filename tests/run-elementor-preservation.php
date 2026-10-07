<?php
/** Static compatibility guard for the Elementor verification widgets shipped before the new-domain cutover. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/certloom.php' );
$base   = file_get_contents( $root . '/elementor/class-ecapla-certverify-elementor-widget.php' );
$themes = file_get_contents( $root . '/elementor/class-ecapla-certverify-template-widgets.php' );
if ( false === $plugin || false === $base || false === $themes ) {
	throw new RuntimeException( 'Could not read the Elementor compatibility sources.' );
}

$classes = array(
	'Ecapla_CertVerify_Elementor_Widget',
	'Ecapla_CertVerify_Elementor_Meridian_Widget',
	'Ecapla_CertVerify_Elementor_Halo_Widget',
	'Ecapla_CertVerify_Elementor_Axis_Widget',
	'Ecapla_CertVerify_Elementor_Nova_Widget',
	'Ecapla_CertVerify_Elementor_Forge_Widget',
);
foreach ( $classes as $class ) {
	if ( false === strpos( $plugin, '$widgets_manager->register( new ' . $class . '() );' ) ) {
		throw new RuntimeException( 'Missing Elementor registration for ' . $class );
	}
	if ( 'Ecapla_CertVerify_Elementor_Widget' === $class ) {
		if ( false === strpos( $base, 'class ' . $class . ' extends \\Elementor\\Widget_Base' ) ) {
			throw new RuntimeException( 'Missing base Elementor verification widget class.' );
		}
	} elseif ( false === strpos( $themes, 'class ' . $class . ' extends Ecapla_CertVerify_Elementor_Template_Widget' ) ) {
		throw new RuntimeException( 'Missing Elementor template widget class ' . $class );
	}
}
if ( false === strpos( $plugin, "add_action( 'elementor/widgets/register', 'ecapla_certverify_register_elementor_widgets' );" ) ) {
	throw new RuntimeException( 'Elementor widget registration hook was removed.' );
}
if ( false === strpos( $base, 'Ecapla_CertVerify_Render::legacy_widget' ) || false === strpos( $themes, 'Ecapla_CertVerify_Render::template_widget' ) ) {
	throw new RuntimeException( 'Elementor widgets no longer delegate to the shared Certloom renderer.' );
}

echo "PASS  existing Certloom Elementor verification widgets remain registered and delegate to the shared renderer\n";
echo "\n1 test, 0 failures\n";
