<?php
/** Regression tests for privacy-conscious, read-only Certloom system diagnostics. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ECAPLA_CERTVERIFY_VERSION', '1.6.0-test' );
define( 'ECAPLA_CERTVERIFY_TABLE', 'certverify_certificates' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
$GLOBALS['wp_version'] = '6.8-test';
$GLOBALS['system_status_options'] = array( 'permalink_structure' => '/%postname%/', 'certloom_domain_write_authority' => 'legacy' );
$GLOBALS['system_status_db_writes'] = 0;

function __( $t ) { return $t; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['system_status_options'] ) ? $GLOBALS['system_status_options'][ $k ] : $d; }
function is_multisite() { return false; }
function is_ssl() { return true; }
function wp_convert_hr_to_bytes( $v ) { $v = trim( (string) $v ); if ( '-1' === $v ) return -1; $n=(int)$v; $u=strtolower(substr($v,-1)); if('g'===$u)$n*=1024*1024*1024; elseif('m'===$u)$n*=1024*1024; elseif('k'===$u)$n*=1024; return $n; }

class SystemStatusWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
    public function esc_like( $v ) { return (string) $v; }
    public function get_row( $prepared, $format = null ) { return array( 'Engine' => 'InnoDB' ); }
    public function db_server_info() { return 'MariaDB 10.11-test'; }
    public function query() { $GLOBALS['system_status_db_writes']++; return 1; }
    public function insert() { $GLOBALS['system_status_db_writes']++; return 1; }
    public function update() { $GLOBALS['system_status_db_writes']++; return 1; }
    public function delete() { $GLOBALS['system_status_db_writes']++; return 1; }
}
$GLOBALS['wpdb'] = new SystemStatusWpdb();

class Ecapla_CertVerify_Database { public static function table_name() { return 'wp_certverify_certificates'; } }
class Ecapla_CertVerify_Backup { const MAINTENANCE_LOCK_OPTION = 'certverify_restore_maintenance_lock'; }
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-system-status.php';

function ss_assert( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
function ss_find( $checks, $id ) { foreach ( $checks as $check ) if ( $id === $check['id'] ) return $check; return null; }

$checks = Ecapla_CertVerify_System_Status::get_checks();
ss_assert( is_array( $checks ) && count( $checks ) >= 10, 'Expected a useful status checklist.' );
ss_assert( 'good' === ss_find( $checks, 'legacy_registry' )['status'], 'Live registry should be reported healthy.' );
ss_assert( false !== strpos( ss_find( $checks, 'legacy_registry' )['value'], 'InnoDB' ), 'Storage engine should be visible without exposing table/database names.' );
ss_assert( 'good' === ss_find( $checks, 'authority' )['status'], 'Legacy authority should be the expected healthy state before cutover.' );
ss_assert( 'good' === ss_find( $checks, 'domain_feature_gates' )['status'], 'All future-domain gates off should be healthy.' );
ss_assert( 0 === $GLOBALS['system_status_db_writes'], 'System diagnostics must be read-only.' );

$GLOBALS['system_status_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = 'corrupt-lock';
$corrupt_checks = Ecapla_CertVerify_System_Status::get_checks();
ss_assert( 'bad' === ss_find( $corrupt_checks, 'maintenance' )['status'], 'Corrupt maintenance state should be visible and fail-closed.' );
ss_assert( 'corrupt-lock' === $GLOBALS['system_status_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ], 'Viewing diagnostics must not repair/delete maintenance state.' );
unset( $GLOBALS['system_status_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] );

$report = Ecapla_CertVerify_System_Status::get_text_report();
ss_assert( false === strpos( $report, 'wp_certverify_certificates' ), 'Support report must not disclose table names.' );
ss_assert( false === strpos( $report, '/var/' ) && false === strpos( $report, 'http://' ) && false === strpos( $report, 'https://' ), 'Support report must not disclose paths or URLs.' );
ss_assert( false !== strpos( $report, 'Proven 1.5 registry' ), 'Support report should state current authority.' );
ss_assert( 0 === $GLOBALS['system_status_db_writes'], 'Generating the text report must remain read-only.' );

echo "PASS  System Status is read-only, privacy-conscious and authority-aware\n";
echo "\n10 checks, 0 failures\n";
