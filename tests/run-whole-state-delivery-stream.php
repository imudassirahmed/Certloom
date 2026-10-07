<?php
/** Bounded streaming transport, exact null/bytes and complete delivery inventories. */
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v ) { return $v; }
use Certloom\Application\Backup\WholeStateDeliveryFormat;
use Certloom\Application\Backup\WholeStateDeliveryRecord;
use Certloom\Application\Backup\WholeStateOptionRecord;
$checks = 0;
function stream_check( $v, $message ) { global $checks; if ( ! $v ) { throw new RuntimeException( $message ); } ++$checks; }
function stream_analyze( $bytes, &$rows ) { $handle = fopen( 'php://temp', 'w+b' ); fwrite( $handle, $bytes ); rewind( $handle ); $rows = array(); $result = ( new WholeStateDeliveryFormat() )->analyze( $handle, static function ( $type, $row ) use ( &$rows ) { $rows[] = array( $type, $row ); return true; } ); fclose( $handle ); return $result; }
$format = new WholeStateDeliveryFormat(); $codec = new WholeStateDeliveryRecord(); $types = array( 'email_deliveries', 'destination_deliveries' ); $records = array(); $originals = array(); $entities = array();
foreach ( $types as $type ) {
 $row = array_fill_keys( $codec::columns( $type ), null ); $row['id'] = 'email_deliveries' === $type ? '8' : '19'; $row['delivery_key'] = str_repeat( 'a',32 ); $row['status'] = 'sent'; $row['evidence_json'] = '{"identifier":"00123","score":"0","unicode":"لانس"}'; $row['failure_code'] = '';
 $record = $codec->from_row( $type, $row ); stream_check( ! is_wp_error( $record ), 'exact storage record builds' ); $records[] = $record; $originals[] = array( $type, $row ); $entities[$type] = array( 'count' => 1, 'max_id' => $row['id'], 'bytes' => $record['bytes'] );
}
$runs = ( new WholeStateOptionRecord() )->from_storage( serialize( array( array( 'automation_id' => 'issued-once', 'status' => 'sent' ) ) ) );
$header = $format->header( array( 'entities' => $entities ), $runs ); stream_check( is_string( $header ), 'complete automation replay record and delivery bounds build' );
$lines = array( $header ); $hash = $format::next_hash( '', $header ); foreach ( $records as $record ) { $line = $format->record( $record ); $lines[] = $line; $hash = $format::next_hash( $hash, $line ); } $footer = $format->footer( 2, $hash ); $lines[] = $footer; $bytes = implode( '', $lines );
$result = stream_analyze( $bytes, $rows ); stream_check( ! is_wp_error( $result ) && $rows === $originals && $runs == $result['automation_runs'] && 2 === $result['records'], 'both delivery stores and replay bytes round trip with SQL null and empty distinguished' );
foreach ( array( substr( $bytes,0,-1 ), $bytes . "\n", $bytes . $footer, $header . $footer, implode( '', array( $header,$lines[2],$lines[1],$footer ) ), implode( '', array( $header,$lines[1],$lines[1],$lines[2],$footer ) ), str_replace( 'c2VudA==','ZmFpbGVk',$bytes ), str_replace( '"records":2','"records":1',$bytes ), str_replace( '"format_version":1','"format_version":2',$bytes ), str_replace( '"type":"manifest"','"type":"manifest","type":"manifest"',$bytes ) ) as $bad ) { stream_check( is_wp_error( stream_analyze( $bad, $rows ) ), 'truncated, expanded, reordered or forged stream fails closed' ); }
$bad_entities = $entities; $bad_entities['email_deliveries']['bytes']++; $bad_header = $format->header( array( 'entities' => $bad_entities ), $runs ); $bad_hash = $format::next_hash( '', $bad_header ); foreach ( array( $lines[1],$lines[2] ) as $line ) { $bad_hash = $format::next_hash( $bad_hash,$line ); } stream_check( is_wp_error( stream_analyze( $bad_header . $lines[1] . $lines[2] . $format->footer(2,$bad_hash), $rows ) ), 'even a recomputed chain cannot conceal incorrect declared storage bytes' );
foreach ( array( 'count' => '1', 'max_id' => 8, 'bytes' => -1, 'extra' => true ) as $key => $v ) { $bad = $entities; $bad['email_deliveries'][$key] = $v; stream_check( is_wp_error( $format->header( array( 'entities' => $bad ), $runs ) ), 'invalid typed inventory ' . $key . ' is rejected' ); }
$empty = array_fill_keys( $types, array( 'count' => 0, 'max_id' => '0', 'bytes' => 0 ) ); $empty_header = $format->header( array( 'entities' => $empty ), ( new WholeStateOptionRecord() )->from_storage(null) ); stream_check( ! is_wp_error( stream_analyze( $empty_header . $format->footer(0,$format::next_hash('',$empty_header)), $rows ) ) && ! $rows, 'empty complete stores retain absent replay evidence' );
$h = fopen( 'php://temp', 'w+b' ); fwrite( $h,$bytes ); rewind($h); $stopped = $format->analyze( $h, static function () { return new WP_Error( 'qa_semantic_rejected' ); } ); fclose($h); stream_check( is_wp_error($stopped) && 'qa_semantic_rejected' === $stopped->get_error_code(), 'semantic staging rejection propagates without publication' );
stream_check( is_wp_error( stream_analyze( str_repeat('x',$format::MAX_LINE_BYTES+1),$rows ) ), 'overlong unterminated line is bounded before JSON parsing' );
echo $checks . " delivery stream checks, 0 failures\n";
