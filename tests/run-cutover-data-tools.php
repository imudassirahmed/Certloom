<?php
/** Pure test: final cutover is blocked until non-admin data tools are domain-aware. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', true );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
$GLOBALS['cut_data_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c; $this->message=$m; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace('/[^a-z0-9_\-]/','',(string)$v) ); }
function get_option( $k, $d=false ) { return array_key_exists($k,$GLOBALS['cut_data_options']) ? $GLOBALS['cut_data_options'][$k] : $d; }
function update_option( $k, $v ) { $GLOBALS['cut_data_options'][$k]=$v; return true; }
function delete_option( $k ) { unset($GLOBALS['cut_data_options'][$k]); return true; }
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\LegacySourceSnapshotInterface;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Migration\DomainCutoverCoordinator;
class CutDataSource implements LegacySourceSnapshotInterface { public function snapshot(){ return array('count'=>1,'max_id'=>1,'generation'=>'g'); } }
$GLOBALS['cut_data_options'][LegacyMigrationState::OPTION] = array('status'=>LegacyMigrationState::COMPLETE,'source_count'=>1,'source_max_id'=>1,'source_generation'=>'g');
$result=(new DomainCutoverCoordinator(new CutDataSource(), null, null, null, function(){return true;}))->promote();
if(!is_object($result)||'certloom_domain_cutover_data_tools_not_ready'!==$result->get_error_code()){ throw new RuntimeException('Expected data-tools cutover gate'); }
echo "PASS  source-of-truth cutover requires domain-aware import/backup/data tools\n\n1 tests, 0 failures\n";
