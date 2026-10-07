<?php
/** Lossless, closed option transport and bounded uncached capture. No restoration or unserialization. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
require __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateConfigurationFormat;
use Certloom\Infrastructure\Backup\WholeStateOptionRepository;

class ConfigurationWakeupProbe { public static $woken = 0; public function __wakeup() { ++self::$woken; } }
class ConfigurationReadDatabase {
	public $options = 'wp_options'; public $last_error = ''; public $reads = array(); public $stored = array(); public $failure = null; public $mutation = null; public $extra = null;
	public function prepare( $query, $args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_results( $prepared, $mode ) {
		$values = false !== strpos( $prepared['query'], 'CASE WHEN' ); $kind = $values ? 'storage' : 'descriptors'; $this->reads[] = $prepared;
		if ( $this->failure === $kind ) { $this->last_error = 'Injected database failure.'; return null; }
		if ( $values && $this->mutation ) { $mutate = $this->mutation; $this->mutation = null; $mutate( $this ); }
		$rows = array();
		foreach ( $this->stored as $key => $bytes ) {
			if ( ! in_array( $key, WholeStateOptionInventory::keys(), true ) ) { continue; }
			$rows[] = $values ? array( 'option_name' => $key, 'storage' => strlen( $bytes ) <= WholeStateOptionInventory::MAX_OPTION_BYTES ? $bytes : null ) : array( 'option_name' => $key, 'bytes' => (string) strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) );
		}
		if ( null !== $this->extra ) { $rows[] = $this->extra; }
		return $rows;
	}
}
$passed = 0; $failed = 0;
$check = static function ( $ok, $label ) use ( &$passed, &$failed ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; if ( $ok ) { ++$passed; } else { ++$failed; } };
$format = new WholeStateOptionRecord(); $configuration = new WholeStateConfigurationFormat(); $repository = new WholeStateOptionRepository();
$keys = WholeStateOptionInventory::keys();
$check( 31 === count( $keys ) && 21 === count( WholeStateOptionInventory::keys( 'configuration' ) ) && $keys === array_values( array_unique( $keys ) ), 'inventory names thirty-one durable options with one owning component each' );
$check( array( 'certverify_fields', 'certverify_retired_fields', 'certverify_settings' ) === WholeStateOptionInventory::keys( 'domain' ) && array( 'certverify_number_sequence_next' ) === WholeStateOptionInventory::keys( 'legacy' ), 'historical domain and legacy configuration retains its existing component ownership' );
foreach ( array( 'cron', 'certloom_domain_schema', 'certloom_release_authority', 'certloom_pro_entitlement', 'certloom_backup_run_lock', 'certloom_backup_last_result', 'woocommerce_custom_orders_table_enabled', 'certloom_template_recommendations_123' ) as $key ) { $check( ! in_array( $key, $keys, true ), 'disposable/upstream/dynamic or authority state is outside fixed option capture: ' . $key ); }
$check( 'connection_review' === WholeStateOptionInventory::definitions()['certloom_google_drive_secrets']['reconciliation'] && 'automation_hold' === WholeStateOptionInventory::definitions()['certloom_email_automation_rules_v1']['reconciliation'] && 'replay_evidence' === WholeStateOptionInventory::definitions()['certloom_email_automation_runs_v1']['reconciliation'], 'local policies require connection review and held execution while preserving replay evidence' );

$records = array_fill_keys( $keys, $format->from_storage( null ) );
foreach ( array( '', '0', '0000123', 'b:0;', serialize( array( 'identity' => '00123', 'score' => 0, 'text' => "\u{0627}\u{0645}\u{06cc}\u{062f}", 'nested' => array( false, null, 0.0 ) ) ), "binary\x00\xff", serialize( new ConfigurationWakeupProbe() ) ) as $index => $storage ) {
	$record = $format->from_storage( $storage );
	$check( ! is_wp_error( $format->validate( $record ) ) && $record['exists'] && base64_decode( $record['storage_base64'], true ) === $storage && strlen( $storage ) === $record['bytes'], 'exact raw bytes, identities, types and binary transport are preserved, case ' . $index );
}
$check( 0 === ConfigurationWakeupProbe::$woken && $format->from_storage( null ) !== $format->from_storage( '' ), 'transport never instantiates serialized objects and distinguishes absence from a present empty option' );
$bad_records = array(); $valid = $format->from_storage( 'exact' );
foreach ( array( 'exists' => 1, 'bytes' => '5', 'sha256' => strtoupper( $valid['sha256'] ), 'storage_base64' => "ZXhhY3Q=\n" ) as $key => $value ) { $bad = $valid; $bad[ $key ] = $value; $bad_records[] = $bad; }
$bad = $valid; $bad['bytes'] = 4; $bad_records[] = $bad;
$bad = $valid; $bad['sha256'] = str_repeat( 'a', 64 ); $bad_records[] = $bad;
$bad = $valid; $bad['exists'] = false; $bad_records[] = $bad;
$bad = $valid; $bad['activate'] = true; $bad_records[] = $bad;
$bad = $format->from_storage( 'a' ); $bad['storage_base64'] = 'YR=='; $bad_records[] = $bad;
foreach ( $bad_records as $i => $bad ) { $check( is_wp_error( $format->validate( $bad ) ), 'typed, exact, canonical record rejects forgery/ambiguity, case ' . $i ); }
$check( is_wp_error( $format->from_storage( str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) ) ) && is_wp_error( $format->from_storage( false ) ), 'capture rejects oversized or non-storage values' );
$missing = $records; unset( $missing[ $keys[0] ] ); $expanded = $records; $expanded['certloom_pro_entitlement'] = $valid;
$check( is_wp_error( $format->validate_set( $missing, $keys ) ) && is_wp_error( $format->validate_set( $expanded, $keys ) ), 'closed complete records refuse omitted absence and expanded authority scope' );
$large = $format->from_storage( str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES ) ); $excess = $records; foreach ( array_slice( $keys, 0, 3 ) as $key ) { $excess[ $key ] = $large; }
$check( is_wp_error( $format->validate_set( $excess, $keys ) ), 'aggregate raw storage limit applies independently of each option bound' ); unset( $excess, $large );
$records['certverify_onboarding'] = $format->from_storage( 'a:1:{s:6:"active";b:0;}' );
$snapshot_fixture = array( 'inventory_version' => 1, 'options' => $records, 'fingerprint' => $format->fingerprint( $records ) );
$component = $configuration->from_snapshot( $snapshot_fixture ); $encoded = $configuration->encode( $component );
$bad_snapshot = $snapshot_fixture; $bad_snapshot['fingerprint'] = str_repeat( 'a', 64 );
$check( is_wp_error( $configuration->from_snapshot( $bad_snapshot ) ) && is_wp_error( $configuration->from_snapshot( array( 'options' => $records ) ) ), 'component capture requires the complete snapshot metadata and its exact option fingerprint' );
$bad_snapshot = $snapshot_fixture; $bad_snapshot['inventory_version'] = 2;
$check( is_wp_error( $configuration->from_snapshot( $bad_snapshot ) ), 'unsupported inventory snapshots cannot be relabeled as the current configuration format' );
$check( is_string( $encoded ) && $configuration->decode( $encoded ) == $component && 21 === count( $component['options'] ) && ! isset( $component['options']['certverify_settings'] ), 'canonical configuration transports its complete owning scope without duplicating historical domain payloads' );
$check( is_wp_error( $configuration->decode( str_replace( '"format_version":1', '"format_version":1,"format_version":1', $encoded ) ) ) && is_wp_error( $configuration->decode( ' ' . $encoded ) ), 'duplicate JSON properties and noncanonical component bytes fail before semantic or ownership review' );
foreach ( array( 'format_version' => '1', 'inventory_version' => 2, 'execute' => true, 'format' => 'other' ) as $key => $value ) { $bad = $component; $bad[ $key ] = $value; $check( is_wp_error( $configuration->encode( $bad ) ), 'configuration rejects unsupported/expanded metadata: ' . $key ); }
$bad = $component; $bad['options']['certverify_fields'] = $valid; $check( is_wp_error( $configuration->encode( $bad ) ), 'configuration cannot claim another component ownership' );
$before_hash = $format->fingerprint( $records ); $changed = $records; $changed['certverify_onboarding'] = $format->from_storage( 'a:1:{s:6:"active";b:1;}' );
$check( $before_hash !== $format->fingerprint( $changed ) && $before_hash === $format->fingerprint( array_reverse( $records, true ) ), 'fingerprints detect same-length changes and remain independent of database row order' );

$wpdb = new ConfigurationReadDatabase(); $wpdb->stored = array( 'certverify_onboarding' => '0', 'certloom_email_template_catalog_v1' => 'raw catalog', 'certloom_whole_unlisted' => 'never captured' );
$snapshot = $repository->snapshot();
$check( ! is_wp_error( $snapshot ) && 31 === count( $snapshot['options'] ) && true === $repository->verify( $snapshot['fingerprint'] ) && ! isset( $snapshot['options']['certloom_whole_unlisted'] ), 'uncached repository captures the complete explicit set and verifies exact storage without wildcard scope' );
$check( 3 === count( $wpdb->reads ) && count( $wpdb->reads[0]['args'] ) === 32 && count( $wpdb->reads[1]['args'] ) === 33 && false === strpos( implode( ' ', array_column( $wpdb->reads, 'query' ) ), 'LIKE' ) && false === strpos( implode( ' ', array_column( $wpdb->reads, 'query' ) ), 'FOR UPDATE' ), 'capture uses two bounded indexed read-only queries and one final descriptor verification' );
foreach ( array( 'change', 'delete', 'insert', 'oversized' ) as $mode ) {
	$wpdb = new ConfigurationReadDatabase(); $wpdb->stored = array( 'certverify_onboarding' => 'old' );
	$wpdb->mutation = static function ( $db ) use ( $mode ) { if ( 'delete' === $mode ) { unset( $db->stored['certverify_onboarding'] ); } elseif ( 'insert' === $mode ) { $db->stored['certloom_backup_schedule'] = 'off'; } else { $db->stored['certverify_onboarding'] = 'oversized' === $mode ? str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) : 'new'; } };
	$check( is_wp_error( $repository->snapshot() ), 'capture refuses a configuration ' . $mode . ' between descriptor and value reads' );
}
foreach ( array( 'storage', 'descriptors' ) as $mode ) { $wpdb = new ConfigurationReadDatabase(); $wpdb->failure = $mode; $check( is_wp_error( $repository->snapshot() ), 'checked database failure cannot become a complete capture: ' . $mode ); }
foreach ( array( array( 'option_name' => 'certloom_unlisted', 'bytes' => '1', 'sha256' => hash( 'sha256', 'a' ) ), array( 'option_name' => 'certverify_onboarding', 'bytes' => '01', 'sha256' => hash( 'sha256', 'a' ) ), array( 'option_name' => 'certverify_onboarding', 'bytes' => '-1', 'sha256' => hash( 'sha256', 'a' ) ), array( 'option_name' => 'certverify_onboarding', 'bytes' => '1', 'sha256' => null ) ) as $i => $row ) { $wpdb = new ConfigurationReadDatabase(); $wpdb->extra = $row; $check( is_wp_error( $repository->snapshot() ), 'malformed/expanded database descriptors fail closed, case ' . $i ); }
$wpdb = new ConfigurationReadDatabase(); $wpdb->stored = array( 'certverify_onboarding' => 'a' ); $wpdb->extra = array( 'option_name' => 'certverify_onboarding', 'bytes' => '1', 'sha256' => hash( 'sha256', 'a' ) );
$check( is_wp_error( $repository->snapshot() ), 'ambiguous duplicate option rows cannot choose which value is captured' );
$wpdb = new ConfigurationReadDatabase(); $snapshot = $repository->snapshot(); $wpdb->stored['certloom_backup_retention'] = '14';
$check( is_wp_error( $repository->verify( $snapshot['fingerprint'] ) ) && is_wp_error( $repository->verify( 'untrusted' ) ), 'final presence change or invalid fingerprint prevents publication' );
echo "\n$passed checks passed; $failed failed.\n";
exit( $failed ? 1 : 0 );
