<?php
/** Dynamic regression tests for checked private-table cleanup in the live legacy restore engine. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ECAPLA_CERTVERIFY_TABLE', 'certverify_certificates' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }

class LegacyRestoreTableWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();
	public $script = array();

	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function query( $prepared ) {
		$sql  = is_array( $prepared ) ? (string) $prepared['sql'] : (string) $prepared;
		$args = is_array( $prepared ) ? (array) $prepared['args'] : array();
		$this->queries[] = array( 'sql' => $sql, 'args' => $args );
		if ( empty( $this->script ) ) {
			return 1;
		}
		$next = array_shift( $this->script );
		if ( false === $next ) {
			$this->last_error = 'forced database failure';
		}
		return $next;
	}
}

$GLOBALS['wpdb'] = new LegacyRestoreTableWpdb();
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-database.php';

function lr_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function lr_reset( $script ) {
	$GLOBALS['wpdb']->queries = array();
	$GLOBALS['wpdb']->script = $script;
	$GLOBALS['wpdb']->last_error = '';
}

// 1. Never create over an earlier private staging table if its removal cannot be confirmed.
lr_reset( array( false ) );
$result = Ecapla_CertVerify_Database::create_restore_staging_table( 'abc123', false );
lr_same( true, is_wp_error( $result ), 'initial staging cleanup returns error' );
lr_same( 'ecapla_certverify_restore_cleanup_failed', $result->get_error_code(), 'initial staging cleanup error code' );
lr_same( 1, count( $GLOBALS['wpdb']->queries ), 'CREATE is not attempted after failed pre-drop' );
echo "PASS  restore staging refuses to continue after failed pre-drop\n";

// 2. If staging creation/copy fails, failure to remove the incomplete workspace is first-class.
lr_reset( array( 1, false, false ) );
$result = Ecapla_CertVerify_Database::create_restore_staging_table( 'abc123', false );
lr_same( true, is_wp_error( $result ), 'failed staging preparation returns error' );
lr_same( 'ecapla_certverify_restore_cleanup_failed', $result->get_error_code(), 'failed staging cleanup is explicit' );
lr_same( 3, count( $GLOBALS['wpdb']->queries ), 'cleanup attempted after staging preparation failure' );
echo "PASS  incomplete staging cleanup failure cannot be hidden by the preparation error\n";

// 3. The live registry is never renamed if an old rollback table could not be cleared first.
lr_reset( array( false ) );
$result = Ecapla_CertVerify_Database::swap_restore_table( 'wp_certverify_stage_abc123', 'abc123' );
lr_same( true, is_wp_error( $result ), 'swap pre-cleanup returns error' );
lr_same( 'ecapla_certverify_restore_cleanup_failed', $result->get_error_code(), 'swap pre-cleanup error code' );
lr_same( 1, count( $GLOBALS['wpdb']->queries ), 'RENAME is not attempted after rollback cleanup failure' );
echo "PASS  restore swap cannot proceed across uncertain rollback-table cleanup\n";

// 4. Rollback never overwrites an uncleared failed-replacement workspace.
lr_reset( array( false ) );
$result = Ecapla_CertVerify_Database::rollback_restore_table( 'wp_certverify_rollback_abc123', 'abc123' );
lr_same( false, $result, 'rollback pre-cleanup failure is explicit' );
lr_same( 1, count( $GLOBALS['wpdb']->queries ), 'rollback RENAME not attempted after failed pre-drop' );
echo "PASS  rollback refuses to overwrite an uncleared failed-restore workspace\n";

// 5. Restoring the prior registry is not reported fully clean if the failed promoted graph remains.
lr_reset( array( 1, 1, false ) );
$result = Ecapla_CertVerify_Database::rollback_restore_table( 'wp_certverify_rollback_abc123', 'abc123' );
lr_same( false, $result, 'post-rollback cleanup failure propagates' );
lr_same( 3, count( $GLOBALS['wpdb']->queries ), 'failed replacement cleanup attempted after successful rollback rename' );
echo "PASS  restored live data does not hide failed-replacement cleanup uncertainty\n";

// 6. Clean rollback remains successful when every private-table operation succeeds.
lr_reset( array( 1, 1, 1 ) );
$result = Ecapla_CertVerify_Database::rollback_restore_table( 'wp_certverify_rollback_abc123', 'abc123' );
lr_same( true, $result, 'fully clean rollback succeeds' );
echo "PASS  fully clean legacy rollback remains successful\n";

echo "\n6 tests, 0 failures\n";
