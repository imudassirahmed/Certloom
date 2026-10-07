<?php
/** Real ZIP envelope integrity, hostile members and non-authorizing read-only analysis. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Backup\WholeStateBackupFormat;
use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\DomainBackupFormat;

$passed = 0; $failed = 0; $paths = array();
$check = static function ( $ok, $label ) use ( &$passed, &$failed ) { if ( $ok ) { ++$passed; echo 'PASS  ' . $label . "\n"; } else { ++$failed; echo 'FAIL  ' . $label . "\n"; } };
if ( ! class_exists( 'ZipArchive' ) ) { echo "FAIL  ZIP extension required for real archive acceptance\n"; exit( 1 ); }
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'certloom-whole-envelope-' . bin2hex( random_bytes( 12 ) );
if ( ! mkdir( $dir, 0700 ) ) { exit( 1 ); }
$format = new WholeStateBackupFormat(); $reader = new WholeStateBackupReader();
$payloads = array_fill_keys( WholeStateBackupFormat::required_members(), "{\"fixture\":true}\n" );
$asset = str_repeat( 'exact referenced bytes', 4000 ); $asset_key = hash( 'sha256', $asset ); $payloads[ 'assets/' . $asset_key ] = $asset;
$manifest = array( 'format' => WholeStateBackupFormat::FORMAT, 'format_version' => 1, 'created_at' => '2026-10-03T10:00:00Z', 'plugin_version' => '1.7.0-alpha.4.87', 'scope' => WholeStateBackupFormat::scope(), 'source' => array( 'installation_id' => str_repeat( 'a', 32 ), 'export_id' => str_repeat( 'b', 32 ), 'blog_id' => 1, 'home_url' => 'http://ecapla.local', 'generation' => '1790000000.123456|abcdef123456abcdef123456' ), 'provenance' => array( 'algorithm' => 'site-hmac-sha256', 'tag' => str_repeat( 'c', 64 ) ), 'members' => array() );
foreach ( $payloads as $name => $bytes ) { $manifest['members'][ $name ] = array( 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) ); }
$write = static function ( $label, array $members, $manifest_bytes, $callback = null ) use ( $dir, &$paths ) {
	$path = $dir . DIRECTORY_SEPARATOR . $label . '.zip'; $paths[] = $path;
	$zip = new ZipArchive(); if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::EXCL ) ) { throw new RuntimeException( 'Owned ZIP fixture could not be created.' ); }
	if ( ! $zip->addFromString( 'manifest.json', $manifest_bytes ) ) { throw new RuntimeException( 'Fixture manifest write failed.' ); }
	foreach ( $members as $name => $bytes ) { if ( ! $zip->addFromString( $name, $bytes ) ) { throw new RuntimeException( 'Fixture member write failed.' ); } }
	if ( $callback ) { $callback( $zip ); }
	if ( ! $zip->close() ) { throw new RuntimeException( 'Fixture close failed.' ); } return $path;
};

try {
	$encoded = $format->encode_manifest( $manifest );
	$check( is_string( $encoded ) && $format->decode_manifest( $encoded ) == $manifest, 'versioned canonical scope and member descriptors round-trip without changing existing formats' );
	$original_scope = $manifest; $original_scope['scope'] = WholeStateBackupFormat::scope( 1 );
	$check( ! is_wp_error( $format->encode_manifest( $original_scope ) ), 'original reference and asset scope remains supported' );
	$unsupported_scope = $manifest; $unsupported_scope['scope']['references'] = 3;
	$check( is_wp_error( $format->encode_manifest( $unsupported_scope ) ), 'unknown file-reference protocols remain refused' );
	$mixed_scope = $manifest; $mixed_scope['scope']['assets'] = 1;
	$check( is_wp_error( $format->encode_manifest( $mixed_scope ) ), 'asset and reference protocols cannot claim mismatched coverage' );
	$valid = $write( 'valid', $payloads, $encoded ); $before = hash_file( 'sha256', $valid ); $entries_before = scandir( $dir );
	$accepted = $reader->analyze( $valid );
	$check( ! is_wp_error( $accepted ) && true === $accepted['integrity_valid'] && $before === $accepted['archive_sha256'], 'real compressed ZIP verifies all component and exact asset hashes' );
	$check( false === $accepted['ownership_verified'] && false === $accepted['restore_ready'], 'well-formed source ID, URL and HMAC claim do not grant account ownership or restore authority' );
	$check( $before === hash_file( 'sha256', $valid ) && $entries_before === scandir( $dir ), 'analysis preserves archive bytes and creates no extracted file or directory' );
	$bad_manifests = array();
	$bad = $manifest; $bad['format_version'] = '1'; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['scope']['domain'] = 4; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['scope']['jobs'] = 1; $bad_manifests[] = $bad;
	$bad = $manifest; unset( $bad['members']['data/references.jsonl'] ); $bad_manifests[] = $bad;
	$bad = $manifest; $bad['members']['data/domain.cvbackup']['bytes'] = '17'; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['members']['data/domain.cvbackup']['bytes'] = WholeStateBackupFormat::MAX_MEMBER_BYTES + 1; $bad_manifests[] = $bad;
	$bad = $manifest; foreach ( array( 'data/domain.cvbackup', 'data/legacy.cvbackup', 'data/deliveries.jsonl' ) as $name ) { $bad['members'][ $name ]['bytes'] = WholeStateBackupFormat::MAX_MEMBER_BYTES; } $bad_manifests[] = $bad;
	$bad = $manifest; $bad['members'][ 'assets/' . $asset_key ]['sha256'] = str_repeat( 'd', 64 ); $bad_manifests[] = $bad;
	$bad = $manifest; $bad['created_at'] = '2026-02-30T01:02:03Z'; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['source']['blog_id'] = 0; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['source']['home_url'] = 'https://user:secret@example.test'; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['provenance']['tag'] = 'plain-secret'; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['execute_after_restore'] = true; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['members']['data/configuration.json']['bytes'] = WholeStateBackupFormat::member_limit( 'data/configuration.json' ) + 1; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['members'][ 'assets/' . $asset_key ]['bytes'] = WholeStateBackupFormat::member_limit( 'assets/' . $asset_key ) + 1; $bad_manifests[] = $bad;
	$bad = $manifest; $bad['plugin_version'] = '1.7.0-' . str_repeat( 'a', 80 ); $bad_manifests[] = $bad;
	foreach ( $bad_manifests as $index => $bad ) { $check( is_wp_error( $format->validate_manifest( $bad ) ), 'expanded, ambiguous, excessive or untrusted scope metadata is refused (' . $index . ')' ); }
	foreach ( array( '../outside', '/absolute', 'C:/Windows/file', 'data\\domain.cvbackup', 'data/domain.cvbackup/extra', 'assets/' . strtoupper( $asset_key ), "data/domain.cvbackup\0evil", 'assets/' . $asset_key . '.png' ) as $index => $name ) { $check( ! WholeStateBackupFormat::member_name( $name ), 'member names reject traversal, platform aliases and ambiguous identities (' . $index . ')' ); }
	$duplicate_json = str_replace( '"format_version":1', '"format_version":1,"format_version":1', $encoded );
	$check( is_wp_error( $format->decode_manifest( $duplicate_json ) ) && is_wp_error( $format->decode_manifest( ' ' . $encoded ) ), 'duplicate JSON properties and noncanonical representations are rejected before semantic review' );
	$changed = $payloads; $changed['data/deliveries.jsonl'] = str_replace( 'true', 'fake', $changed['data/deliveries.jsonl'] );
	$check( is_wp_error( $reader->analyze( $write( 'changed-bytes', $changed, $encoded ) ) ), 'same-length member corruption fails its SHA-256 check' );
	$missing = $payloads; unset( $missing['data/references.jsonl'] );
	$check( is_wp_error( $reader->analyze( $write( 'missing', $missing, $encoded ) ) ), 'missing declared component cannot appear to be a complete backup' );
	$extra = $payloads; $extra[ 'assets/' . str_repeat( 'd', 64 ) ] = 'undeclared';
	$check( is_wp_error( $reader->analyze( $write( 'undeclared', $extra, $encoded ) ) ), 'undeclared content-addressed bytes cannot bypass exact archive scope' );
	$traversal = $payloads; $traversal['../outside.txt'] = 'never extracted';
	$check( is_wp_error( $reader->analyze( $write( 'traversal', $traversal, $encoded ) ) ) && ! file_exists( dirname( $dir ) . '/outside.txt' ), 'real ZIP traversal entry is refused without extraction' );
	$symlink = $write( 'symlink', $payloads, $encoded, static function ( $zip ) { if ( ! $zip->setExternalAttributesName( 'data/domain.cvbackup', ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) ) ) { throw new RuntimeException( 'Symlink attack fixture failed.' ); } } );
	$check( is_wp_error( $reader->analyze( $symlink ) ), 'Unix symlink attributes cannot be treated as ordinary backup data' );
	$directory = $write( 'directory', $payloads, $encoded, static function ( $zip ) { if ( ! $zip->setExternalAttributesName( 'data/domain.cvbackup', ZipArchive::OPSYS_UNIX, ( 0040755 << 16 ) ) ) { throw new RuntimeException( 'Directory attack fixture failed.' ); } } );
	$check( is_wp_error( $reader->analyze( $directory ) ), 'directory attributes with an ordinary member name are refused' );
	$dos_directory = $write( 'dos-directory', $payloads, $encoded, static function ( $zip ) { if ( ! $zip->setExternalAttributesName( 'data/domain.cvbackup', ZipArchive::OPSYS_DOS, 0x10 ) ) { throw new RuntimeException( 'DOS directory fixture failed.' ); } } );
	$check( is_wp_error( $reader->analyze( $dos_directory ) ), 'DOS directory flags cannot bypass regular-member checks' );
	$encrypted = $write( 'encrypted', $payloads, $encoded, static function ( $zip ) { if ( ! $zip->setEncryptionName( 'data/domain.cvbackup', ZipArchive::EM_AES_256, 'only-a-fixture' ) ) { throw new RuntimeException( 'Encrypted entry fixture failed.' ); } } );
	$check( is_wp_error( $reader->analyze( $encrypted ) ), 'encrypted ZIP members cannot conceal unverified component bytes' );
	$duplicate = $dir . '/duplicate.zip'; $paths[] = $duplicate; $raw = file_get_contents( $valid );
	$duplicates = str_replace( 'data/legacy.cvbackup', 'data/domain.cvbackup', $raw, $replaced );
	if ( $replaced < 2 || strlen( $duplicates ) !== strlen( $raw ) || strlen( $duplicates ) !== file_put_contents( $duplicate, $duplicates ) ) { throw new RuntimeException( 'Duplicate central/local entry fixture failed.' ); }
	$check( is_wp_error( $reader->analyze( $duplicate ) ), 'duplicate central/local ZIP names cannot choose which payload is restored' );
	$oversized = $dir . '/oversized.zip'; $paths[] = $oversized; $forged = $raw; $offset = 0; $patched = false;
	while ( false !== ( $offset = strpos( $forged, "PK\x01\x02", $offset ) ) ) {
		$length = unpack( 'v', substr( $forged, $offset + 28, 2 ) )[1]; $name = substr( $forged, $offset + 46, $length );
		if ( 'data/domain.cvbackup' === $name ) { $forged = substr_replace( $forged, pack( 'V', WholeStateBackupFormat::MAX_MEMBER_BYTES + 1 ), $offset + 24, 4 ); $patched = true; break; } ++$offset;
	}
	if ( ! $patched || strlen( $forged ) !== file_put_contents( $oversized, $forged ) ) { throw new RuntimeException( 'Oversized central-entry fixture failed.' ); }
	$check( is_wp_error( $reader->analyze( $oversized ) ), 'forged oversized decompressed entry is refused before allocating its declared bytes' );
	$truncated = $dir . '/truncated.zip'; $paths[] = $truncated; file_put_contents( $truncated, substr( $raw, 0, 100 ) );
	$check( is_wp_error( $reader->analyze( $truncated ) ), 'interrupted archive creation cannot pass read-only analysis' );
	$check( 5 === DomainBackupFormat::VERSION && array( 1, 2, 3, 4, 5 ) === DomainBackupFormat::supported_versions(), 'native historical versions and their format boundary remain unchanged' );
	$empty_manifest = $write( 'bad-manifest', $payloads, '{}' ); $check( is_wp_error( $reader->analyze( $empty_manifest ) ), 'integrity analysis refuses a ZIP without a valid explicit whole-state manifest' );
	foreach ( array( 'phar://' . $valid . '/manifest.json', 'file://' . $valid, $valid . "\0suffix" ) as $index => $path ) { $check( is_wp_error( $reader->analyze( $path ) ), 'local archive analysis rejects stream wrappers and NUL paths before filesystem access (' . $index . ')' ); }
} catch ( Throwable $error ) { ++$failed; echo 'FAIL  ' . $error->getMessage() . "\n"; }
finally { foreach ( $paths as $path ) { if ( dirname( $path ) === $dir && is_file( $path ) ) { if ( ! unlink( $path ) ) { ++$failed; } } } if ( is_dir( $dir ) && ! rmdir( $dir ) ) { ++$failed; } }
echo "\n$passed whole-state envelope checks, $failed failures\n"; exit( $failed ? 1 : 0 );
