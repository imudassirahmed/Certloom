<?php
/** Minimal WordPress bootstrap smoke test for file wiring only. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/certloom/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_action() {}
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

echo "PASS  plugin bootstrap and Certloom namespace autoload\n";
