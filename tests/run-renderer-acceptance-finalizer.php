<?php
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/renderer-candidate/finalize-lib.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererVerifierEnvironment;

$checks = 0;
function raf_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function raf_throws( $callback ) { try { $callback(); } catch ( Throwable $e ) { return true; } return false; }
function raf_write( $path, $value ) { file_put_contents( $path, json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); }
function raf_version() { $bytes=file_get_contents(dirname(__DIR__).'/certloom.php'); preg_match('/^\s*\*\s*Version:\s*([^\s]+)/m',$bytes,$m); return trim($m[1]); }

$version = raf_version();
$environment = array(
	'schema'=>RendererVerifierEnvironment::SCHEMA,'version'=>RendererVerifierEnvironment::VERSION,'plugin_version'=>$version,
	'runtime'=>array('python_implementation'=>'CPython','python_version'=>'3.13.1','php_version'=>'8.4.23','platform'=>'Linux x86_64'),
	'tools'=>array('pdfinfo'=>'25.03.0','pdftocairo'=>'25.03.0'),
	'packages'=>array('numpy'=>'2.2.0','opencv'=>'4.11.0','pillow'=>'11.1.0'),
	'scripts'=>array_fill_keys(array('verify_bundle_sha256','capture_environment_sha256','validate_candidate_sha256','verify_receipt_sha256','validate_environment_sha256','validate_report_sha256','verify_entrypoint_sha256'),str_repeat('a',64)),
);
$env_contract = new RendererVerifierEnvironment();
$env_hash = $env_contract->hash($environment);
$renderers = array(
	'pdf'=>array('id'=>'mpdf-pdf','version'=>'8.3.1+certloom.1','candidate_manifest_sha256'=>str_repeat('b',64)),
	'png'=>array('id'=>'certloom-raster','version'=>'1.0.0','candidate_manifest_sha256'=>str_repeat('c',64)),
	'jpeg'=>array('id'=>'certloom-raster','version'=>'1.0.0','candidate_manifest_sha256'=>str_repeat('c',64)),
);
$fixtures=$parity=$golden=array();
foreach(RendererAcceptanceContract::required_fixtures() as $name){
	$fixtures[$name]=array('render_plan_sha256'=>hash('sha256','plan-'.$name),'pdf_sha256'=>hash('sha256','pdf-'.$name),'png_sha256'=>hash('sha256','png-'.$name),'jpeg_sha256'=>hash('sha256','jpeg-'.$name));
	$metric=array('mean_abs_error'=>0.1,'fraction_channels_delta_gt_10'=>0.001); $parity[$name]=array('pdf_png'=>$metric,'png_jpeg'=>$metric);
	$g=array('foreground_precision'=>0.99,'foreground_recall'=>0.99); $golden[$name]=array('pdf'=>$g,'png'=>$g,'jpeg'=>$g);
}
$report = array(
	'schema'=>RendererVerificationReport::SCHEMA,'version'=>RendererVerificationReport::VERSION,'verified'=>true,'qualification'=>'production-candidate','qualifies_pdf04'=>true,
	'bundle_manifest_sha256'=>str_repeat('d',64),'fixture_manifest_sha256'=>str_repeat('e',64),'render_plan_manifest_sha256'=>str_repeat('f',64),'verifier_environment_sha256'=>$env_hash,
	'candidate_provenance_rechecked'=>true,'artifact_receipts_rechecked'=>true,'deterministic_runs'=>true,'pdf_geometry'=>true,'png_geometry'=>true,'jpeg_geometry'=>true,'qr_pdf_decode'=>true,'qr_png_decode'=>true,'qr_jpeg_decode'=>true,'pdf_png_visual_parity'=>true,'png_jpeg_visual_parity'=>true,'golden_visual_parity'=>true,'primitive_capability_coverage'=>true,
	'renderers'=>$renderers,'fixtures'=>$fixtures,'parity'=>$parity,'golden_parity'=>$golden,
);
$report_contract = new RendererVerificationReport(); $report_hash=$report_contract->hash($report);
$checks_map=array(); foreach(RendererAcceptanceContract::required_checks() as $key){$checks_map[$key]=true;}
$evidence_renderers=array(); foreach(RendererAcceptanceContract::required_formats() as $format){$evidence_renderers[$format]=array('id'=>$renderers[$format]['id'],'version'=>$renderers[$format]['version'],'context_hash'=>hash('sha256','context-'.$format),'candidate_manifest_sha256'=>$renderers[$format]['candidate_manifest_sha256']);}
$evidence=array(
	'schema'=>RendererAcceptanceContract::SCHEMA,'version'=>RendererAcceptanceContract::VERSION,'qualification'=>'production-candidate','plugin_version'=>$version,
	'fixture_manifest_sha256'=>$report['fixture_manifest_sha256'],'render_plan_manifest_sha256'=>$report['render_plan_manifest_sha256'],'bundle_manifest_sha256'=>$report['bundle_manifest_sha256'],'verification_report_sha256'=>$report_hash,'verifier_environment_sha256'=>$env_hash,
	'renderers'=>$evidence_renderers,'checks'=>$checks_map,'fixtures'=>$fixtures,
);
$verification_index=array('schema'=>'certloom-renderer-verification-index','version'=>1,'bundle_manifest_sha256'=>$report['bundle_manifest_sha256'],'verification_report_sha256'=>$report_hash,'verifier_environment_sha256'=>$env_hash);

