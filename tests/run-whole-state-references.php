<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { public function __construct( $code, $message = '', $data = null ) {} }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v, $domain = null ) { return $v; }
require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Backup\WholeStateReferenceRecord;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
$passed = 0; $failed = 0;
$check = static function ( $ok, $name ) use ( &$passed, &$failed ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $ok ? ++$passed : ++$failed; };
$codec = new WholeStateReferenceRecord(); $format = new WholeStateReferenceFormat(); $raw = new WholeStateOptionRecord();
$options = array_fill_keys( WholeStateOptionInventory::keys( 'references' ), $raw->from_storage( null ) );
$rows = array(
 array( 'kind' => 'account', 'recipient_id' => 1, 'source_user_id' => 93, 'identity_sha256' => hash( 'sha256', 'account' ) ),
 array( 'kind' => 'account', 'recipient_id' => 10, 'source_user_id' => 7, 'identity_sha256' => null ),
 array( 'kind' => 'object', 'source_id' => 31, 'post_type' => 'product_variation', 'identity_sha256' => hash( 'sha256', 'object' ) ),
 array( 'kind' => 'object', 'source_id' => 32, 'post_type' => null, 'identity_sha256' => null ),
 array( 'kind' => 'image', 'reference' => 'attachment:31', 'descriptor' => array( 'sha256' => hash( 'sha256', 'image' ), 'mime' => 'image/png', 'size' => 500, 'width_px' => 800, 'height_px' => 600 ) ),
 array( 'kind' => 'font', 'sha256' => hash( 'sha256', 'font' ), 'format' => 'ttf', 'size' => 1000 ),
);
$encode = static function ( array $items, $changed_options = null, $version = WholeStateReferenceFormat::VERSION ) use ( $format, $options ) { $text = $format->header( $changed_options ?? $options, $version ); if ( is_wp_error( $text ) ) { return $text; } $hash = $format::next_hash( '', $text ); $counts = $format::empty_counts( $version ); foreach ( $items as $r ) { $line = $format->record( $r ); if ( is_wp_error( $line ) ) { return $line; } $text .= $line; $hash = $format::next_hash( $hash, $line ); if ( ! array_key_exists( $r['kind'], $counts ) ) { return new WP_Error( 'unsupported_fixture_version' ); } ++$counts[$r['kind']]; } $footer = $format->footer( $counts, $hash ); return is_wp_error( $footer ) ? $footer : $text . $footer; };
$analyze = static function ( $bytes, $callback = null ) use ( $format ) { $h = fopen( 'php://temp', 'w+b' ); fwrite( $h, $bytes ); rewind( $h ); try { return $format->analyze( $h, $callback ?: static function () { return true; } ); } finally { fclose( $h ); } };
foreach ( $rows as $r ) { $check( ! is_wp_error( $codec->validate( $r ) ), 'strict reference kind: ' . $r['kind'] ); }
$bytes = $encode( $rows ); $seen = array(); $result = $analyze( $bytes, static function ( $r ) use ( &$seen ) { $seen[] = $r; return true; } );
$check( ! is_wp_error( $result ) && count( $seen ) === 6 && $result['counts']['account'] === 2 && count( $result['assets'] ) === 2, 'mixed references round trip with exact lossless presence and asset index' );
$check( strpos( $bytes, 'user_email' ) === false && strpos( $bytes, 'password' ) === false && strpos( $bytes, 'roles' ) === false, 'no ordinary account data or execution authority in the stream' );
$check( strcmp( $codec->key( $rows[0] ), $codec->key( $rows[1] ) ) < 0, 'numeric account ordering survives lexical stream validation' );
$check( is_wp_error( $analyze( $bytes, static function () { return new WP_Error( 'staging_failed' ); } ) ), 'callback failure stops private staging' );
$bad = $rows; $bad[0]['source_user_id'] = '93'; $check( is_wp_error( $encode( $bad ) ), 'string account identity is not coerced' );
$bad = $rows; $bad[0]['roles'] = array( 'administrator' ); $check( is_wp_error( $encode( $bad ) ), 'account authority cannot be inserted into a reference' );
$bad = $rows; $bad[3]['post_type'] = 'attachment'; $check( is_wp_error( $encode( $bad ) ), 'missing object cannot claim a live type' );
$bad = $rows; $bad[4]['reference'] = 'attachment:031'; $check( is_wp_error( $encode( $bad ) ), 'noncanonical attachment alias rejected' );
$bad = $rows; $bad[4]['reference'] = 'https://evil.invalid/image.png'; $check( is_wp_error( $encode( $bad ) ), 'remote asset identity rejected' );
$bad = $rows; $bad[4]['descriptor']['path'] = '/tmp/unowned'; $check( is_wp_error( $encode( $bad ) ), 'archive cannot introduce a local asset path' );
$bad = $rows; $bad[4]['descriptor']['width_px'] = 12001; $check( is_wp_error( $encode( $bad ) ), 'image dimension bound enforced' );
$bad = $rows; $bad[4]['descriptor']['size'] = 26214401; $check( is_wp_error( $encode( $bad ) ), 'image byte bound enforced' );
$bad = $rows; $bad[5]['size'] = 8388609; $check( is_wp_error( $encode( $bad ) ), 'custom font byte bound enforced' );
$bad = $rows; $bad[5]['format'] = 'woff2'; $check( is_wp_error( $encode( $bad ) ), 'unsupported font container rejected' );
$check( is_wp_error( $analyze( $encode( array_reverse( $rows ) ) ) ), 'out of order rows rejected even with a recomputed chain' );
$bad = $rows; array_splice( $bad, 1, 0, array( $rows[0] ) ); $check( is_wp_error( $analyze( $encode( $bad ) ) ), 'duplicate account alias rejected even with a recomputed chain' );
$check( is_wp_error( $analyze( substr( $bytes, 0, -2 ) ) ), 'truncated stream rejected' );
$check( is_wp_error( $analyze( $bytes . "{}\n" ) ), 'trailing content rejected' );
$check( is_wp_error( $analyze( str_replace( '"source_user_id":93', '"source_user_id":94', $bytes ) ) ), 'tampered account digest chain rejected' );
$check( is_wp_error( $analyze( str_replace( '"kind":"account"', '"kind":"account","kind":"account"', $bytes ) ) ), 'duplicate JSON properties rejected' );
$extra = $options; $extra['certloom_recovery_provenance_v1'] = $raw->from_storage( 'never import' ); $check( is_wp_error( $encode( $rows, $extra ) ), 'reference options cannot expand to local signing authority' );
$empty = $analyze( $encode( array() ) ); $check( ! is_wp_error( $empty ) && $empty['counts']['account'] === 0, 'empty installation has a complete reference component' );
$old = $analyze( $encode( $rows, null, 1 ) ); $check( ! is_wp_error( $old ) && 1 === $old['format_version'] && count( $old['counts'] ) === 4, 'original four-kind version-one archives remain readable' );
$file = array( 'kind' => 'file', 'reference' => 'attachment:31', 'descriptor' => array( 'sha256' => $rows[4]['descriptor']['sha256'], 'size' => 500, 'file_type' => 'email_attachment' ) );
$url = array( 'kind' => 'media_url', 'source_url' => 'https://source.invalid/uploads/email.png', 'source_id' => 31, 'image_sha256' => $rows[4]['descriptor']['sha256'] );
$extended = array_merge( $rows, array( $file, $url ) ); $result = $analyze( $encode( $extended ) );
$check( ! is_wp_error( $result ) && 2 === $result['format_version'] && 1 === $result['counts']['file'] && 1 === $result['counts']['media_url'] && count( $result['assets'] ) === 2 && $result['assets'][$file['descriptor']['sha256']] == $rows[4]['descriptor'], 'version-two attachment and URL bindings deduplicate the same image bytes without weakening its descriptor' );
$generic = $file; $generic['reference'] = 'attachment:32'; $generic['descriptor']['sha256'] = hash( 'sha256', 'pdf' ); $result = $analyze( $encode( array_merge( $rows, array( $generic ) ) ) );
$check( ! is_wp_error( $result ) && count( $result['assets'] ) === 3, 'opaque static documents have their own bounded exact-byte asset descriptor' );
$bad = $file; $bad['descriptor']['path'] = '/unowned'; $check( is_wp_error( $codec->validate( $bad ) ), 'a static attachment cannot import a local path' );
$bad = $file; $bad['descriptor']['size'] = 10485761; $check( is_wp_error( $codec->validate( $bad ) ), 'static attachment capture obeys the email ten-megabyte limit' );
$bad = $file; $bad['descriptor']['file_type'] = 'executable'; $check( is_wp_error( $codec->validate( $bad ) ), 'opaque attachment purpose is closed' );
$bad = $url; $bad['image_sha256'] = hash( 'sha256', 'different image' ); $check( is_wp_error( $analyze( $encode( array_merge( $rows, array( $bad ) ) ) ) ), 'a URL binding must reference the actual declared source image bytes' );
foreach ( array( 'https://user:secret@source.invalid/image.png', 'https://source.invalid/image.png?token=secret', 'https://source.invalid/image.png#fragment', 'file:///tmp/image.png', "https://source.invalid/a\n.png" ) as $unsafe ) { $bad = $url; $bad['source_url'] = $unsafe; $check( is_wp_error( $codec->validate( $bad ) ), 'unsafe URL credentials, wrappers or aliases are refused' ); }
$legacy_text = str_replace( '"format_version":2', '"format_version":1', $encode( $extended ) ); $check( is_wp_error( $analyze( $legacy_text ) ), 'new record kinds cannot be smuggled into an original version-one stream' );
$bad = $file; $bad['descriptor']['size'] = 499; $check( is_wp_error( $analyze( $encode( array_merge( $rows, array( $bad ) ) ) ) ), 'two asset roles cannot disagree on the same file length' );
echo "Checks: $passed passed, $failed failed\n"; exit( $failed ? 1 : 0 );
