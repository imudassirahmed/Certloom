<?php
/** Actual storage/stream invariants; no recovery writes, unserialization or execution authority. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
require __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Backup\WholeStateAuthoringFormat;
use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateTemplateRecommendationInventory;
use Certloom\Infrastructure\Backup\WholeStateTemplateRecommendationRepository;

class AuthoringWakeupProbe { public static $woken = 0; public function __wakeup() { ++self::$woken; } }
class AuthoringReadDatabase {
	public $options = 'wp_options'; public $last_error = ''; public $stored = array(); public $reads = array(); public $mutation = null; public $late_mutation = null; public $failure = null; public $extra = null; private $descriptor_reads = 0;
	public function prepare( $sql, $args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function get_results( $prepared, $mode ) {
		$this->reads[] = $prepared; $values = false !== strpos( $prepared['sql'], 'CASE WHEN' ); $kind = $values ? 'storage' : 'descriptors';
		if ( $this->failure === $kind ) { $this->last_error = 'Injected failure'; return null; }
		if ( $values && $this->mutation ) { $mutate = $this->mutation; $this->mutation = null; $mutate( $this ); }
		if ( ! $values && ++$this->descriptor_reads === 2 && $this->late_mutation ) { $mutate = $this->late_mutation; $this->late_mutation = null; $mutate( $this ); }
		$rows = array(); $stored = $this->stored; ksort( $stored, SORT_STRING ); $keys = array(); $lengths = array();
		if ( $values ) {
			$count = ( count( $prepared['args'] ) - 2 ) / 3;
			for ( $i = 0; $i < $count; ++$i ) { $lengths[ $prepared['args'][ 2 * $i ] ] = $prepared['args'][ 2 * $i + 1 ]; }
			$keys = array_slice( $prepared['args'], 2 * $count + 1, $count );
		}
		foreach ( $stored as $name => $bytes ) {
			if ( $values ? ! in_array( $name, $keys, true ) : 0 !== strncasecmp( $name, WholeStateTemplateRecommendationInventory::PREFIX, strlen( WholeStateTemplateRecommendationInventory::PREFIX ) ) ) { continue; }
			$rows[] = $values ? array( 'option_name' => $name, 'storage' => strlen( $bytes ) === $lengths[ $name ] ? $bytes : null ) : array( 'option_name' => $name, 'bytes' => (string) strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) );
		}
		if ( null !== $this->extra ) { $rows[] = $this->extra; }
		return array_slice( $rows, 0, $values ? WholeStateTemplateRecommendationRepository::PAGE_SIZE + 1 : WholeStateTemplateRecommendationInventory::MAX_OPTIONS + 1 );
	}
}
$passed = 0; $failed = 0;
$check = static function ( $ok, $label ) use ( &$passed, &$failed ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $ok ? ++$passed : ++$failed; };
$prefix = WholeStateTemplateRecommendationInventory::PREFIX; $record = new WholeStateOptionRecord(); $inventory = new WholeStateTemplateRecommendationInventory(); $format = new WholeStateAuthoringFormat(); $repository = new WholeStateTemplateRecommendationRepository();
$check( 1 === $inventory::template_id( $prefix . '1' ) && PHP_INT_MAX === $inventory::template_id( $prefix . PHP_INT_MAX ), 'canonical positive platform-safe native Template IDs are preserved exactly' );
foreach ( array( '0', '01', '-1', '+1', '1.0', '1e2', '1 ', (string) PHP_INT_MAX . '0', 'bad' ) as $suffix ) { $check( false === $inventory::template_id( $prefix . $suffix ), 'noncanonical/unrepresentable Template reference rejected: ' . $suffix ); }
$check( false === $inventory::template_id( 'certloom_pro_entitlement' ) && false === $inventory::template_id( strtoupper( $prefix ) . '1' ) && false === $inventory::template_id( array( '1' ) ), 'only the exact local dynamic authoring namespace is recognized' );
$wpdb = new AuthoringReadDatabase(); $empty = $repository->snapshot();
$check( ! is_wp_error( $empty ) && array() === $empty['options'] && true === $repository->verify( $empty['fingerprint'] ), 'empty dynamic inventory has an exact verifiable presence fingerprint' );
$wpdb = new AuthoringReadDatabase();
$wpdb->stored = array( $prefix . '7' => serialize( array( 'training_lms' ) ), $prefix . '900' => "\x00\xff00123", $prefix . '91' => serialize( new AuthoringWakeupProbe() ), 'certloom_unlisted_authority' => 'excluded' );
$captured = $repository->snapshot();
$check( ! is_wp_error( $captured ) && 3 === count( $captured['options'] ) && "\x00\xff00123" === base64_decode( $captured['options'][ $prefix . '900' ]['storage_base64'], true ) && 0 === AuthoringWakeupProbe::$woken, 'raw metadata including orphan references and opaque serialized values is captured without instantiation or filtering' );
$check( true === $repository->verify( $captured['fingerprint'] ) && ! is_wp_error( $inventory->validate_snapshot( $captured ) ), 'complete dynamic snapshot metadata and fingerprint agree' );
$bad = $captured; $bad['inventory_version'] = '1'; $check( is_wp_error( $inventory->validate_snapshot( $bad ) ), 'inventory metadata is strictly typed' );
$bad = $captured; $bad['fingerprint'] = str_repeat( 'f', 64 ); $check( is_wp_error( $inventory->validate_snapshot( $bad ) ), 'dynamic record digest cannot be substituted' );
$bad = $captured; $bad['restore_ready'] = true; $check( is_wp_error( $inventory->validate_snapshot( $bad ) ), 'capture cannot claim additional restoration authority' );
$bad = $captured['options']; $bad[ $prefix . '7' ] = $record->from_storage( null ); $check( is_wp_error( $inventory->validate_records( $bad ) ), 'a captured dynamic row cannot be disguised as absence' );
$wpdb = new AuthoringReadDatabase(); for ( $i = 1; $i <= 201; ++$i ) { $wpdb->stored[ $prefix . $i ] = '0'; }
$paged = $repository->snapshot(); $check( ! is_wp_error( $paged ) && 201 === count( $paged['options'] ) && 4 === count( $wpdb->reads ) && false !== strpos( $wpdb->reads[1]['sql'], 'OCTET_LENGTH(option_value) = CASE option_name' ), '201 rows use two bounded value pages with per-key length guards and a complete final namespace check' );
foreach ( array( 'change', 'grow', 'delete', 'insert', 'huge' ) as $mode ) {
	$wpdb = new AuthoringReadDatabase(); $wpdb->stored = array( $prefix . '1' => 'old' );
	$wpdb->mutation = static function ( $db ) use ( $mode, $prefix ) { if ( 'delete' === $mode ) { unset( $db->stored[ $prefix . '1' ] ); } elseif ( 'insert' === $mode ) { $db->stored[ $prefix . '2' ] = 'new'; } else { $db->stored[ $prefix . '1' ] = 'huge' === $mode ? str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) : ( 'grow' === $mode ? 'longer' : 'new' ); } };
	$check( is_wp_error( $repository->snapshot() ), 'racing metadata ' . $mode . ' cannot produce accepted capture' );
}
$wpdb = new AuthoringReadDatabase(); $wpdb->stored = array( $prefix . '1' => 'old' ); $wpdb->late_mutation = static function ( $db ) use ( $prefix ) { $db->stored[ $prefix . '2' ] = 'new'; };
$check( is_wp_error( $repository->snapshot() ), 'namespace phantom inserted after value pages is refused' );
foreach ( array( 'descriptors', 'storage' ) as $kind ) { $wpdb = new AuthoringReadDatabase(); $wpdb->stored = array( $prefix . '1' => 'a' ); $wpdb->failure = $kind; $check( is_wp_error( $repository->snapshot() ), 'checked SQL ' . $kind . ' error cannot become empty metadata' ); }
foreach ( array( $prefix . '01', strtoupper( $prefix ) . '1' ) as $name ) { $wpdb = new AuthoringReadDatabase(); $wpdb->stored = array( $name => 'a' ); $check( is_wp_error( $repository->snapshot() ) && 1 === count( $wpdb->reads ), 'malformed namespace member fails before body reads: ' . $name ); }
$wpdb = new AuthoringReadDatabase(); $wpdb->stored = array( $prefix . '1' => str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) );
$check( is_wp_error( $repository->snapshot() ) && 1 === count( $wpdb->reads ), 'oversized raw metadata fails before body fetch' );
$wpdb = new AuthoringReadDatabase(); for ( $i = 1; $i <= $inventory::MAX_OPTIONS + 1; ++$i ) { $wpdb->stored[ $prefix . $i ] = ''; }
$check( is_wp_error( $repository->snapshot() ) && 1 === count( $wpdb->reads ), 'over-limit namespace never silently truncates an inventory' );
$wpdb = new AuthoringReadDatabase(); for ( $i = 1; $i <= $inventory::MAX_OPTIONS; ++$i ) { $wpdb->stored[ $prefix . $i ] = ''; }
$maximum = $repository->snapshot();
$check( ! is_wp_error( $maximum ) && $inventory::MAX_OPTIONS === count( $maximum['options'] ) && 27 === count( $wpdb->reads ), 'the full supported namespace fits twenty-five bounded value pages with both complete descriptor reads' );
$fixed_records = array_fill_keys( WholeStateOptionInventory::keys(), $record->from_storage( null ) ); $fixed_records['certloom_email_template_catalog_v1'] = $record->from_storage( serialize( array( 'name' => 'Email design', 'markup' => '<p>00123</p>' ) ) );
$fixed = array( 'inventory_version' => 1, 'options' => $fixed_records, 'fingerprint' => $record->fingerprint( $fixed_records ) );
$authoring = $format->from_snapshots( $fixed, $captured ); $encoded = $format->encode( $authoring );
$minimal = $format->from_snapshots( $fixed, $empty );
$check( ! is_wp_error( $minimal ) && 2 === count( $minimal ) && $format->decode( $format->encode( $minimal ) ) == $minimal, 'a site without dynamic authoring retains both fixed-option presence records' );
$maximum_stream = $format->encode( $format->from_snapshots( $fixed, $maximum ) );
$check( is_string( $maximum_stream ) && $inventory::MAX_OPTIONS + 2 === count( $format->decode( $maximum_stream ) ) && WholeStateAuthoringFormat::MAX_BYTES === \Certloom\Application\Backup\WholeStateBackupFormat::member_limit( 'data/authoring.jsonl' ), 'maximum supported authoring round trip fits the outer envelope component bound' );
$check( is_string( $encoded ) && $format->decode( $encoded ) == $authoring && 5 === count( $authoring ) && ! isset( $authoring['certverify_settings'] ), 'authoring stream combines its two fixed options and approved dynamic namespace without duplicating native domain state' );
$check( $encoded === $format->encode( array_reverse( $authoring, true ) ), 'canonical name ordering makes equivalent authoring streams reproducible' );
$check( 0 === AuthoringWakeupProbe::$woken, 'stream decode never unserializes opaque captured bytes' );
$lines = explode( "\n", rtrim( $encoded, "\n" ) ); $bad_lines = $lines; $bad_lines[2] = $bad_lines[1];
$check( is_wp_error( $format->decode( implode( "\n", $bad_lines ) . "\n" ) ), 'duplicate option identities in a component are refused' );
$bad_lines = $lines; list( $bad_lines[1], $bad_lines[2] ) = array( $bad_lines[2], $bad_lines[1] );
$check( is_wp_error( $format->decode( implode( "\n", $bad_lines ) . "\n" ) ), 'out-of-order option lines are refused' );
foreach ( array( str_replace( '"format_version":1', '"format_version":1,"format_version":1', $encoded ), ' ' . $encoded, rtrim( $encoded, "\n" ), str_replace( '"option_count":5', '"option_count":4', $encoded ), str_replace( '"format_version":1', '"format_version":2', $encoded ) ) as $i => $bad_bytes ) { $check( is_wp_error( $format->decode( $bad_bytes ) ), 'ambiguous, truncated or unsupported stream rejected, case ' . $i ); }
$bad_lines = $lines; $footer = json_decode( end( $bad_lines ), true ); $footer['fingerprint'] = str_repeat( 'a', 64 ); $bad_lines[ count( $bad_lines ) - 1 ] = ( new \Certloom\Application\Rendering\CanonicalJson() )->encode( $footer );
$check( is_wp_error( $format->decode( implode( "\n", $bad_lines ) . "\n" ) ), 'complete-stream storage fingerprint detects footer forgery' );
$bad = $authoring; unset( $bad['certloom_email_template_issued_v1'] ); $check( is_wp_error( $format->encode( $bad ) ), 'fixed absent authoring options must still be explicit' );
$bad = $authoring; $bad['certloom_pro_entitlement'] = $record->from_storage( '1' ); $check( is_wp_error( $format->encode( $bad ) ), 'authoring cannot carry executable or commercial authority options' );
$large = $record->from_storage( str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES ) ); $bad = $authoring; foreach ( array_slice( array_keys( $bad ), 0, 3 ) as $name ) { $bad[ $name ] = $large; }
$check( is_wp_error( $format->encode( $bad ) ), 'component-wide raw storage cap includes both fixed and dynamic records' );
echo "\n$passed passed, $failed failed\n"; exit( $failed ? 1 : 0 );
