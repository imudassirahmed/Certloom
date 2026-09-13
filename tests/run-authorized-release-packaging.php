<?php
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/renderer-candidate/finalize-lib.php';
require_once __DIR__ . '/renderer-candidate/authorized-release-lib.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererVerifierEnvironment;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;

$checks = 0;
function arp_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function arp_write( $path, $value ) { file_put_contents( $path, json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); }
function arp_version() { $bytes = file_get_contents( dirname( __DIR__ ) . '/certloom.php' ); preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $bytes, $m ); return trim( $m[1] ); }
function arp_exec( $command, array $env = array() ) {
    $prefix = '';
    foreach ( $env as $key => $value ) { $prefix .= $key . '=' . escapeshellarg( $value ) . ' '; }
    $output = array(); $rc = 0; exec( $prefix . $command . ' 2>&1', $output, $rc );
    return array( $rc, implode( "\n", $output ) );
}
function arp_rm( $path ) { if ( file_exists( $path ) || is_link( $path ) ) { exec( 'rm -rf ' . escapeshellarg( $path ) ); } }

$root = dirname( __DIR__ );
$version = arp_version();
$base = sys_get_temp_dir() . '/certloom-authorized-release-' . bin2hex( random_bytes( 5 ) );
mkdir( $base, 0700, true );

$environment = array(
    'schema'=>RendererVerifierEnvironment::SCHEMA,'version'=>RendererVerifierEnvironment::VERSION,'plugin_version'=>$version,
    'runtime'=>array('python_implementation'=>'CPython','python_version'=>'3.13.1','php_version'=>PHP_VERSION,'platform'=>'Linux x86_64'),
    'tools'=>array('pdfinfo'=>'25.03.0','pdftocairo'=>'25.03.0'),
    'packages'=>array('numpy'=>'2.2.0','opencv'=>'4.11.0','pillow'=>'11.1.0'),
    'scripts'=>array_fill_keys(array('verify_bundle_sha256','capture_environment_sha256','validate_candidate_sha256','verify_receipt_sha256','validate_environment_sha256','validate_report_sha256','verify_entrypoint_sha256'),str_repeat('a',64)),
);
$env_contract = new RendererVerifierEnvironment();
$env_hash = $env_contract->hash( $environment );
$renderers = array(
    'pdf'=>array('id'=>'mpdf-pdf','version'=>'8.3.1+certloom.1','candidate_manifest_sha256'=>str_repeat('b',64)),
    'png'=>array('id'=>'certloom-raster','version'=>'1.0.0','candidate_manifest_sha256'=>str_repeat('c',64)),
    'jpeg'=>array('id'=>'certloom-raster','version'=>'1.0.0','candidate_manifest_sha256'=>str_repeat('c',64)),
);
$fixtures=$parity=$foreground=$golden=array();
foreach ( RendererAcceptanceContract::required_fixtures() as $name ) {
    $fixtures[$name]=array('render_plan_sha256'=>hash('sha256','plan-'.$name),'pdf_sha256'=>hash('sha256','pdf-'.$name),'png_sha256'=>hash('sha256','png-'.$name),'jpeg_sha256'=>hash('sha256','jpeg-'.$name));
    $metric=array('mean_abs_error'=>0.1,'fraction_channels_delta_gt_10'=>0.001); $parity[$name]=array('pdf_png'=>$metric,'png_jpeg'=>$metric);$foreground[$name]=array('pdf_png'=>array('foreground_precision'=>0.99,'foreground_recall'=>0.99));
    $g=array('foreground_precision'=>0.99,'foreground_recall'=>0.99); $golden[$name]=array('pdf'=>$g,'png'=>$g,'jpeg'=>$g);
}
$report = array(
    'schema'=>RendererVerificationReport::SCHEMA,'version'=>RendererVerificationReport::VERSION,'verified'=>true,'qualification'=>'production-candidate','qualifies_pdf04'=>true,
    'bundle_manifest_sha256'=>str_repeat('d',64),'fixture_manifest_sha256'=>str_repeat('e',64),'render_plan_manifest_sha256'=>str_repeat('f',64),'current_oracle_manifest_sha256'=>str_repeat('9',64),'verifier_environment_sha256'=>$env_hash,
    'candidate_provenance_rechecked'=>true,'artifact_receipts_rechecked'=>true,'deterministic_runs'=>true,'pdf_geometry'=>true,'png_geometry'=>true,'jpeg_geometry'=>true,'qr_pdf_decode'=>true,'qr_png_decode'=>true,'qr_jpeg_decode'=>true,'pdf_png_visual_parity'=>true,'pdf_png_foreground_parity'=>true,'png_jpeg_visual_parity'=>true,'golden_visual_parity'=>true,'current_oracle_v2'=>true,'primitive_capability_coverage'=>true,'schema4_rich_text'=>true,'schema4_signer_signature'=>true,'schema4_fixed_table'=>true,'schema4_data_table'=>true,
    'renderers'=>$renderers,'fixtures'=>$fixtures,'parity'=>$parity,'foreground_parity'=>$foreground,'golden_parity'=>$golden,
);
$report_contract = new RendererVerificationReport(); $report_hash = $report_contract->hash( $report );
$checks_map=array(); foreach ( RendererAcceptanceContract::required_checks() as $key ) { $checks_map[$key]=true; }
$evidence_renderers=array(); foreach ( RendererAcceptanceContract::required_formats() as $format ) { $evidence_renderers[$format]=array('id'=>$renderers[$format]['id'],'version'=>$renderers[$format]['version'],'context_hash'=>hash('sha256','context-'.$format),'candidate_manifest_sha256'=>$renderers[$format]['candidate_manifest_sha256']); }
$evidence = array(
    'schema'=>RendererAcceptanceContract::SCHEMA,'version'=>RendererAcceptanceContract::VERSION,'qualification'=>'production-candidate','plugin_version'=>$version,
    'fixture_manifest_sha256'=>$report['fixture_manifest_sha256'],'render_plan_manifest_sha256'=>$report['render_plan_manifest_sha256'],'current_oracle_manifest_sha256'=>$report['current_oracle_manifest_sha256'],'bundle_manifest_sha256'=>$report['bundle_manifest_sha256'],'verification_report_sha256'=>$report_hash,'verifier_environment_sha256'=>$env_hash,
    'renderers'=>$evidence_renderers,'checks'=>$checks_map,'fixtures'=>$fixtures,
);
$verification_index=array('schema'=>'certloom-renderer-verification-index','version'=>1,'bundle_manifest_sha256'=>$report['bundle_manifest_sha256'],'verification_report_sha256'=>$report_hash,'verifier_environment_sha256'=>$env_hash);
arp_write($base.'/evidence.json',$evidence); arp_write($base.'/report.json',$report); arp_write($base.'/environment.json',$environment); arp_write($base.'/verification-index.json',$verification_index);
$finalizer = new CertloomProductionAcceptanceFinalizer( $root );
$finalized = $finalizer->finalize( $base.'/evidence.json', $base.'/report.json', $base.'/environment.json', $base.'/verification-index.json', $base.'/authority' );

