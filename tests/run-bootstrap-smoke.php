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

$pot = file_get_contents( dirname( __DIR__ ) . '/languages/certloom.pot' );
if ( false === $pot || false === strpos( $pot, 'msgid "This is the native WordPress block. Elementor is not required."' ) || false === strpos( $pot, 'msgid "Certloom could not persist its required administrator access. The migration will be retried automatically."' ) ) {
	fwrite( STDERR, "Certloom POT must cover both native-block JavaScript and PHP runtime strings.\n" );
	exit( 1 );
}

echo "PASS  plugin bootstrap, namespace autoload, init-safe provisioning hook and complete PHP/block translation source\n";
