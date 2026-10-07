<?php
/** Pure policy, private notice schema, restore graph and erased-history contracts. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $v ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
use Certloom\Application\Issuance\PendingRequestPolicy;
use Certloom\Application\Recipient\RecipientNoticeRecord;
use Certloom\Application\Restore\RecipientWorkflowRowValidator;
$passed = 0; $failed = 0;
function lifecycle_check( $value, $label ) { global $passed, $failed; if ( $value ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$service = new PendingRequestPolicy(); $defaults = $service::defaults();
lifecycle_check( array( 'completion' => null, 'cancellation' => null ) === $service->normalize_notice_since( $service::notice_since_defaults() ), 'absent notice cutoffs cannot send historical closed requests' );
foreach ( array( array( 'completion' => '2026-02-31 00:00:00', 'cancellation' => null ), array( 'completion' => 123, 'cancellation' => null ), array( 'completion' => null ), array( 'completion' => null, 'cancellation' => null, 'extra' => true ) ) as $bad ) { lifecycle_check( is_wp_error( $service->normalize_notice_since( $bad ) ), 'malformed notice activation boundary fails closed' ); }
lifecycle_check( $defaults === $service->normalize( $defaults ) && 0 === $defaults['reminder_days'] && 0 === $defaults['expiry_days'] && false === $defaults['completion_notice'] && false === $defaults['cancellation_notice'], 'upgraded and new sites start with optional operations disabled' );
foreach ( array( 'version' => 2, 'reminder_days' => '7', 'reminder_limit' => 4, 'completion_notice' => 'false', 'cancellation_notice' => 1, 'expiry_days' => -1, 'extra' => true ) as $key => $value ) {
 $bad = $defaults; $bad[$key] = $value; lifecycle_check( is_wp_error( $service->normalize( $bad ) ), 'malformed ' . $key . ' cannot enable lifecycle policy' );
}
$policy = $defaults; $policy['expiry_days'] = 30; $now = strtotime( '2026-10-04 12:00:00 UTC' );
$row = array( 'restore_state' => 'local', 'status' => 'waiting', 'created_at' => '2026-09-04 12:00:00' );
lifecycle_check( $service->expired( $row, $policy, $now ), 'expiry includes the exact UTC deadline' );
$row['created_at'] = '2026-09-04 12:00:01'; lifecycle_check( ! $service->expired( $row, $policy, $now ), 'a request just before its deadline stays open' );
$row['created_at'] = '2026-01-01 00:00:00';
foreach ( array( 'issued', 'cancelled' ) as $status ) { $row['status'] = $status; lifecycle_check( ! $service->expired( $row, $policy, $now ), $status . ' is terminal and never expires as a request' ); }
$row['status'] = 'waiting'; foreach ( array( 'paused', 'reconciled' ) as $state ) { $row['restore_state'] = $state; lifecycle_check( ! $service->expired( $row, $policy, $now ), 'restored ' . $state . ' work does not resume by expiry' ); }
$meta = array( 'version' => 1, 'notice_key' => str_repeat( 'a', 64 ), 'kind' => 'reminder', 'slot' => 1, 'state' => 'queued', 'user_id' => 8, 'destination_sha256' => str_repeat( 'b', 64 ), 'job_key' => str_repeat( 'c', 32 ), 'attempt_sha256' => null );
$record = new RecipientNoticeRecord(); lifecycle_check( true === $record->validate( $meta ), 'pending notices do not invent a Series or Version' );
foreach ( array( 'recipient_email' => 'private@example.test', 'answer' => 'private', 'version_id' => 1, 'user_id' => '8', 'slot' => 4, 'state' => 'approved' ) as $key => $value ) { $bad = $meta; $bad[$key] = $value; lifecycle_check( is_wp_error( $record->validate( $bad ) ), 'notice rejects ' . $key . ' expansion or invalid identity' ); }
foreach ( array( 'sending', 'sent', 'failed', 'uncertain' ) as $state ) {
 $bad = $meta; $bad['state'] = $state; lifecycle_check( is_wp_error( $record->validate( $bad ) ), $state . ' needs an exact attempt fingerprint' );
 $bad['attempt_sha256'] = str_repeat( 'd', 64 ); lifecycle_check( true === $record->validate( $bad ), $state . ' has durable attempt evidence' );
}
$event = array( 'id' => '1', 'request_id' => '2', 'event_type' => 'request.notice', 'event_key' => RecipientNoticeRecord::event_key( $meta['notice_key'], 'queued' ), 'actor_user_id' => null, 'reason_code' => 'notice_queued', 'request_revision' => '1', 'meta_json' => json_encode( $meta ) );
$validator = new RecipientWorkflowRowValidator(); lifecycle_check( true === $validator->validate( 'issuance_request_events', $event ), 'notice history is validated by the live and backup row contract' );
$bad = $event; $bad['event_key'] = str_repeat( 'e',64 ); lifecycle_check( is_wp_error( $validator->validate( 'issuance_request_events', $bad ) ), 'forged replay evidence fails validation' );
$event['event_type'] = 'request.notice_erased'; $event['meta_json'] = null; $event['reason_code'] = 'notice_erased'; lifecycle_check( true === $validator->validate( 'issuance_request_events', $event ), 'privacy erasure preserves a non-executable history tombstone' );
$event['meta_json'] = json_encode( $meta ); lifecycle_check( is_wp_error( $validator->validate( 'issuance_request_events', $event ) ), 'erased history cannot retain account or destination metadata' );
printf( "\n%d lifecycle policy checks, %d failures\n", $passed, $failed ); exit( $failed ? 1 : 0 );
