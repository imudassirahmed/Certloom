<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Database\DatabaseSession;
class SessionDb { public $reconnect_retries = 5; public function check_connection() {} }
function session_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$wpdb = new SessionDb();
session_assert( 'ok' === DatabaseSession::run( function () { global $wpdb; session_assert( 0 === $wpdb->reconnect_retries, 'Automatic reconnect is disabled during the callback.' ); return 'ok'; } ), 'Callback result.' );
session_assert( 5 === $wpdb->reconnect_retries, 'Original setting restored.' );
echo "PASS  locked session cannot enable WordPress automatic command replay\n";
DatabaseSession::run( function () { global $wpdb; DatabaseSession::run( function () { global $wpdb; session_assert( 0 === $wpdb->reconnect_retries, 'Nested setting.' ); } ); session_assert( 0 === $wpdb->reconnect_retries, 'Inner scope cannot restore outer retries early.' ); } );
session_assert( 5 === $wpdb->reconnect_retries, 'Nested final restoration.' );
echo "PASS  nested transaction and maintenance scopes retain the outer no-retry setting\n";
try { DatabaseSession::run( function () { throw new LogicException( 'probe' ); } ); } catch ( LogicException $error ) {}
session_assert( 5 === $wpdb->reconnect_retries, 'Exception restoration.' );
echo "PASS  exceptions restore the original WordPress retry setting\n";
$wpdb->reconnect_retries = 'invalid'; $called = false;
$result = DatabaseSession::run( function () use ( &$called ) { $called = true; } );
session_assert( $result instanceof WP_Error && 'certloom_database_session_unsupported' === $result->get_error_code() && ! $called, 'Unsupported retry control fails closed.' );
echo "PASS  unsupported reconnect control cannot invoke a mutation callback\n";
class PrivateSessionDb { private $reconnect_retries = 5; public function check_connection() {} }
$wpdb = new PrivateSessionDb(); $called = false;
$result = DatabaseSession::run( function () use ( &$called ) { $called = true; } );
session_assert( $result instanceof WP_Error && 'certloom_database_session_unsupported' === $result->get_error_code() && ! $called, 'An inaccessible retry setting must fail closed without a PHP fatal.' );
echo "PASS  incompatible database adapters fail closed without an inaccessible-property fatal\n";
class UnsettableSessionDb { protected $reconnect_retries = 5; public function check_connection() {} public function __get( $key ) { return $this->$key; } public function __set( $key, $value ) { throw new LogicException( 'read only' ); } }
$wpdb = new UnsettableSessionDb(); $called = false;
$result = DatabaseSession::run( function () use ( &$called ) { $called = true; } );
session_assert( $result instanceof WP_Error && 'certloom_database_session_unsupported' === $result->get_error_code() && ! $called, 'A read-only adapter retry setting cannot invoke or crash the callback.' );
echo "PASS  read-only adapter settings cannot invoke a mutation or cause a setter fatal\n";
echo "\n6 tests, 0 failures\n";
