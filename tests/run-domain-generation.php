<?php
/** Pure tests for pre-mutation domain generation safety. */
$GLOBALS['dg_options']=array(); $GLOBALS['dg_fail']=false;
if(!class_exists('WP_Error')){class WP_Error{private $c;function __construct($c=''){ $this->c=$c;} function get_error_code(){return $this->c;}}}
function is_wp_error($v){return $v instanceof WP_Error;} function __($t){return $t;} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['dg_options'])?$GLOBALS['dg_options'][$k]:$d;} function update_option($k,$v,$autoload=false){if($GLOBALS['dg_fail'])return false;$GLOBALS['dg_options'][$k]=$v;return true;}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Data\DomainGeneration;
function dg_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$g=new DomainGeneration(); dg_same('0',$g->current(),'default'); dg_same(true,$g->mark_before_mutation(),'mark'); $one=$g->current(); dg_same(false,'0'===$one,'changed'); usleep(2); dg_same(true,$g->mark_before_mutation(),'mark2'); dg_same(false,$one===$g->current(),'unique'); echo "PASS  generation advances before mutations\n";
$GLOBALS['dg_fail']=true; $r=$g->mark_before_mutation(); dg_same('certloom_domain_generation_save_failed',$r->get_error_code(),'fail closed'); echo "PASS  mutation marker failure is fail-closed\n\n2 tests, 0 failures\n";
