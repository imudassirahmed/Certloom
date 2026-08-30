<?php
/**
 * Shared Certloom namespaced autoloader bootstrap for standalone tests.
 *
 * Individual test files still provide only the WordPress function/class stubs
 * required by the code path they exercise. This bootstrap removes brittle
 * source-file require ordering so adding an interface/dependency cannot break
 * an unrelated test merely because a manual require list became stale.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
}
if ( ! defined( 'CERTLOOM_PATH' ) ) {
	define( 'CERTLOOM_PATH', rtrim( dirname( __DIR__ ), '/\\' ) . DIRECTORY_SEPARATOR );
}

require_once CERTLOOM_PATH . 'src/Autoloader.php';
\Certloom\Autoloader::register();
