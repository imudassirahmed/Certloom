<?php
/** Canonical Settings round-trip checks for ID-02 numbering policy. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_hex_color($v){$v=(string)$v;return preg_match('/^#[0-9a-fA-F]{6}$/',$v)?strtolower($v):null;}
function absint($v){return abs((int)$v);}
function wp_parse_args($a,$b=array()){return array_merge($b,is_array($a)?$a:array());}
$GLOBALS['settings_options']=array();
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['settings_options'])?$GLOBALS['settings_options'][$k]:$d;}
function update_option($k,$v,$autoload=null){$GLOBALS['settings_options'][$k]=$v;return true;}
class Ecapla_CertVerify_Backup { public static $reserved=0; public static function assert_writes_allowed(){return true;} public static function reserve_mutation_generation(){self::$reserved++;return true;} }
function scheck($c,$m){global $sc;if(!$c)throw new RuntimeException($m);$sc++;echo "PASS  $m\n";}
$sc=0;
require dirname(__DIR__).'/includes/class-ecapla-certverify-settings.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-numbering.php';
$defaults=Ecapla_CertVerify_Settings::defaults();
scheck($defaults['numbering_mode']==='manual','Settings upgrade default remains manual');
scheck($defaults['numbering_prefix']==='CERT-'&&$defaults['numbering_start']===1,'Settings owns deterministic numbering defaults');
$input=$defaults;
$input['numbering_mode']='sequential';$input['numbering_prefix']='ACME-';$input['numbering_suffix']='-2026';$input['numbering_start']='500';$input['numbering_padding']='8';
scheck(Ecapla_CertVerify_Settings::save($input),'valid numbering settings save through canonical whitelist');
$saved=get_option(Ecapla_CertVerify_Settings::OPTION_KEY,array());
scheck($saved['numbering_mode']==='sequential'&&$saved['numbering_prefix']==='ACME-'&&$saved['numbering_suffix']==='-2026','mode/prefix/suffix round-trip');
scheck($saved['numbering_start']===500&&$saved['numbering_padding']===8,'start/padding persist as normalized integers');
scheck(Ecapla_CertVerify_Backup::$reserved===1,'settings mutation reserves generation boundary once');
$before=$saved;$bad=$saved;$bad['numbering_prefix']='BAD PREFIX';
scheck(!Ecapla_CertVerify_Settings::save($bad),'invalid numbering policy is rejected by Settings model');
scheck(get_option(Ecapla_CertVerify_Settings::OPTION_KEY,array())===$before,'invalid policy leaves stored settings untouched');
scheck(Ecapla_CertVerify_Backup::$reserved===1,'invalid policy consumes no mutation generation');
$GLOBALS['settings_options'][Ecapla_CertVerify_Numbering::STATE_OPTION]='700';
$raised=$saved;$raised['numbering_start']='900';
scheck(Ecapla_CertVerify_Settings::save($raised),'raising configured sequence floor saves');
scheck(get_option(Ecapla_CertVerify_Numbering::STATE_OPTION,null)==='700','settings save never rewrites durable allocation cursor');
scheck(Ecapla_CertVerify_Numbering::current_next_sequence()===900,'read-time next sequence applies raised floor without mutating cursor');
echo "\n{$sc} checks, 0 failures\n";
