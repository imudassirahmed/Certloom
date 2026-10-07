<?php
/** Pure tests for pre-mutation domain generation safety. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$GLOBALS['dg_options']=array(); $GLOBALS['dg_fail']=false;
if(!class_exists('WP_Error')){class WP_Error{private $c;function __construct($c=''){ $this->c=$c;} function get_error_code(){return $this->c;}}}
function is_wp_error($v){return $v instanceof WP_Error;} function __($t){return $t;} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['dg_options'])?$GLOBALS['dg_options'][$k]:$d;} function update_option($k,$v,$autoload=false){if($GLOBALS['dg_fail'])return false;$GLOBALS['dg_options'][$k]=$v;return true;}
final class DGWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public $read_error = false;
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $prepared ) {
		$this->last_error = $this->read_error ? 'read failed' : '';
		list( $query, $args ) = $prepared;
		if ( strpos( $query, 'SELECT option_value' ) === 0 ) { return $GLOBALS['dg_options'][ $args[1] ] ?? null; }
		return null;
	}
}
$wpdb = new DGWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Data\DomainGeneration;
function dg_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$g=new DomainGeneration(); dg_same('0',$g->current(),'default'); dg_same(true,$g->mark_before_mutation(),'mark'); $one=$g->current(); dg_same(false,'0'===$one,'changed'); usleep(2); dg_same(true,$g->mark_before_mutation(),'mark2'); dg_same(false,$one===$g->current(),'unique'); echo "PASS  generation advances before mutations\n";
$GLOBALS['dg_fail']=true; $r=$g->mark_before_mutation(); dg_same('certloom_domain_generation_save_failed',$r->get_error_code(),'fail closed'); echo "PASS  mutation marker failure is fail-closed\n";
dg_same( $g->current(), $g->read_current(), 'fresh database generation' );
$wpdb->read_error = true;
dg_same( 'certloom_domain_generation_read_failed', $g->read_current()->get_error_code(), 'fresh read error cannot masquerade as missing marker' );
$wpdb->read_error = false; unset( $GLOBALS['dg_options']['certloom_domain_data_generation'] );
dg_same( '0', $g->read_current(), 'fresh absent marker' );
$GLOBALS['dg_options']['certloom_domain_data_generation'] = '';
dg_same( 'certloom_domain_generation_read_failed', $g->read_current()->get_error_code(), 'empty corrupt marker' );
echo "PASS  fresh generation reads reject database errors and corrupt empty markers\n\n3 tests, 0 failures\n";
