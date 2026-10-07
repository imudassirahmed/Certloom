<?php
/** Indexed selection ceilings, bounded slices and replay-safe retry cursors. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message = '', $data = null ) {} }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function current_time( $format, $utc = false ) { return '2026-10-02 12:00:00'; }
class PendingSelection implements \Certloom\Application\Contract\PendingRequestSelectionInterface {
	public $ids; public $selection;
	public function due_ids( $cutoff, $after_id = 0, $max_id = PHP_INT_MAX, $limit = 11 ) { $this->selection = array( $cutoff, $after_id, $max_id, $limit ); return $this->ids; }
}
class PendingExecutor implements \Certloom\Application\Contract\PendingRequestExecutorInterface {
	public $called = array(); public $fail = 0; public $wrong_owner = false;
	public function process( $id ) { $this->called[] = $id; return $id === $this->fail ? new WP_Error( 'storage_failed' ) : array( 'id' => $this->wrong_owner ? 99 : $id, 'status' => $id % 2 ? 'waiting' : 'issued' ); }
}
$passed = 0; $failed = 0;
function pj_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS $label\n"; } else { ++$failed; echo "FAIL $label\n"; } }
use Certloom\Application\Issuance\PendingIssuanceJobHandler;
use Certloom\Application\Job\JobSliceResult;
$selection = new PendingSelection(); $executor = new PendingExecutor(); $handler = new PendingIssuanceJobHandler( $selection, $executor );
$job = array( 'payload' => array( 'schema_version' => 1, 'cutoff' => '2026-10-02 11:59:59', 'max_id' => 20 ), 'cursor' => 0 );
$selection->ids = range( 1, 11 ); $slice = $handler->handle( $job, 'worker' );
pj_check( JobSliceResult::PROGRESS === $slice->state() && 10 === $slice->cursor() && range( 1, 10 ) === $executor->called, 'an eleven-row selection executes only ten and yields the last committed cursor' );
pj_check( array( '2026-10-02 11:59:59', 0, 20, 11 ) === $selection->selection, 'worker selection uses the original cutoff, cursor, ceiling and bounded lookahead' );
pj_check( 10 === $slice->result()['processed'] && 5 === $slice->result()['issued'] && 5 === $slice->result()['waiting'], 'worker stores only bounded aggregate counts, with no field or evidence values' );
$job['cursor'] = 10; $job['result'] = $slice->result(); $selection->ids = array( 11 ); $executor->called = array();
$slice = $handler->handle( $job, 'worker');
pj_check( JobSliceResult::SUCCEEDED === $slice->state() && array( 11 ) === $executor->called && 11 === $slice->result()['processed'], 'resume advances past committed rows and retains the aggregate result' );
foreach ( array( array( 11, 11 ), array( 12, 11 ), array( 21 ), array( '11' ), range( 11, 22 ) ) as $index => $ids ) {
	$selection->ids = $ids; $executor->called = array();
	pj_check( is_wp_error( $handler->handle( $job, 'worker' ) ) && ! $executor->called, 'invalid selection cannot execute a request, case ' . $index );
}
$selection->ids = array( 11, 12, 13 ); $executor->fail = 12; $executor->called = array();
pj_check( is_wp_error( $handler->handle( $job, 'worker' ) ) && array( 11, 12 ) === $executor->called, 'storage failure stops the slice and leaves later requests unprocessed for a safe retry' );
$executor->fail = 0; $executor->wrong_owner = true;
pj_check( is_wp_error( $handler->handle( $job, 'worker' ) ), 'executor receipt must match the selected request identity' );
foreach ( array( array( 'schema_version' => '1' ), array( 'max_id' => 0 ), array( 'cutoff' => '2026-02-30 12:00:00' ), array( 'cutoff' => '2026-10-02 12:00:01' ), array( 'request' => array( 'grade' => '100' ) ) ) as $index => $patch ) {
	$bad = $job; $bad['payload'] = array_merge( $bad['payload'], $patch ); $executor->called = array();
	pj_check( is_wp_error( $handler->handle( $bad, 'worker' ) ) && ! $executor->called, 'untyped or expanded payload fails before execution, case ' . $index );
}
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
