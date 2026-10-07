<?php
/** Regression tests for Phase 1 Part B registry/lifecycle/field integration. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );

$GLOBALS['certloom_registry_options'] = array();
require __DIR__ . '/bootstrap-autoload.php';
require __DIR__ . '/support/option-database.php';
function wp_cache_delete() { return true; }
class RegistryTestDatabase {
	public $options = 'wp_options'; public $prefix = 'wp_'; public $last_error = '';
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function query( $prepared ) { return 1; }
	public function get_var( $prepared ) {
		if ( is_string( $prepared ) ) { return 50; }
		if ( 0 === strpos( $prepared['query'], 'SELECT ENGINE' ) ) { return 'InnoDB'; }
		if ( ( $prepared['args'][1] ?? '' ) === 'certloom_domain_write_fence' ) { return '1'; }
		return $GLOBALS['certloom_registry_options'][$prepared['args'][1] ?? ''] ?? null;
	}
	public function get_results( $prepared, $format ) { return certloom_test_option_rows( $prepared, $GLOBALS['certloom_registry_options'] ); }
}
$GLOBALS['wpdb'] = new RegistryTestDatabase();

function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function current_time( $type ) { return 'Y-m-d' === $type ? '2026-08-23' : '2026-08-23 04:00:00'; }
function is_admin() { return true; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['certloom_registry_options'] ) ? $GLOBALS['certloom_registry_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['certloom_registry_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['certloom_registry_options'][ $key ] ); return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {}
class Ecapla_CertVerify_Backup {
	public static function assert_writes_allowed() { return true; }
	public static function reserve_mutation_generation() { return 'g-test'; }
	public static function is_restore_in_progress() { return false; }
}
class Ecapla_CertVerify_Settings {
	public static function get( $key, $default = false ) { return $default; }
}

require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-fields.php';

function registry_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$defaults = Ecapla_CertVerify_Fields::get_default_fields();
$map = array();
foreach ( $defaults as $field ) {
	$map[ $field['key'] ] = $field;
}
registry_assert( isset( $map['status_reason'] ), 'REG-02 requires a first-class status reason field.' );
registry_assert( isset( $map['internal_notes'] ), 'REG-03 requires administrator internal notes.' );
registry_assert( ! empty( $map['internal_notes']['locked_visibility'] ), 'Internal notes must be locked private by schema.' );
registry_assert( empty( $map['internal_notes']['show_on_frontend'] ), 'Internal notes must default to non-public.' );
registry_assert( ! empty( $map['status_reason']['core'] ), 'Status reason must remain a protected core schema field.' );

$tampered = $defaults;
foreach ( $tampered as &$field ) {
	if ( 'internal_notes' === $field['key'] ) {
		$field['show_on_frontend'] = true;
	}
}
unset( $field );
$GLOBALS['certloom_registry_options'][ Ecapla_CertVerify_Fields::OPTION_KEY ] = $tampered;
Ecapla_CertVerify_Fields::reset_runtime_cache();
$normalized = Ecapla_CertVerify_Fields::get_fields();
foreach ( $normalized as $field ) {
	if ( 'internal_notes' === $field['key'] ) {
		registry_assert( empty( $field['show_on_frontend'] ), 'Tampered internal-note visibility must normalize fail-closed.' );
	}
}
$visible_keys = array();
foreach ( Ecapla_CertVerify_Fields::get_visible_fields() as $field ) {
	$visible_keys[] = $field['key'];
}
registry_assert( ! in_array( 'internal_notes', $visible_keys, true ), 'Internal notes must never enter public-visible fields.' );

$root = dirname( __DIR__ );
$sources = array(
	'database' => file_get_contents( $root . '/includes/class-ecapla-certverify-database.php' ),
	'list' => file_get_contents( $root . '/admin/class-ecapla-certverify-list-table.php' ),
	'admin' => file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' ),
	'view' => file_get_contents( $root . '/admin/views/list-certificates.php' ),
	'editor' => file_get_contents( $root . '/admin/views/edit-certificate.php' ),
	'fields' => file_get_contents( $root . '/admin/views/fields-manager.php' ),
	'dashboard' => file_get_contents( $root . '/admin/views/dashboard.php' ),
);
foreach ( $sources as $name => $source ) {
	registry_assert( false !== $source, 'Could not read ' . $name . ' source.' );
}
registry_assert( false !== strpos( $sources['database'], "'attention'      => ''" ) && false !== strpos( $sources['database'], "'expiring_soon' === \$attention" ), 'Registry repository must support the bounded expiring-soon view.' );
registry_assert( false !== strpos( $sources['list'], 'get_dashboard_metrics()' ) && false !== strpos( $sources['list'], "__( 'Expiring soon', 'certloom' )" ), 'Registry should expose operational status-count views.' );
registry_assert( false !== strpos( $sources['list'], "custom_fields']['status_reason']" ), 'Registry status presentation should surface administrator lifecycle reasons.' );
registry_assert( false !== strpos( $sources['list'], 'Ecapla_CertVerify_Fields::get_field_map()' ), 'Registry column labels must use the shared field schema instead of hard-coded labels.' );
registry_assert( false !== strpos( $sources['admin'], "! empty( \$field['locked_visibility'] ) ? false" ), 'Field-save handler must enforce locked-private visibility server-side.' );
registry_assert( false !== strpos( $sources['view'], 'certloom-filter-bar' ) && false !== strpos( $sources['view'], 'certloom-registry-views' ), 'Premium registry filter/view UI should be present.' );
registry_assert( false !== strpos( $sources['editor'], 'Status & reason' ) && false !== strpos( $sources['editor'], 'Internal notes' ), 'Certificate editor must integrate lifecycle reason and internal notes.' );
registry_assert( false !== strpos( $sources['fields'], 'Privacy-first field schema' ) && false !== strpos( $sources['fields'], 'locked_visibility' ), 'Field Manager must explain and present privacy locking.' );
registry_assert( false !== strpos( $sources['dashboard'], "'attention' => 'expiring_soon'" ), 'Dashboard expiry metric should deep-link into the actionable registry view.' );

foreach ( array( "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false", "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false", "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false" ) as $flag ) {
	$bootstrap = file_get_contents( $root . '/certloom.php' );
	registry_assert( false !== strpos( $bootstrap, $flag ), 'Future-domain production gate must remain disabled: ' . $flag );
}

echo "PASS  premium registry, lifecycle reasons, field privacy and authority gates are integrated\n";
echo "\n26 checks, 0 failures\n";
