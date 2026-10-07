<?php
/** Bounded configuration scans cannot grant qualification, source or restore authority. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message = '', $data = null ) {} }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
class ConfigurationSelection implements \Certloom\Application\Contract\PendingConfigurationSelectionInterface {
	public $rows = array(); public $selected; public $acknowledged = array(); public $fail_ack = false;
	public function configuration_rows( $program_id, $after_id, $max_id, $limit = 11 ) { $this->selected = array( $program_id, $after_id, $max_id, $limit ); return $this->rows; }
	public function acknowledge( $generation ) { $this->acknowledged[] = $generation; return $this->fail_ack ? new WP_Error( 'storage_failed' ) : true; }
}
class ConfigurationExecutor implements \Certloom\Application\Contract\PendingRequestExecutorInterface {
	public $called = array(); public $fail = 0; public $wrong_id = false; public $status = 'waiting';
	public function process( $id ) { $this->called[] = $id; return $id === $this->fail ? new WP_Error( 'storage_failed' ) : array( 'id' => $this->wrong_id ? 999 : $id, 'status' => $this->status ); }
}
$passed = 0; $failed = 0;
function pcj_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS $label\n"; } else { ++$failed; echo "FAIL $label\n"; } }
function pcj_row( $id, array $patch = array() ) { return array_merge( array( 'id' => $id, 'program_id' => 7, 'status' => 'waiting', 'restore_state' => 'local', 'source_type' => 'manual', 'mapping_sha256' => null ), $patch ); }
use Certloom\Application\Issuance\PendingConfigurationJobHandler;
use Certloom\Application\Job\JobSliceResult;
$selection = new ConfigurationSelection(); $executor = new ConfigurationExecutor(); $handler = new PendingConfigurationJobHandler( $selection, $executor );
$job = array( 'payload' => array( 'schema_version' => 1, 'generation' => str_repeat( 'a', 32 ), 'program_id' => 7, 'max_id' => 50 ), 'cursor' => 0 );
$selection->rows = array_map( 'pcj_row', range( 1, 11 ) );
$slice = $handler->handle( $job, 'worker' );
pcj_check( JobSliceResult::PROGRESS === $slice->state() && 10 === $slice->cursor() && range( 1, 10 ) === $executor->called, 'one page assesses at most ten requests with an eleven-row lookahead' );
pcj_check( array( 7, 0, 50, 11 ) === $selection->selected && ! $selection->acknowledged, 'partial work retains its configuration signal and original scope/ceiling' );
pcj_check( array( 'checked' => 10, 'reassessed' => 10, 'issued' => 0 ) === $slice->result(), 'result stores aggregate scheduling counts only' );
$job['cursor'] = 10; $job['result'] = $slice->result(); $selection->rows = array( pcj_row( 11 ) ); $executor->called = array(); $executor->status = 'issued';
$slice = $handler->handle( $job, 'worker' );
pcj_check( JobSliceResult::SUCCEEDED === $slice->state() && array( 11 ) === $executor->called && 11 === $slice->cursor() && 11 === $slice->result()['checked'] && 1 === $slice->result()['issued'], 'resumed work advances past committed rows and keeps aggregate results' );
pcj_check( array( str_repeat( 'a', 32 ) ) === $selection->acknowledged, 'only a complete pass acknowledges its own generation' );
$job['cursor'] = 0; unset( $job['result'] ); $job['payload']['program_id'] = 0;
$selection->rows = array( pcj_row( 1, array( 'status' => 'issued' ) ), pcj_row( 2, array( 'status' => 'cancelled' ) ), pcj_row( 3, array( 'status' => 'not_eligible' ) ), pcj_row( 4, array( 'restore_state' => 'paused' ) ), pcj_row( 5, array( 'source_type' => 'webhook' ) ), pcj_row( 6, array( 'mapping_sha256' => str_repeat( 'b', 64 ) ) ), pcj_row( 7, array( 'program_id' => 8 ) ) );
$executor->called = array(); $slice = $handler->handle( $job, 'worker' );
pcj_check( array( 7 ) === $executor->called && 7 === $slice->result()['checked'] && 1 === $slice->result()['reassessed'], 'global pages skip terminal, not-eligible, restored, external and mapped work while accepting local manual intent' );
$selection->rows = array( pcj_row( 1, array( 'restore_state' => 'reconciled' ) ) ); $executor->called = array(); $slice = $handler->handle( $job, 'worker' );
pcj_check( JobSliceResult::SUCCEEDED === $slice->state() && ! $executor->called, 'reconciled restored work remains held without blocking a global scan' );
foreach ( \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::STATUSES as $index => $status ) {
	$selection->rows = array( pcj_row( 1, array( 'status' => $status ) ) ); $executor->called = array();
	pcj_check( $handler->handle( $job, 'worker' ) instanceof JobSliceResult && array( 1 ) === $executor->called, 'accepted local manual status remains reassessable: ' . $status );
}
foreach ( array( array( pcj_row( 2 ), pcj_row( 2 ) ), array( pcj_row( 2 ), pcj_row( 1 ) ), array( pcj_row( 51 ) ), array( pcj_row( '1' ) ), array_map( 'pcj_row', range( 1, 12 ) ), array( pcj_row( 1, array( 'program_id' => 0 ) ) ), array( pcj_row( 1, array( 'status' => 'invalid' ) ) ), array( pcj_row( 1, array( 'restore_state' => 'active' ) ) ), array( pcj_row( 1, array( 'mapping_sha256' => false ) ) ) ) as $index => $rows ) {
	$selection->rows = $rows; $executor->called = array(); $selection->acknowledged = array();
	pcj_check( is_wp_error( $handler->handle( $job, 'worker' ) ) && ! $executor->called && ! $selection->acknowledged, 'malformed selection cannot execute or consume its signal, case ' . $index );
}
$job['payload']['program_id'] = 7; $selection->rows = array( pcj_row( 1, array( 'program_id' => 8 ) ) ); $executor->called = array();
pcj_check( is_wp_error( $handler->handle( $job, 'worker' ) ) && ! $executor->called, 'a Program-scoped scan cannot assess another Program' );
$selection->rows = array( pcj_row( 1 ), pcj_row( 2 ), pcj_row( 3 ) ); $executor->fail = 2; $executor->called = array(); $selection->acknowledged = array();
pcj_check( is_wp_error( $handler->handle( $job, 'worker' ) ) && array( 1, 2 ) === $executor->called && ! $selection->acknowledged, 'storage failure stops the page without consuming later work or its signal' );
$executor->fail = 0; $executor->wrong_id = true; $executor->called = array();
pcj_check( is_wp_error( $handler->handle( $job, 'worker' ) ) && ! $selection->acknowledged, 'an unrelated executor receipt cannot acknowledge the pass' );
$executor->wrong_id = false; $selection->fail_ack = true;
pcj_check( is_wp_error( $handler->handle( $job, 'worker' ) ), 'failed acknowledgement leaves the job retryable rather than claiming completion' );
$selection->fail_ack = false;
foreach ( array( array( 'schema_version' => '1' ), array( 'generation' => 'invalid' ), array( 'program_id' => '7' ), array( 'program_id' => -1 ), array( 'max_id' => 0 ), array( 'fields' => array( 'score' => 100 ) ) ) as $index => $patch ) {
	$bad = $job; $bad['payload'] = array_merge( $bad['payload'], $patch ); $executor->called = array(); $selection->acknowledged = array();
	pcj_check( is_wp_error( $handler->handle( $bad, 'worker' ) ) && ! $executor->called && ! $selection->acknowledged, 'invalid or expanded payload fails before execution, case ' . $index );
}
$selection->rows = array(); $slice = $handler->handle( $job, 'worker' );
pcj_check( JobSliceResult::SUCCEEDED === $slice->state(), 'an empty eligible scope completes and acknowledges without issuing' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
