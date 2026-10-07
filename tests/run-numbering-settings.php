<?php
/** Canonical Settings round-trip checks for ID-02 numbering policy. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/support/option-database.php';
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
function wp_cache_delete($key,$group=''){return true;}
function update_option($k,$v,$autoload=null){$GLOBALS['settings_options'][$k]=$v;return true;}
class NumberingSettingsWpdb {
 public $options='wp_options'; public $last_error='';
 public function prepare($sql,...$args){return ['query'=>$sql,'args'=>$args];}
 public function query($sql){$this->last_error='';return 0;}
 public function get_var($sql){$this->last_error='';if(strpos($sql['query'],'SELECT ENGINE')===0){return 'InnoDB';}return ($sql['args'][1]??'')==='certloom_domain_write_fence'?'1':null;}
 public function get_results($sql,$format){return certloom_test_option_rows($sql,$GLOBALS['settings_options']);}
}
$GLOBALS['wpdb']=new NumberingSettingsWpdb();
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

$random=$saved;$random['numbering_mode']='random';
scheck(Ecapla_CertVerify_Settings::save($random),'Random human-number strategy saves through canonical Settings model');
$random_saved=get_option(Ecapla_CertVerify_Settings::OPTION_KEY,array());
scheck($random_saved['numbering_mode']==='random','Random strategy round-trips without changing sequential policy fields');
scheck(get_option(Ecapla_CertVerify_Numbering::STATE_OPTION,null)==='700','switching to Random does not reset durable sequential cursor');
$uuid=$random_saved;$uuid['numbering_mode']='uuid';
scheck(Ecapla_CertVerify_Settings::save($uuid),'UUID human-number strategy saves through canonical Settings model');
$uuid_saved=get_option(Ecapla_CertVerify_Settings::OPTION_KEY,array());
scheck($uuid_saved['numbering_mode']==='uuid','UUID strategy round-trips through the strict settings whitelist');
scheck(get_option(Ecapla_CertVerify_Numbering::STATE_OPTION,null)==='700','switching to UUID does not reset durable sequential cursor');
scheck($defaults['verification_qr_enabled']===true,'local verification QR is enabled by default for existing/current-authority presentation');
$qr_off=$uuid_saved;$qr_off['verification_qr_enabled']=false;
scheck(Ecapla_CertVerify_Settings::save($qr_off),'local verification QR can be disabled through canonical Settings model');
$qr_off_saved=get_option(Ecapla_CertVerify_Settings::OPTION_KEY,array());
scheck($qr_off_saved['verification_qr_enabled']===false,'disabled QR visibility round-trips through the strict settings whitelist');
$qr_on=$qr_off_saved;$qr_on['verification_qr_enabled']=true;
scheck(Ecapla_CertVerify_Settings::save($qr_on),'local verification QR can be re-enabled through canonical Settings model');
$qr_on_saved=get_option(Ecapla_CertVerify_Settings::OPTION_KEY,array());
scheck($qr_on_saved['verification_qr_enabled']===true,'enabled QR visibility round-trips through canonical Settings persistence');

scheck($defaults['certificate_layout']==='right'&&$defaults['certificate_center_width']===960,'one site-wide public appearance defaults to certificate right at a bounded centered width');
$layout=$qr_on_saved;$layout['certificate_layout']='center';$layout['certificate_center_width']='840';
scheck(Ecapla_CertVerify_Settings::save($layout),'global certificate appearance saves through the canonical settings guard');
scheck(Ecapla_CertVerify_Settings::get('certificate_layout')==='center'&&Ecapla_CertVerify_Settings::get('certificate_center_width')===840,'center position and width round-trip');
unset($layout['certificate_layout'],$layout['certificate_center_width']);
scheck(Ecapla_CertVerify_Settings::save($layout)&&Ecapla_CertVerify_Settings::get('certificate_layout')==='center'&&Ecapla_CertVerify_Settings::get('certificate_center_width')===840,'unrelated settings callers preserve the current certificate appearance');
$layout['certificate_layout']='<script>'; $layout['certificate_center_width']='99999';
scheck(Ecapla_CertVerify_Settings::save($layout)&&Ecapla_CertVerify_Settings::get('certificate_layout')==='right'&&Ecapla_CertVerify_Settings::get('certificate_center_width')===1400,'unsupported layout and excessive width cannot reach public styles');
$layout['certificate_layout']='left';$layout['certificate_center_width']='-1';
scheck(Ecapla_CertVerify_Settings::save($layout)&&Ecapla_CertVerify_Settings::get('certificate_layout')==='left'&&Ecapla_CertVerify_Settings::get('certificate_center_width')===320,'left position is global and negative width is clamped');
$layout['certificate_center_width']=array('bad');
scheck(Ecapla_CertVerify_Settings::save($layout)&&Ecapla_CertVerify_Settings::get('certificate_center_width')===960,'non-numeric width receives a safe numeric default');
echo "\n{$sc} checks, 0 failures\n";
