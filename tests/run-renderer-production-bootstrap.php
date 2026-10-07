<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/renderer-candidate/production-bootstrap-lib.php';
require_once __DIR__ . '/support/python-process.php';
require_once __DIR__ . '/support/packaging-tools.php';

use Certloom\Application\Rendering\RendererCandidateManifest;

$checks = 0; $failures = 0;
function rpb_check( $ok, $message ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures++; fwrite( STDERR, "FAIL  {$message}\n" ); } else { echo "PASS  {$message}\n"; } }
function rpb_cmd( array $parts ) { return certloom_test_process( $parts ); }
function rpb_throws( callable $fn ) { try { $fn(); return false; } catch ( Throwable $e ) { return true; } }

$root = dirname( __DIR__ );
$tmp = sys_get_temp_dir() . '/certloom-bootstrap-' . bin2hex( random_bytes( 6 ) );
mkdir( $tmp, 0700, true );
try {
	$example = file_get_contents( __DIR__ . '/renderer-candidate/production-bootstrap.example.php' );
	rpb_check( false !== strpos( $example, "$" . "certloom_source_root . '/tests/renderer-candidate/production-bootstrap-lib.php'" ), 'Copied bootstrap resolves helper code from the configured Certloom source root, not external __DIR__.' );
	rpb_check( false === strpos( $example, "$" . "candidate_contract->hash" ) && false === strpos( $example, '$read_json(' ), 'Bootstrap template no longer references undefined helper variables.' );
	rpb_check( false === strpos( $example, "'dejavu' =>" ) && false !== strpos( $example, 'CertloomProductionCandidateBootstrapFactory' ), 'Bootstrap template cannot drift by rebuilding a separate hard-coded font manifest.' );

	$candidate = $tmp . '/prepared'; mkdir( $candidate . '/vendor', 0700, true );
	file_put_contents( $candidate . '/vendor/autoload.php', "<?php require_once __DIR__ . '/Mpdf.php';\n" );
	file_put_contents( $candidate . '/vendor/Mpdf.php', "<?php namespace CertloomVendor\\Mpdf; final class Mpdf {}\n" );
	$lock = array( 'packages'=>array( array( 'name'=>'mpdf/mpdf','version'=>'v8.3.1','type'=>'library','license'=>array('GPL-2.0-only') ) ), 'packages-dev'=>array() );
	$lock_path = $tmp . '/composer.lock'; file_put_contents( $lock_path, json_encode( $lock, JSON_UNESCAPED_SLASHES ) );
	$tool = $root . '/tests/renderer-candidate/freeze-candidate.py';
	$pdf_recipe = $root . '/tests/renderer-candidate/recipes/mpdf-8.3.1.json';
	$gd_recipe = $root . '/tests/renderer-candidate/recipes/gd-raster.json';
	$gd_lock = $root . '/tests/renderer-candidate/recipes/gd-runtime.lock.json';
	$pdf_freeze = $tmp . '/pdf-freeze'; $gd_freeze = $tmp . '/gd-freeze';
	list($prc,$pout)=certloom_test_python(array($tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$pdf_freeze));
	list($grc,$gout)=certloom_test_python(array($tool,'--recipe',$gd_recipe,'--candidate-root',$root,'--dependency-lock',$gd_lock,'--output',$gd_freeze));
	rpb_check( 0 === $prc && 0 === $grc, 'Bootstrap regression can create exact frozen PDF and GD candidate evidence.' );
	if ( 0 !== $prc || 0 !== $grc ) { throw new RuntimeException( 'Candidate fixture freezing failed: ' . $pout . $gout ); }

	$setup = certloom_render_plan_acceptance_setup();
	$font_hash = $setup['font_face_sha256'];
	$font_hash_600 = (string) $setup['font_manifest']['families']['sans-serif']['600-normal']['faces'][0]['sha256'];
	$font_source = ''; $font_source_600 = '';
	foreach ( array(getenv('CERTLOOM_TEST_DEJAVU_SANS'),$root.'/resources/renderer/fonts/'.$font_hash.'.ttf','/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/share/fonts/dejavu/DejaVuSans.ttf','/usr/local/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/local/share/dejavu/DejaVuSans.ttf') as $path ) {
		if ( is_file($path) && hash_equals($font_hash,hash_file('sha256',$path)) ) { $font_source=$path; break; }
	}
	foreach ( array(getenv('CERTLOOM_TEST_DEJAVU_SANS_BOLD'),$root.'/resources/renderer/fonts/'.$font_hash_600.'.ttf','/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf','/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf','/usr/local/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf','/usr/local/share/dejavu/DejaVuSans-Bold.ttf') as $path ) {
		if ( is_file($path) && hash_equals($font_hash_600,hash_file('sha256',$path)) ) { $font_source_600=$path; break; }
	}
	rpb_check( '' !== $font_source && '' !== $font_source_600, 'Bootstrap regression has both exact frozen 400/600 fixture fonts available locally.' );
	if ( '' === $font_source || '' === $font_source_600 ) { throw new RuntimeException( 'Exact frozen font fixtures are required.' ); }
	$font_root=$tmp.'/fonts';mkdir($font_root,0700);copy($font_source,$font_root.'/'.$font_hash.'.ttf');copy($font_source_600,$font_root.'/'.$font_hash_600.'.ttf');
	$temp=$tmp.'/mpdf-temp';mkdir($temp,0700);

	$factory = new CertloomProductionCandidateBootstrapFactory( $root );
	$config = $factory->build( array( 'pdf_freeze_dir'=>$pdf_freeze, 'raster_freeze_dir'=>$gd_freeze, 'font_root'=>$font_root, 'mpdf_temp_dir'=>$temp ) );
	rpb_check( 'CERTLOOM_PDF04_PRODUCTION_CANDIDATE' === $config['production_candidate_ack'], 'Bootstrap factory emits the explicit production-candidate acknowledgement.' );
	rpb_check( array('jpeg','pdf','png') === (function($x){$k=array_keys($x);sort($k);return $k;})($config['formats']), 'Bootstrap factory configures exactly PDF, PNG and JPEG.' );
	rpb_check( 'certloom-mpdf-pdf' === $config['formats']['pdf']['renderer']->id() && array('pdf') === $config['formats']['pdf']['renderer']->formats(), 'Bootstrap binds the exact frozen mPDF PDF adapter.' );
	$rformats=$config['formats']['png']['renderer']->formats(); sort($rformats);
	rpb_check( 'certloom-gd-raster' === $config['formats']['png']['renderer']->id() && array('jpeg','png') === $rformats && $config['formats']['png']['renderer'] === $config['formats']['jpeg']['renderer'], 'Bootstrap binds one exact frozen GD renderer to both raster formats.' );
	rpb_check( $setup['font_manifest'] === $config['formats']['pdf']['renderer']->context()['font_manifest'], 'Bootstrap reuses the current 11-fixture acceptance font manifest exactly, including the distinct weight-600 face.' );
	$pdf_manifest=(new RendererCandidateManifest())->normalize(json_decode(file_get_contents($pdf_freeze.'/candidate.json'),true));
	rpb_check( hash_equals((new RendererCandidateManifest())->hash($pdf_manifest),$config['formats']['pdf']['renderer']->context()['candidate_manifest_sha256']), 'Bootstrap renderer context binds the exact canonical frozen PDF candidate manifest.' );

	$bad_link=$tmp.'/font-link';
	if ( function_exists( 'symlink' ) && @symlink( $font_root, $bad_link ) ) {
		rpb_check( rpb_throws(function()use($factory,$pdf_freeze,$gd_freeze,$bad_link,$temp){$factory->build(array('pdf_freeze_dir'=>$pdf_freeze,'raster_freeze_dir'=>$gd_freeze,'font_root'=>$bad_link,'mpdf_temp_dir'=>$temp));}), 'Bootstrap rejects a symbolic exact-font root before candidate production.' );
		certloom_test_remove_fixture_tree( $bad_link, $tmp );
	} else { echo "SKIP  Font-root symbolic-link probe requires local symlink permission.\n"; }
	$bad_temp=$tmp.'/temp-link';
	if ( function_exists( 'symlink' ) && @symlink( $temp, $bad_temp ) ) {
		rpb_check( rpb_throws(function()use($factory,$pdf_freeze,$gd_freeze,$font_root,$bad_temp){$factory->build(array('pdf_freeze_dir'=>$pdf_freeze,'raster_freeze_dir'=>$gd_freeze,'font_root'=>$font_root,'mpdf_temp_dir'=>$bad_temp));}), 'Bootstrap rejects a symbolic mPDF temp directory.' );
		certloom_test_remove_fixture_tree( $bad_temp, $tmp );
	} else { echo "SKIP  Temp-directory symbolic-link probe requires local symlink permission.\n"; }
} finally { certloom_test_remove_fixture_tree( $tmp, $tmp ); }
if ( $failures ) { fwrite( STDERR, "\n{$checks} checks, {$failures} failures\n" ); exit( 1 ); }
echo "\n{$checks} checks, 0 failures\n";
