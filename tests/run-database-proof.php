<?php
/** Regression tests for the administrator-triggered isolated database capability proof. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ECAPLA_CERTVERIFY_VERSION', '1.5.14-test' );
$GLOBALS['wp_version'] = '6.6-test';
define( 'DB_USER', 'secret_user_9384' );
define( 'DB_PASSWORD', 'secret_pass_9384' );
define( 'DB_NAME', 'secret_db_9384' );
define( 'DB_HOST', 'secret_host_9384' );

$GLOBALS['dbproof_options'] = array();
$GLOBALS['dbproof_dbdelta_calls'] = 0;

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['dbproof_options'] ) ? $GLOBALS['dbproof_options'][ $key ] : $default; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['dbproof_options'] ) ) return false; $GLOBALS['dbproof_options'][ $key ] = $value; return true; }
function update_option( $key, $value ) { $changed = ! array_key_exists( $key, $GLOBALS['dbproof_options'] ) || $GLOBALS['dbproof_options'][ $key ] !== $value; $GLOBALS['dbproof_options'][ $key ] = $value; return $changed; }
function delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['dbproof_options'] ) ) return false; unset( $GLOBALS['dbproof_options'][ $key ] ); return true; }
function dbDelta( $sql ) { $GLOBALS['dbproof_dbdelta_calls']++; wpdb::$created = true; }

class wpdb {
	public static $created = false;
	public static $rows = array();
	public static $next_id = 1;
	public static $primary_lock = false;
	public $prefix = 'wp_';
	public $last_error = '';
	private $tx = false;
	private $snapshot = null;
	private $secondary = false;
	private $suppressed = false;

	public function __construct( $user = null, $pass = null, $name = null, $host = null ) { $this->secondary = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] !== $this; }
	public function suppress_errors( $value = true ) { $old = $this->suppressed; $this->suppressed = (bool) $value; return $old; }
	public function db_server_info() { return 'MySQL test-server'; }
	public function db_version() { return '8.0.36'; }
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function esc_like( $v ) { return (string) $v; }
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function query( $query ) {
		$this->last_error = '';
		$sql = is_array( $query ) ? $query['sql'] : (string) $query;
		if ( 0 === stripos( $sql, 'DROP TABLE IF EXISTS' ) ) { self::$created = false; self::$rows = array(); self::$next_id = 1; self::$primary_lock = false; return 0; }
		if ( 0 === stripos( $sql, 'DELETE FROM' ) ) {
			if ( is_array( $query ) && isset( $query['args'][1] ) ) { $key=(string)$query['args'][1]; self::$rows=array_values(array_filter(self::$rows,function($r)use($key){return $r['probe_key']!==$key;})); }
			else { self::$rows = array(); }
			return 1;
		}
		if ( 'START TRANSACTION' === strtoupper( trim( $sql ) ) ) { $this->tx=true; $this->snapshot=array( self::$rows, self::$next_id ); return 0; }
		if ( 'ROLLBACK' === strtoupper( trim( $sql ) ) ) { if($this->tx && is_array($this->snapshot)){ self::$rows=$this->snapshot[0]; self::$next_id=$this->snapshot[1]; } $this->tx=false; $this->snapshot=null; if(!$this->secondary) self::$primary_lock=false; return 0; }
		if ( 'COMMIT' === strtoupper( trim( $sql ) ) ) { $this->tx=false; $this->snapshot=null; if(!$this->secondary) self::$primary_lock=false; return 0; }
		if ( 0 === stripos( $sql, 'SET SESSION innodb_lock_wait_timeout' ) ) return 0;
		return 1;
	}
	public function insert( $table, $row ) {
		$this->last_error = '';
		foreach ( self::$rows as $existing ) { if ( $existing['probe_key'] === $row['probe_key'] ) { $this->last_error='Duplicate entry'; return false; } }
		$row['id']=self::$next_id++; self::$rows[]=$row; return 1;
	}
	public function get_row( $query, $format = null ) { $this->last_error=''; return self::$created ? array( 'Engine' => 'InnoDB' ) : null; }
	public function get_results( $query, $format = null ) {
		$this->last_error=''; if(!self::$created)return array();
		return array(
			array('Key_name'=>'PRIMARY','Non_unique'=>0),
			array('Key_name'=>'probe_key','Non_unique'=>0),
			array('Key_name'=>'probe_value','Non_unique'=>1),
		);
	}
	public function get_var( $query ) {
		$this->last_error=''; $sql=is_array($query)?$query['sql']:(string)$query; $args=is_array($query)?$query['args']:array();
		if ( false !== stripos( $sql, 'COUNT(*)' ) ) {
			if ( false !== stripos( $sql, 'WHERE probe_key' ) ) { $key=(string)end($args); $c=0; foreach(self::$rows as $r) if($r['probe_key']===$key)$c++; return (string)$c; }
			return (string)count(self::$rows);
		}
		if ( false !== stripos( $sql, 'FOR UPDATE' ) ) {
			$key=(string)end($args);
			if ( $this->secondary && self::$primary_lock ) { $this->last_error='Lock wait timeout exceeded; try restarting transaction (1205)'; return null; }
			foreach(self::$rows as $r){ if($r['probe_key']===$key){ if(!$this->secondary)self::$primary_lock=true; return (string)$r['id']; } }
			return null;
		}
		return null;
	}
	public function close() { return true; }
}

$GLOBALS['wpdb'] = new wpdb();
class Ecapla_CertVerify_Backup { const MAINTENANCE_LOCK_OPTION = 'certverify_restore_maintenance_lock'; }

function is_multisite(){ return false; }
function wp_get_environment_type(){ return 'staging'; }
require_once dirname( __DIR__ ) . '/src/Application/Rendering/CanonicalJson.php';
require_once dirname( __DIR__ ) . '/src/Application/Rendering/RuntimePayloadFingerprint.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Diagnostics/RuntimePayloadEvidence.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Diagnostics/RuntimeEnvironmentEvidence.php';
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-database-proof.php';

function dp_assert( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function dp_find( $result, $id ) { foreach ( (array) $result['checks'] as $check ) if ( $id === $check['id'] ) return $check; return null; }

$result = Ecapla_CertVerify_Database_Proof::run();
dp_assert( ! is_wp_error( $result ), 'Database proof should complete.' );
dp_assert( 'passed' === $result['overall'], 'Fully capable fake database should pass.' );
dp_assert( 1 === $GLOBALS['dbproof_dbdelta_calls'], 'Proof must exercise WordPress dbDelta().' );
dp_assert( 'pass' === dp_find( $result, 'innodb' )['status'], 'InnoDB must pass.' );
dp_assert( 'pass' === dp_find( $result, 'unique_constraint' )['status'], 'Unique constraint must be enforced.' );
dp_assert( 'pass' === dp_find( $result, 'rollback' )['status'], 'Rollback must be proven.' );
dp_assert( 'pass' === dp_find( $result, 'commit' )['status'], 'Commit must be proven.' );
dp_assert( 'pass' === dp_find( $result, 'row_lock' )['status'], 'Independent connection must prove row-lock contention.' );
dp_assert( false === wpdb::$created, 'Scratch table must be removed before returning.' );
dp_assert( false === get_option( Ecapla_CertVerify_Database_Proof::LOCK_OPTION, false ), 'Execution lock must be removed.' );
dp_assert( is_array( Ecapla_CertVerify_Database_Proof::get_last_result() ), 'Non-sensitive result should be stored.' );
dp_assert( '1.5.14-test' === $result['plugin_version'], 'Stored proof evidence must bind to the Certloom version that produced it.' );
dp_assert( is_array( $result['runtime_payload'] ?? null ) && Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ), 'Stored proof evidence must bind to the exact Certloom production payload.' );
dp_assert( 'passed' === Ecapla_CertVerify_Database_Proof::evidence_state(), 'Fresh passing proof evidence should be current.' );
dp_assert( Ecapla_CertVerify_Database_Proof::is_current_passed(), 'Current passing proof helper should return true.' );
$report = Ecapla_CertVerify_Database_Proof::get_text_report();
dp_assert( false === strpos( $report, 'wp_certloom_db_proof' ), 'Support report must not expose scratch table names.' );
dp_assert( false === strpos( $report, DB_NAME ) && false === strpos( $report, DB_USER ), 'Support report must not expose database credentials/names.' );
dp_assert( false !== strpos( $report, 'Checked UTC:' ), 'Support evidence should include a non-sensitive proof timestamp.' );

$saved_result = $GLOBALS['dbproof_options'][ Ecapla_CertVerify_Database_Proof::LAST_OPTION ];
$tampered = $saved_result;
$tampered['overall'] = 'passed';
$tampered['checks'][0]['status'] = 'fail';
$GLOBALS['dbproof_options'][ Ecapla_CertVerify_Database_Proof::LAST_OPTION ] = $tampered;
dp_assert( null === Ecapla_CertVerify_Database_Proof::get_last_result(), 'Stored proof evidence with inconsistent overall/check status must fail closed.' );
$GLOBALS['dbproof_options'][ Ecapla_CertVerify_Database_Proof::LAST_OPTION ] = $saved_result;
$tampered_payload = $saved_result;
$tampered_payload['runtime_payload']['sha256'] = str_repeat( 'e', 64 );
$GLOBALS['dbproof_options'][ Ecapla_CertVerify_Database_Proof::LAST_OPTION ] = $tampered_payload;
dp_assert( 'stale' === Ecapla_CertVerify_Database_Proof::evidence_state(), 'Same-version production-byte change must stale isolated database proof evidence.' );
$GLOBALS['dbproof_options'][ Ecapla_CertVerify_Database_Proof::LAST_OPTION ] = $saved_result;

$GLOBALS['dbproof_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = array( 'token_hash' => 'x', 'expires_at' => time() + 60 );
$before_calls = $GLOBALS['dbproof_dbdelta_calls'];
$blocked = Ecapla_CertVerify_Database_Proof::run();
dp_assert( is_wp_error( $blocked ) && 'certloom_database_proof_maintenance_active' === $blocked->get_error_code(), 'Proof must refuse to run during active Certloom maintenance.' );
dp_assert( $before_calls === $GLOBALS['dbproof_dbdelta_calls'], 'Blocked proof must not create scratch schema.' );

$GLOBALS['dbproof_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = array( 'token_hash' => 'x', 'expires_at' => time() - 60 );
$stale = Ecapla_CertVerify_Database_Proof::run();
dp_assert( is_array( $stale ) && 'passed' === $stale['overall'], 'A valid expired legacy maintenance lease should remain visible but must not permanently block the isolated proof.' );

unset( $GLOBALS['dbproof_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] );
$GLOBALS['dbproof_options']['certloom_domain_maintenance_lock'] = 'corrupt';
$before_calls = $GLOBALS['dbproof_dbdelta_calls'];
$corrupt = Ecapla_CertVerify_Database_Proof::run();
dp_assert( is_wp_error( $corrupt ) && 'certloom_database_proof_domain_maintenance_corrupt' === $corrupt->get_error_code(), 'Corrupt gated-domain maintenance state must block the proof fail-closed.' );
dp_assert( $before_calls === $GLOBALS['dbproof_dbdelta_calls'], 'Corrupt protected state must block before scratch schema is touched.' );
unset( $GLOBALS['dbproof_options']['certloom_domain_maintenance_lock'] );

printf( "PASS  Isolated database proof is private, cleanup-safe and exercises real database guarantees\n\n22 checks, 0 failures\n" );
