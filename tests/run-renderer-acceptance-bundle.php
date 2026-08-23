<?php
/** 1.6.20 renderer candidate provenance + artifact-bundle contract regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererCandidateManifest;

$checks = 0;
function rab_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rab_throws( callable $fn ) { try { $fn(); } catch ( InvalidArgumentException $e ) { return true; } throw new RuntimeException( 'Expected InvalidArgumentException.' ); }

$candidate = array(
	'schema' => RendererCandidateManifest::SCHEMA,
	'version' => RendererCandidateManifest::VERSION,
	'renderer_id' => 'mpdf-pdf',
	'renderer_version' => '8.3.1+certloom.1',
	'formats' => array( 'pdf' ),
	'implementation' => array(
		'package' => 'mpdf/mpdf',
		'package_version' => '8.3.1',
		'namespace_prefix' => 'CertloomVendor\\Mpdf\\',
		'source_sha256' => str_repeat( 'a', 64 ),
		'dependency_lock_sha256' => str_repeat( 'b', 64 ),
		'license' => 'GPL-2.0-only',
	),
	'security' => array(
		'execution_mode' => 'php-inprocess',
		'input_schema' => 'certloom-render-plan:1',
		'network' => 'none',
		'processes' => 'none',
		'remote_assets' => false,
	),
	'requirements' => array(
		'php_min' => '7.4',
		'extensions' => array( 'gd', 'mbstring' ),
		'classes' => array( 'CertloomVendor\\Mpdf\\Mpdf' ),
		'functions' => array(),
	),
);
$manifest = new RendererCandidateManifest();
$normalized = $manifest->normalize( $candidate );
rab_check( 'CertloomVendor\\Mpdf\\' === $normalized['implementation']['namespace_prefix'], 'Candidate namespace prefix must remain isolated under CertloomVendor.' );
rab_check( array( 'gd', 'mbstring' ) === $normalized['requirements']['extensions'], 'Candidate requirements must normalize deterministically.' );
rab_check( 1 === preg_match( '/^[0-9a-f]{64}$/', $manifest->hash( $candidate ) ), 'Candidate manifest must have a stable canonical SHA-256.' );

$bad = $candidate; $bad['security']['network'] = 'allowed';
rab_check( rab_throws( function () use ( $manifest, $bad ) { $manifest->normalize( $bad ); } ), 'Candidate manifest must reject network-enabled renderer execution.' );
$bad = $candidate; $bad['security']['processes'] = 'allowed';
rab_check( rab_throws( function () use ( $manifest, $bad ) { $manifest->normalize( $bad ); } ), 'Candidate manifest must reject process-backed renderer execution.' );
$bad = $candidate; $bad['implementation']['namespace_prefix'] = 'Mpdf\\';
rab_check( rab_throws( function () use ( $manifest, $bad ) { $manifest->normalize( $bad ); } ), 'Candidate manifest must reject unprefixed third-party namespaces.' );
$bad = $candidate; $bad['implementation']['source_sha256'] = 'nope';
rab_check( rab_throws( function () use ( $manifest, $bad ) { $manifest->normalize( $bad ); } ), 'Candidate manifest must reject unbound source provenance.' );

$checks_map = array(); foreach ( RendererAcceptanceContract::required_checks() as $key ) { $checks_map[ $key ] = true; }
$fixtures = array(); foreach ( RendererAcceptanceContract::required_fixtures() as $name ) { $fixtures[ $name ] = array( 'render_plan_sha256' => str_repeat( 'f', 64 ), 'pdf_sha256' => str_repeat( '1', 64 ), 'png_sha256' => str_repeat( '2', 64 ) ); }
$candidate_hash = $manifest->hash( $candidate );
$evidence = array(
	'schema' => RendererAcceptanceContract::SCHEMA,
	'version' => RendererAcceptanceContract::VERSION,
	'qualification' => 'production-candidate',
	'plugin_version' => '1.6.20',
	'fixture_manifest_sha256' => str_repeat( '3', 64 ),
	'render_plan_manifest_sha256' => str_repeat( '4', 64 ),
	'bundle_manifest_sha256' => str_repeat( '5', 64 ),
	'renderers' => array(
		'pdf' => array( 'id' => 'mpdf-pdf', 'version' => '8.3.1+certloom.1', 'context_hash' => str_repeat( '6', 64 ), 'candidate_manifest_sha256' => $candidate_hash ),
		'png' => array( 'id' => 'certloom-raster', 'version' => '1', 'context_hash' => str_repeat( '7', 64 ), 'candidate_manifest_sha256' => str_repeat( '8', 64 ) ),
	),
	'checks' => $checks_map,
	'fixtures' => $fixtures,
);
$acceptance = new RendererAcceptanceContract();
rab_check( 3 === RendererAcceptanceContract::VERSION, 'Acceptance evidence must advance to v3 when artifact bundle/provenance binding is required.' );
rab_check( 1 === preg_match( '/^[0-9a-f]{64}$/', $acceptance->hash( $evidence ) ), 'Production acceptance evidence must normalize with bundle/candidate bindings.' );
$bad = $evidence; $bad['qualification'] = 'development-reference';
rab_check( rab_throws( function () use ( $acceptance, $bad ) { $acceptance->normalize( $bad ); } ), 'Development reference evidence must never qualify as PDF-04 production acceptance.' );
$bad = $evidence; unset( $bad['bundle_manifest_sha256'] );
rab_check( rab_throws( function () use ( $acceptance, $bad ) { $acceptance->normalize( $bad ); } ), 'Acceptance evidence must bind the independently verified artifact bundle.' );
$bad = $evidence; unset( $bad['renderers']['pdf']['candidate_manifest_sha256'] );
rab_check( rab_throws( function () use ( $acceptance, $bad ) { $acceptance->normalize( $bad ); } ), 'Acceptance evidence must bind exact renderer candidate provenance.' );

rab_check( is_file( __DIR__ . '/renderer-candidate/build-reference-bundle.py' ), 'Development reference bundle builder must ship in source tests.' );
rab_check( is_file( __DIR__ . '/renderer-candidate/verify-bundle.py' ), 'Independent artifact-bundle verifier must ship in source tests.' );
rab_check( is_file( __DIR__ . '/renderer-candidate/verify-receipt.php' ), 'Independent ArtifactReceipt verifier must ship in source tests.' );
$verifier = file_get_contents( __DIR__ . '/renderer-candidate/verify-bundle.py' );
rab_check( false !== strpos( $verifier, 'pdfinfo' ) && false !== strpos( $verifier, 'pdftocairo' ), 'Independent verifier must inspect real PDF geometry and rasterize PDF output.' );
rab_check( false !== strpos( $verifier, 'QRCodeDetector' ), 'Independent verifier must decode QR from actual PNG output.' );
rab_check( false !== strpos( $verifier, 'ArtifactReceipt v4' ) && false !== strpos( $verifier, 'verify-receipt.php' ), 'Production bundle verification must require ArtifactReceipt v4 for every artifact.' );
rab_check( false !== strpos( $verifier, "qualification != 'production-candidate'" ), 'Independent verifier must reject development-reference bundles in production mode.' );

echo "PASS  Renderer acceptance binds exact candidate provenance and independently rechecked artifact bundles\n";
echo "\n{$checks} checks, 0 failures\n";
