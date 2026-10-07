<?php
/** Values come from exact owned stores and current approval/evidence, not issuance input aliases. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_salt( $scheme = '' ) { return 'owned-source-test-secret'; }
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\CollectedFieldValueProvider;
use Certloom\Application\Fields\FieldValueResolver;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Application\Recipient\FieldApprovalProof;
use Certloom\Application\Issuance\IssuanceEvidence;
$passed = 0; $failed = 0;
function cfpv_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$policy = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'recipient', 'writer' => 'recipient', 'collect_when' => 'registration', 'approval_required' => true, 'show_on_certificate' => false, 'help' => '' );
$catalog = array();
foreach ( array( 'id_number' => array( 'recipient', 'recipient', 'registration', 'text' ), 'enrollment_number' => array( 'registration', 'recipient', 'registration', 'text' ), 'training_hours' => array( 'program', 'staff', 'never', 'number' ), 'score' => array( 'achievement', 'source', 'never', 'number' ) ) as $key => $settings ) {
	$p = $policy; $p['lifetime'] = $settings[0]; $p['writer'] = $settings[1]; $p['collect_when'] = $settings[2]; $p['approval_required'] = 'recipient' === $settings[1];
	$catalog[ $key ] = array( 'key' => $key, 'label' => $key, 'type' => $settings[3], 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => $p );
}
$recipient = array( 'id' => 2, 'updated_at' => '2026-10-02 11:00:00', 'custom_fields' => array( 'id_number' => '000-PRIVATE', 'score' => '999' ) );
$program = array( 'id' => 4, 'updated_at' => '2026-10-02 11:00:00' );
$registration = array( 'id' => 7, 'recipient_id' => 2, 'program_id' => 4, 'batch_id' => null, 'status' => 'completed', 'revision' => 3, 'fields' => array( 'enrollment_number' => '000-E-12' ) );
$proof = new FieldApprovalProof(); $submissions = array();
foreach ( array( 'recipient' => array( 'id_number', 2, '000-PRIVATE' ), 'registration' => array( 'enrollment_number', 7, '000-E-12' ) ) as $kind => $data ) {
	$submissions[ $kind ][ $data[0] ] = array( 'id' => 10 + $data[1], 'revision' => 2, 'status' => 'approved', 'decided_by' => 1, 'decided_at' => '2026-10-02 11:05:00', 'candidate_value' => $data[2], 'policy_sha256' => $proof->digest( $catalog[ $data[0] ], 2, $kind, $data[1], $data[2] ) );
}
$defaults = ( new ProgramFieldDefaults() )->normalize( array( '_meta' => array( 'schema' => ProgramFieldDefaults::SCHEMA, 'schema_version' => 1 ), 'values' => array( 'training_hours' => 0 ) ), $catalog );
cfpv_check( ! is_wp_error( $defaults ) && '0' === $defaults['values']['training_hours'], 'typed Program defaults preserve a valid zero' );
foreach ( array( array( 'id_number' => 'forged-person' ), array( 'score' => 100 ), array( 'training_hours' => array( 40 ) ), array( 'missing' => 'value' ) ) as $values ) {
	cfpv_check( is_wp_error( ( new ProgramFieldDefaults() )->normalize( array( '_meta' => array( 'schema' => ProgramFieldDefaults::SCHEMA, 'schema_version' => 1 ), 'values' => $values ), $catalog ) ), 'Program defaults cannot supply a personal field, source result or malformed value' );
}
$evidence = array( '_meta' => array( 'schema' => IssuanceEvidence::SCHEMA, 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => '2026-10-02 11:00:00', 'valid_until' => '2026-10-02 13:00:00', 'fields' => array( 'score' => array( 'value' => '0', 'writer' => 'source', 'revision' => 'attempt-12' ) ) );
$record = array( 'id' => 9, 'recipient_id' => 2, 'program_id' => 4, 'registration_id' => 7, 'batch_id' => null, 'restore_state' => 'local', 'status' => 'waiting', 'evidence_revision' => 2, 'evidence_json' => json_encode( $evidence ) );
$now = '2026-10-02 12:00:00';
$factory = static function ( $person = null, $enrollment = null, $decisions = null, $request = null ) use ( $recipient, $registration, $submissions, $record, $program, $defaults, $now ) { return new CollectedFieldValueProvider( $person ?? $recipient, $program, $enrollment ?? $registration, $decisions ?? $submissions, $defaults, $request ?? $record, $now ); };
$new_recipient_provider = $factory( array( 'display_name' => 'New recipient', 'custom_fields' => array() ) );
$new_shared = $new_recipient_provider->resolve( $catalog['training_hours'], $catalog['training_hours'][FieldCollectionPolicy::KEY] );
cfpv_check( 'ready' === $new_shared['status'] && '0' === $new_shared['value'], 'a new recipient review can use a Program-owned zero value before the recipient is created' );
cfpv_check( 'recipient_required' === $new_recipient_provider->resolve( $catalog['id_number'], $catalog['id_number'][FieldCollectionPolicy::KEY] )['status'], 'a new recipient form cannot substitute for an approved account identity' );
cfpv_check( 'recipient_required' === $new_recipient_provider->resolve( $catalog['score'], $catalog['score'][FieldCollectionPolicy::KEY] )['status'], 'a new recipient form cannot substitute for a bound source result' );
$contract = array( 'fields' => array_map( static function ( $key ) { return array( 'key' => $key, 'required' => true, 'value_source' => 'credential_custom' ); }, array_keys( $catalog ) ) );
$resolver = new FieldValueResolver();
$request = array( 'custom_merge_fields' => array( 'id_number' => 'FORGED', 'enrollment_number' => 'WRONG', 'training_hours' => '50', 'score' => '100' ) );
$resolved = $resolver->resolve( $contract, $recipient, $request, $catalog, $factory() );
cfpv_check( ! is_wp_error( $resolved ) && $resolved['ready'], 'complete owned sources and current qualification produce ready fields' );
cfpv_check( '000-PRIVATE' === $resolved['values']['id_number'] && '000-E-12' === $resolved['values']['enrollment_number'] && '0' === $resolved['values']['training_hours'] && '0' === $resolved['values']['score'], 'one-off issuance inputs cannot replace approved details, enrollment data, Program defaults or source results' );
cfpv_check( 3 === $resolved['provenance']['enrollment_number']['revision'] && 'attempt-12' === $resolved['provenance']['score']['source_revision'], 'review contributions bind enrollment and trusted-source revisions' );
$blocked_record = $record; $blocked_record['status'] = 'blocked';
cfpv_check( $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, null, $blocked_record ) )['ready'], 'a locally blocked request can reassess unchanged trusted evidence after its configuration is repaired' );
cfpv_check( false === strpos( json_encode( array( $resolved['fields'], $resolved['provenance'] ) ), '000-PRIVATE' ), 'readiness and provenance metadata do not contain private values' );
$pending = $submissions; $pending['recipient']['id_number']['status'] = 'pending';
$held = $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, $pending ) );
cfpv_check( ! $held['ready'] && '' === $held['values']['id_number'] && 'awaiting_approval' === $held['missing_fields'][0]['status'], 'a pending candidate cannot become issuance-ready or an artwork value' );
$rejected = $pending; $rejected['recipient']['id_number']['status'] = 'rejected';
$held = $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, $rejected ) );
cfpv_check( ! $held['ready'] && 'needs_resubmission' === $held['missing_fields'][0]['status'], 'a rejected candidate needs customer action rather than a misleading approval wait' );
$changed = $recipient; $changed['custom_fields']['id_number'] = 'CHANGED';
cfpv_check( ! $resolver->resolve( $contract, $changed, $request, $catalog, $factory( $changed ) )['ready'], 'editing an accepted personal value invalidates its exact approval' );
$restored = $submissions; $restored['recipient']['id_number']['decided_by'] = null;
cfpv_check( ! $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, $restored ) )['ready'], 'restored actor references cannot make a personal field ready' );
$foreign = $registration; $foreign['recipient_id'] = 33;
cfpv_check( is_wp_error( $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, $foreign ) ) ), 'an enrollment belonging to another recipient fails closed' );
$wrong_program = $registration; $wrong_program['program_id'] = 55;
cfpv_check( is_wp_error( $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, $wrong_program ) ) ), 'an enrollment belonging to another Program fails closed' );
foreach ( array( array( 'recipient_id' => 33 ), array( 'program_id' => 55 ), array( 'registration_id' => 99 ), array( 'batch_id' => 3 ), array( 'restore_state' => 'paused' ) ) as $patch ) {
	cfpv_check( is_wp_error( $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, null, array_merge( $record, $patch ) ) ) ), 'source evidence cannot cross ownership/context or restored execution holds' );
}
$expired = $evidence; $expired['valid_until'] = $now; $expired_record = $record; $expired_record['evidence_json'] = json_encode( $expired );
cfpv_check( ! $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, null, $expired_record ) )['ready'], 'source evidence is rechecked at the exact expiry boundary' );
$future = $evidence; $future['checked_at'] = '2026-10-02 12:30:00'; $future_record = $record; $future_record['evidence_json'] = json_encode( $future );
cfpv_check( ! $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, null, $future_record ) )['ready'], 'future completion timestamps cannot grant current readiness' );
$staff = $evidence; $staff['fields']['score']['writer'] = 'staff'; $staff_record = $record; $staff_record['evidence_json'] = json_encode( $staff );
cfpv_check( ! $resolver->resolve( $contract, $recipient, $request, $catalog, $factory( null, null, null, $staff_record ) )['ready'], 'staff evidence cannot masquerade as a source-owned result' );
$missing_provider = $resolver->resolve( $contract, $recipient, $request, $catalog );
cfpv_check( is_wp_error( $missing_provider ), 'configured collection still fails closed when its authoritative provider is absent' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