$base=sys_get_temp_dir().'/certloom-raf-'.bin2hex(random_bytes(5)); mkdir($base,0700,true);
raf_write($base.'/evidence.json',$evidence); raf_write($base.'/report.json',$report); raf_write($base.'/environment.json',$environment); raf_write($base.'/verification-index.json',$verification_index);
$finalizer=new CertloomProductionAcceptanceFinalizer(dirname(__DIR__));
$result=$finalizer->finalize($base.'/evidence.json',$base.'/report.json',$base.'/environment.json',$base.'/verification-index.json',$base.'/out');
raf_check(is_file($base.'/out/accepted.json'),'Finalizer must emit accepted.json staging candidate.');
raf_check(is_file($base.'/out/acceptance-evidence.json')&&is_file($base.'/out/verification-report.json')&&is_file($base.'/out/verifier-environment.json')&&is_file($base.'/out/verification-index.json')&&is_file($base.'/out/authority-index.json'),'Finalizer must emit the self-contained normalized evidence set and authority index.');
raf_check($version===$result['release_manifest']['plugin_version'],'Release authority must target exact source version.');
raf_check(2===RendererReleaseManifest::VERSION&&RendererReleaseManifest::VERSION===$result['release_manifest']['version'],'Finalizer must derive current v2 release authority.');
raf_check(hash_equals((new RendererAcceptanceContract())->hash($evidence),$result['acceptance_sha256']),'Finalizer must preserve exact normalized acceptance identity.');
raf_check(hash_equals((new RendererReleaseManifest())->hash($result['release_manifest']),$result['release_manifest_sha256']),'Finalizer must expose canonical release-manifest identity.');
raf_check(0600===(fileperms($base.'/out/accepted.json')&0777),'Staged accepted.json must be owner-readable/writable only.');
raf_check(0700===(fileperms($base.'/out')&0777),'Published authority staging directory must be owner-only.');
raf_check(hash_equals($report_hash,$report_contract->hash(json_decode(file_get_contents($base.'/out/verification-report.json'),true))),'Staged verification report must preserve canonical report identity.');
raf_check(raf_throws(function()use($finalizer,$base){$finalizer->finalize($base.'/evidence.json',$base.'/report.json',$base.'/environment.json',$base.'/verification-index.json',$base.'/out');}),'Finalizer must reject a non-empty output directory.');
mkdir($base.'/empty-out',0700);
raf_check(raf_throws(function()use($finalizer,$base){$finalizer->finalize($base.'/evidence.json',$base.'/report.json',$base.'/environment.json',$base.'/verification-index.json',$base.'/empty-out');}),'Finalizer must require a new output path so directory publication is atomic.');
raf_check(0===count(glob($base.'/.certloom-authority-*')?:array()),'Successful finalization must leave no temporary authority staging directories.');
$bad=$verification_index;$bad['bundle_manifest_sha256']=str_repeat('0',64);raf_write($base.'/bad-index.json',$bad);
raf_check(raf_throws(function()use($finalizer,$base){$finalizer->finalize($base.'/evidence.json',$base.'/report.json',$base.'/environment.json',$base.'/bad-index.json',$base.'/bad-out');}),'Finalizer must reject verification-index/bundle disagreement.');
$bad_env=$environment;$bad_env['plugin_version']='9.9.9';raf_write($base.'/bad-env.json',$bad_env);$bad_env_hash=$env_contract->hash($bad_env);$bad_report=$report;$bad_report['verifier_environment_sha256']=$bad_env_hash;raf_write($base.'/bad-report.json',$bad_report);$bad_report_hash=$report_contract->hash($bad_report);$bad_evidence=$evidence;$bad_evidence['verifier_environment_sha256']=$bad_env_hash;$bad_evidence['verification_report_sha256']=$bad_report_hash;raf_write($base.'/bad-evidence.json',$bad_evidence);$bad_idx=$verification_index;$bad_idx['verification_report_sha256']=$bad_report_hash;$bad_idx['verifier_environment_sha256']=$bad_env_hash;raf_write($base.'/bad-version-index.json',$bad_idx);
raf_check(raf_throws(function()use($finalizer,$base){$finalizer->finalize($base.'/bad-evidence.json',$base.'/bad-report.json',$base.'/bad-env.json',$base.'/bad-version-index.json',$base.'/version-out');}),'Finalizer must reject evidence verified for another Certloom version.');
raf_check(raf_throws(function()use($finalizer,$base){$finalizer->finalize($base.'/evidence.json',$base.'/report.json',$base.'/environment.json',$base.'/verification-index.json',dirname(__DIR__).'/tests/renderer-candidate/authority-out');}),'Finalizer must never write authority staging inside the Certloom source tree.');
raf_check(is_file(__DIR__.'/renderer-candidate/finalize-production-acceptance.php'),'Canonical acceptance finalization CLI must be source-controlled.');
raf_check(is_file(__DIR__.'/renderer-candidate/finalize-lib.php'),'Canonical source-only finalizer library must be source-controlled.');

exec('rm -rf '.escapeshellarg($base));
echo "PASS  PDF-04 finalizer cross-links imported evidence and stages release authority without mutating plugin runtime\n\n{$checks} checks, 0 failures\n";
