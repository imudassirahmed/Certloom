<?php
/** UI Phase 2.1 review-build contract: primary workspaces stay open for review. */
namespace Certloom\Infrastructure\Migration {
	final class DomainAuthorityGate {
		public function allows( $scope ) { return false; }
	}
}
namespace {
	$root = dirname( __DIR__ );
	if ( ! defined( 'CERTLOOM_REVIEW_ADMIN_PREVIEW' ) ) { define( 'CERTLOOM_REVIEW_ADMIN_PREVIEW', true ); }
	require_once $root . '/src/Infrastructure/Migration/DomainFeatureGate.php';
	$checks = 0; $failures = array();
	function p21open( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures[] = $label; } }
	\Certloom\Infrastructure\Migration\DomainFeatureGate::reset_cache();
	p21open( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready(), 'review admin preview opens without accepted manifest' );
	p21open( \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready(), 'review build opens implemented credential write paths for end-to-end staging verification' );
	p21open( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::data_tools_ready(), 'review build keeps destructive Data Tools authority closed' );
	$plugin = file_get_contents( $root . '/src/Plugin.php' );
	$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
	$nav = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
	p21open( false !== strpos( $plugin, 'DomainFeatureGate::admin_preview_ready() && is_admin()' ) && false !== strpos( $plugin, 'new \\Certloom\\Infrastructure\\Admin\\DomainAdmin()' ), 'open review preview boots real domain admin workspaces' );
	p21open( false !== strpos( $nav, "'certloom-domain-credentials' => 'Credentials'" ) && false !== strpos( $nav, "'certloom-domain-programs'    => 'Programs'" ) && false !== strpos( $nav, "'certloom-automations'         => 'Automations'" ), 'six-section shell contains Credentials, Programs and Automations' );
	p21open( false !== strpos( $admin, "add_submenu_page( self::DASHBOARD_PAGE, __( 'Automations'" ) && false !== strpos( $admin, "array( \$this, 'render_automations_screen' )" ), 'Automations primary route targets the real Automations screen' );
	p21open( false !== strpos( $admin, "add_submenu_page( self::DASHBOARD_PAGE, __( 'Credentials'" ) || false !== strpos( $admin, "add_submenu_page( null, __( 'Credentials'" ), 'Credentials route remains registered across compatibility branches' );
	if ( $failures ) {
		echo 'FAIL  UI Phase 2.1 open-workspaces contract: ' . implode( ', ', $failures ) . "\n\n";
		echo ( $checks - count( $failures ) ) . ' passed, ' . count( $failures ) . " failures\n";
		exit( 1 );
	}
	echo "PASS  UI Phase 2.1 open-workspaces contract\n\n";
	echo $checks . " checks, 0 failures\n";
}
