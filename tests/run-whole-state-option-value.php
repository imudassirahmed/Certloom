<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v ) { return $v; }
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Backup\WholeStateOptionRecord;
$checks = 0;
function value_check( $v, $label ) { global $checks; if ( ! $v ) { throw new RuntimeException( $label ); } ++$checks; }
$codec = new WholeStateOptionRecord(); $reader = new WholeStateOptionValue();
foreach ( array( '', '00123', '0', 'false', 'لانس', 'a plain string', serialize( array( 'identity' => '00123', 'score' => '0', 'enabled' => false, 'empty' => null, 'unicode' => 'لانس' ) ), serialize( array( 4 => 'sparse', 'nested' => array( true, 3.25 ) ) ), serialize( false ), serialize( null ), serialize( 'a:0:{}' ) ) as $raw ) {
 $result = $reader->decode( $codec->from_storage( $raw ) ); value_check( ! is_wp_error( $result ) && true === $result['exists'], 'safe present bytes decode' );
 if ( ! preg_match( '/^[aObsidCR]:/', $raw ) && 'N;' !== $raw ) { value_check( $raw === $result['value'], 'scalar storage retains exact text and leading zeros' ); }
}
value_check( array( 'exists' => false, 'value' => null ) === $reader->decode( $codec->from_storage( null ) ), 'absence stays distinct from present serialized null and empty' );
$GLOBALS['whole_value_woke'] = false;
class RecoveryUnsafeValue { public function __wakeup() { $GLOBALS['whole_value_woke'] = true; } }
foreach ( array( serialize( new RecoveryUnsafeValue() ), 'a:1:{s:1:"x";O:19:"RecoveryUnsafeValue":0:{}}', 'a:1:{i:0;R:1;}', 'a:1:{i:0;s:20:"truncated";}', 'a:0:{}garbage', 'b:2;', serialize( INF ), serialize( NAN ), 'C:19:"RecoveryUnsafeValue":0:{}', 'R:1;' ) as $bad ) { value_check( is_wp_error( $reader->decode( $codec->from_storage( $bad ) ) ), 'objects, references, corrupt encodings and non-finite values refuse preflight' ); }
value_check( false === $GLOBALS['whole_value_woke'], 'decoding never executes an archive object wakeup hook' );
$nested = 'value'; for ( $i = 0; $i < 35; ++$i ) { $nested = array( $nested ); }
value_check( is_wp_error( $reader->decode( $codec->from_storage( serialize( $nested ) ) ) ), 'depth is bounded before semantic traversal' );
value_check( is_wp_error( $reader->decode( $codec->from_storage( serialize( array_fill( 0, 50001, 0 ) ) ) ) ), 'wide semantic trees have a closed node ceiling' );
$record = $codec->from_storage( '00123' ); $record['sha256'] = str_repeat( 'f', 64 ); value_check( is_wp_error( $reader->decode( $record ) ), 'invalid exact bytes fail before decoding' );
echo $checks . " safe recovery option checks, 0 failures\n";
