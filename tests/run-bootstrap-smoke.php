<?php
/** Minimal WordPress bootstrap smoke test for file wiring only. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/certloom/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
$GLOBALS['bootstrap_actions'] = array();
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['bootstrap_actions'][] = array( $hook, $callback, $priority, $accepted_args ); }
function add_filter() {}

require dirname( __DIR__ ) . '/certloom.php';

if ( ! class_exists( 'Certloom\\Plugin' ) ) {
	fwrite( STDERR, "Certloom namespaced bootstrap did not autoload.\n" );
	exit( 1 );
}
if ( ! class_exists( 'Certloom\\Domain\\Credential\\StatusService' ) ) {
	fwrite( STDERR, "Certloom domain autoloader did not resolve StatusService.\n" );
	exit( 1 );
}
if ( ! class_exists( 'Certloom\\Application\\CredentialVerificationService' ) ) {
	fwrite( STDERR, "Certloom application autoloader did not resolve CredentialVerificationService.\n" );
	exit( 1 );
}
$main_init = array_values( array_filter( $GLOBALS['bootstrap_actions'], function ( $hook ) { return 'ecapla_certverify_init' === $hook[1]; } ) );
if ( 1 !== count( $main_init ) || 'init' !== $main_init[0][0] || 1 !== $main_init[0][2] ) {
	fwrite( STDERR, "Certloom runtime bootstrap must run on init priority 1 so translation-capable provisioning is not executed during plugins_loaded.\n" );
	exit( 1 );
}

/* PHP 8.4 deprecates typed parameters that default to null without an explicit nullable type. */
$production_root = dirname( __DIR__ );
$implicit_nullable = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $production_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
	$path = str_replace( '\\', '/', $file->getPathname() );
	if ( false !== strpos( $path, '/tests/' ) || false !== strpos( $path, '/docs/' ) ) { continue; }
	$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) { continue; }
		while ( $i < $count && '(' !== $tokens[ $i ] ) { $i++; }
		if ( $i >= $count ) { break; }
		$depth = 0;
		$signature = '';
		for ( ; $i < $count; $i++ ) {
			$text = is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			$signature .= $text;
			if ( '(' === $text ) { $depth++; }
			if ( ')' === $text && 0 === --$depth ) { break; }
		}
		if ( preg_match( '/(?<![?\\\\\w])(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*|array|callable|string|int|float|bool|iterable|object)\s+\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*null\b/', $signature ) ) {
			$implicit_nullable[] = $path;
		}
	}
}
if ( $implicit_nullable ) {
	fwrite( STDERR, "Certloom production code must not use PHP 8.4-deprecated implicit-nullable typed parameters: " . implode( ', ', array_unique( $implicit_nullable ) ) . "\n" );
	exit( 1 );
}

/* PHP 8.5 deprecates closing modern Fileinfo/GD objects through legacy resource APIs. */
$php85_resource_violations = array();
foreach ( array(
	$production_root . '/includes/class-ecapla-certverify-qr-logo.php',
	$production_root . '/src/Application/Rendering/AssetInspector.php',
) as $path ) {
	$source = (string) file_get_contents( $path );
	if ( false !== strpos( $source, 'finfo_close( $finfo )' ) && false === strpos( $source, 'if ( is_resource( $finfo ) ) { finfo_close( $finfo ); }' ) ) {
		$php85_resource_violations[] = $path . ': finfo_close';
	}
}
$gd_driver_source = (string) file_get_contents( $production_root . '/src/Infrastructure/Rendering/NativeGdDriver.php' );
if ( false !== strpos( $gd_driver_source, 'imagedestroy( $image )' ) && false === strpos( $gd_driver_source, 'if ( is_resource( $image ) ) { imagedestroy( $image ); }' ) ) {
	$php85_resource_violations[] = $production_root . '/src/Infrastructure/Rendering/NativeGdDriver.php: imagedestroy';
}
if ( $php85_resource_violations ) {
	fwrite( STDERR, "Certloom production code must keep PHP 8.5-deprecated legacy close/destroy APIs restricted to legacy resources: " . implode( ', ', $php85_resource_violations ) . "\n" );
	exit( 1 );
}

$pot = file_get_contents( dirname( __DIR__ ) . '/languages/certloom.pot' );
if ( false === $pot || false === strpos( $pot, 'msgid "This is the native WordPress block. Elementor is not required."' ) || false === strpos( $pot, 'msgid "Certloom could not persist its required administrator access. The migration will be retried automatically."' ) ) {
	fwrite( STDERR, "Certloom POT must cover both native-block JavaScript and PHP runtime strings.\n" );
	exit( 1 );
}
foreach ( array(
	"msgctxt \"search action button\"\nmsgid \"Search\"",
	"msgctxt \"search field label\"\nmsgid \"Search\"",
	"msgctxt \"entity status\"\nmsgid \"Active\"",
	"msgctxt \"entity status\"\nmsgid \"Inactive\"",
	"msgctxt \"maintenance status\"\nmsgid \"Inactive\"",
) as $required_context_entry ) {
	if ( false === strpos( $pot, $required_context_entry ) ) {
		fwrite( STDERR, "Certloom POT must preserve planned translator contexts across PHP and native-block JavaScript.\n" );
		exit( 1 );
	}
}

echo "PASS  plugin bootstrap, namespace autoload, init-safe provisioning hook and complete contextual PHP/block translation source\n";