$validator = new CertloomAuthorizedReleaseAuthority( $root );
$validated = $validator->validate_authority_directory( $base.'/authority' );
arp_check( $validated['plugin_version'] === $version, 'Authorized-release validator must bind the exact Certloom version.' );
arp_check( $validated['runtime_payload'] === ( new RuntimePayloadFingerprint() )->fingerprint( $root ), 'Authorized-release validator must bind the exact production-intended payload.' );
arp_check( hash_equals( $validated['release_manifest_sha256'], $finalized['release_manifest_sha256'] ), 'Authorized-release validator must preserve the finalizer release-manifest identity.' );
arp_check( $validated['accepted_json_bytes'] === file_get_contents( $base.'/authority/accepted.json' ), 'Finalizer accepted.json must already be canonical packaging bytes.' );

list( $build_rc, $build_output ) = arp_exec( 'bash ' . escapeshellarg( $root.'/tests/build-release.sh' ) . ' ' . escapeshellarg( $base.'/build' ), array( 'CERTLOOM_RENDERER_AUTHORITY_DIR'=>$base.'/authority' ) );
arp_check( 0 === $build_rc, 'Authorized deterministic release build must succeed: ' . $build_output );
arp_check( false !== strpos( $build_output, 'renderer_authority=validated' ), 'Authorized release builder must report validated renderer authority.' );

