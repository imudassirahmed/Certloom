<?php
/** Backup-format compatibility checks for numbering-state introduction in complete backup v4. */
define('ABSPATH',__DIR__.'/');
define('MINUTE_IN_SECONDS',60);define('MB_IN_BYTES',1048576);
class WP_Error{private $c;private $m;public function __construct($c,$m){$this->c=$c;$this->m=$m;}public function get_error_code(){return $this->c;}public function get_error_message(){return $this->m;}}
function is_wp_error($v){return $v instanceof WP_Error;}function __($v,$d=null){return $v;}function absint($v){return abs((int)$v);}function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}function sanitize_text_field($v){return trim(strip_tags((string)$v));}
class Ecapla_CertVerify_Numbering{const STATE_OPTION='certverify_number_sequence_next';public static function validate_backup_state($state){if($state===null||$state==='')return null;if(is_bool($state)||is_array($state)||is_object($state)||!preg_match('/^[0-9]+$/D',(string)$state))return new WP_Error('bad','bad');$v=(int)$state;return $v>=1&&$v<=1000000000000?$v:new WP_Error('bad','bad');}}
function bcheck($c,$m){global $bc;if(!$c)throw new RuntimeException($m);$bc++;echo "PASS  $m\n";}$bc=0;
require dirname(__DIR__).'/includes/class-ecapla-certverify-backup.php';
$method=new ReflectionMethod('Ecapla_CertVerify_Backup','validate_manifest');$method->setAccessible(true);
$base=array('type'=>'manifest','format'=>'certverify-backup','generated_at'=>'2026-08-23T00:00:00Z','plugin_version'=>'1.6.10','schema_version'=>'7','fields'=>array(),'retired_fields'=>array(),'settings'=>array());
$v2=$base;$v2['format_version']=2;bcheck(true===$method->invoke(null,$v2),'stream v2 backup remains accepted without numbering state');
$v3=$base;$v3['format_version']=3;bcheck(true===$method->invoke(null,$v3),'stream v3 backup remains accepted without numbering state');
$v4=$base;$v4['format_version']=4;$r=$method->invoke(null,$v4);bcheck(is_wp_error($r)&&$r->get_error_code()==='ecapla_certverify_backup_structure','stream v4 requires explicit numbering-state key');
$v4['numbering_state']=null;bcheck(true===$method->invoke(null,$v4),'stream v4 accepts explicit unallocated numbering state');
$v4['numbering_state']=42;bcheck(true===$method->invoke(null,$v4),'stream v4 accepts validated durable numbering state');
$v4['numbering_state']='garbage';bcheck(is_wp_error($method->invoke(null,$v4)),'stream v4 rejects corrupt numbering state');
$v5=$base;$v5['format_version']=5;$v5['numbering_state']=null;bcheck(is_wp_error($method->invoke(null,$v5)),'unknown future complete-backup version fails closed');
$src=file_get_contents(dirname(__DIR__).'/includes/class-ecapla-certverify-backup.php');
bcheck(false!==strpos($src,'self::LEGACY_STREAM_VERSION !== $stream_version'),'v2 keeps legacy no-manifest-hash stream semantics while v3/v4 hash the manifest');
bcheck(false!==strpos($src,"'numbering_state' => get_option( Ecapla_CertVerify_Numbering::STATE_OPTION, null )"),'restore preview marker includes numbering cursor for stale-review detection');
echo "\n{$bc} checks, 0 failures\n";
