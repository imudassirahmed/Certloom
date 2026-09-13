<?php
if ( $argc < 2 ) { fwrite( STDERR, "usage: php qr-public-svg.php <url>\n" ); exit(2); }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function absint($v){return abs((int)$v);}
function wp_parse_url($v){return parse_url($v);}
class Ecapla_CertVerify_Settings { public static function get($k,$d=''){return true;} }
require dirname(__DIR__).'/src/Application/Contract/QrEncoderInterface.php';
require dirname(__DIR__).'/src/Infrastructure/Rendering/LocalQrEncoder.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-qr.php';
$svg=Ecapla_CertVerify_QR::svg($argv[1],256);
if(is_wp_error($svg)){fwrite(STDERR,$svg->get_error_message()."\n");exit(1);} echo $svg;
