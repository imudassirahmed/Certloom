<?php
/** Database-session fixture for the draft workspace's cross-request mutex. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $key, $group ) { return true; } }
final class CertloomDraftTestDatabase {
	public $usermeta = 'wp_usermeta'; public $last_error = '';
	public function prepare( $query, ...$args ) { return $query; }
	public function get_var( $query ) { return false !== strpos( $query, 'GET_LOCK' ) || false !== strpos( $query, 'RELEASE_LOCK' ) ? '1' : null; }
}
$GLOBALS['wpdb'] = new CertloomDraftTestDatabase();
