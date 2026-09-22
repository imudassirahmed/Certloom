<?php
/** BAK-02B canonical builder -> real restore-analyzer round-trip regression. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ECAPLA_CERTVERIFY_VERSION', '1.6.89-test' );
define( 'ECAPLA_CERTVERIFY_SCHEMA_VERSION', '1.4.7' );

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( (string) $value ) ); }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_tempnam( $name = '' ) { return tempnam( sys_get_temp_dir(), 'certloom-roundtrip-' ); }
$GLOBALS['roundtrip_password_counter'] = 0;
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	$GLOBALS['roundtrip_password_counter']++;
	$seed = 'roundtrip' . $GLOBALS['roundtrip_password_counter'];
	return substr( str_repeat( $seed, 8 ), 0, $length );
}
function wp_list_pluck( $list, $field ) { $out = array(); foreach ( (array) $list as $row ) { if ( is_array( $row ) && array_key_exists( $field, $row ) ) { $out[] = $row[ $field ]; } } return $out; }
function get_current_user_id() { return 71; }

$GLOBALS['roundtrip_options'] = array(
	'certverify_data_generation' => 'gen-stable',
	'fields_opt'                  => array( array( 'key' => 'course', 'label' => 'Course', 'type' => 'text' ) ),
	'retired_opt'                 => array(),
	'settings_opt'                => array( 'certificate_number_strategy' => 'manual' ),
	'number_state'                => null,
);
$GLOBALS['roundtrip_transients'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['roundtrip_options'] ) ? $GLOBALS['roundtrip_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['roundtrip_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['roundtrip_options'][ $key ] ); return true; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['roundtrip_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['roundtrip_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['roundtrip_transients'][ $key ] ); return true; }

class Ecapla_CertVerify_Fields {
	const OPTION_KEY = 'fields_opt';
	const RETIRED_OPTION_KEY = 'retired_opt';
	public static function get_fields() { return array( array( 'key' => 'course', 'label' => 'Course', 'type' => 'text' ) ); }
	public static function get_retired_fields() { return array(); }
	public static function get_field_map() { return array( 'course' => array( 'key' => 'course', 'label' => 'Course', 'type' => 'text' ) ); }
}
class Ecapla_CertVerify_Settings {
	const OPTION_KEY = 'settings_opt';
	public static function get_all() { return array( 'certificate_number_strategy' => 'manual' ); }
}
class Ecapla_CertVerify_Numbering {
	const STATE_OPTION = 'number_state';
	public static function normalize_policy( $settings ) { return array( 'certificate_number_strategy' => 'manual' ); }
	public static function export_state() { return null; }
	public static function validate_backup_state( $state ) { return null === $state || is_string( $state ) ? $state : new WP_Error( 'bad_number_state', 'bad numbering state' ); }
}
class Ecapla_CertVerify_Database {
	public static $snapshot_calls = 0;
	public static function get_registry_snapshot() { self::$snapshot_calls++; return array( 'count' => 2, 'max_id' => 2, 'max_updated_at' => '2026-08-29 00:00:00' ); }
	public static function get_export_batch( $last, $limit ) {
		if ( $last >= 2 ) { return array(); }
		return array(
			array( 'id' => 1, 'cert_number' => 'CERT-ROUND-1', 'status' => 'valid', 'status_source' => 'manual', 'holder_name' => 'Ada Lovelace', 'issue_date' => '2026-01-01', 'expiry_date' => null, 'custom_fields' => array( 'course' => 'Analytical Engines' ), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ),
			array( 'id' => 2, 'cert_number' => 'CERT-ROUND-2', 'status' => 'withdrawn', 'status_source' => 'manual', 'holder_name' => 'Lin Chen', 'issue_date' => '2026-01-02', 'expiry_date' => '2027-01-02', 'custom_fields' => array( 'course' => 'Credential Operations' ), 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00' ),
		);
	}
	public static function workflow_status( $record ) {
		$status = sanitize_key( $record['status'] ?? 'valid' );
		return in_array( $status, array( 'suspended', 'withdrawn' ), true ) ? $status : ( 'expired' === $status ? 'expired' : 'valid' );
	}
	public static function effective_status_source( $record ) { return sanitize_key( $record['status_source'] ?? 'manual' ) ?: 'manual'; }
	public static function certificate_hash( $cert_number ) { return hash( 'sha256', strtolower( trim( (string) $cert_number ) ) ); }
	public static function get_existing_number_map( $numbers ) {
		$map = array();
		foreach ( (array) $numbers as $number ) {
			if ( 'CERT-ROUND-1' === $number ) { $map[ self::certificate_hash( $number ) ] = 501; }
		}
		return $map;
	}
	public static function get_registry_count() { return 2; }
}

require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-backup.php';

$checks = 0; $fails = 0;
function rcheck( $value, $message ) { global $checks, $fails; $checks++; if ( $value ) { echo "PASS  $message\n"; } else { $fails++; echo "FAIL  $message\n"; } }

$built = Ecapla_CertVerify_Backup::create_export_file();
rcheck( is_array( $built ) && is_file( $built['path'] ?? '' ), 'canonical builder creates one complete current-authority file' );
rcheck( 'current_registry' === ( $built['source_authority'] ?? '' ), 'round-trip source remains current registry authority' );
rcheck( 2 === ( $built['records'] ?? -1 ), 'canonical file contains the expected record count' );

$token = is_array( $built ) ? Ecapla_CertVerify_Backup::analyze_file( $built['path'] ) : new WP_Error( 'no_file', 'no file' );
rcheck( is_string( $token ) && 0 === strpos( $token, 'ecapla_certverify_backup_' ), 'real restore analyzer accepts the exact canonical-builder output' );
$preview = is_string( $token ) ? Ecapla_CertVerify_Backup::get_preview( $token ) : false;
rcheck( is_array( $preview ), 'real analyzer stores a bounded non-destructive restore preview' );
rcheck( 2 === ( $preview['records'] ?? -1 ), 'restore preview preserves exact backup record count' );
rcheck( 2 === ( $preview['current_records'] ?? -1 ), 'restore preview reads the current registry count' );
rcheck( 1 === ( $preview['conflicts'] ?? -1 ) && 1 === ( $preview['new_records'] ?? -1 ), 'restore preview computes existing/new certificate counts from canonical bytes' );
rcheck( 1 === ( $preview['fields'] ?? -1 ) && 0 === ( $preview['backup_only_fields'] ?? -1 ), 'restore preview preserves field-definition compatibility' );

if ( is_string( $token ) ) { Ecapla_CertVerify_Backup::delete_session( $token ); }
rcheck( is_string( $token ) && false === get_transient( $token ), 'round-trip review session can be deleted cleanly after test' );

// Tamper the same canonical file without rewriting its integrity line: the real analyzer must fail closed.
if ( is_array( $built ) && is_file( $built['path'] ) ) {
	$content = file_get_contents( $built['path'] );
	$content = preg_replace( '/Ada Lovelace/', 'Ada Tampered', $content, 1 );
	file_put_contents( $built['path'], $content );
	$tampered = Ecapla_CertVerify_Backup::analyze_file( $built['path'] );
	rcheck( is_wp_error( $tampered ) && 'ecapla_certverify_backup_integrity' === $tampered->get_error_code(), 'real analyzer rejects tampered canonical backup bytes' );
	@unlink( $built['path'] );
} else {
	rcheck( false, 'real analyzer rejects tampered canonical backup bytes' );
}

rcheck( empty( $GLOBALS['roundtrip_transients'] ), 'failed tamper analysis leaves no temporary review chunks behind' );

echo "\n$checks checks, $fails failures\n";
exit( $fails ? 1 : 0 );
