<?php
/** Pure migration commit + durable state tests. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );

$GLOBALS['cm_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['cm_options'] ) ? $GLOBALS['cm_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['cm_options'][ $key ] = $value; return true; }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\LegacyMigrationTargetInterface;
use Certloom\Application\Migration\LegacyMigrationCommitService;
use Certloom\Application\Migration\LegacyMigrationState;

final class FakeMigrationTarget implements LegacyMigrationTargetInterface {
	public $calls = array();
	public $fail_id = 0;
	public $skip_id = 0;
	public function migrate( $legacy_id, array $plan ) {
		$this->calls[] = (int) $legacy_id;
		if ( (int) $legacy_id === $this->fail_id ) { return new WP_Error( 'write_failed', 'write failed' ); }
		if ( (int) $legacy_id === $this->skip_id ) { return array( 'outcome' => 'skipped' ); }
		return array( 'outcome' => 'migrated' );
	}
}
function mc_same( $e, $a, $m='' ) { if ( $e !== $a ) { throw new RuntimeException( ($m?$m.': ':'') . 'Expected '.var_export($e,true).', got '.var_export($a,true) ); } }
function mc_row( $id ) { return array( 'id'=>$id, 'cert_number'=>'CERT-'.$id, 'status'=>'valid', 'status_source'=>'manual', 'holder_name'=>'Person '.$id, 'issue_date'=>'2026-01-01', 'expiry_date'=>'2027-01-01', 'custom_fields'=>array(), 'created_at'=>'2026-01-01 00:00:00', 'updated_at'=>'2026-01-01 00:00:00' ); }

$target = new FakeMigrationTarget();
$target->skip_id = 2;
$result = ( new LegacyMigrationCommitService( $target ) )->commit( array( mc_row(1), mc_row(2), mc_row(3) ) );
mc_same( 2, $result['migrated'] );
mc_same( 1, $result['skipped'] );
mc_same( 0, $result['failed'] );
mc_same( 3, $result['last_legacy_id'] );

echo "PASS  commit service accounts migrated/skipped rows\n";

$target = new FakeMigrationTarget();
$target->fail_id = 2;
$result = ( new LegacyMigrationCommitService( $target ) )->commit( array( mc_row(1), mc_row(2), mc_row(3) ) );
mc_same( 1, $result['migrated'] );
mc_same( 1, $result['failed'] );
mc_same( array(1,2), $target->calls, 'stops after first unsafe row' );

echo "PASS  commit service stops at first failed row\n";

$state = new LegacyMigrationState();
$fingerprint = str_repeat( 'a', 64 );
mc_same( true, $state->begin_preflight( array('count'=>2,'max_id'=>3,'generation'=>'gen-a'), '2026-08-22' ) );
mc_same( LegacyMigrationState::PREFLIGHTING, $state->status() );
mc_same( true, $state->record_preflight_batch( 3, array('total'=>2,'ready'=>2,'errors'=>0,'status_counts'=>array('valid'=>2,'suspended'=>0,'revoked'=>0,'expired'=>0),'error_details'=>array()), $fingerprint ) );
mc_same( true, $state->finish_preflight() );
mc_same( LegacyMigrationState::PREFLIGHT_VERIFYING, $state->status() );
mc_same( true, $state->record_preflight_verification_batch( 3, 2, $fingerprint ) );
mc_same( true, $state->finish_preflight_verification() );
mc_same( LegacyMigrationState::PREFLIGHT_READY, $state->status() );
mc_same( true, $state->begin_commit() );
mc_same( true, $state->record_progress( 3, 2, 0, 0 ) );
mc_same( true, $state->mark_complete() );
mc_same( true, $state->is_complete() );

echo "PASS  migration state requires preflight before completion\n\n3 tests, 0 failures\n";
