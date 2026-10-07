<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/support/option-database.php';
/** Pure owner/staleness/TTL/internal-write tests for shared domain maintenance lock. */
$GLOBALS['dm_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $c, $m;
		function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
		function get_error_code() { return $this->c; }
		function get_error_message() { return $this->m; }
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
function wp_cache_delete() { return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['dm_options'] ) ? $GLOBALS['dm_options'][ $k ] : $d; }
function add_option( $k, $v, $deprecated = '', $autoload = false ) { if ( array_key_exists( $k, $GLOBALS['dm_options'] ) ) return false; $GLOBALS['dm_options'][ $k ] = $v; return true; }
final class DMWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public $busy = false;
	public $fail_read = false;
	public $fail_release = false;
	public function get_var( $p ) {
		$this->last_error = '';
		if ( is_string( $p ) && 'SELECT @@SESSION.innodb_lock_wait_timeout' === $p ) { return 50; }
		if ( strpos( $p['q'], 'SELECT ENGINE' ) === 0 ) { return 'InnoDB'; }
		if ( strpos( $p['q'], 'SELECT option_value' ) === 0 && ( $p['a'][1] ?? '' ) === 'certloom_domain_write_fence' ) { return '1'; }
		if ( $this->fail_read && strpos($p['q'], 'SELECT option_value') === 0 ) { $this->last_error='read failed'; return null; }
		if ( strpos( $p['q'], 'SELECT GET_LOCK' ) === 0 ) return $this->busy ? '0' : '1';
		if ( strpos( $p['q'], 'SELECT RELEASE_LOCK' ) === 0 ) return $this->fail_release ? null : '1';
		if ( strpos( $p['q'], 'SELECT option_value' ) === 0 ) return $GLOBALS['dm_options'][$p['a'][1]] ?? null;
		if ( strpos( $p['q'], 'SELECT option_name' ) === 0 ) { foreach ( array_slice($p['a'],1) as $key ) if (isset($GLOBALS['dm_options'][$key])) return $key; }
		return null;
	}
	public function get_results( $prepared, $format ) {
		$this->last_error = $this->fail_read ? 'read failed' : '';
		return $this->fail_read ? null : certloom_test_option_rows( array( 'query' => $prepared['q'], 'args' => $prepared['a'] ), $GLOBALS['dm_options'] );
	}
	public $fail_delete_once = false;
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) {
		if ( is_string( $p ) ) { return 0; }
		$a = $p['a'];
		if ( 0 === strpos( $p['q'], 'UPDATE' ) ) {
			$new = $a[1]; $name = $a[2]; $old = $a[3];
			if ( ( $GLOBALS['dm_options'][ $name ] ?? null ) !== $old ) return 0;
			if ( $new === $old ) return 0; // MySQL reports zero affected rows for identical values.
			$GLOBALS['dm_options'][ $name ] = $new;
			return 1;
		}
		if ( 0 === strpos( $p['q'], 'DELETE' ) ) {
			$name = $a[1]; $old = $a[2];
			if ( $this->fail_delete_once ) { $this->fail_delete_once = false; return 0; }
			if ( ( $GLOBALS['dm_options'][ $name ] ?? null ) !== $old ) return 0;
			unset( $GLOBALS['dm_options'][ $name ] );
			return 1;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new DMWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Data\DomainMaintenanceLock;

function dm_same( $e, $a, $m = '' ) {
	if ( $e !== $a ) throw new RuntimeException( ( $m ? $m . ': ' : '' ) . 'Expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) );
}

$l = new DomainMaintenanceLock();
$a = str_repeat( 'a', 32 );
$b = str_repeat( 'b', 32 );

dm_same( true, $l->acquire( $a, 'restore' ), 'acquire' );
dm_same( true, $l->active(), 'active' );
dm_same( 'certloom_domain_maintenance_write_locked', $l->assert_writes_allowed()->get_error_code(), 'normal writes blocked' );
dm_same( 'certloom_domain_maintenance_locked', $l->acquire( $b, 'cutover' )->get_error_code(), 'other owner blocked' );
echo "PASS  maintenance lock blocks normal writes and competing owners\n";

dm_same( true, DomainMaintenanceLock::run_internal( fn() => ! is_wp_error( $l->assert_writes_allowed() ) ), 'internal restore write' );
dm_same( true, $l->touch( $a, 'restore' ), 'owner touch' );
dm_same( true, $l->touch( $a, 'restore' ), 'same-second owner touch with unchanged payload' );
dm_same( 'certloom_domain_maintenance_owner_mismatch', $l->release( $b )->get_error_code(), 'wrong release' );
dm_same( true, $l->release( $a ), 'owner release' );
dm_same( false, $l->active(), 'inactive' );
echo "PASS  only owning worker refreshes/releases while internal restore writes can run under lock\n";

// Backward-compatible stale three-part lock can be taken over.
$GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ] = $a . '|' . ( time() - 4000 ) . '|restore';
dm_same( true, $l->acquire( $b, 'cutover' ), 'legacy stale takeover' );
dm_same( true, 0 === strpos( $GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ], $b . '|' ), 'new owner' );
echo "PASS  stale legacy-format maintenance lock can be taken over with compare-and-swap semantics\n";

// A custom long-lived lock must remain active for its persisted expiry, not DEFAULT_TTL.
$old_timestamp = time() - 3000;
$GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ] = $a . '|' . $old_timestamp . '|' . ( time() + 500 ) . '|restore';
dm_same( true, $l->active(), 'persisted expiry active' );
dm_same( 'certloom_domain_maintenance_locked', $l->acquire( $b, 'cutover', 60 )->get_error_code(), 'request ttl cannot steal live lock' );
echo "PASS  custom persisted lock expiry prevents premature write reopening or takeover\n";

