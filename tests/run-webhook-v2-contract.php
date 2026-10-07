<?php
/** Route-bound authentication, strict scalar intake and source ownership. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $v ) { return $v; } function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
use Certloom\Application\Integration\WebhookV2Contract;
use Certloom\Application\Integration\WebhookFieldMapping;
use Certloom\Application\Fields\FieldCollectionPolicy;
$passed = 0; $failed = 0;
function w2_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS $label\n"; } else { ++$failed; echo "FAIL $label\n"; } }
$wire = new WebhookV2Contract(); $source = 'v2-0123456789ab'; $secret = str_repeat( 'a', 64 ); $now = 1791000000; $ts = (string) $now;
$mapping = array( 'Score' => 'assessment_score', 'StudentID' => 'assessment_reference' );
$payload = array( 'schema_version' => 2, 'event_id' => 'attempt/1.completed', 'recipient_external_id' => 'student-001', 'registration_id' => 0, 'completed_at' => '2026-10-02T12:00:00Z', 'evidence' => array( 'eligibility' => 'eligible', 'checked_at' => '2026-10-02T12:00:00Z', 'valid_until' => null, 'fields' => array( 'Score' => array( 'value' => '0', 'revision' => 'attempt/1.rev1' ), 'StudentID' => array( 'value' => '00123', 'revision' => 'attempt/1.rev1' ) ) ) );
$raw = json_encode( $payload ); $input = $wire->signing_input( $source, 'intake', $ts, $raw ); $signature = 'sha256=' . hash_hmac( 'sha256', $input, $secret );
w2_check( true === $wire->authenticate( $source, 'intake', $ts, $signature, $raw, $secret, $now ), 'correct route, timestamp and exact bytes authenticate' );
foreach ( array( array( 'test', $source, $raw, '' ), array( 'intake', 'v2-1123456789ab', $raw, '' ), array( 'intake', $source, $raw . "\n", '' ), array( 'evidence', $source, $raw, str_repeat( 'b', 32 ) ) ) as $i => $case ) { w2_check( is_wp_error( $wire->authenticate( $case[1], $case[0], $ts, $signature, $case[2], $secret, $now, $case[3] ) ), 'signature cannot transfer to another route, source or body ' . $i ); }
w2_check( is_wp_error( $wire->authenticate( $source, 'intake', $ts, $signature, $raw, $secret, $now + 301 ) ), 'clock window fails after 300 seconds' );
w2_check( true === $wire->authenticate( $source, 'intake', $ts, $signature, $raw, $secret, $now + 300 ), 'exact clock boundary is accepted' );
w2_check( is_wp_error( $wire->authenticate( $source, 'intake', $ts, $signature, $raw, str_repeat( 'b', 64 ), $now ) ), 'rotated secret cannot authenticate an old signature' );
$v1 = 'sha256=' . hash_hmac( 'sha256', $ts . '.' . $raw, $secret ); w2_check( is_wp_error( $wire->authenticate( $source, 'intake', $ts, $v1, $raw, $secret, $now ) ), 'legacy signature has a different domain' );
$parsed = $wire->intake( $raw, $mapping ); w2_check( ! is_wp_error( $parsed ) && '0' === $parsed['evidence']['fields']['assessment_score']['value'] && '00123' === $parsed['evidence']['fields']['assessment_reference']['value'], 'zero results and leading-zero text identifiers retain their meaning' );
require dirname( __DIR__ ) . '/examples/webhook-v2-request.php';
foreach ( array( 'intake', 'test', 'evidence' ) as $mode ) {
	$ref = 'evidence' === $mode ? str_repeat( 'b', 32 ) : ''; $example = certloom_webhook_v2_request( $source, $secret, $mode, $payload, $ref, $ts );
	w2_check( true === $wire->authenticate( $source, $mode, $example['headers']['X-Certloom-Timestamp'], $example['headers']['X-Certloom-Signature'], $example['body'], $secret, $now, $ref ), 'portable HTTP recipe authenticates on ' . $mode . ' route' );
}
w2_check( 'source' === $parsed['evidence']['fields']['assessment_score']['writer'] && '2026-10-02 12:00:00' === $parsed['completed_at'], 'writer and UTC storage time come from the versioned contract' );
$cases = array(); $bad = $payload; $bad['recipient_email'] = 'not-an-identity@example.test'; $cases[] = $bad;
$bad = $payload; $bad['registration_id'] = '1'; $cases[] = $bad; $bad = $payload; $bad['registration_id'] = -1; $cases[] = $bad;
$bad = $payload; $bad['schema_version'] = '2'; $cases[] = $bad; $bad = $payload; $bad['completed_at'] = '2026-02-30T12:00:00Z'; $cases[] = $bad;
$bad = $payload; $bad['evidence']['fields']['Score']['writer'] = 'staff'; $cases[] = $bad;
$bad = $payload; $bad['evidence']['fields']['Score']['value'] = 0; $cases[] = $bad;
$bad = $payload; $bad['evidence']['fields']['FatherName'] = array( 'value' => 'Someone', 'revision' => '1' ); $cases[] = $bad;
$bad = $payload; $bad['evidence']['checked_at'] = null; $cases[] = $bad;
$bad = $payload; $bad['evidence']['valid_until'] = $bad['evidence']['checked_at']; $cases[] = $bad;
$bad = $payload; $bad['event_id'] = 'bad event'; $cases[] = $bad;
foreach ( $cases as $i => $case ) { w2_check( is_wp_error( $wire->intake( json_encode( $case ), $mapping ) ), 'unsupported or malformed input fails closed ' . $i ); }
w2_check( is_wp_error( $wire->intake( str_repeat( 'x', 32769 ), $mapping ) ), 'body length is bounded before decoding' );
w2_check( is_wp_error( $wire->mapping( array( 'score' => 'assessment_score', 'mark' => 'assessment_score' ) ) ), 'two incoming slots cannot replace one target' );
$policy = static function ( $writer, $lifetime ) { return array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => $writer, 'lifetime' => $lifetime, 'collect_when' => 'recipient' === $writer ? 'profile' : 'never', 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ); };
$definition = static function ( $key, $writer, $lifetime ) use ( $policy ) { return array( 'key' => $key, 'label' => $key, 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => $policy( $writer, $lifetime ) ); };
$catalog = array( 'assessment_score' => $definition( 'assessment_score', 'source', 'achievement' ), 'assessment_reference' => $definition( 'assessment_reference', 'source', 'achievement' ), 'father_name' => $definition( 'father_name', 'recipient', 'recipient' ), 'program_hours' => $definition( 'program_hours', 'staff', 'program' ), 'staff_result' => $definition( 'staff_result', 'staff', 'achievement' ) );
$contract = array( 'fields' => array() ); foreach ( $catalog as $key => $field ) { $contract['fields'][] = array( 'key' => $key, 'required' => 'staff_result' !== $key, 'value_source' => 'credential_custom' ); }
$guard = new WebhookFieldMapping(); w2_check( ! is_wp_error( $guard->validate( $mapping, $contract, $catalog ) ), 'customer collection and shared defaults can accompany mapped trusted results' );
foreach ( array( 'father_name', 'program_hours', 'staff_result', 'unknown_field' ) as $key ) { w2_check( is_wp_error( $guard->validate( array( 'override' => $key ), $contract, $catalog ) ), 'mapping grants no write authority to ' . $key ); }
w2_check( is_wp_error( $guard->validate( array( 'Score' => 'assessment_score' ), $contract, $catalog ) ), 'required source targets cannot remain structurally unmapped' );
$bad_catalog = $catalog; $bad_catalog['program_hours']['collection_policy']['writer'] = 'source';
w2_check( is_wp_error( $guard->validate( $mapping, $contract, $bad_catalog ) ), 'a required shared value needs the supported staff Program provider' );
$update = array( 'schema_version' => 2, 'event_id' => 'update/2', 'expected_revision' => 3, 'evidence' => $payload['evidence'] );
w2_check( ! is_wp_error( $wire->evidence_update( json_encode( $update ), $mapping ) ), 'new evidence has its own stable event and expected request revision' );
$update['recipient_external_id'] = 'other'; w2_check( is_wp_error( $wire->evidence_update( json_encode( $update ), $mapping ) ), 'evidence update cannot change recipient or Program context' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
