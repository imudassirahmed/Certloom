<?php
/** 1.6.18 PDF-04 readiness / exact-font boundary regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\FontAssetInspector;
use Certloom\Infrastructure\Rendering\ContentAddressedFontResolver;

$checks = 0;
function rrcheck( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }

rrcheck( 3 === ArtifactReceipt::VERSION, 'ArtifactReceipt production schema must remain v3.' );
$rendering = file_get_contents( dirname( __DIR__ ) . '/docs/rendering-contract.md' );
$architecture = file_get_contents( dirname( __DIR__ ) . '/docs/architecture.md' );
rrcheck( false !== strpos( $rendering, 'ArtifactReceipt v3' ), 'Rendering contract must document ArtifactReceipt v3.' );
rrcheck( false === strpos( $rendering, 'ArtifactReceipt v2' ) && false === strpos( $rendering, 'Artifact receipt v2' ), 'Rendering contract must not regress to stale receipt-v2 wording.' );
rrcheck( false !== strpos( $architecture, 'ArtifactReceipt v3' ), 'Architecture must document ArtifactReceipt v3.' );
rrcheck( interface_exists( 'Certloom\\Application\\Contract\\LocalFontResolverInterface' ), 'Exact local font resolver contract must autoload.' );
rrcheck( class_exists( FontAssetInspector::class ), 'Font byte inspector must autoload.' );
rrcheck( class_exists( ContentAddressedFontResolver::class ), 'Content-addressed local font resolver must autoload.' );
rrcheck( 6 === count( RendererAcceptanceContract::required_fixtures() ), 'Renderer acceptance must retain all six permanent fixtures.' );
rrcheck( in_array( 'urdu-rtl', RendererAcceptanceContract::required_fixtures(), true ), 'Urdu RTL fixture must remain mandatory.' );
rrcheck( in_array( 'rotation', RendererAcceptanceContract::required_fixtures(), true ), 'Arbitrary rotation fixture must remain mandatory.' );
rrcheck( in_array( 'qr_raster_decode', RendererAcceptanceContract::required_checks(), true ), 'Raster QR decode must remain mandatory.' );
rrcheck( in_array( 'no_remote_fetch', RendererAcceptanceContract::required_checks(), true ), 'No-remote-fetch proof must remain mandatory.' );
rrcheck( in_array( 'pdf_png_visual_parity', RendererAcceptanceContract::required_checks(), true ), 'PDF/PNG visual parity must remain mandatory.' );
$coordinator = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Rendering/ArtifactRenderCoordinator.php' );
rrcheck( false !== strpos( $coordinator, "array_key_exists( '_runtime'" ) && false !== strpos( $coordinator, 'LocalFontResolverInterface' ), 'Coordinator must own reserved runtime font resolution.' );
$probe = file_get_contents( __DIR__ . '/renderer-candidate/check-runtime.php' );
rrcheck( false !== strpos( $probe, 'CertloomVendor\\\\Mpdf\\\\Mpdf' ), 'Development probe must target a dependency-prefixed mPDF candidate.' );
rrcheck( 0 === preg_match( '/\b(?:curl_exec|wp_remote_|shell_exec|exec\s*\(|passthru\s*\(|proc_open\s*\()/i', $probe ), 'Renderer readiness probe must not fetch/install/execute external processes.' );

echo "PASS  Renderer readiness contract, exact-font boundary, and acceptance evidence are fail-closed\n";
echo "\n{$checks} checks, 0 failures\n";
