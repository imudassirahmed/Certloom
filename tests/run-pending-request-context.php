<?php
/** Qualification, source, restore holds and contribution revisions are independent. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
use Certloom\Application\Issuance\PendingRequestContext;
use Certloom\Application\Rendering\CanonicalJson;
$passed = 0; $failed = 0;
function pc_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS $label\n"; } else { ++$failed; echo "FAIL $label\n"; } }
$evidence = array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => '2026-10-02 00:00:00', 'valid_until' => '2026-10-03 00:00:00', 'fields' => array( 'score' => array( 'value' => '0', 'writer' => 'source', 'revision' => 'attempt-1' ) ) );
$envelope = array( '_meta' => array( 'schema' => 'certloom-issuance-intake', 'schema_version' => 1 ), 'request' => array( 'recipient_mode' => 'existing', 'recipient_id' => 2, 'program_id' => 3, 'batch_id' => 0, 'credential_basis' => 'program', 'valid_from' => '2026-10-02' ), 'initial_evidence' => $evidence );
$json = ( new CanonicalJson() )->encode( $envelope );
$row = array( 'id' => 5, 'public_ref' => str_repeat( 'a', 32 ), 'intake_key' => str_repeat( 'b', 64 ), 'intake_json' => $json, 'payload_sha256' => hash( 'sha256', $json ), 'evidence_json' => wp_json_encode( $evidence ), 'evidence_revision' => 1, 'recipient_id' => 2, 'program_id' => 3, 'batch_id' => null, 'registration_id' => 7, 'source_type' => 'manual', 'mapping_sha256' => null, 'restore_state' => 'local', 'status' => 'received' );
$service = new PendingRequestContext(); $now = '2026-10-02 12:00:00';
$out = $service->resolve( $row, $now );
pc_check( ! is_wp_error( $out ) && 7 === $out['request']['registration_id'], 'v1 intake resolves its exact durable enrollment without rewriting original JSON' );
pc_check( ! isset( $out['context']['fields'] ) && ! isset( $out['context']['evidence'] ), 'snapshot context contains provenance fingerprints without raw source fields' );
foreach ( array( 'restore_state' => 'paused', 'source_type' => 'webhook', 'mapping_sha256' => str_repeat( 'c', 64 ), 'status' => 'issued' ) as $key => $value ) {
	$bad = $row; $bad[ $key ] = $value;
	pc_check( is_wp_error( $service->resolve( $bad, $now ) ), 'unsupported execution authority fails closed for ' . $key );
}
foreach ( array( 'unknown', 'not_eligible' ) as $eligibility ) {
	$bad = $row; $changed = $evidence; $changed['eligibility'] = $eligibility; $bad['evidence_json'] = wp_json_encode( $changed );
	pc_check( is_wp_error( $service->resolve( $bad, $now ) ), 'registration/profile data never bypasses ' . $eligibility . ' qualification' );
}
pc_check( is_wp_error( $service->resolve( $row, '2026-10-01 23:59:59' ) ), 'future source evidence is refused' );
pc_check( is_wp_error( $service->resolve( $row, '2026-10-03 00:00:00' ) ), 'expiry boundary is checked again at final execution' );
$changed = $row; $changed['status'] = 'processing'; $changed['revision'] = 99;
pc_check( $out['context'] === $service->resolve( $changed, $now )['context'], 'ordinary readiness transitions do not invalidate the contributing-data fingerprint' );
$changed['evidence_revision'] = 2;
pc_check( $out['context'] !== $service->resolve( $changed, $now )['context'], 'a new evidence revision invalidates the old review' );
$changed = $row; $next = $evidence; $next['fields']['score']['value'] = '90'; $changed['evidence_json'] = wp_json_encode( $next );
pc_check( $out['context']['evidence_sha256'] !== $service->resolve( $changed, $now )['context']['evidence_sha256'], 'evidence content is fingerprinted independently of the numeric revision' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
