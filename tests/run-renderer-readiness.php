<?php
/** Current PDF-04 readiness / three-format golden-bound authority regression. */
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
use Certloom\Infrastructure\Rendering\MpdfPdfRenderer;
use Certloom\Infrastructure\Rendering\GdRasterRenderer;
use Certloom\Application\Rendering\ArabicPresentationShaper;
use Certloom\Application\Rendering\DeterministicPdfNormalizer;

$checks=0; function rrcheck($c,$m){global $checks;$checks++;if(!$c){throw new RuntimeException($m);}}
rrcheck(4===ArtifactReceipt::VERSION,'ArtifactReceipt must remain v4 after shared-plan binding.');
$rendering=file_get_contents(dirname(__DIR__).'/docs/rendering-contract.md');$architecture=file_get_contents(dirname(__DIR__).'/docs/architecture.md');
rrcheck(false!==strpos($rendering,'ArtifactReceipt v4'),'Rendering contract must document ArtifactReceipt v4.');
rrcheck(false===strpos($rendering,'ArtifactReceipt v2')&&false===strpos($rendering,'Artifact receipt v2'),'Stale receipt-v2 wording must stay absent.');
rrcheck(false!==strpos($architecture,'ArtifactReceipt v4'),'Architecture must document current receipt v4.');
rrcheck(interface_exists('Certloom\\Application\\Contract\\LocalFontResolverInterface'),'Exact local font resolver must autoload.');
rrcheck(class_exists(FontAssetInspector::class)&&class_exists(ContentAddressedFontResolver::class)&&class_exists(RenderPlanBuilder::class),'Font and shared-plan readiness classes must autoload.');
rrcheck(class_exists(MpdfPdfRenderer::class)&&class_exists(GdRasterRenderer::class),'Concrete mPDF/GD PDF-04 candidate adapters must autoload.');
rrcheck(class_exists(ArabicPresentationShaper::class)&&class_exists(DeterministicPdfNormalizer::class),'Candidate shaping/deterministic-PDF helpers must autoload.');
rrcheck(8===RendererAcceptanceContract::VERSION,'Renderer acceptance must be v8 after mandatory Schema-4 coverage.');
rrcheck(6===RendererVerificationReport::VERSION,'Independent verification report must be v6 with Schema-4 proof flags.');
rrcheck(2===RendererVerifierEnvironment::VERSION,'Verifier environment must be v2 so the standard production-verification entrypoint is provenance-bound.');
rrcheck(3===RendererReleaseManifest::VERSION,'Release authority manifest must be v3 with exact production-payload binding.');
rrcheck(class_exists(RendererCandidateManifest::class)&&class_exists(RendererVerifierEnvironment::class)&&class_exists(RendererAcceptanceImporter::class),'Candidate/verifier/import contracts must autoload.');
foreach(array('independent_verifier_environment_bound','candidate_provenance_rechecked','artifact_receipts_rechecked','shared_render_plan','golden_visual_parity','primitive_capability_coverage','schema4_rich_text','schema4_signer_signature','schema4_fixed_table','schema4_data_table','qr_pdf_decode','qr_jpeg_decode','png_jpeg_visual_parity') as $check){rrcheck(in_array($check,RendererAcceptanceContract::required_checks(),true),'Acceptance must require '.$check.'.');}
rrcheck(array('pdf','png','jpeg')===RendererAcceptanceContract::required_formats(),'PDF-04 must retain all three product artifact formats.');
rrcheck(11===count(RendererAcceptanceContract::required_fixtures())&&7===count(RendererAcceptanceContract::legacy_fixtures())&&4===count(RendererAcceptanceContract::schema4_fixtures()),'Acceptance must retain the seven permanent fixtures and require four Schema-4 fixtures.');
$plan_manifest=file_get_contents(__DIR__.'/fixtures/rendering/render-plan-manifest.json');
rrcheck('99a1e13fc48bf877699f22a76645be3e31f3762c8436ab6c57b6bb8a2e9da98a'===hash('sha256',$plan_manifest),'Seven-fixture shared Render Plan manifest must remain frozen.');
$golden_manifest=file_get_contents(__DIR__.'/fixtures/rendering/reference/manifest.json');
rrcheck('fb5a3db32dbf24b1f7554bfab6f12a3a274f314226f5bb6501733f51adc4cb6f'===hash('sha256',$golden_manifest),'PDF/PNG/JPEG golden fixture manifest must remain frozen.');
rrcheck(false!==strpos($rendering,'certloom-render-plan')&&false!==strpos($architecture,'certloom-render-plan'),'Docs must retain shared render-plan boundary.');
rrcheck(false!==strpos($rendering,'certloom-renderer-acceptance` v8'),'Rendering contract must document acceptance v8.');
rrcheck(false!==strpos($rendering,'JPEG')&&false!==strpos($rendering,'golden'),'Rendering contract must document JPEG and frozen-golden comparison.');
rrcheck(false!==strpos($rendering,'RendererVerifierEnvironment` v2')||false!==strpos($rendering,'RendererVerifierEnvironment v2'),'Rendering contract must document verifier-environment v2 entrypoint provenance.');
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
$release_builder=file_get_contents(__DIR__.'/build-release.sh');
$authorized_lib=file_get_contents(__DIR__.'/renderer-candidate/authorized-release-lib.php');
rrcheck(is_string($authorized_lib)&&false!==strpos($authorized_lib,'CertloomAuthorizedReleaseAuthority')&&false!==strpos($authorized_lib,'validate_authority_directory'),'Reviewed renderer authority must have a strict source-only packaging validator.');
rrcheck(is_file(__DIR__.'/renderer-candidate/validate-authorized-release.php')&&is_file(__DIR__.'/renderer-candidate/validate-bundled-authority.php'),'Canonical external and bundled authority validation CLIs must be source-controlled.');
rrcheck(is_string($release_builder)&&false!==strpos($release_builder,'CERTLOOM_RENDERER_AUTHORITY_DIR')&&false!==strpos($release_builder,'validate-authorized-release.php'),'Deterministic release builder must consume reviewed external authority only through the strict validator.');
rrcheck(is_string($release_builder)&&false!==strpos($release_builder,'validate-bundled-authority.php'),'Accepted source rebuilds must revalidate bundled authority before packaging.');
rrcheck(false!==strpos($rendering,'Authorized renderer release packaging (1.6.40)'),'Living rendering contract must document the canonical authorized packaging boundary.');
rrcheck(false===is_file(dirname(__DIR__).'/resources/renderer/accepted.json'),'Current source must remain renderer-authority closed until a real PDF-04 pass is reviewed and packaged.');

echo "PASS  Renderer readiness is three-format, golden-bound, exact-font and fail-closed\n";
echo "\n{$checks} checks, 0 failures\n";