// Touch must actually extend expiry according to the requested TTL.
$GLOBALS['dm_options'] = array();
dm_same( true, $l->acquire( $a, 'restore', 60 ), 'short acquire' );
$before = explode( '|', $GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ], 4 );
dm_same( true, $l->touch( $a, 'restore', 3600 ), 'long touch' );
$after = explode( '|', $GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ], 4 );
dm_same( true, isset( $after[2] ) && ( (int) $after[2] - (int) $after[1] ) === 3600, 'touch persisted ttl' );
dm_same( true, (int) $after[2] >= (int) $before[2], 'expiry extended' );
echo "PASS  touch persists the requested TTL and active() reads that persisted expiry\n";

// A non-empty corrupt lock must fail closed for ordinary writes, but a fresh
// maintenance owner may atomically replace it rather than requiring DB surgery.
$GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ] = 'corrupt-lock-payload';
dm_same( true, $l->active(), 'corrupt lock fail closed' );
dm_same( 'certloom_domain_maintenance_write_locked', $l->assert_writes_allowed()->get_error_code(), 'corrupt lock blocks ordinary write' );
dm_same( true, $l->acquire( $b, 'repair', 60 ), 'maintenance CAS replaces corrupt lock' );
dm_same( true, 0 === strpos( $GLOBALS['dm_options'][ DomainMaintenanceLock::OPTION ], $b . '|' ), 'repair owner persisted' );
echo "PASS  corrupt maintenance state blocks normal writes while a new maintenance owner can repair it atomically\n";

// A failed owner-matched release must be an explicit error rather than a false
// value that callers could accidentally interpret as success.
$GLOBALS['wpdb']->fail_delete_once = true;
$release_failed = $l->release( $b );
dm_same( 'certloom_domain_maintenance_release_failed', $release_failed->get_error_code(), 'release failure is explicit' );
dm_same( true, $l->active(), 'failed release keeps write lock active' );
dm_same( true, $l->release( $b ), 'release retry succeeds' );
echo "PASS  maintenance release failure is explicit and safely retryable\n";

// An expired lease must not reopen writes or admit an unrelated maintenance job while recovery is pending.
$GLOBALS['dm_options']['certloom_domain_restore_journal'] = array('owner_token'=>$a, 'state'=>'swapped');
$GLOBALS['dm_options'][DomainMaintenanceLock::OPTION] = $a.'|'.(time()-100).'|'.(time()-1).'|restore';
dm_same(true,$l->active(),'unresolved journal blocks writes after expiry');
dm_same('certloom_domain_maintenance_recovery_required',$l->acquire($b)->get_error_code(),'unrelated maintenance cannot steal interrupted restore');
$GLOBALS['dm_options']=array();
$GLOBALS['wpdb']->busy=true;
dm_same('certloom_maintenance_busy',$l->acquire($a)->get_error_code(),'mutex contention refuses ownership transition');
$GLOBALS['wpdb']->busy=false;
echo "PASS  pending recovery blocks writes and competing maintenance; live transitions exclude other workers\n";

$GLOBALS['dm_options'][DomainMaintenanceLock::OPTION]=$a.'|'.(time()-100).'|'.(time()-1).'|restore';
dm_same(true,$l->recovery_allowed($a),'expired owner permits recovery');
dm_same('certloom_domain_maintenance_owner_mismatch',$l->recovery_allowed($b)->get_error_code(),'recovery refuses a different owner');
$GLOBALS['wpdb']->fail_read=true;
dm_same(true,$l->active(),'database error closes write boundary');
dm_same('certloom_domain_maintenance_read_failed',$l->recovery_allowed($a)->get_error_code(),'database error is not permission for recovery DDL');
$GLOBALS['wpdb']->fail_read=false;
echo "PASS  uncertain ownership blocks recovery and user writes\n";

$GLOBALS['wpdb']->fail_release = true;
$result = \Certloom\Infrastructure\Data\MaintenanceMutex::run( function () { return true; } );
dm_same( 'certloom_maintenance_mutex_release_failed', $result->get_error_code(), 'uncertain advisory lock release cannot report success' );
$GLOBALS['wpdb']->fail_release = false;
echo "PASS  failed advisory lock release is reported rather than silently accepted\n";
echo "\n10 tests, 0 failures\n";
