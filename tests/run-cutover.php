<?php
/** Pure tests for the final source-of-truth cutover safeguards. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', true );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', true );
$GLOBALS['cutover_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['cutover_options'] ) ? $GLOBALS['cutover_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['cutover_options'][ $key ] = $value; return true; }
function delete_option( $key ) { if ( array_key_exists( $key, $GLOBALS['cutover_options'] ) ) { unset( $GLOBALS['cutover_options'][ $key ] ); return true; } return false; }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Migration\LegacyRegistryWriteGuard;

function cut_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$state = new LegacyMigrationState();
$GLOBALS['cutover_options'][ LegacyMigrationState::OPTION ] = array(
	'status' => LegacyMigrationState::COMPLETE,
	'source_count' => 7,
	'source_max_id' => 11,
	'source_generation' => 'generation-A',
);

cut_same( true, $state->source_snapshot_matches( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-A' ) ), 'matching source' );
cut_same( false, $state->source_snapshot_matches( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-B' ) ), 'changed generation' );
cut_same( false, $state->source_snapshot_matches( array( 'count' => 8, 'max_id' => 11, 'generation' => 'generation-A' ) ), 'changed count' );
cut_same( false, $state->source_snapshot_matches( array( 'count' => 7, 'max_id' => 12, 'generation' => 'generation-A' ) ), 'changed max id' );

$cutover = new DomainCutoverState();
$result = $cutover->promote_to_domain( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-B' ) );
cut_same( 'certloom_domain_cutover_source_changed', $result->get_error_code(), 'stale migration cannot cut over' );
cut_same( DomainCutoverState::LEGACY, $cutover->authority(), 'authority remains legacy on stale source' );
cut_same( true, $cutover->promote_to_domain( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-A' ) ), 'fresh migration can cut over' );
cut_same( DomainCutoverState::DOMAIN, $cutover->authority(), 'authority promoted after exact source match' );
$guard = new LegacyRegistryWriteGuard();
$blocked_write = $guard->assert_allowed();
cut_same( 'certloom_legacy_registry_read_only', $blocked_write->get_error_code(), 'legacy writes blocked after cutover' );
cut_same( true, $cutover->keep_legacy_authoritative(), 'legacy authority can be restored for rollback before public cutover' );
cut_same( true, $guard->assert_allowed(), 'legacy writes allowed while legacy remains authoritative' );

echo "PASS  source-of-truth cutover requires exact completed migration snapshot\n\n1 tests, 0 failures\n";
