<?php
/** Pure option-state tests for the hard public Series-verification cutover gate. */
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
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Verification\PublicVerificationGate;

function vg_same($e,$a,$m=''){ if($e!==$a){ throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true)); } }
$schema_checks=0;
$gate = new PublicVerificationGate(function() use (&$schema_checks){ $schema_checks++; return true; });
vg_same(false,$gate->is_ready(),'gate off by default');
$result = $gate->enable();
vg_same('certloom_series_verification_migration_incomplete',$result->get_error_code());

$GLOBALS['vg_options'][LegacyMigrationState::OPTION] = array('status'=>LegacyMigrationState::COMPLETE);
$GLOBALS['vg_options'][SchemaManager::SCHEMA_OPTION] = SchemaManager::SCHEMA_VERSION;
$GLOBALS['vg_options'][SchemaManager::HEALTH_OPTION] = array('version'=>SchemaManager::SCHEMA_VERSION,'checked_at'=>time());
$result = $gate->enable();
vg_same('certloom_series_verification_write_authority_legacy',$result->get_error_code());

$GLOBALS['vg_options'][DomainCutoverState::OPTION] = DomainCutoverState::DOMAIN;
$result = $gate->enable();
vg_same('certloom_series_verification_cutover_receipt_missing',$result->get_error_code(),'manual authority option is not enough');
$GLOBALS['vg_options'][DomainCutoverState::RECEIPT_OPTION] = array(
	'state'=>'committed','authority'=>DomainCutoverState::DOMAIN,'completed_at'=>time(),
	'source_generation'=>'g','schema_version'=>SchemaManager::SCHEMA_VERSION,
);
vg_same(true,$gate->enable(),'official cutover receipt permits enable');
vg_same(1,$schema_checks,'enable performs one live schema check');
vg_same(true,$gate->is_ready());

/* Maintenance makes public domain verification fail closed even after cutover. */
$GLOBALS['vg_options'][DomainMaintenanceLock::OPTION] = 'corrupt-maintenance-payload';
vg_same(false,$gate->is_ready(),'corrupt/active maintenance lock disables public domain verification');
$result=$gate->enable();
vg_same('certloom_series_verification_maintenance_active',$result->get_error_code(),'cannot enable while maintenance active');
unset($GLOBALS['vg_options'][DomainMaintenanceLock::OPTION]);
vg_same(true,$gate->is_ready(),'verification recovers after maintenance boundary clears');

vg_same(true,$gate->disable());
vg_same(false,$gate->is_ready());
echo "PASS  public Series verification requires committed cutover + live schema proof and fails closed during maintenance\n\n1 tests, 0 failures\n";
