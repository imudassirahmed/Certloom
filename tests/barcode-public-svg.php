<?php
/** Emits the exact Code 128 SVG used by the public server/no-JS figure. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define('ABSPATH',__DIR__.'/');
class WP_Error { public function __construct($c,$m){} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function sanitize_html_class($v){return (string)$v;}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function absint($v){return abs((int)$v);}
class Ecapla_CertVerify_Settings { public static function get($k,$d=''){return true;} }
require dirname(__DIR__).'/src/Application/Contract/BarcodeEncoderInterface.php';
require dirname(__DIR__).'/src/Infrastructure/Rendering/LocalCode128Encoder.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-barcode.php';
$value=isset($argv[1])?(string)$argv[1]:'';
$html=Ecapla_CertVerify_Barcode::figure_html($value,'cv');
if(''===$html||!preg_match('#<svg\b[^>]*class="certloom-barcode-svg".*?</svg>#s',$html,$match)){fwrite(STDERR,"Barcode error\n");exit(2);} echo $match[0];
