<?php
/** 1.6.26 PDF-04 readiness / three-format golden-bound authority finalization regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Application\Rendering\RendererVerifierEnvironment;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererAcceptanceImporter;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Rendering\FontAssetInspector;
use Certloom\Infrastructure\Rendering\ContentAddressedFontResolver;

$checks=0; function rrcheck($c,$m){global $checks;$checks++;if(!$c){throw new RuntimeException($m);}}
rrcheck(4===ArtifactReceipt::VERSION,'ArtifactReceipt must remain v4 after shared-plan binding.');
$rendering=file_get_contents(dirname(__DIR__).'/docs/rendering-contract.md');$architecture=file_get_contents(dirname(__DIR__).'/docs/architecture.md');
rrcheck(false!==strpos($rendering,'ArtifactReceipt v4'),'Rendering contract must document ArtifactReceipt v4.');
rrcheck(false===strpos($rendering,'ArtifactReceipt v2')&&false===strpos($rendering,'Artifact receipt v2'),'Stale receipt-v2 wording must stay absent.');
rrcheck(false!==strpos($architecture,'ArtifactReceipt v4'),'Architecture must document current receipt v4.');
rrcheck(interface_exists('Certloom\\Application\\Contract\\LocalFontResolverInterface'),'Exact local font resolver must autoload.');
rrcheck(class_exists(FontAssetInspector::class)&&class_exists(ContentAddressedFontResolver::class)&&class_exists(RenderPlanBuilder::class),'Font and shared-plan readiness classes must autoload.');
rrcheck(5===RendererAcceptanceContract::VERSION,'Renderer acceptance must be v5 after JPEG/golden parity expansion.');
rrcheck(3===RendererVerificationReport::VERSION,'Independent verification report must be v3.');
rrcheck(2===RendererReleaseManifest::VERSION,'Release authority manifest must be v2.');
rrcheck(class_exists(RendererCandidateManifest::class)&&class_exists(RendererVerifierEnvironment::class)&&class_exists(RendererAcceptanceImporter::class),'Candidate/verifier/import contracts must autoload.');
foreach(array('independent_verifier_environment_bound','candidate_provenance_rechecked','artifact_receipts_rechecked','shared_render_plan','golden_visual_parity','qr_jpeg_decode','png_jpeg_visual_parity') as $check){rrcheck(in_array($check,RendererAcceptanceContract::required_checks(),true),'Acceptance must require '.$check.'.');}
rrcheck(array('pdf','png','jpeg')===RendererAcceptanceContract::required_formats(),'PDF-04 must retain all three product artifact formats.');
rrcheck(6===count(RendererAcceptanceContract::required_fixtures())&&in_array('urdu-rtl',RendererAcceptanceContract::required_fixtures(),true)&&in_array('rotation',RendererAcceptanceContract::required_fixtures(),true),'All six permanent Unicode/rotation fixtures must remain mandatory.');
$plan_manifest=file_get_contents(__DIR__.'/fixtures/rendering/render-plan-manifest.json');
rrcheck('bdcbfba5c61632a86cdf881131bd12496bd260b21992d53129e79c4070df519b'===hash('sha256',$plan_manifest),'Six-fixture shared Render Plan manifest must remain frozen.');
$golden_manifest=file_get_contents(__DIR__.'/fixtures/rendering/reference/manifest.json');
rrcheck('2afda4d66a1b89cfa85847cfd616d8b2117ea2dc9dc53307b42cf4f35a185b0f'===hash('sha256',$golden_manifest),'PDF/PNG/JPEG golden fixture manifest must remain frozen.');
rrcheck(false!==strpos($rendering,'certloom-render-plan')&&false!==strpos($architecture,'certloom-render-plan'),'Docs must retain shared render-plan boundary.');
rrcheck(false!==strpos($rendering,'certloom-renderer-acceptance` v5'),'Rendering contract must document acceptance v5.');
rrcheck(false!==strpos($rendering,'JPEG')&&false!==strpos($rendering,'golden'),'Rendering contract must document JPEG and frozen-golden comparison.');
$coordinator=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Rendering/ArtifactRenderCoordinator.php');
rrcheck(false!==strpos($coordinator,"array_key_exists( '_runtime'")&&false!==strpos($coordinator,'LocalFontResolverInterface'),'Coordinator must own reserved runtime font resolution.');
rrcheck(false!==strpos($coordinator,'RenderPlanBuilder')&&false!==strpos($coordinator,'$renderer->render( $render_plan'),'Coordinator must build shared plan before adapter execution.');
rrcheck(false!==strpos($coordinator,'$render_plan_hash, $renderer'),'Coordinator must bind plan hash into ArtifactReceipt v4.');
$probe=file_get_contents(__DIR__.'/renderer-candidate/check-runtime.php');
rrcheck(false!==strpos($probe,'CertloomVendor\\\\Mpdf\\\\Mpdf'),'Development probe must target dependency-prefixed mPDF.');
rrcheck(0===preg_match('/\b(?:curl_exec|wp_remote_|shell_exec|exec\s*\(|passthru\s*\(|proc_open\s*\()/i',$probe),'Readiness probe must not fetch/install/execute external processes.');

$finalizer=file_get_contents(__DIR__.'/renderer-candidate/finalize-lib.php');
$finalizer_cli=file_get_contents(__DIR__.'/renderer-candidate/finalize-production-acceptance.php');
rrcheck(is_string($finalizer)&&false!==strpos($finalizer,'RendererAcceptanceImporter')&&false!==strpos($finalizer,'RendererReleaseManifest'),'Source finalizer must import verified evidence through production contracts before deriving release authority.');
rrcheck(is_string($finalizer)&&false!==strpos($finalizer,'verification-report.json')&&false!==strpos($finalizer,'verifier-environment.json')&&false!==strpos($finalizer,'verification-index.json'),'Authority staging must preserve the independently normalized verifier evidence set.');
rrcheck(is_string($finalizer)&&false!==strpos($finalizer,'rename( $temp, $path )'),'Authority staging must publish a complete directory atomically.');
rrcheck(is_string($finalizer_cli)&&false!==strpos($finalizer_cli,'CertloomProductionAcceptanceFinalizer'),'Canonical source-only finalization CLI must use the strict finalizer.');
rrcheck(false===is_file(dirname(__DIR__).'/resources/renderer/accepted.json'),'Current source must remain renderer-authority closed until a real PDF-04 pass is reviewed and packaged.');

echo "PASS  Renderer readiness is three-format, golden-bound, exact-font and fail-closed\n";
echo "\n{$checks} checks, 0 failures\n";
