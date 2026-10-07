<?php
/** Checked row-fence admission, transaction rollback and session-setting restoration. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['fence_cache_deletes'] = array();
function wp_cache_delete( $key, $group ) { $GLOBALS['fence_cache_deletes'][] = $key; return true; }
require __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Data\DomainWriteFence;

final class FenceWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public $values = array( 'certloom_domain_write_fence' => '1' );
	public $engine = 'InnoDB';
	public $timeout = 50;
	public $exclusive_error = false;
	public $fail_control = '';
	public $queries = array();
	private $snapshot;
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $prepared ) {
		$this->last_error = '';
		if ( is_string( $prepared ) ) { return $this->timeout; }
		list( $query, $args ) = $prepared; $this->queries[] = $query;
		if ( strpos( $query, 'SELECT ENGINE' ) === 0 ) { return $this->engine; }
		if ( $this->exclusive_error && str_ends_with( $query, ' FOR UPDATE' ) ) { $this->last_error = 'lock timeout'; return null; }
		return $this->values[ $args[1] ] ?? null;
	}
	public function query( $prepared ) {
		$this->last_error = '';
		if ( is_array( $prepared ) ) { list( $query, $args ) = $prepared; } else { $query = $prepared; $args = array(); }
		$this->queries[] = $query;
		if ( $query === $this->fail_control ) { $this->last_error = 'control failed'; return false; }
		if ( $query === 'START TRANSACTION' ) { $this->snapshot = $this->values; }
		if ( $query === 'ROLLBACK' ) { $this->values = $this->snapshot; }
		if ( strpos( $query, 'INSERT IGNORE' ) === 0 ) { $this->values[ $args[1] ] = $this->values[ $args[1] ] ?? $args[2]; }
		if ( strpos( $query, 'SET SESSION' ) === 0 ) { $this->timeout = (int) substr( $query, strrpos( $query, ' ' ) + 1 ); }
		return 0;
	}
}
$checks = 0;
function fence_check( $ok, $message ) { global $checks; $checks++; if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS  $message\n"; }
$wpdb = new FenceWpdb();
fence_check( true === DomainWriteFence::prepare(), 'existing InnoDB fence is admitted' );
fence_check( ! array_filter( $wpdb->queries, static function ( $query ) { return strpos( $query, 'INSERT' ) === 0; } ), 'existing shared row is never rewritten during ordinary admission' );
unset( $wpdb->values[ DomainWriteFence::OPTION ] );
fence_check( true === DomainWriteFence::prepare() && $wpdb->values[ DomainWriteFence::OPTION ] === '1', 'absent permanent row is inserted before starting a transaction' );
$wpdb->values[ DomainWriteFence::OPTION ] = 'corrupt';
fence_check( is_wp_error( DomainWriteFence::prepare() ) && $wpdb->values[ DomainWriteFence::OPTION ] === 'corrupt', 'corrupt fence fails closed without overwriting evidence' );
$wpdb->values[ DomainWriteFence::OPTION ] = '1'; $wpdb->engine = 'MyISAM';
fence_check( is_wp_error( DomainWriteFence::prepare() ), 'nontransactional options cannot pretend to hold an InnoDB row lock' );
$wpdb->engine = 'InnoDB';
$result = DomainWriteFence::exclusive( static function () use ( $wpdb ) { $wpdb->values['lease'] = 'owner'; return 'saved'; } );
fence_check( $result === 'saved' && $wpdb->values['lease'] === 'owner', 'exclusive callback commits its lease within the fence' );
fence_check( $wpdb->timeout === 50, 'maintenance restores the original session lock timeout after success' );
fence_check( in_array( 'notoptions', $GLOBALS['fence_cache_deletes'], true ), 'negative option cache is invalidated after lease publication' );
unset( $wpdb->values['lease'] );
$result = DomainWriteFence::exclusive( static function () use ( $wpdb ) { $wpdb->values['lease'] = 'partial'; return new WP_Error( 'intentional' ); } );
fence_check( is_wp_error( $result ) && $result->get_error_code() === 'intentional' && ! isset( $wpdb->values['lease'] ), 'callback failure rolls back an unpublished lease' );
fence_check( $wpdb->timeout === 50, 'callback failure restores the session lock timeout' );
$wpdb->exclusive_error = true; $called = false;
$result = DomainWriteFence::exclusive( static function () use ( &$called ) { $called = true; return true; } );
fence_check( is_wp_error( $result ) && ! $called && $wpdb->timeout === 50, 'contended exclusive fence rolls back without invoking maintenance and restores timeout' );
$wpdb->exclusive_error = false; $wpdb->fail_control = 'COMMIT';
$result = DomainWriteFence::exclusive( static function () use ( $wpdb ) { $wpdb->values['lease'] = 'partial'; return true; } );
fence_check( is_wp_error( $result ) && $result->get_error_code() === 'certloom_transaction_commit_failed' && ! isset( $wpdb->values['lease'] ), 'failed lease commit cannot publish a successful acquisition' );
fence_check( $wpdb->timeout === 50, 'failed commit also restores the session lock timeout' );
$wpdb->fail_control = 'SET SESSION innodb_lock_wait_timeout = 50';
$result = DomainWriteFence::exclusive( static function () { return true; } );
fence_check( is_wp_error( $result ), 'failure to restore the session setting is reported explicitly' );
echo "\n$checks checks, 0 failures\n";
