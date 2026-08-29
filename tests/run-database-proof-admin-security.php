<?php
/** Static regression for the database-proof admin/security boundary. */
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$view  = file_get_contents( $root . '/admin/views/settings-manager.php' );
$proof = file_get_contents( $root . '/includes/class-ecapla-certverify-database-proof.php' );
$status = file_get_contents( $root . '/includes/class-ecapla-certverify-system-status.php' );
if ( false === $admin || false === $view || false === $proof || false === $status ) { throw new RuntimeException( 'Could not read database-proof source files.' ); }

function dbpa_assert( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function dbpa_method_body( $source, $method, $next_method ) {
	$start = strpos( $source, 'public function ' . $method . '(' );
	$end   = strpos( $source, 'public function ' . $next_method . '(', $start + 1 );
	if ( false === $start || false === $end ) throw new RuntimeException( 'Could not isolate ' . $method . '.' );
	return substr( $source, $start, $end - $start );
}

$handler = dbpa_method_body( $admin, 'handle_database_proof', 'handle_create_manager_role' );
dbpa_assert( false !== strpos( $admin, "add_action( 'admin_init', array( \$this, 'handle_database_proof' ) )" ), 'Database proof must be registered only as an explicit admin action.' );
dbpa_assert( false !== strpos( $handler, '$this->require_options_permission();' ), 'Database proof must remain administrator-only.' );
dbpa_assert( false !== strpos( $handler, "check_admin_referer( 'certverify_run_database_proof' )" ), 'Database proof lost its CSRF nonce gate.' );
dbpa_assert( false !== strpos( $handler, 'Ecapla_CertVerify_Database_Proof::run()' ), 'Admin handler must delegate to the isolated proof service.' );
dbpa_assert( false !== strpos( $view, "wp_nonce_field( 'certverify_run_database_proof' )" ), 'Database-proof form must print its nonce.' );
dbpa_assert( false !== strpos( $view, "'certverify_run_database_proof'" ), 'Database-proof form button is missing.' );
dbpa_assert( false === strpos( $proof, 'Ecapla_CertVerify_Database::' ), 'Database proof must never read/write the live certificate repository.' );
dbpa_assert( false === strpos( $proof, 'SchemaManager::activate' ) && false === strpos( $proof, 'certloom_domain_write_authority' ), 'Database proof must never provision/cut over the gated domain.' );
dbpa_assert( false === strpos( $status, 'Ecapla_CertVerify_Database_Proof::run(' ), 'Read-only System Status must never execute the mutating proof.' );
dbpa_assert( false === strpos( $view, 'Ecapla_CertVerify_Database_Proof::run(' ), 'Rendering Settings must never execute the proof as a side effect.' );

echo "PASS  Database proof is administrator-triggered, nonce-protected and isolated from credential truth\n\n10 checks, 0 failures\n";
