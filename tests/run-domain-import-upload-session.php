<?php
/** Protected CSV mapping-session tests. */
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
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
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( (string) $name ) ); }
function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }
function wp_mkdir_p( $value ) { return is_dir( $value ) || mkdir( $value, 0700, true ); }

$upload_session_dir = sys_get_temp_dir() . '/certloom-upload-session-' . bin2hex( random_bytes( 4 ) );
function apply_filters( $name, $value ) { global $upload_session_dir; return 'certloom_private_job_directory' === $name ? $upload_session_dir : $value; }

$upload_session_transients = array();
function set_transient( $key, $value, $ttl ) {
	global $upload_session_transients;
	$upload_session_transients[ $key ] = array( 'value' => $value, 'expires' => time() + (int) $ttl );
	return true;
}
function get_transient( $key ) {
	global $upload_session_transients;
	if ( empty( $upload_session_transients[ $key ] ) ) { return false; }
	if ( $upload_session_transients[ $key ]['expires'] < time() ) { unset( $upload_session_transients[ $key ] ); return false; }
	return $upload_session_transients[ $key ]['value'];
}
function delete_transient( $key ) { global $upload_session_transients; unset( $upload_session_transients[ $key ] ); return true; }

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportCsvReader;
use Certloom\Infrastructure\Import\DomainImportUploadSession;

function upload_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$source = tempnam( sys_get_temp_dir(), 'certloom-upload-' );
file_put_contents( $source, "Certificate Number,Holder,Status\nCERT-1,Alice,Valid\n" );
$files   = new PrivateJobFileStore();
$reader  = new DomainImportCsvReader( $files );
$session = new DomainImportUploadSession( $files, $reader );

$token = $session->create( $source, 'credentials.csv', 7 );
if ( is_wp_error( $token ) ) { throw new RuntimeException( $token->get_error_message() ); }
upload_same( 32, strlen( $token ), 'token length' );
$payload = $session->get( $token, 7 );
if ( is_wp_error( $payload ) ) { throw new RuntimeException( $payload->get_error_message() ); }
upload_same( array( 'Certificate Number', 'Holder', 'Status' ), $payload['header'], 'header persisted' );
upload_same( true, $files->owns( $payload['path'] ) && is_file( $payload['path'] ), 'private file exists' );
echo "PASS  upload mapping session stores only a protected private source + normalized header\n";

$wrong = $session->get( $token, 8 );
upload_same( 'certloom_import_mapping_owner_mismatch', $wrong->get_error_code(), 'owner mismatch' );
$invalid = $session->get( '../bad', 7 );
upload_same( 'certloom_import_mapping_token_invalid', $invalid->get_error_code(), 'invalid token' );
echo "PASS  mapping session is token-validated and administrator-owner scoped\n";

$private_path = $payload['path'];
$consumed = $session->consume( $token, 7 );
upload_same( true, $consumed, 'consume result' );
upload_same( true, is_file( $private_path ), 'consume transfers file ownership without deleting source' );
$gone = $session->get( $token, 7 );
upload_same( 'certloom_import_mapping_session_expired', $gone->get_error_code(), 'consumed transient removed' );
$files->delete( $private_path );
echo "PASS  consume removes the mapping transient while retaining the private source for preflight transfer\n";

$token2 = $session->create( $source, 'credentials.csv', 7 );
$payload2 = $session->get( $token2, 7 );
$abandoned_path = $payload2['path'];
$res = $session->abandon( $token2, 7 );
upload_same( true, $res, 'abandon' );
upload_same( false, file_exists( $abandoned_path ), 'abandoned file deleted' );
echo "PASS  abandon removes both transient and protected recipient-data file\n";

$bad = $session->create( $source, 'credentials.xlsx', 7 );
upload_same( 'certloom_import_upload_type_invalid', $bad->get_error_code(), 'extension policy' );
echo "PASS  mapping session rejects unsupported upload extensions before retaining data\n";

@unlink( $source );
foreach ( glob( $upload_session_dir . '/*' ) ?: array() as $file ) { @unlink( $file ); }
@rmdir( $upload_session_dir );

echo "\n5 tests, 0 failures\n";