mkdir( $base.'/release',0700 ); mkdir( $base.'/source',0700 );
exec( 'unzip -q ' . escapeshellarg( $base.'/build/certloom-'.$version.'.zip' ) . ' -d ' . escapeshellarg( $base.'/release' ), $o1, $u1 );
exec( 'unzip -q ' . escapeshellarg( $base.'/build/certloom-'.$version.'-source.zip' ) . ' -d ' . escapeshellarg( $base.'/source' ), $o2, $u2 );
arp_check( 0===$u1 && 0===$u2, 'Authorized source/installable archives must extract cleanly.' );
$release_manifest_path=$base.'/release/certloom/resources/renderer/accepted.json';
$source_manifest_path=$base.'/source/certloom/resources/renderer/accepted.json';
arp_check( is_file($release_manifest_path) && is_file($source_manifest_path), 'Authorized accepted.json must be staged into both source and installable archives.' );
arp_check( file_get_contents($release_manifest_path)===file_get_contents($source_manifest_path) && file_get_contents($release_manifest_path)===$validated['accepted_json_bytes'], 'Authorized source/installable accepted.json bytes must be identical and canonical.' );
$release_payload=(new RuntimePayloadFingerprint())->fingerprint($base.'/release/certloom');
arp_check( $release_payload===$validated['runtime_payload'], 'Packaged runtime payload must exactly match the payload reviewed by the finalizer.' );
$gate=new RendererAuthorityGate(null,$base.'/release/certloom',$version);
arp_check( true===$gate->manifest_status()['accepted'], 'Packaged accepted.json must validate against the exact packaged production payload.' );

list($rebuild_rc,$rebuild_output)=arp_exec('bash '.escapeshellarg($base.'/source/certloom/tests/build-release.sh').' '.escapeshellarg($base.'/rebuild'));
arp_check(0===$rebuild_rc && false!==strpos($rebuild_output,'renderer_authority=validated'),'An accepted source archive must rebuild without external evidence while revalidating its bundled authority.');
arp_check(hash_file('sha256',$base.'/build/certloom-'.$version.'.zip')===hash_file('sha256',$base.'/rebuild/certloom-'.$version.'.zip'),'Rebuilding the accepted source archive must reproduce the exact installable ZIP.');
arp_check(hash_file('sha256',$base.'/build/certloom-'.$version.'-source.zip')===hash_file('sha256',$base.'/rebuild/certloom-'.$version.'-source.zip'),'Rebuilding the accepted source archive must reproduce the exact source ZIP.');

$bad=$base.'/bad-authority'; exec('cp -a '.escapeshellarg($base.'/authority').' '.escapeshellarg($bad));
$bad_manifest=json_decode(file_get_contents($bad.'/accepted.json'),true); $bad_manifest['runtime_payload']['sha256']=str_repeat('0',64); arp_write($bad.'/accepted.json',$bad_manifest);
$bad_index=json_decode(file_get_contents($bad.'/authority-index.json'),true); $bad_index['release_manifest_sha256']=(new RendererReleaseManifest())->hash($bad_manifest); arp_write($bad.'/authority-index.json',$bad_index);
$rejected=false; try{$validator->validate_authority_directory($bad);}catch(Throwable $e){$rejected=true;}
arp_check($rejected,'Authorized-release validator must reject authority targeted at different production bytes even if its release-manifest hash is internally updated.');
file_put_contents($base.'/authority/extra.json',"{}\n"); $rejected=false; try{$validator->validate_authority_directory($base.'/authority');}catch(Throwable $e){$rejected=true;} unlink($base.'/authority/extra.json');
arp_check($rejected,'Authorized-release validator must reject unexpected authority-directory files.');
$inside=$root.'/tests/.certloom-authority-packaging-probe'; arp_rm($inside); exec('cp -a '.escapeshellarg($base.'/authority').' '.escapeshellarg($inside));
list($inside_rc,$inside_output)=arp_exec('bash '.escapeshellarg($root.'/tests/build-release.sh').' '.escapeshellarg($base.'/inside-build'),array('CERTLOOM_RENDERER_AUTHORITY_DIR'=>$inside));
arp_rm($inside);
arp_check(0!==$inside_rc && false!==strpos($inside_output,'outside the Certloom source tree'),'Authorized release builder must reject authority evidence sourced from inside the Certloom tree.');

list($closed_rc,$closed_output)=arp_exec('bash '.escapeshellarg($root.'/tests/build-release.sh').' '.escapeshellarg($base.'/closed-build'));
arp_check(0===$closed_rc && false!==strpos($closed_output,'renderer_authority=absent'),'Normal closed release builds must remain renderer-authority absent.');
mkdir($base.'/closed',0700); exec('unzip -q '.escapeshellarg($base.'/closed-build/certloom-'.$version.'.zip').' -d '.escapeshellarg($base.'/closed'));
arp_check(!is_file($base.'/closed/certloom/resources/renderer/accepted.json'),'Closed release path must not synthesize renderer authority.');

arp_rm($base);
echo "PASS  Authorized release packaging consumes finalized authority deterministically and preserves exact payload binding\n\n{$checks} checks, 0 failures\n";
