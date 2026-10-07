<?php
/** Emits an actual PHP-generated logo QR SVG for independent scan acceptance. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define('ABSPATH',__DIR__.'/');
class WP_Error { public function __construct($c,$m){} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function sanitize_html_class($v){return (string)$v;}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function absint($v){return abs((int)$v);}
function wp_parse_url($v){return parse_url($v);}
class Ecapla_CertVerify_Settings { public static function get($k,$d=''){return false;} }
require dirname(__DIR__).'/src/Application/Contract/QrEncoderInterface.php';
require dirname(__DIR__).'/src/Infrastructure/Rendering/LocalQrEncoder.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-qr.php';
$url=isset($argv[1])?(string)$argv[1]:'';
$path=__DIR__.'/fixtures/qr-logo.png';
$bytes=file_get_contents($path);
$asset=array('data_uri'=>'data:image/png;base64,'.base64_encode($bytes),'sha256'=>hash('sha256',$bytes),'mime'=>'image/png','bytes'=>strlen($bytes),'width_px'=>96,'height_px'=>96);
$svg=Ecapla_CertVerify_QR::svg($url,256,$asset);
if(is_wp_error($svg)){fwrite(STDERR,"QR error\n");exit(2);} echo $svg;
