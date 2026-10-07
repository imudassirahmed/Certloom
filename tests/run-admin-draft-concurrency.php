<?php
/** Semantic draft conflicts, lock failures and network-site isolation. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function get_current_user_id() { return 11; }
function get_current_blog_id() { return $GLOBALS['draft_blog']; }
function get_user_meta( $user, $key, $single ) { return $GLOBALS['draft_meta'][$user][$key] ?? ''; }
function update_user_meta( $user, $key, $value ) { if ( $GLOBALS['draft_deny_write'] ) { return false; } $GLOBALS['draft_meta'][$user][$key] = $value; return 1; }
function delete_user_meta( $user, $key ) { if ( $GLOBALS['draft_deny_write'] ) { return false; } unset( $GLOBALS['draft_meta'][$user][$key] ); return true; }
function wp_cache_delete( $key, $group ) { return true; }
function wp_generate_uuid4() { return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['draft_uuid'] ); }
class DraftLockDatabase {
	public $usermeta = 'wp_usermeta'; public $last_error = ''; public $held = false; public $busy = false; public $release_error = false;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) {
		$this->last_error = '';
		if ( false !== strpos( $sql, 'GET_LOCK' ) ) { if ( $this->busy ) { return '0'; } $this->held = true; return '1'; }
		if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { $this->held = false; return $this->release_error ? null : '1'; }
		return null;
	}
}
$GLOBALS['draft_meta'] = array(); $GLOBALS['draft_blog'] = 1; $GLOBALS['draft_uuid'] = 0; $GLOBALS['draft_deny_write'] = false;
$wpdb = new DraftLockDatabase();
use Certloom\Application\Admin\DraftWorkspace;
$checks = 0; $failures = 0;
function draft_check( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
function draft_error( $value, $code ) { return $value instanceof WP_Error && $code === $value->get_error_code(); }
$first = DraftWorkspace::start_new( 'setup', array( 'name' => 'Original' ) );
$base = (int) ( $first['revision'] ?? 0 ); $run = $first['run_id'];
$context = array( 'expected_revision' => $base, 'run_id' => $run, 'client_revision' => 100 );
$newer = DraftWorkspace::save( 'setup', array( 'name' => 'Tab A' ), $context );
draft_check( is_array( $newer ) && ( $newer['revision'] ?? 0 ) === $base + 1, 'save advances a server revision independently of browser time' );
$context['client_revision'] = 200;
$stale = DraftWorkspace::save( 'setup', array( 'name' => 'Tab B stale' ), $context );
draft_check( draft_error( $stale, 'certloom_draft_workspace_conflict' ), 'a higher client timestamp cannot overwrite a stale server base' );
draft_check( 'Tab A' === ( DraftWorkspace::get( 'setup' )['payload']['name'] ?? '' ), 'conflict preserves the durable newer draft' );
$called = false;
$final = DraftWorkspace::run_if_current( 'setup', $run, $base, static function () use ( &$called ) { $called = true; return true; } );
draft_check( draft_error( $final, 'certloom_draft_workspace_conflict' ) && ! $called, 'a stale final action cannot advance setup or issue a credential' );
$skewed = DraftWorkspace::save( 'setup', array( 'name' => 'Clock behind' ), array( 'expected_revision' => (int) ( $newer['revision'] ?? 0 ), 'run_id' => $run, 'client_revision' => 1 ) );
draft_check( is_array( $skewed ) && 'Clock behind' === $skewed['payload']['name'], 'a valid server base is accepted even when another browser clock is behind' );
$next = DraftWorkspace::start_new( 'setup', array( 'name' => 'Restart' ) );
$context['expected_revision'] = (int) ( $next['revision'] ?? 0 );
draft_check( draft_error( DraftWorkspace::save( 'setup', array( 'name' => 'Old run' ), $context ), 'certloom_draft_workspace_conflict' ), 'old tabs cannot revive a discarded/restarted run' );
$GLOBALS['draft_blog'] = 2;
draft_check( array() === DraftWorkspace::get( 'setup' ), 'same network administrator has a separate workspace on another site' );
DraftWorkspace::start_new( 'setup', array( 'name' => 'Site two' ) );
DraftWorkspace::save( 'other', array( 'name' => 'Other task' ) );
DraftWorkspace::clear( 'setup' );
draft_check( 'Other task' === ( DraftWorkspace::get( 'other' )['payload']['name'] ?? '' ), 'discard affects only the selected task' );
$GLOBALS['draft_blog'] = 1;
draft_check( 'Restart' === ( DraftWorkspace::get( 'setup' )['payload']['name'] ?? '' ), 'second-site save/discard preserves first-site intent' );
$wpdb->busy = true;
draft_check( draft_error( DraftWorkspace::save( 'setup', array( 'name' => 'Busy' ) ), 'certloom_draft_workspace_busy' ), 'another worker excludes read-modify-write access' );
$wpdb->busy = false; $GLOBALS['draft_deny_write'] = true;
draft_check( draft_error( DraftWorkspace::save( 'setup', array( 'name' => 'Denied' ) ), 'certloom_draft_workspace_save_failed' ), 'denied persistence never reports a saved draft' );
draft_check( ! $wpdb->held, 'failed persistence releases its database lock' );
$GLOBALS['draft_deny_write'] = false; $wpdb->release_error = true;
draft_check( draft_error( DraftWorkspace::save( 'setup', array( 'name' => 'Uncertain' ) ), 'certloom_draft_workspace_lock_release_failed' ), 'uncertain lock release is reported honestly' );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
