<?php
/** Immutable intake validation precedes persistence, recovery and execution. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message = '', $data = null ) {} }
function __( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
use Certloom\Application\Issuance\IssuanceIntake;
use Certloom\Application\Rendering\CanonicalJson;
$passed = 0; $failed = 0;
function ii_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS $label\n"; } else { ++$failed; echo "FAIL $label\n"; } }
$contract = new IssuanceIntake();
$request = array( 'recipient_mode' => 'existing', 'recipient_id' => 2, 'credential_basis' => 'program', 'program_id' => 3, 'batch_id' => 0, 'valid_from' => '2026-10-02', 'presentation_mode' => 'details_only', 'custom_merge_fields' => array( 'registration_number' => '0001', 'score' => 0 ) );
ii_check( true === $contract->validate_request( $request ), 'typed request preserves zero scores and leading-zero values' );
foreach ( array( 'recipient_id' => array( 2 ), 'issuer_id' => true, 'program_id' => -1, 'batch_id' => PHP_INT_MAX . '0', 'registration_id' => '9223372036854775808', 'grade' => array( 'A' ), 'display_number' => false, 'valid_from' => '2026-02-30', 'title_source' => 'arbitrary', 'recipient_mode' => 'new', 'new_recipient' => array( 'display_name' => 'other' ), 'source_type' => 'manual_admin', 'review_fingerprint' => str_repeat( 'a', 64 ) ) as $key => $value ) {
	$bad = $request; $bad[ $key ] = $value;
	ii_check( is_wp_error( $contract->validate_request( $bad ) ), 'request refuses unsupported or untyped ' . $key );
}
foreach ( array( array( 'score' => array( '99' ) ), array( 'score' => true ), array( 'score' => INF ), array( 'score' => NAN ), array( 'bad.key' => 'data' ), array( 'score' => str_repeat( 'a', 8001 ) ) ) as $index => $fields ) {
	$bad = $request; $bad['custom_merge_fields'] = $fields;
	ii_check( is_wp_error( $contract->validate_request( $bad ) ), 'custom scalar payload rejects unsafe case ' . $index );
}
$bad = $request; $bad['repeating_data'] = array( 'results' => array( array( 'subject' => 'Math', 'score' => true ) ) );
ii_check( is_wp_error( $contract->validate_request( $bad ) ), 'repeating results cannot coerce a boolean to a score' );
$bad['repeating_data']['results'][0]['score'] = '0';
ii_check( true === $contract->validate_request( $bad ), 'approved repeating-data contract accepts a zero result' );
$bad = $request; $bad['verification_field_keys'] = array( array( 'score' ) );
ii_check( is_wp_error( $contract->validate_request( $bad ) ), 'verification list cannot contain structured keys' );
$evidence = array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'unknown', 'checked_at' => null, 'valid_until' => null, 'fields' => array() );
$envelope = array( '_meta' => array( 'schema' => IssuanceIntake::SCHEMA, 'schema_version' => 1 ), 'request' => $request, 'initial_evidence' => $evidence );
$json = ( new CanonicalJson() )->encode( $envelope );
$row = array( 'intake_json' => $json, 'payload_sha256' => hash( 'sha256', $json ), 'recipient_id' => 2, 'program_id' => 3, 'batch_id' => null, 'registration_id' => 7, 'restore_state' => 'local', 'status' => 'received' );
ii_check( json_decode( $json, true ) === $contract->from_row( $row ), 'validation returns the original data without changing replay identity' );
$bad = $row; $bad['intake_json'] = str_replace( '0001', '0002', $json );
ii_check( is_wp_error( $contract->from_row( $bad ) ), 'changed immutable payload fails its original digest' );
$bad = $row; $bad['program_id'] = 9;
ii_check( is_wp_error( $contract->from_row( $bad ) ), 'local request cannot inherit another Program' );
$bad['restore_state'] = 'paused';
ii_check( ! is_wp_error( $contract->from_row( $bad ) ), 'paused portable history retains source IDs without rewriting the digest' );
$bad['restore_state'] = 'reconciled';
ii_check( is_wp_error( $contract->from_row( $bad ) ), 'marking a remapped row reconciled alone cannot grant execution authority' );
$bad = $envelope; $bad['_meta']['schema_version'] = '1';
ii_check( is_wp_error( $contract->envelope( $bad ) ), 'intake schema version is strictly typed' );
$bad = $envelope; $bad['initial_evidence'] = array();
ii_check( is_wp_error( $contract->envelope( $bad ) ), 'initial evidence is a typed immutable contract' );
$erased = array( 'status' => 'cancelled', 'intake_json' => '{}', 'evidence_json' => null, 'source_ref' => null, 'review_sha256' => null, 'next_attempt_at' => null );
ii_check( $contract->is_erased( $erased ), 'cancelled privacy receipt is recognized without executable intake' );
$erased['status'] = 'waiting';
ii_check( ! $contract->is_erased( $erased ), 'a waiting request cannot masquerade as an erased receipt' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
