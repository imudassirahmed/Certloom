<?php
/** Behavioral/static acceptance for ID-02 current-authority numbering policy. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function absint( $value ) { return abs( (int) $value ); }
$GLOBALS['number_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['number_options'] ) ? $GLOBALS['number_options'][ $key ] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = true ) {
	if ( array_key_exists( $key, $GLOBALS['number_options'] ) ) { return false; }
	$GLOBALS['number_options'][ $key ] = $value;
	return true;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['number_options'][ $key ] = $value; return true; }
function delete_option( $key ) { if ( array_key_exists( $key, $GLOBALS['number_options'] ) ) { unset( $GLOBALS['number_options'][ $key ] ); return true; } return false; }
function wp_cache_delete( $key, $group = '' ) { return true; }

class NumberingFakeWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public $last_args = array();
	public $last_insert_id = 0;
	public function prepare( $query, ...$args ) { $this->last_args = $args; return $query; }
	public function query( $query ) {
		$start = (int) ( $this->last_args[1] ?? 1 );
		$key   = (string) ( $this->last_args[2] ?? '' );
		$current = $GLOBALS['number_options'][ $key ] ?? null;
		if ( ! is_string( $current ) && ! is_int( $current ) ) { $this->last_error = 'invalid option row'; return false; }
		if ( ! preg_match( '/^[0-9]+$/D', (string) $current ) ) { $this->last_error = 'invalid sequence state'; return false; }
		$allocated = max( (int) $current, $start );
		$this->last_insert_id = $allocated;
		$GLOBALS['number_options'][ $key ] = (string) ( $allocated + 1 );
		return 1;
	}
	public function get_var( $query ) { return false !== strpos( (string) $query, 'LAST_INSERT_ID' ) ? $this->last_insert_id : null; }
}
$GLOBALS['wpdb'] = new NumberingFakeWpdb();

class Ecapla_CertVerify_Settings {
	public static $all = array();
	public static function get_all() { return self::$all; }
}
class Ecapla_CertVerify_Backup {
	public static $reserved = 0;
	public static function assert_writes_allowed() { return true; }
	public static function reserve_mutation_generation() { self::$reserved++; return 'generation-' . self::$reserved; }
}
class Ecapla_CertVerify_Validator {
	public static $error = null;
	public static function certificate_data_for_generated_number( $data ) { return self::$error ?: $data; }
}
class Ecapla_CertVerify_Database {
	public static $existing = array();
	public static $inserted = array();
	public static $next_id = 10;
	public static function certificate_number_exists( $number ) { return in_array( $number, self::$existing, true ) || isset( self::$inserted[ $number ] ); }
	public static function insert( $payload ) { self::$inserted[ $payload['cert_number'] ] = $payload; return self::$next_id++; }
}

function ncheck( $condition, $message ) {
	global $number_checks;
	$number_checks++;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS  {$message}\n";
}
$number_checks = 0;
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-numbering.php';

$defaults = Ecapla_CertVerify_Numbering::defaults();
ncheck( 'manual' === $defaults['numbering_mode'], 'existing installs default to manual numbering' );
ncheck( 1 === $defaults['numbering_start'] && 6 === $defaults['numbering_padding'], 'default start/padding are deterministic' );

$policy = Ecapla_CertVerify_Numbering::normalize_policy( array(
	'numbering_mode' => 'sequential', 'numbering_prefix' => 'CERT-', 'numbering_suffix' => '-PK', 'numbering_start' => '42', 'numbering_padding' => '6',
) );
ncheck( ! is_wp_error( $policy ), 'valid sequential policy normalizes' );
ncheck( 'CERT-000042-PK' === Ecapla_CertVerify_Numbering::format_sequence( 42, $policy ), 'prefix + padded sequence + suffix format correctly' );
ncheck( 'CERT-1234567-PK' === Ecapla_CertVerify_Numbering::format_sequence( 1234567, $policy ), 'padding is a minimum and never truncates larger sequences' );
ncheck( ! is_wp_error( Ecapla_CertVerify_Numbering::normalize_policy( array_merge( $policy, array( 'numbering_prefix' => 'شهادة-' ) ) ) ), 'Unicode letter prefixes are accepted' );
ncheck( is_wp_error( Ecapla_CertVerify_Numbering::normalize_policy( array_merge( $policy, array( 'numbering_prefix' => 'BAD PREFIX ' ) ) ) ), 'whitespace in machine prefix is rejected' );
ncheck( is_wp_error( Ecapla_CertVerify_Numbering::normalize_policy( array_merge( $policy, array( 'numbering_padding' => 13 ) ) ) ), 'padding beyond 12 is rejected' );
ncheck( is_wp_error( Ecapla_CertVerify_Numbering::normalize_policy( array_merge( $policy, array( 'numbering_start' => 0 ) ) ) ), 'zero sequence start is rejected' );

Ecapla_CertVerify_Settings::$all = $policy;
$GLOBALS['number_options'] = array();
ncheck( 42 === Ecapla_CertVerify_Numbering::current_next_sequence(), 'missing sequence state starts at configured floor' );
$GLOBALS['number_options'][ Ecapla_CertVerify_Numbering::STATE_OPTION ] = '5';
ncheck( 42 === Ecapla_CertVerify_Numbering::current_next_sequence(), 'raising sequence floor never rewinds to older stored state' );
$allocated = Ecapla_CertVerify_Numbering::allocate_sequence();
ncheck( 42 === $allocated && '43' === (string) $GLOBALS['number_options'][ Ecapla_CertVerify_Numbering::STATE_OPTION ], 'atomic allocator advances from max(stored state, configured floor)' );
$GLOBALS['number_options'][ Ecapla_CertVerify_Numbering::STATE_OPTION ] = 'not-a-number';
ncheck( is_wp_error( Ecapla_CertVerify_Numbering::current_next_sequence() ), 'corrupt persisted sequence state fails closed' );
$GLOBALS['number_options'][ Ecapla_CertVerify_Numbering::STATE_OPTION ] = (string) Ecapla_CertVerify_Numbering::EXHAUSTED_STATE;
$exhausted = Ecapla_CertVerify_Numbering::current_next_sequence();
ncheck( is_wp_error( $exhausted ) && 'ecapla_certverify_number_sequence_exhausted' === $exhausted->get_error_code(), 'exhausted sentinel stops automatic numbering' );

Ecapla_CertVerify_Settings::$all = array_merge( $policy, array( 'numbering_start' => 1, 'numbering_suffix' => '' ) );
$GLOBALS['number_options'] = array();
Ecapla_CertVerify_Database::$existing = array( 'CERT-000001', 'CERT-000002' );
$preview = Ecapla_CertVerify_Numbering::preview();
ncheck( ! is_wp_error( $preview ) && 'CERT-000003' === $preview['certificate_number'] && 2 === $preview['skipped_collisions'], 'non-mutating preview skips existing manual/generated collisions' );
ncheck( ! array_key_exists( Ecapla_CertVerify_Numbering::STATE_OPTION, $GLOBALS['number_options'] ), 'preview never reserves sequence state' );

Ecapla_CertVerify_Database::$existing = array( 'CERT-000001' );
Ecapla_CertVerify_Database::$inserted = array();
Ecapla_CertVerify_Validator::$error = null;
Ecapla_CertVerify_Backup::$reserved = 0;
$GLOBALS['number_options'] = array();
$created = Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => 'Ada Lovelace' ) );
ncheck( ! is_wp_error( $created ) && 'CERT-000002' === $created['certificate_number'], 'generator burns collided value and creates next unique certificate number' );
ncheck( '3' === (string) $GLOBALS['number_options'][ Ecapla_CertVerify_Numbering::STATE_OPTION ], 'generated insert leaves durable next-sequence cursor' );
ncheck( 2 === Ecapla_CertVerify_Backup::$reserved, 'each reserved sequence advances backup mutation generation' );

$GLOBALS['number_options'] = array();
Ecapla_CertVerify_Validator::$error = new WP_Error( 'invalid_holder', 'invalid' );
Ecapla_CertVerify_Backup::$reserved = 0;
$invalid = Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => '' ) );
ncheck( is_wp_error( $invalid ) && empty( $GLOBALS['number_options'] ) && 0 === Ecapla_CertVerify_Backup::$reserved, 'record validation happens before any sequence value is consumed' );
Ecapla_CertVerify_Validator::$error = null;

ncheck( 99 === Ecapla_CertVerify_Numbering::validate_backup_state( '99' ), 'valid persisted numbering state is backup-safe' );
ncheck( null === Ecapla_CertVerify_Numbering::validate_backup_state( null ), 'older backup without numbering state normalizes to null' );
ncheck( is_wp_error( Ecapla_CertVerify_Numbering::validate_backup_state( 'garbage' ) ), 'malformed backup numbering state is rejected' );
ncheck( is_wp_error( Ecapla_CertVerify_Numbering::validate_backup_state( Ecapla_CertVerify_Numbering::EXHAUSTED_STATE + 1 ) ), 'out-of-range backup numbering state is rejected' );

$root = dirname( __DIR__ );
$src = array(
	'database' => file_get_contents( $root . '/includes/class-ecapla-certverify-database.php' ),
	'admin' => file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' ),
	'editor' => file_get_contents( $root . '/admin/views/partials/certificate-field.php' ),
	'settings' => file_get_contents( $root . '/admin/views/settings-manager.php' ),
	'import' => file_get_contents( $root . '/includes/class-ecapla-certverify-import.php' ),
	'backup' => file_get_contents( $root . '/includes/class-ecapla-certverify-backup.php' ),
	'uninstall' => file_get_contents( $root . '/uninstall.php' ),
	'plugin' => file_get_contents( $root . '/certloom.php' ),
);
foreach ( $src as $name => $text ) { ncheck( is_string( $text ) && '' !== $text, 'source loaded: ' . $name ); }
ncheck( false !== strpos( $src['database'], 'HASH_COMPLETE_OPTION' ) && false !== strpos( $src['database'], 'WHERE cert_number_hash = %s LIMIT 1' ), 'healthy collision probes use indexed certificate hash' );
ncheck( false !== strpos( $src['admin'], 'Ecapla_CertVerify_Numbering::insert_generated' ) && false !== strpos( $src['admin'], "array_key_exists( 'cert_number', \$data )" ) && false !== strpos( $src['admin'], 'Ecapla_CertVerify_Numbering::is_enabled()' ), 'blank new-admin number invokes sequential allocator only when enabled' );
ncheck( false !== strpos( $src['admin'], 'Ecapla_CertVerify_Database::update' ), 'existing credential edit path remains a normal update and never auto-renumbers' );
ncheck( false !== strpos( $src['editor'], 'manual override') && false !== strpos( $src['editor'], 'not reserved until save' ), 'editor explains manual override and non-reserving preview' );
ncheck( false !== strpos( $src['editor'], 'Automatic numbering is unavailable:') && false !== strpos( file_get_contents( $root . '/admin/views/edit-certificate.php' ), '! is_wp_error( $ecapla_certverify_numbering_preview )' ), 'editor requires manual fallback when numbering health/preview fails' );
ncheck( false !== strpos( $src['settings'], 'Program and template sequences remain gated' ), 'UI explicitly gates unsupported program/template sequence scopes' );
ncheck( false !== strpos( $src['import'], "'cert_number'") && false === strpos( $src['import'], 'insert_generated' ), 'CSV import remains explicit-number and never invokes automatic allocator' );
ncheck( false !== strpos( $src['backup'], 'const FORMAT_VERSION          = 4;') && false !== strpos( $src['backup'], "'numbering_state'" ), 'complete backup v4 owns durable numbering state' );
ncheck( false !== strpos( $src['backup'], 'LEGACY_STREAM_VERSION   = 2') && false !== strpos( $src['backup'], 'PREVIOUS_STREAM_VERSION = 3'), 'backup reader keeps stream v2/v3 compatibility' );
ncheck( false !== strpos( $src['backup'], "'old_numbering_state'") && false !== strpos( $src['backup'], 'save_numbering_state'), 'Replace restore journals/restores numbering state' );
$restore_pos = strpos( $src['backup'], "if ( 'replace' === \$mode )" );
$restore_branch = false === $restore_pos ? '' : substr( $src['backup'], $restore_pos, 1000 );
ncheck( false !== strpos( $restore_branch, 'save_numbering_state' ) && false !== strpos( $restore_branch, '} else {' ) && false !== strpos( $restore_branch, 'merge_backup_only_fields_as_retired' ), 'Replace restores numbering state while Merge keeps current-site numbering state' );
ncheck( false !== strpos( $src['uninstall'], 'certverify_number_sequence_next' ), 'explicit full-data uninstall deletes durable numbering cursor' );
ncheck( false !== strpos( $src['plugin'], "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false") && false !== strpos( $src['plugin'], "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false") && false !== strpos( $src['plugin'], "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"), 'normalized-domain authority gates remain false' );

// Ensure no policy implementation attempts to renumber previously issued rows.
$numbering_source = file_get_contents( $root . '/includes/class-ecapla-certverify-numbering.php' );
ncheck( false === strpos( $numbering_source, 'Ecapla_CertVerify_Database::update' ), 'numbering service contains no credential-row renumber path' );
ncheck( false === strpos( $numbering_source, 'program_id' ) && false === strpos( $numbering_source, 'template_id' ), 'current-authority allocator does not simulate gated program/template scopes' );

echo "\n{$number_checks} checks, 0 failures\n";
