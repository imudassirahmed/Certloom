<?php
require_once __DIR__ . '/bootstrap-autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = (string) $code; $this->message = (string) $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }

use Certloom\Application\Data\SafeJson;

function sj_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS  {$message}\n"; }

$json = new SafeJson();
$encoded = $json->encode_array( array( 'name' => 'اردو', 'nested' => array( 'ok' => true ) ), 'encode', 'encode failed' );
sj_assert( is_string( $encoded ) && false !== strpos( $encoded, 'اردو' ), 'valid structured data encodes without Unicode escaping' );
$decoded = $json->decode_array( $encoded, 'decode', 'decode failed' );
sj_assert( is_array( $decoded ) && true === $decoded['nested']['ok'], 'valid structured data decodes as an array' );
sj_assert( array() === $json->decode_array( null, 'decode', 'decode failed' ), 'NULL storage is the canonical empty structured value' );
sj_assert( is_wp_error( $json->encode_array( 'not-an-array', 'encode', 'encode failed' ) ), 'non-array structured payloads fail closed before persistence' );
$resource = fopen( 'php://memory', 'rb' );
$bad = $json->encode_array( array( 'resource' => $resource ), 'encode', 'encode failed' );
if ( is_resource( $resource ) ) { fclose( $resource ); }
sj_assert( is_wp_error( $bad ), 'unencodable nested values fail closed before persistence' );
sj_assert( is_wp_error( $json->decode_array( '{broken', 'decode', 'decode failed' ) ), 'corrupt JSON fails closed on repository reads' );
sj_assert( is_wp_error( $json->decode_array( '"scalar"', 'decode', 'decode failed' ) ), 'scalar JSON cannot masquerade as structured repository data' );
