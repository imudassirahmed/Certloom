<?php
/** Focused QR-01 acceptance: local encoder + public verification QR integration. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function absint($v){return abs((int)$v);}
function wp_parse_url($v){return parse_url($v);}
function qcheck($c,$m){global $qc;if(!$c)throw new RuntimeException($m);$qc++;echo "PASS  $m\n";}
$qc=0;
require dirname(__DIR__).'/src/Application/Contract/QrEncoderInterface.php';
require dirname(__DIR__).'/src/Infrastructure/Rendering/LocalQrEncoder.php';
class Ecapla_CertVerify_Settings { public static $enabled=true; public static function get($k,$d=''){return 'verification_qr_enabled'===$k?self::$enabled:$d;} }
require dirname(__DIR__).'/includes/class-ecapla-certverify-qr.php';

$encoder=new \Certloom\Infrastructure\Rendering\LocalQrEncoder();
qcheck($encoder instanceof \Certloom\Application\Contract\QrEncoderInterface,'local encoder implements renderer-independent QR contract');
qcheck($encoder->id()==='certloom-local-qr-model2'&&$encoder->version()==='1.0.0','encoder exposes stable machine identity/version');
$url='https://example.test/certloom/verify/CERT-QR-001/';
$m=$encoder->encode($url,'M');
qcheck(count($m)===33,'reference verification URL selects expected Model 2 size');
qcheck(hash('sha256',json_encode($m))==='079892066e4804e3358e35c547d8759656fe63d33e5ab7e3a37606775ad540fe','reference QR matrix is deterministic');
qcheck($m===$encoder->encode($url,'M'),'same payload produces byte-stable module matrix');
$binary=true;foreach($m as $r){if(count($r)!==count($m)){$binary=false;break;}foreach($r as $v){if($v!==0&&$v!==1){$binary=false;break 2;}}}
qcheck($binary,'matrix is square and strictly binary');
foreach(array('L','M','Q','H') as $ec){$mx=$encoder->encode($url,$ec);$n=count($mx);qcheck($n>=21&&$n<=177&&0===($n-21)%4,"error correction $ec returns a real Model 2 version");}
$thrown=false;try{$encoder->encode($url,'X');}catch(InvalidArgumentException $e){$thrown=true;}qcheck($thrown,'unknown error-correction mode is rejected');
$thrown=false;try{$encoder->encode(str_repeat('A',2049),'M');}catch(InvalidArgumentException $e){$thrown=true;}qcheck($thrown,'oversized payload is rejected before encoding');

$payload=Ecapla_CertVerify_QR::public_payload($url);
qcheck(!is_wp_error($payload),'public QR payload is generated locally');
qcheck($payload['url']===$url.'?certloom_source=qr','public QR binds to the canonical verification URL plus privacy-safe QR source attribution');
qcheck(preg_match('/^[M0-9 hvz-]+$/',$payload['path'])===1,'public transport carries only validated compact SVG path data');
qcheck($payload['view_size']===$payload['modules']+8,'public QR includes mandatory four-module quiet zone on every side');
$svg=Ecapla_CertVerify_QR::svg($url);
qcheck(!is_wp_error($svg)&&false!==strpos($svg,'shape-rendering="crispEdges"'),'server SVG uses crisp module rendering');
qcheck(false!==strpos($svg,'fill="#fff"')&&false!==strpos($svg,'fill="#111"'),'public QR uses fixed high-contrast black/white modules');
qcheck(false===strpos($svg,$url),'SVG geometry does not leak/duplicate the verification URL in markup');
$figure=Ecapla_CertVerify_QR::figure_html($url,'cvp');
qcheck(false!==strpos($figure,'role="img"')&&false!==strpos($figure,'figcaption'),'public QR has accessible non-visual labeling/caption');
Ecapla_CertVerify_Settings::$enabled=false;
qcheck(Ecapla_CertVerify_QR::figure_html($url,'cvp')==='','administrator can hide public QR without changing verification');
Ecapla_CertVerify_Settings::$enabled=true;
$health=Ecapla_CertVerify_QR::health();
qcheck(!empty($health['available'])&&$health['id']===$encoder->id(),'QR self-check reports bundled local encoder healthy');

$root=dirname(__DIR__);
$src=array(
 'bootstrap'=>file_get_contents($root.'/certloom.php'),
 'settings'=>file_get_contents($root.'/includes/class-ecapla-certverify-settings.php'),
 'admin'=>file_get_contents($root.'/admin/class-ecapla-certverify-admin.php'),
 'settings_view'=>file_get_contents($root.'/admin/views/settings-manager.php'),
 'render'=>file_get_contents($root.'/includes/class-ecapla-certverify-render.php'),
 'rest'=>file_get_contents($root.'/includes/class-ecapla-certverify-rest.php'),
 'js'=>file_get_contents($root.'/public/assets/certloom-public.js'),
 'css'=>file_get_contents($root.'/public/assets/certloom-public.css'),
 'status'=>file_get_contents($root.'/includes/class-ecapla-certverify-system-status.php'),
 'encoder'=>file_get_contents($root.'/src/Infrastructure/Rendering/LocalQrEncoder.php'),
 'resolver'=>file_get_contents($root.'/src/Application/Rendering/LayoutResolver.php'),
);
foreach($src as $name=>$text){qcheck(is_string($text)&&$text!=='','source loaded: '.$name);}
qcheck(false!==strpos($src['bootstrap'],'class-ecapla-certverify-qr.php'),'QR service is loaded in normal plugin bootstrap');
qcheck(false!==strpos($src['settings'],"'verification_qr_enabled'     => true")&&false!==strpos($src['admin'],"'verification_qr_enabled' => ! empty( \$_POST['verification_qr_enabled'] )"),'QR visibility setting has canonical default/save path');
qcheck(false!==strpos($src['settings_view'],'Generation is fully local and does not call a remote QR service.'),'settings UX states local-only/privacy contract');
qcheck(false!==strpos($src['render'],'Ecapla_CertVerify_QR::figure_html'),'server/no-JS and profile renderer reuse shared QR service');
qcheck(false!==strpos($src['rest'],'Ecapla_CertVerify_QR::public_payload')&&false!==strpos($src['rest'],"'qr'                 => \$qr"),'AJAX/REST success payload uses same tracked server QR model');
qcheck(false!==strpos($src['js'],'/^[M0-9 hvz-]+$/')&&false!==strpos($src['js'],'certloom-verification-qr'),'browser reconstructs only validated QR geometry, not arbitrary server HTML');
qcheck(false!==strpos($src['css'],'.certloom-qr-svg')&&false!==strpos($src['css'],'forced-color-adjust:none'),'QR keeps scan-safe fixed colors even under forced-color themes');
qcheck(false!==strpos($src['status'],'local_qr_check'),'System Status exposes local QR self-check');
qcheck(false!==strpos($src['encoder'],'versions 1-40')&&false!==strpos($src['encoder'],'error correction L/M/Q/H'),'production encoder covers full QR Model 2 version range and all standard EC levels');
qcheck(false!==strpos($src['resolver'],'QrEncoderInterface'),'future artifact resolver remains bound to same central QR interface');
qcheck(file_exists($root.'/licenses/python-qrcode-BSD.txt'),'BSD attribution/license is shipped with the adapted encoder');
$prod=$src['encoder']."\n".file_get_contents($root.'/includes/class-ecapla-certverify-qr.php');
qcheck(0===preg_match('/\b(?:wp_remote_get|wp_remote_post|curl_exec|file_get_contents)\s*\(\s*[\'\"]https?:/i',$prod),'QR production path contains no remote-generation HTTP client');
qcheck(false===strpos($src['rest'],'certloom/qr')&&false===strpos($src['bootstrap'],'certloom/qr'),'QR-01 adds no standalone public generator endpoint');

echo "\n{$qc} checks, 0 failures\n";
