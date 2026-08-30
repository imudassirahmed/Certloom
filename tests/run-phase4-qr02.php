<?php
/** Focused QR-02 acceptance: bounded center-logo QR + local Code 128. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function absint($v){return abs((int)$v);}
function wp_parse_url($v){return parse_url($v);}
$GLOBALS['qr02_site_icon']=77;
$GLOBALS['qr02_attached_file']=__DIR__.'/fixtures/qr-logo.png';
function get_option($key,$default=false){return 'site_icon'===$key?$GLOBALS['qr02_site_icon']:$default;}
function get_attached_file($id,$unfiltered=false){return $GLOBALS['qr02_attached_file'];}
function wp_upload_dir($time=null,$create=true){return array('basedir'=>realpath(__DIR__.'/fixtures'),'error'=>false);}
function wp_get_attachment_metadata($id){return array();}
function q2check($c,$m){global $q2c;if(!$c)throw new RuntimeException($m);$q2c++;echo "PASS  $m\n";}
$q2c=0;

require dirname(__DIR__).'/src/Application/Contract/QrEncoderInterface.php';
require dirname(__DIR__).'/src/Application/Contract/BarcodeEncoderInterface.php';
require dirname(__DIR__).'/src/Infrastructure/Rendering/LocalQrEncoder.php';
require dirname(__DIR__).'/src/Infrastructure/Rendering/LocalCode128Encoder.php';
class Ecapla_CertVerify_Settings {
	public static $qr=true; public static $logo=true; public static $barcode=true;
	public static function get($k,$d=''){
		if('verification_qr_enabled'===$k)return self::$qr;
		if('verification_qr_logo_enabled'===$k)return self::$logo;
		if('verification_barcode_enabled'===$k)return self::$barcode;
		return $d;
	}
}
require dirname(__DIR__).'/includes/class-ecapla-certverify-qr-logo.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-qr.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-barcode.php';

$barcode_encoder=new \Certloom\Infrastructure\Rendering\LocalCode128Encoder();
q2check($barcode_encoder instanceof \Certloom\Application\Contract\BarcodeEncoderInterface,'Code 128 encoder implements renderer-independent barcode contract');
q2check($barcode_encoder->id()==='certloom-local-code128'&&$barcode_encoder->version()==='1.0.0','Code 128 encoder exposes stable machine identity/version');
$modules=$barcode_encoder->encode('CERT-2026-000001');
q2check(count($modules)>=46&&count($modules)<=760,'typical Certloom number produces bounded Code 128 module sequence');
q2check($modules===$barcode_encoder->encode('CERT-2026-000001'),'Code 128 module output is deterministic');
q2check($barcode_encoder->encode('123456789012')!==$barcode_encoder->encode('ABCDEFGHIJKL'),'different identifiers produce distinct Code 128 symbols');
q2check(count($barcode_encoder->encode('123456789012'))<count($barcode_encoder->encode('ABCDEFGHIJKL')),'long numeric runs use compact Code Set C');
$binary=true;foreach($modules as $v){if($v!==0&&$v!==1){$binary=false;break;}}q2check($binary&&$modules[0]===1&&$modules[count($modules)-1]===1,'Code 128 modules are strictly binary with valid dark boundaries');
foreach(array('',"CERT\n1",'اردو-001',str_repeat('A',101)) as $bad){$thrown=false;try{$barcode_encoder->encode($bad);}catch(InvalidArgumentException $e){$thrown=true;}q2check($thrown,'unsupported Code 128 input fails closed');}

$barcode=Ecapla_CertVerify_Barcode::public_payload('CERT-2026-000001');
q2check(!is_wp_error($barcode),'public Code 128 payload is generated locally');
q2check($barcode['value']==='CERT-2026-000001','barcode represents exact existing human certificate number');
q2check(1===preg_match('/^[M0-9 hvz-]+$/D',$barcode['path'])&&$barcode['view_width']===$barcode['modules']+20&&$barcode['display_width']>=440,'barcode transport is validated compact geometry with ten-module quiet zones and scan-safe display width');
$barcode_svg=Ecapla_CertVerify_Barcode::svg('CERT-2026-000001');
q2check(!is_wp_error($barcode_svg)&&false!==strpos($barcode_svg,'certloom-barcode-svg')&&false!==strpos($barcode_svg,'shape-rendering="crispEdges"'),'server Code 128 SVG is crisp local geometry');
q2check(false===strpos($barcode_svg,'CERT-2026-000001'),'barcode SVG geometry does not duplicate identifier as hidden markup');
$barcode_figure=Ecapla_CertVerify_Barcode::figure_html('CERT-2026-000001','cvp');
q2check(false!==strpos($barcode_figure,'role="img"')&&false!==strpos($barcode_figure,'<code>CERT-2026-000001</code>'),'barcode figure has accessible labeling and visible human-readable identifier');
q2check(is_wp_error(Ecapla_CertVerify_Barcode::public_payload('اردو-001')),'Unicode manual certificate numbers fail soft instead of creating a lossy barcode');
$barcode_health=Ecapla_CertVerify_Barcode::health();
q2check(!empty($barcode_health['available'])&&$barcode_health['id']===$barcode_encoder->id(),'Code 128 System Status self-check is healthy');
Ecapla_CertVerify_Settings::$barcode=false;
q2check(Ecapla_CertVerify_Barcode::figure_html('CERT-2026-000001','cvp')==='','administrator can hide barcode presentation without changing certificate truth');
Ecapla_CertVerify_Settings::$barcode=true;

$asset=Ecapla_CertVerify_QR_Logo::asset();
q2check(!is_wp_error($asset),'QR center-logo resolver accepts verified local WordPress uploads Site Icon');
q2check($asset['mime']==='image/png'&&$asset['width_px']===96&&$asset['height_px']===96,'QR logo resolver re-derives raster type and dimensions');
q2check(hash_file('sha256',__DIR__.'/fixtures/qr-logo.png')===$asset['sha256'],'QR logo binds exact local image bytes by SHA-256');
q2check(0===strpos($asset['data_uri'],'data:image/png;base64,'),'QR logo is embedded as local validated data URI, not a network URL');

$url='https://example.test/certloom/verify/CERT-QR-LOGO-001/';
$qr=Ecapla_CertVerify_QR::public_payload($url);
q2check(!is_wp_error($qr)&&is_array($qr['logo']),'logo-enabled public QR contains bounded logo presentation model');
q2check($qr['error_correction']==='H','logo QR automatically upgrades to high error correction');
q2check($qr['logo']['size']>=5&&$qr['logo']['size']<=11&&$qr['logo']['x']+$qr['logo']['size']<=$qr['view_size'],'logo cutout stays small and inside QR viewBox');
$logo_svg=Ecapla_CertVerify_QR::svg($url,256,$asset);
q2check(false!==strpos($logo_svg,'certloom-qr-logo-image')&&false!==strpos($logo_svg,'data:image/png;base64,'),'server logo QR embeds only validated local raster bytes');
q2check(0===preg_match('#<image[^>]+href=[\"\']https?://#i',$logo_svg),'logo QR SVG contains no remote image dependency');

$GLOBALS['qr02_site_icon']=0; Ecapla_CertVerify_QR_Logo::reset_cache();
$fallback=Ecapla_CertVerify_QR::public_payload($url);
q2check(!is_wp_error($fallback)&&null===$fallback['logo']&&$fallback['error_correction']==='M','missing Site Icon falls back to plain scan-safe QR instead of failing verification');
$GLOBALS['qr02_site_icon']=77; $GLOBALS['qr02_attached_file']='/etc/hosts'; Ecapla_CertVerify_QR_Logo::reset_cache();
q2check(is_wp_error(Ecapla_CertVerify_QR_Logo::asset()),'QR logo resolver rejects attachment paths outside WordPress uploads');
$GLOBALS['qr02_attached_file']=__DIR__.'/fixtures/qr-logo.png'; Ecapla_CertVerify_QR_Logo::reset_cache();

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
 'barcode_encoder'=>file_get_contents($root.'/src/Infrastructure/Rendering/LocalCode128Encoder.php'),
 'barcode_contract'=>file_get_contents($root.'/src/Application/Contract/BarcodeEncoderInterface.php'),
 'qr_logo'=>file_get_contents($root.'/includes/class-ecapla-certverify-qr-logo.php'),
);
foreach($src as $name=>$text){q2check(is_string($text)&&$text!=='','source loaded: '.$name);}
q2check(false!==strpos($src['bootstrap'],'class-ecapla-certverify-qr-logo.php')&&false!==strpos($src['bootstrap'],'class-ecapla-certverify-barcode.php'),'QR-02 services are loaded in normal plugin bootstrap');
q2check(false!==strpos($src['settings'],"'verification_qr_logo_enabled' => false")&&false!==strpos($src['settings'],"'verification_barcode_enabled' => false"),'new visual enhancements are opt-in defaults with canonical settings storage');
q2check(false!==strpos($src['admin'],"'verification_qr_logo_enabled' => ! empty( \$_POST['verification_qr_logo_enabled'] )")&&false!==strpos($src['admin'],"'verification_barcode_enabled' => ! empty( \$_POST['verification_barcode_enabled'] )"),'QR-02 settings have explicit save path');
q2check(false!==strpos($src['settings_view'],'falls back to the plain scan-safe QR')&&false!==strpos($src['settings_view'],'does not create a new lookup route or identifier'),'settings UX states scan-safety and identity boundaries');
q2check(false!==strpos($src['render'],'Ecapla_CertVerify_Barcode::figure_html'),'server/no-JS/profile presentation reuses shared Code 128 service');
q2check(false!==strpos($src['rest'],'Ecapla_CertVerify_Barcode::public_payload')&&false!==strpos($src['rest'],"'barcode'            => \$barcode"),'AJAX/REST payload uses same local barcode model');
q2check(false!==strpos($src['js'],'certloom-barcode-svg')&&false!==strpos($src['js'],'data:image\\/(?:png|jpeg);base64'),'browser reconstructs only validated QR-logo/barcode geometry');
q2check(false!==strpos($src['css'],'.certloom-barcode-svg')&&false!==strpos($src['css'],'forced-color-adjust:none'),'QR-02 symbols preserve scanner-safe colors under forced-color themes');
q2check(false!==strpos($src['status'],'local_barcode_check')&&false!==strpos($src['status'],'logo_state'),'System Status exposes Code 128 health and QR-logo readiness');
q2check(false!==strpos($src['barcode_encoder'],'Code Set C')&&false!==strpos($src['barcode_encoder'],'printable ASCII'),'Code 128 encoder documents exact supported character/optimization contract');
q2check(false!==strpos($src['barcode_contract'],'does not own credential lookup or identifier semantics'),'future renderers share barcode geometry without owning identity semantics');
q2check(false!==strpos($src['qr_logo'],"inside the site's uploads directory")&&false!==strpos($src['qr_logo'],'file_get_contents( $path )'),'QR logo reads only validated local uploads bytes');
$prod=$src['barcode_encoder']."\n".$src['qr_logo']."\n".file_get_contents($root.'/includes/class-ecapla-certverify-barcode.php');
q2check(0===preg_match('/\b(?:wp_remote_get|wp_remote_post|curl_exec)\s*\(/i',$prod),'QR-02 production path introduces no outbound HTTP client');
q2check(false===strpos($src['rest'],'certloom/barcode')&&false===strpos($src['bootstrap'],'certloom/barcode'),'Code 128 adds no standalone public lookup/generator endpoint');
q2check(false===strpos($src['rest'],'barcode_id')&&false===strpos($src['settings'],'barcode_id'),'Code 128 does not create or persist a second credential identifier');

echo "\n{$q2c} checks, 0 failures\n";
