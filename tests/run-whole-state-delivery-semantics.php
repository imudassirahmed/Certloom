<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
$validator = new \Certloom\Application\Restore\WholeStateDeliveryRowValidator(); $checks = 0;
function ds_check( $value, $label ) { global $checks; if ( ! $value ) { throw new RuntimeException( $label ); } ++$checks; }
foreach ( array( 'email_deliveries', 'destination_deliveries' ) as $entity ) {
 $row = array_fill_keys( \Certloom\Application\Backup\WholeStateDeliveryRecord::columns( $entity ), null );
 foreach ( $row as $key => $value ) { if ( substr( $key, -7 ) === '_sha256' || 'dedupe_key' === $key ) { $row[$key] = str_repeat( 'a', 64 ); } }
 $row['id'] = '1'; $row['series_id'] = '2'; $row['version_id'] = '3'; $row['delivery_key'] = str_repeat( 'b', 32 ); $row['job_key'] = str_repeat( 'c', 32 ); $row['status'] = 'queued'; $row['created_at'] = $row['updated_at'] = '2026-10-04 12:00:00'; $row['evidence_json'] = '{"result":"0","identifier":"00123","transport_result":false}';
 if ( 'email_deliveries' === $entity ) { $row['delivery_kind'] = 'issued'; $row['template_version'] = '1'; $row['destination_mask'] = 'q***@example.invalid'; } else { $row['destination'] = 'credly'; }
 ds_check( true === $validator->validate( $entity, $row ), 'valid actual SQL delivery shape is accepted without normalization' );
 $projected = $validator->project( $entity, $row, str_repeat( 'f', 64 ) ); ds_check( is_array( $projected ) && 'paused' === $projected['status'] && null === $projected['job_key'] && $projected['failure_code'] === $validator::HOLD_CODE, 'queued source permission becomes an explicit hold without importing jobs' );
 $evidence = json_decode( $projected['evidence_json'], true ); ds_check( '0' === $evidence['result'] && '00123' === $evidence['identifier'] && false === $evidence['transport_result'] && $row['job_key'] === $evidence['certloom_restore']['original_job_key'], 'original receipt facts and prior job binding remain evidence without type coercion' );
 ds_check( $projected === $validator->project( $entity, $projected, str_repeat( 'e', 64 ) ), 'a subsequent recovery retains an existing valid hold without losing its source receipt' );
 foreach ( array( 'id', 'series_id', 'version_id' ) as $column ) { foreach ( array( '0', '001', '-1', '1e3', '9223372036854775808', 1 ) as $value ) { $bad = $row; $bad[$column] = $value; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'noncanonical or overflowing delivery graph IDs are refused' ); } }
 foreach ( array( 'created_at', 'updated_at', 'attempted_at', 'accepted_at', 'failed_at' ) as $column ) { foreach ( array( '2026-02-30 12:00:00', "2026-10-04 12:00:0\0" ) as $value ) { $bad = $row; $bad[$column] = $value; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'invalid delivery dates return errors without parser exceptions' ); } }
 foreach ( array( 'unknown', 'sent_again', '' ) as $status ) { $bad = $row; $bad['status'] = $status; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'unknown send states do not become restart permission' ); }
 foreach ( array( 'not-json', 'null', '"scalar"', '{"broken":}', serialize( array( 'untrusted' ) ) ) as $json ) { $bad = $row; $bad['evidence_json'] = $json; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'corrupt and PHP serialized receipt evidence is not executed or repaired' ); }
 $sending = $row; $sending['status'] = 'sending'; $sending['attempt_token'] = str_repeat( 'd', 32 ); $sending['attempted_at'] = $row['created_at'];
 $held = $validator->project( $entity, $sending, str_repeat( 'f', 64 ) ); ds_check( is_array( $held ) && 'uncertain' === $held['status'] && $held['attempt_token'] === $sending['attempt_token'] && $held['attempted_at'] === $sending['attempted_at'], 'interrupted sending retains the exact attempt and becomes uncertain' );
 $terminal = $sending; $terminal['status'] = 'email_deliveries' === $entity ? 'sent' : 'succeeded'; $terminal['accepted_at'] = '2026-10-04 12:00:01';
 ds_check( $terminal === $validator->project( $entity, $terminal, str_repeat( 'f', 64 ) ), 'every terminal accepted receipt column stays byte-for-byte unchanged' );
 $uncertain = $sending; $uncertain['status'] = 'uncertain'; ds_check( $uncertain === $validator->project( $entity, $uncertain, str_repeat( 'f', 64 ) ), 'uncertain external acceptance is retained without manufacturing another attempt' );
 $bad = $terminal; $bad['accepted_at'] = null; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'accepted states require an actual receipt timestamp' );
 $bad = $sending; $bad['attempt_token'] = null; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'sending requires its durable attempt claim' );
 $bad = $projected; $bad['job_key'] = $row['job_key']; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'a held delivery cannot regain its source execution binding' );
 $bad = $projected; $bad['evidence_json'] = '{}'; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'a hold requires complete local projection evidence' );
 $bad = $row; $bad['untrusted_permission'] = true; ds_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'unknown columns cannot carry restored execution authority' );
}
echo $checks . " delivery semantic checks, 0 failures\n";
