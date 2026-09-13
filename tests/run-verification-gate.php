<?php
/** Operational-readiness tests for normalized public Credential Series verification. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['vg_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c; $this->message=$m; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs((int)$v); }
function sanitize_key( $k ) { return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$k)); }
function get_option( $k, $d=false ) { return array_key_exists($k,$GLOBALS['vg_options']) ? $GLOBALS['vg_options'][$k] : $d; }
function update_option( $k, $v ) { $GLOBALS['vg_options'][$k]=$v; return true; }
function delete_option( $k ) { if (array_key_exists($k,$GLOBALS['vg_options'])) { unset($GLOBALS['vg_options'][$k]); return true; } return false; }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Verification\PublicVerificationGate;

function vg_same($e,$a,$m=''){ if($e!==$a){ throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true)); } }
$schema_checks=0;
$schema_result=true;
$gate = new PublicVerificationGate(function($refresh=true) use (&$schema_checks,&$schema_result){ $schema_checks++; return $schema_result; });

vg_same(false,$gate->is_ready(),'missing normalized schema is a real public-read blocker');
$result=$gate->enable();
vg_same('certloom_series_verification_schema_incomplete',$result->get_error_code(),'readiness probe explains missing schema');

$GLOBALS['vg_options'][SchemaManager::SCHEMA_OPTION] = SchemaManager::SCHEMA_VERSION;
$GLOBALS['vg_options'][SchemaManager::HEALTH_OPTION] = array('version'=>SchemaManager::SCHEMA_VERSION,'checked_at'=>time());
vg_same(true,$gate->is_ready(),'healthy current schema opens public verification without migration/cutover ceremony');
vg_same(0,$schema_checks,'fresh health marker avoids unnecessary live schema probe');

/* Historical marker no longer grants or revokes public read availability. */
$GLOBALS['vg_options'][PublicVerificationGate::OPTION]=0;
vg_same(true,$gate->is_ready(),'legacy public-ready option cannot close healthy verification');
vg_same(true,$gate->disable(),'retired marker can be cleaned up');
vg_same(true,$gate->is_ready(),'removing retired marker still does not close healthy verification');

/* Stale/missing health is re-proved live. */
$GLOBALS['vg_options'][SchemaManager::HEALTH_OPTION] = array('version'=>SchemaManager::SCHEMA_VERSION,'checked_at'=>1);
vg_same(true,$gate->is_ready(),'stale marker triggers live health proof and remains available when healthy');
vg_same(1,$schema_checks,'one live schema proof executed');
$schema_result = new WP_Error('schema_bad','bad');
vg_same(false,$gate->is_ready(),'failed live schema proof closes public verification');
vg_same(2,$schema_checks,'failed proof was actually executed');
$schema_result = true;
vg_same(true,$gate->enable(),'compatibility readiness probe succeeds without migration/cutover authority');
vg_same(3,$schema_checks,'enable performs a live schema proof');

/* Maintenance remains a genuine fail-closed boundary. */
$GLOBALS['vg_options'][DomainMaintenanceLock::OPTION] = 'corrupt-maintenance-payload';
vg_same(false,$gate->is_ready(),'active/corrupt maintenance disables public verification');
$result=$gate->enable();
vg_same('certloom_series_verification_maintenance_active',$result->get_error_code(),'readiness probe refuses active maintenance');
unset($GLOBALS['vg_options'][DomainMaintenanceLock::OPTION]);
$GLOBALS['vg_options'][SchemaManager::HEALTH_OPTION] = array('version'=>SchemaManager::SCHEMA_VERSION,'checked_at'=>time());
vg_same(true,$gate->is_ready(),'verification recovers automatically when maintenance clears');

echo "PASS  public Series verification follows real credential-store readiness and no longer waits for migration/cutover ceremony\n\n1 tests, 0 failures\n";
