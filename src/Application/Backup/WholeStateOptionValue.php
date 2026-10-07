<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Non-executable decoding for component preflight. Decoding grants no import permission. */
final class WholeStateOptionValue {
	const MAX_DEPTH = 32;
	const MAX_NODES = 50000;
	public function decode( array $record ) {
		$valid = ( new WholeStateOptionRecord() )->validate( $record );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( ! $record['exists'] ) { return array( 'exists' => false, 'value' => null ); }
		$raw = base64_decode( $record['storage_base64'], true );
		$value = $raw;
		// WordPress serializes arrays and already-serialized strings. Its raw
		// scalar option storage remains text, including an empty present value.
		if ( 'N;' === $raw || preg_match( '/^[aObsidCR]:/', $raw ) ) {
			set_error_handler( static function () { throw new \RuntimeException( 'Invalid serialized recovery value.' ); } );
			try {
				$value = unserialize( $raw, array( 'allowed_classes' => false, 'max_depth' => self::MAX_DEPTH ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Objects are disabled and canonical bytes are checked.
				if ( serialize( $value ) !== $raw ) { return $this->invalid(); }
			} catch ( \Throwable $error ) { return $this->invalid(); }
			finally { restore_error_handler(); }
		}
		$nodes = 0;
		return $this->safe( $value, 0, $nodes ) ? array( 'exists' => true, 'value' => $value ) : $this->invalid();
	}
	private function safe( $value, $depth, &$nodes ) {
		if ( ++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH ) { return false; }
		if ( is_array( $value ) ) { foreach ( $value as $key => $item ) { if ( ( ! is_int( $key ) && ! is_string( $key ) ) || ! $this->safe( $item, $depth + 1, $nodes ) ) { return false; } } return true; }
		return null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) || ( is_float( $value ) && is_finite( $value ) );
	}
	private function invalid() { return new \WP_Error( 'certloom_whole_option_value_invalid', __( 'A recovery setting contains unsupported or unsafe data. Nothing was imported.', 'certloom' ) ); }
}
