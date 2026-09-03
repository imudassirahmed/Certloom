<?php
/** Pure repository PATCH-semantics tests for hidden/omitted field preservation. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
class FakePatchWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $updates = array();
	public function update( $table, $data, $where ) {
		$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}
}
$GLOBALS['wpdb'] = new FakePatchWpdb();
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type, $gmt = false ) { return '2026-08-22 00:00:00'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
$GLOBALS['patch_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['patch_options'] ) ? $GLOBALS['patch_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['patch_options'][ $key ] = $value; return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c; $this->message=$m; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
}

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;

function patch_true( $value, $message = '' ) { if ( true !== $value ) { throw new RuntimeException( $message ?: 'Expected true' ); } }
function patch_false( $value, $message = '' ) { if ( $value ) { throw new RuntimeException( $message ?: 'Expected false' ); } }

$recipient = new RecipientRepository();
patch_true( $recipient->update( 7, array( 'display_name' => 'Updated Name' ) ), 'recipient patch succeeds' );
$r = $GLOBALS['wpdb']->updates[0]['data'];
patch_true( array_key_exists( 'display_name', $r ), 'submitted recipient field written' );
patch_true( array_key_exists( 'updated_at', $r ), 'recipient timestamp written' );
patch_false( array_key_exists( 'email', $r ), 'omitted email preserved' );
patch_false( array_key_exists( 'custom_fields', $r ), 'omitted custom fields preserved' );
patch_false( array_key_exists( 'external_id', $r ), 'omitted external ID preserved' );

$program = new ProgramRepository();
patch_true( $program->update( 3, array( 'title' => 'New Program Title', 'active' => false ) ), 'program patch succeeds' );
$p = $GLOBALS['wpdb']->updates[1]['data'];
patch_true( array_key_exists( 'title', $p ), 'submitted program title written' );
patch_true( array_key_exists( 'active', $p ) && 0 === $p['active'], 'explicit false active written' );
patch_false( array_key_exists( 'code', $p ), 'omitted code preserved' );
patch_false( array_key_exists( 'metadata_json', $p ), 'omitted metadata preserved' );
patch_false( array_key_exists( 'validity_days', $p ), 'omitted validity preserved' );

$issuer = new IssuerRepository();
patch_true( $issuer->update( 5, array( 'name' => 'New Issuer Name' ) ), 'issuer patch succeeds' );
$i = $GLOBALS['wpdb']->updates[2]['data'];
patch_true( array_key_exists( 'name', $i ), 'submitted issuer name written' );
patch_false( array_key_exists( 'settings_json', $i ), 'omitted issuer settings preserved' );
patch_false( array_key_exists( 'logo_attachment_id', $i ), 'omitted issuer logo preserved' );
patch_false( array_key_exists( 'seal_attachment_id', $i ), 'omitted issuer seal preserved' );

$before = count( $GLOBALS['wpdb']->updates );
patch_true( $program->update( 3, array() ), 'empty patch is safe no-op' );
patch_true( $before === count( $GLOBALS['wpdb']->updates ), 'empty patch does not touch database' );

echo "PASS  repository updates preserve omitted fields with PATCH semantics\n\n1 tests, 0 failures\n";
