<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $value ) {
		$result = @unserialize( $value, array( 'allowed_classes' => false ) );
		return false !== $result || 'b:0;' === $value ? $result : $value;
	}
}
/** Models actual option_value strings, independently of get_option's cached values. */
function certloom_test_option_rows( $prepared, array $options ) {
	if ( strpos( $prepared['query'], 'SELECT option_name, option_value' ) !== 0 ) { return null; }
	$args = $prepared['args'];
	if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
	$rows = array();
	foreach ( array_slice( $args, 1 ) as $key ) {
		if ( array_key_exists( $key, $options ) ) {
			$value = $options[ $key ];
			$rows[] = array( 'option_name' => $key, 'option_value' => is_array( $value ) || is_object( $value ) || is_bool( $value ) ? serialize( $value ) : (string) $value );
		}
	}
	return $rows;
}
final class CertloomOptionDatabase {
	public $options = 'wp_options';
	public $last_error = '';
	public $read_error = false;
	private $values;
	public function __construct( array &$values ) { $this->values =& $values; }
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_var( $prepared ) {
		$this->last_error = $this->read_error ? 'database unavailable' : '';
		return $this->read_error ? null : ( $this->values[ $prepared['args'][1] ] ?? null );
	}
	public function get_results( $prepared, $format ) {
		$this->last_error = $this->read_error ? 'database unavailable' : '';
		return $this->read_error ? null : certloom_test_option_rows( $prepared, $this->values );
	}
}
