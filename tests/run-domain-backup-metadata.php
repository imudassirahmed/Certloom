<?php
/** Archive metadata must be validated, never repaired by scalar coercion. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message ) {} }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Backup\DomainBackupReader;
$format = new DomainBackupFormat(); $bounds = array();
foreach ( DomainBackupFormat::entity_types_for_version( DomainBackupFormat::VERSION ) as $type ) { $bounds[$type] = array( 'count' => 'issuers' === $type ? 1 : 0, 'max_id' => 'issuers' === $type ? 1 : 0 ); }
$manifest = $format->manifest( array( 'entities' => $bounds, 'configuration' => array(), 'configuration_hash' => hash( 'sha256', '[]' ) ), '1.7.0-test' );
$first = $format->encode_line( $manifest ); $second = $format->encode_line( array( 'type' => 'entity', 'entity' => 'issuers', 'data' => array( 'id' => 1 ) ) );
$chain = $format->next_hash( $format->next_hash( '', $first ), $second );
$footer = array( 'type' => 'integrity', 'records' => 1, 'chain_sha256' => $chain );
$checks = 0; $failures = 0;
function metadata_check( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
$path = tempnam( sys_get_temp_dir(), 'certloom-metadata-' );
if ( ! $path ) { throw new RuntimeException( 'Cannot create owned archive fixture.' ); }
set_error_handler( static function ( $severity, $message ) { throw new RuntimeException( $message ); } );
try {
	file_put_contents( $path, $first . $second . $format->encode_line( $footer ) );
	metadata_check( ! is_wp_error( ( new DomainBackupReader() )->analyze( $path ) ), 'valid canonical integrity/count metadata remains readable' );
	foreach ( array( null, -1, '1', 1.5, true, array( 1 ) ) as $count ) {
		$bad = $footer; if ( null === $count ) { unset( $bad['records'] ); } else { $bad['records'] = $count; }
		file_put_contents( $path, $first . $second . $format->encode_line( $bad ) );
		metadata_check( is_wp_error( ( new DomainBackupReader() )->analyze( $path ) ), 'missing/negative/string/fractional/boolean/array integrity counts are refused' );
	}
	foreach ( array( -4, '4', 4.1, true, array( 4 ) ) as $version ) {
		$bad = $manifest; $bad['format_version'] = $version;
		metadata_check( is_wp_error( $format->normalize_manifest( $bad ) ), 'malformed format versions are refused without coercion' );
	}
	foreach ( array( -1, '1', 1.5, true, array( 1 ) ) as $count ) {
		$bad = $manifest; $bad['entities']['issuers']['count'] = $count;
		metadata_check( is_wp_error( $format->normalize_manifest( $bad ) ), 'malformed manifest bounds are refused without coercion' );
	}
	$bad = $manifest; $bad['type'] = array( 'manifest' );
	metadata_check( is_wp_error( $format->normalize_manifest( $bad ) ), 'array record type is rejected without PHP warnings' );
} finally { restore_error_handler(); unlink( $path ); }
echo "\n$checks checks, $failures failures\n"; exit( $failures ? 1 : 0 );
