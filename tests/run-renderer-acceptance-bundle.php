<?php
/** 1.6.24 renderer candidate + three-format golden-bound artifact-bundle contract regression. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererReleaseManifest;

$checks = 0;
function rab_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rab_throws( callable $fn ) { try { $fn(); } catch ( InvalidArgumentException $e ) { return true; } throw new RuntimeException( 'Expected InvalidArgumentException.' ); }

$candidate = array(
    'schema' => RendererCandidateManifest::SCHEMA, 'version' => RendererCandidateManifest::VERSION,
    'renderer_id' => 'mpdf-pdf', 'renderer_version' => '8.3.1+certloom.1', 'formats' => array( 'pdf' ),
    'implementation' => array( 'package'=>'mpdf/mpdf','package_version'=>'8.3.1','provenance_mode'=>'prefixed-package','namespace_prefix'=>'CertloomVendor\\Mpdf\\','source_sha256'=>str_repeat('a',64),'dependency_lock_sha256'=>str_repeat('b',64),'license'=>'GPL-2.0-only' ),
    'security' => array( 'execution_mode'=>'php-inprocess','input_schema'=>'certloom-render-plan:1','network'=>'none','processes'=>'none','remote_assets'=>false ),
    'requirements' => array( 'php_min'=>'7.4','extensions'=>array('gd','mbstring'),'classes'=>array('CertloomVendor\\Mpdf\\Mpdf'),'functions'=>array() ),
);
$manifest = new RendererCandidateManifest(); $normalized = $manifest->normalize( $candidate );
rab_check( 'CertloomVendor\\Mpdf\\' === $normalized['implementation']['namespace_prefix'], 'Candidate namespace prefix must remain isolated.' );
rab_check( array('gd','mbstring') === $normalized['requirements']['extensions'], 'Candidate requirements must normalize deterministically.' );
rab_check( 1 === preg_match('/^[0-9a-f]{64}$/',$manifest->hash($candidate)), 'Candidate manifest must have stable canonical SHA-256.' );
$bad=$candidate;$bad['security']['network']='allowed';rab_check(rab_throws(function()use($manifest,$bad){$manifest->normalize($bad);}), 'Candidate must reject network-enabled execution.');
$bad=$candidate;$bad['security']['processes']='allowed';rab_check(rab_throws(function()use($manifest,$bad){$manifest->normalize($bad);}), 'Candidate must reject process-backed execution.');
$bad=$candidate;$bad['implementation']['namespace_prefix']='Mpdf\\';rab_check(rab_throws(function()use($manifest,$bad){$manifest->normalize($bad);}), 'Candidate must reject unprefixed dependency namespace.');

$checks_map=array();foreach(RendererAcceptanceContract::required_checks() as $key){$checks_map[$key]=true;}
$fixtures=array();foreach(RendererAcceptanceContract::required_fixtures() as $name){$fixtures[$name]=array('render_plan_sha256'=>hash('sha256','plan-'.$name),'pdf_sha256'=>hash('sha256','pdf-'.$name),'png_sha256'=>hash('sha256','png-'.$name),'jpeg_sha256'=>hash('sha256','jpeg-'.$name));}
$candidate_hash=$manifest->hash($candidate);
$evidence=array(
    'schema'=>RendererAcceptanceContract::SCHEMA,'version'=>RendererAcceptanceContract::VERSION,'qualification'=>'production-candidate','plugin_version'=>'1.6.24',
    'fixture_manifest_sha256'=>str_repeat('3',64),'render_plan_manifest_sha256'=>str_repeat('4',64),'current_oracle_manifest_sha256'=>str_repeat('8',64),'bundle_manifest_sha256'=>str_repeat('5',64),'verification_report_sha256'=>str_repeat('9',64),'verifier_environment_sha256'=>str_repeat('0',64),
    'renderers'=>array(
        'pdf'=>array('id'=>'mpdf-pdf','version'=>'8.3.1+certloom.1','context_hash'=>str_repeat('6',64),'candidate_manifest_sha256'=>$candidate_hash),
        'png'=>array('id'=>'certloom-raster','version'=>'1','context_hash'=>str_repeat('7',64),'candidate_manifest_sha256'=>str_repeat('8',64)),
        'jpeg'=>array('id'=>'certloom-raster','version'=>'1','context_hash'=>str_repeat('2',64),'candidate_manifest_sha256'=>str_repeat('8',64)),
    ),
    'checks'=>$checks_map,'fixtures'=>$fixtures,
);
$acceptance=new RendererAcceptanceContract();
rab_check(9===RendererAcceptanceContract::VERSION,'Acceptance evidence must be v9 for mandatory Schema-4 renderer proof.');
rab_check(7===RendererVerificationReport::VERSION,'Independent verifier report must be v7 for Schema-4 proof flags.');
rab_check(3===RendererReleaseManifest::VERSION,'Release authority manifest must be v3 for exact production-payload binding.');
rab_check(array('pdf','png','jpeg')===RendererAcceptanceContract::required_formats(),'PDF-04 must require PDF, PNG and JPEG.');
rab_check(in_array('golden_visual_parity',RendererAcceptanceContract::required_checks(),true),'Acceptance must require candidate-to-golden visual parity.');
rab_check(in_array('pdf_png_foreground_parity',RendererAcceptanceContract::required_checks(),true)&&in_array('current_oracle_v2',RendererAcceptanceContract::required_checks(),true),'Acceptance must require foreground PDF/PNG parity and current-oracle v2 proof.');
rab_check(in_array('primitive_capability_coverage',RendererAcceptanceContract::required_checks(),true)&&in_array('primitives',RendererAcceptanceContract::required_fixtures(),true),'Acceptance must require the permanent non-text primitive capability fixture.');
rab_check(in_array('qr_pdf_decode',RendererAcceptanceContract::required_checks(),true),'Acceptance must decode QR from the actual PDF raster too.');
rab_check(in_array('qr_jpeg_decode',RendererAcceptanceContract::required_checks(),true),'Acceptance must decode QR from JPEG output too.');
rab_check(1===preg_match('/^[0-9a-f]{64}$/',$acceptance->hash($evidence)),'Production acceptance evidence must normalize with three-format bindings.');
$bad=$evidence;$bad['qualification']='development-reference';rab_check(rab_throws(function()use($acceptance,$bad){$acceptance->normalize($bad);}), 'Development references must never qualify as production acceptance.');
$bad=$evidence;unset($bad['renderers']['jpeg']);rab_check(rab_throws(function()use($acceptance,$bad){$acceptance->normalize($bad);}), 'Acceptance evidence missing JPEG renderer must fail.');
$bad=$evidence;unset($bad['fixtures']['english']['jpeg_sha256']);rab_check(rab_throws(function()use($acceptance,$bad){$acceptance->normalize($bad);}), 'Acceptance fixture missing JPEG artifact hash must fail.');
$bad=$evidence;unset($bad['checks']['golden_visual_parity']);rab_check(rab_throws(function()use($acceptance,$bad){$acceptance->normalize($bad);}), 'Acceptance missing frozen-golden comparison must fail.');
$bad=$evidence;unset($bad['checks']['primitive_capability_coverage']);rab_check(rab_throws(function()use($acceptance,$bad){$acceptance->normalize($bad);}), 'Acceptance missing non-text primitive coverage must fail.');
$bad=$evidence;unset($bad['checks']['qr_pdf_decode']);rab_check(rab_throws(function()use($acceptance,$bad){$acceptance->normalize($bad);}), 'Acceptance missing direct PDF QR decode proof must fail.');

foreach(array('build-reference-bundle.py','verify-bundle.py','verify-receipt.php','capture-verifier-environment.py','validate-verifier-environment.php','validate-verification-report.php','verify-production-bundle.sh') as $file){rab_check(is_file(__DIR__.'/renderer-candidate/'.$file),'Renderer acceptance source tool must ship: '.$file);}
$verifier=file_get_contents(__DIR__.'/renderer-candidate/verify-bundle.py');
rab_check(false!==strpos($verifier,'FORMATS = (\'pdf\',\'png\',\'jpeg\')'),'Independent verifier must cover all three artifact formats.');
rab_check(false!==strpos($verifier,'pdfinfo')&&false!==strpos($verifier,'pdftocairo'),'Verifier must inspect/rasterize real PDF output.');
rab_check(false!==strpos($verifier,'QRCodeDetector')&&false!==strpos($verifier,'for fmt in FORMATS'),'Verifier must decode QR from PDF, PNG and JPEG artifact paths.');
rab_check(false!==strpos($verifier,'structural_metric')&&false!==strpos($verifier,'current_oracle_v2')&&false!==strpos($verifier,'foreground_parity'),'Verifier must compare candidate output to the versioned current oracle and require PDF/PNG foreground structure, not only whole-page cross-format consistency.');
rab_check(false!==strpos($verifier,'ArtifactReceipt v4')&&false!==strpos($verifier,'verify-receipt.php'),'Production bundle must require ArtifactReceipt v4 for every artifact.');
rab_check(false!==strpos($verifier,"qualification != 'production-candidate'"),'Verifier must reject development-reference bundles in production mode.');

require_once __DIR__ . '/renderer-candidate/acceptance-evidence-lib.php';
$path_builder = new CertloomProductionAcceptanceEvidenceBuilder( dirname( __DIR__ ) );
$path_method = new ReflectionMethod( CertloomProductionAcceptanceEvidenceBuilder::class, 'publish_json_file' );
if ( PHP_VERSION_ID < 80100 ) { $path_method->setAccessible( true ); }
$path_tmp = sys_get_temp_dir() . '/certloom-rab-output-path-' . bin2hex( random_bytes( 5 ) );
mkdir( $path_tmp, 0700, true );
$prior_error_handler = set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
try {
    $valid_output = $path_tmp . '/acceptance.json';
    $path_method->invoke( $path_builder, $valid_output, array( 'ok' => true ) );
    rab_check( is_file( $valid_output ), 'Acceptance evidence publisher must accept a new safe output path without emitting PHP regex warnings.' );
    rab_check( rab_throws( function () use ( $path_method, $path_builder, $path_tmp ) { $path_method->invoke( $path_builder, $path_tmp . '/../escape.json', array( 'ok' => true ) ); } ), 'Acceptance evidence publisher must reject dot-segment output paths without regex warnings.' );
} finally {
    restore_error_handler();
    @unlink( $path_tmp . '/acceptance.json' );
    @rmdir( $path_tmp );
}

$golden_manifest=file_get_contents(__DIR__.'/fixtures/rendering/reference/manifest.json');
rab_check('fb5a3db32dbf24b1f7554bfab6f12a3a274f314226f5bb6501733f51adc4cb6f'===hash('sha256',$golden_manifest),'Three-format golden manifest must remain frozen by exact SHA-256.');

echo "PASS  Renderer acceptance binds PDF/PNG/JPEG candidates to actual frozen golden artwork\n";
echo "\n{$checks} checks, 0 failures\n";
