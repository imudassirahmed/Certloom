<?php
/** Origin signatures never become account ownership; read-only verification never provisions a root or salts. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class Ecapla_CertVerify_Permissions { const CAP_MANAGE_DATA_TOOLS = 'certloom_manage_data_tools'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function get_current_user_id() { return 0; }
function get_current_blog_id() { return 1; }
function current_user_can( $cap ) { return false; }
$GLOBALS['salt_calls'] = 0;
function get_site_option( $key, $default = false ) { return $default; }
function wp_salt( $scheme ) { ++$GLOBALS['salt_calls']; return str_repeat( 'never generated during verification', 3 ); }
require __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Backup\WholeStateBackupFormat;
use Certloom\Application\Backup\WholeStateBackupProvenance;
use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;

$passed = 0; $failed = 0;
$check = static function ( $ok, $label ) use ( &$passed, &$failed ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; if ( $ok ) { ++$passed; } else { ++$failed; } };
$id = str_repeat( 'a', 32 ); $key = hex2bin( str_repeat( '17', 32 ) );
$manifest = array( 'format' => WholeStateBackupFormat::FORMAT, 'format_version' => 1, 'created_at' => '2026-10-03T10:00:00Z', 'plugin_version' => '1.7.0-alpha.4.87', 'scope' => WholeStateBackupFormat::scope(), 'source' => array( 'installation_id' => $id, 'export_id' => str_repeat( 'b', 32 ), 'blog_id' => 1, 'home_url' => 'http://ecapla.local', 'generation' => '1790000000.123456|abcdef123456abcdef123456' ), 'provenance' => array( 'algorithm' => 'site-hmac-sha256', 'tag' => str_repeat( '0', 64 ) ), 'members' => array() );
foreach ( WholeStateBackupFormat::required_members() as $name ) { $manifest['members'][ $name ] = array( 'bytes' => 3, 'sha256' => hash( 'sha256', '{}'."\n" ) ); }
$signer = new WholeStateBackupProvenance(); $signed = $signer->sign( $manifest, $key, $id, 1 );
$check( is_array( $signed ) && true === $signer->verify( $signed, $key, $id, 1 ) && $signed['provenance']['tag'] !== $manifest['provenance']['tag'], 'a complete origin signature verifies with the independently held local key' );
$reference_message = WholeStateBackupProvenance::CONTEXT . "\n" . ( new WholeStateBackupFormat() )->encode_manifest( $manifest );
$check( hash_hmac( 'sha256', $reference_message, $key ) === $signed['provenance']['tag'], 'signature binds the explicit protocol context and complete canonical manifest with fixed tag placeholder' );
$check( $signed === $signer->sign( $signed, $key, $id, 1 ) && $signed['provenance']['tag'] === $signer->sign( array_reverse( $manifest, true ), $key, $id, 1 )['provenance']['tag'], 'signatures are deterministic across prior tags and manifest field order' );
$check( is_wp_error( $signer->verify( $signed, str_repeat( 'x', 32 ), $id, 1 ) ), 'a different local root cannot authenticate a backup with matching claimed site identity' );
foreach ( array( 'plugin_version' => '1.7.0-alpha.4.88', 'created_at' => '2026-10-03T11:00:00Z' ) as $name => $value ) { $bad = $signed; $bad[ $name ] = $value; $check( is_wp_error( $signer->verify( $bad, $key, $id, 1 ) ), 'signed metadata alteration is refused: ' . $name ); }
foreach ( array( 'home_url' => 'https://other.example', 'generation' => 'changed', 'export_id' => str_repeat( 'd', 32 ), 'installation_id' => str_repeat( 'e', 32 ), 'blog_id' => 2 ) as $name => $value ) { $bad = $signed; $bad['source'][ $name ] = $value; $check( is_wp_error( $signer->verify( $bad, $key, $id, 1 ) ), 'signed source alteration is refused: ' . $name ); }
foreach ( array( 'bytes' => 4, 'sha256' => str_repeat( 'f', 64 ) ) as $name => $value ) { $bad = $signed; $bad['members']['data/configuration.json'][ $name ] = $value; $check( is_wp_error( $signer->verify( $bad, $key, $id, 1 ) ), 'signed component descriptor alteration is refused: ' . $name ); }
$bad = $signed; $bad['members']['assets/' . str_repeat( 'f', 64 )] = array( 'bytes' => 7, 'sha256' => str_repeat( 'f', 64 ) );
$check( is_wp_error( $signer->verify( $bad, $key, $id, 1 ) ), 'new otherwise valid asset scope cannot be attached after signing' );
$bad = $signed; $bad['provenance']['tag'][0] = 'a' === $bad['provenance']['tag'][0] ? 'b' : 'a';
$check( is_wp_error( $signer->verify( $bad, $key, $id, 1 ) ), 'a forged syntactically valid HMAC fails constant-time verification' );
$check( is_wp_error( $signer->verify( $manifest, $key, $id, 1 ) ), 'the unsigned placeholder never acquires authenticity' );
$check( is_wp_error( $signer->sign( $manifest, $key, str_repeat( 'b', 32 ), 1 ) ) && is_wp_error( $signer->sign( $manifest, $key, $id, 2 ) ), 'signing cannot substitute another installation or blog identity' );
foreach ( array( '', str_repeat( 'x', 31 ), str_repeat( 'x', 33 ), false ) as $i => $bad_key ) { $check( is_wp_error( $signer->sign( $manifest, $bad_key, $id, 1 ) ), 'invalid private key material is refused, case ' . $i ); }
$bad = $manifest; $bad['scope']['domain'] = 4; $check( is_wp_error( $signer->sign( $bad, $key, $id, 1 ) ), 'signing does not normalize an unsupported scope into a valid backup' );
$bad = $manifest; $bad['provenance']['algorithm'] = 'sha256'; $check( is_wp_error( $signer->sign( $bad, $key, $id, 1 ) ), 'unsupported claimed algorithm cannot be signed as current provenance' );
$check( ! in_array( WholeStateProvenanceRepository::OPTION, WholeStateOptionInventory::keys(), true ), 'private recovery root is explicitly outside captured option scope' );

class ProvenanceReadDatabase {
	public $options = 'wp_options'; public $last_error = ''; public $row = null; public $reads = 0; public $failure = false;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_row( $sql, $output ) { ++$this->reads; if ( $this->failure ) { $this->last_error = 'Injected database error.'; return null; } return $this->row; }
}
class ProvenanceWakeupProbe { public static $calls = 0; public function __wakeup() { ++self::$calls; } }
$wpdb = new ProvenanceReadDatabase(); $repository = new WholeStateProvenanceRepository();
$check( is_wp_error( $repository->verify( $signed ) ) && null === $wpdb->row && 0 === $GLOBALS['salt_calls'], 'fresh-installation verification does not generate a root or any WordPress salt' );
$before = $wpdb->reads; $check( is_wp_error( $repository->ensure( 0 ) ) && $before === $wpdb->reads, 'unauthenticated setup cannot enter root provisioning or a transaction' );
$root = array( 'schema_version' => 1, 'installation_id' => $id, 'blog_id' => 1, 'sealed_key' => 'v1:sodium:untrusted-local-fixture' );
$storage = ( new CanonicalJson() )->encode( $root ); $wpdb->row = array( 'option_name' => WholeStateProvenanceRepository::OPTION, 'storage' => $storage );
$check( is_wp_error( $repository->verify( $signed ) ) && $wpdb->row['storage'] === $storage && 0 === $GLOBALS['salt_calls'], 'existing root with unavailable authentication material fails before wp_salt could generate missing options' );
$wpdb->row['storage'] = str_replace( '"schema_version":1', '"schema_version":1,"schema_version":1', $storage );
$check( is_wp_error( $repository->identity() ) && 0 === $GLOBALS['salt_calls'], 'ambiguous duplicate local-root properties are refused before cryptography' );
$wpdb->row['storage'] = serialize( new ProvenanceWakeupProbe() );
$check( is_wp_error( $repository->identity() ) && 0 === ProvenanceWakeupProbe::$calls, 'local root parsing never instantiates serialized objects' );
$wpdb->row['storage'] = $storage; $wpdb->failure = true; $failed_read = $repository->identity();
$check( is_wp_error( $failed_read ) && 'certloom_recovery_root_invalid' === $failed_read->get_error_code(), 'checked root read failure cannot be mistaken for absent root or verified origin' );
echo "\n$passed checks passed; $failed failed.\n";
exit( $failed ? 1 : 0 );
