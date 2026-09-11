<?php
/** Certloom 1.7 UI Phase 1 design-system contract. */
$root = dirname( __DIR__ );

function phase1_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$files = array(
	'tokens' => $root . '/assets/css/certloom-tokens.css',
	'foundation' => $root . '/admin/assets/certloom-foundation.css',
	'menu' => $root . '/admin/assets/menu-icon.css',
	'menu_asset' => $root . '/admin/assets/certloom-menu-icon.svg',
	'artifact' => $root . '/public/assets/certloom-artifact-foundation.css',
	'admin' => $root . '/admin/class-ecapla-certverify-admin.php',
	'shortcode' => $root . '/includes/class-ecapla-certverify-shortcode.php',
	'render' => $root . '/includes/class-ecapla-certverify-render.php',
	'route' => $root . '/public/views/credential-route.php',
	'bootstrap' => $root . '/certloom.php',
);
$source = array();
foreach ( $files as $key => $path ) {
	phase1_assert( is_file( $path ), 'Missing Phase 1 file: ' . $path );
	$source[ $key ] = file_get_contents( $path );
	phase1_assert( false !== $source[ $key ], 'Could not read Phase 1 file: ' . $path );
}

phase1_assert( false !== strpos( $source['bootstrap'], 'Version: 1.7.0-alpha.' ), 'Development package must identify the 1.7 alpha line.' );
phase1_assert( false !== strpos( $source['tokens'], '--cl-ink: #01133D' ), 'Token authority must include Certloom navy ink.' );
phase1_assert( false !== strpos( $source['tokens'], '--cl-blue: #1450E0' ), 'Token authority must include blue action.' );
phase1_assert( false !== strpos( $source['tokens'], '--cl-valid: #00734E' ), 'Token authority must reserve verified green.' );
phase1_assert( false !== strpos( $source['tokens'], '--cl-revoked: #96214A' ), 'Token authority must include revoked rose.' );
phase1_assert( false !== strpos( $source['tokens'], '--cl-expired: #8A5A00' ), 'Token authority must include expired ochre.' );
phase1_assert( false === strpos( $source['tokens'], '!important' ), 'Token authority must not use !important for reduced motion.' );
phase1_assert( false === strpos( $source['foundation'], '!important' ), 'New admin foundation must contain zero !important declarations.' );
phase1_assert( false === strpos( $source['artifact'], '!important' ), 'New artifact foundation must contain zero !important declarations.' );
phase1_assert( ! preg_match( '/#[0-9a-fA-F]{3,8}\b/', $source['foundation'] ), 'New admin foundation must use tokens instead of raw hex colours.' );
phase1_assert( ! preg_match( '/#[0-9a-fA-F]{3,8}\b/', $source['artifact'] ), 'New public artifact foundation must use tokens instead of raw hex colours.' );
phase1_assert( false !== strpos( $source['admin'], "'none',\n\t\t\t30" ) && false === strpos( $source['admin'], "'dashicons-awards',\n\t\t\t30" ), 'WordPress top-level menu must use the Certloom mark rather than the generic award Dashicon.' );
phase1_assert( false !== strpos( $source['menu'], '#toplevel_page_certloom .wp-menu-image::before' ) && false !== strpos( $source['menu'], 'mask:' ), 'Menu CSS must provide the Certloom shield/check mask.' );
phase1_assert( false !== strpos( $source['menu'], 'certloom-menu-icon.svg' ), 'Menu CSS must use the user-supplied Certloom sidebar SVG asset.' );
phase1_assert( false !== strpos( $source['menu'], 'width: 18px;' ) && false !== strpos( $source['menu'], 'height: 20px;' ) && false !== strpos( $source['menu'], 'top: 50%;' ) && false !== strpos( $source['menu'], 'left: 50%;' ) && false !== strpos( $source['menu'], 'transform: translate(-50%, -50%);' ), 'Menu CSS must center the mark and render it ten percent smaller than the prior 20x22 slot size.' );
phase1_assert( hash( 'sha256', $source['menu_asset'] ) === '24ecb3b540c17a5b1328013819d84bc82facd93c10c4030333ec37bdff57db30', 'WordPress sidebar SVG must match the user-supplied asset bytes.' );
phase1_assert( false !== strpos( $source['admin'], "'certloom-tokens'" ) && false !== strpos( $source['admin'], "'certloom-admin-foundation'" ), 'Admin must load tokens first and the Phase 1 foundation after legacy admin CSS.' );
phase1_assert( false !== strpos( $source['shortcode'], "'certloom-artifact-foundation'" ) && false !== strpos( $source['shortcode'], "array( 'certloom-tokens' )" ), 'Public assets must load the token authority and artifact foundation.' );
phase1_assert( false !== strpos( $source['render'], 'certloom-verdict' ) && false !== strpos( $source['render'], 'certloom-recipient-name' ), 'Public artifact markup must expose verdict and recipient typography roles.' );
phase1_assert( false !== strpos( $source['render'], 'certloom-public certloom-credential-profile' ), 'Normalized public profile must scope itself to the Certloom token surface.' );
phase1_assert( false !== strpos( $source['route'], 'certloom-public certloom-route-main' ), 'Clean credential route must use the Certloom public token scope.' );
phase1_assert( false !== strpos( $source['foundation'], '.ecapla-certverify-badge::before' ), 'Ledger status must include a non-colour shape cue.' );
phase1_assert( false !== strpos( $source['artifact'], '.cvp-status-valid .cvp-status-mark' ) && false !== strpos( $source['artifact'], '.cvp-status-revoked .cvp-status-mark' ) && false !== strpos( $source['artifact'], '.cvp-status-expired .cvp-status-mark' ), 'Artifact status must define valid/revoked/expired shape-and-colour treatments.' );
phase1_assert( false !== strpos( $source['artifact'], 'border-radius: var(--cl-r-none)' ) && false !== strpos( $source['artifact'], 'box-shadow: none' ), 'Public verification reference must be document-like rather than card soup.' );

foreach ( array( "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false", "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false", "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false" ) as $flag ) {
	phase1_assert( false !== strpos( $source['bootstrap'], $flag ), 'Phase 1 must not weaken normalized-domain authority gate: ' . $flag );
}

$forbidden_manifests = array(
	$root . '/resources/domain/accepted.json',
	$root . '/resources/renderer/accepted.json',
);
foreach ( $forbidden_manifests as $manifest ) {
	phase1_assert( ! is_file( $manifest ), 'Phase 1 must not fabricate accepted authority manifest: ' . $manifest );
}

echo "PASS  Certloom 1.7 UI Phase 1 design-system contract\n";
echo "\n27 checks, 0 failures\n";
