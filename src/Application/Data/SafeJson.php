<?php
namespace Certloom\Application\Data;

/** Small fail-closed JSON boundary for structured domain payloads. */
final class SafeJson {
	/** @return string|\WP_Error */
	public function encode_array( $value, $code, $message ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( (string) $code, (string) $message );
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? new \WP_Error( (string) $code, (string) $message ) : $json;
	}

	/** @return array<mixed>|\WP_Error */
	public function decode_array( $value, $code, $message ) {
		if ( null === $value || '' === (string) $value ) {
			return array();
		}
		$decoded = json_decode( (string) $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new \WP_Error( (string) $code, (string) $message );
		}
		return $decoded;
	}
}
