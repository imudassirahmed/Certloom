<?php
/** 1.6.19 PDF-04 readiness / shared composition boundary regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Rendering\FontAssetInspector;
use Certloom\Infrastructure\Rendering\ContentAddressedFontResolver;

$checks = 0;
function rrcheck( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }

rrcheck( 4 === ArtifactReceipt::VERSION, 'ArtifactReceipt production schema must be v4 after shared-plan binding.' );
$rendering = file_get_contents( dirname( __DIR__ ) . '/docs/rendering-contract.md' );
$architecture = file_get_contents( dirname( __DIR__ ) . '/docs/architecture.md' );
rrcheck( false !== strpos( $rendering, 'ArtifactReceipt v4' ), 'Rendering contract must document ArtifactReceipt v4.' );
rrcheck( false === strpos( $rendering, 'ArtifactReceipt v2' ) && false === strpos( $rendering, 'Artifact receipt v2' ), 'Rendering contract must not regress to stale receipt-v2 wording.' );
rrcheck( false !== strpos( $architecture, 'ArtifactReceipt v4' ), 'Architecture must document current ArtifactReceipt v4.' );
rrcheck( interface_exists( 'Certloom\\Application\\Contract\\LocalFontResolverInterface' ), 'Exact local font resolver contract must autoload.' );
rrcheck( class_exists( FontAssetInspector::class ), 'Font byte inspector must autoload.' );
rrcheck( class_exists( ContentAddressedFontResolver::class ), 'Content-addressed local font resolver must autoload.' );
rrcheck( class_exists( RenderPlanBuilder::class ), 'Shared renderer-independent RenderPlanBuilder must autoload.' );
rrcheck( 3 === RendererAcceptanceContract::VERSION, 'Renderer acceptance evidence must be schema v3 after independent bundle/candidate provenance binding.' );
rrcheck( class_exists( RendererCandidateManifest::class ), 'Renderer candidate provenance manifest contract must autoload.' );
rrcheck( in_array( 'shared_render_plan', RendererAcceptanceContract::required_checks(), true ), 'Renderer acceptance must prove the shared render-plan path.' );
$plan_manifest_path = __DIR__ . '/fixtures/rendering/render-plan-manifest.json';
$plan_manifest = file_get_contents( $plan_manifest_path );
rrcheck( is_string( $plan_manifest ) && '3070631a405c471fd663373b7440243cfa915d65eb38c8ddb4746ed35f2e1279' === hash( 'sha256', $plan_manifest ), 'Six-fixture shared render-plan manifest must remain frozen by exact SHA-256.' );
rrcheck( false !== strpos( $rendering, 'certloom-render-plan' ) && false !== strpos( $architecture, 'certloom-render-plan' ), 'Rendering/architecture documents must describe the shared render-plan boundary.' );
rrcheck( false !== strpos( $rendering, 'RendererAcceptanceContract` (`certloom-renderer-acceptance` v3)' ), 'Rendering contract must document renderer acceptance schema v3.' );
rrcheck( 6 === count( RendererAcceptanceContract::required_fixtures() ), 'Renderer acceptance must retain all six permanent fixtures.' );
rrcheck( in_array( 'urdu-rtl', RendererAcceptanceContract::required_fixtures(), true ), 'Urdu RTL fixture must remain mandatory.' );
rrcheck( in_array( 'rotation', RendererAcceptanceContract::required_fixtures(), true ), 'Arbitrary rotation fixture must remain mandatory.' );
rrcheck( in_array( 'qr_raster_decode', RendererAcceptanceContract::required_checks(), true ), 'Raster QR decode must remain mandatory.' );
rrcheck( in_array( 'no_remote_fetch', RendererAcceptanceContract::required_checks(), true ), 'No-remote-fetch proof must remain mandatory.' );
rrcheck( in_array( 'pdf_png_visual_parity', RendererAcceptanceContract::required_checks(), true ), 'PDF/PNG visual parity must remain mandatory.' );
$coordinator = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Rendering/ArtifactRenderCoordinator.php' );
rrcheck( false !== strpos( $coordinator, "array_key_exists( '_runtime'" ) && false !== strpos( $coordinator, 'LocalFontResolverInterface' ), 'Coordinator must own reserved runtime font resolution.' );
rrcheck( false !== strpos( $coordinator, 'RenderPlanBuilder' ) && false !== strpos( $coordinator, '$renderer->render( $render_plan' ), 'Coordinator must build the shared plan before adapter execution.' );
rrcheck( false !== strpos( $coordinator, '$render_plan_hash, $renderer' ), 'Coordinator must bind the exact shared plan hash into ArtifactReceipt v4.' );
rrcheck( false !== strpos( $coordinator, 'Certloom render plan preparation failed' ), 'Coordinator must report plan preparation failures distinctly from adapter execution failures.' );
$probe = file_get_contents( __DIR__ . '/renderer-candidate/check-runtime.php' );
rrcheck( false !== strpos( $probe, 'CertloomVendor\\\\Mpdf\\\\Mpdf' ), 'Development probe must target a dependency-prefixed mPDF candidate.' );
rrcheck( 0 === preg_match( '/\b(?:curl_exec|wp_remote_|shell_exec|exec\s*\(|passthru\s*\(|proc_open\s*\()/i', $probe ), 'Renderer readiness probe must not fetch/install/execute external processes.' );

echo "PASS  Renderer readiness, exact-font, shared composition, and acceptance evidence are fail-closed\n";
echo "\n{$checks} checks, 0 failures\n";
