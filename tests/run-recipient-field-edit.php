<?php
/** Recipient edits preserve types and approvals bind an exact owner, value and policy. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_salt( $scheme = '' ) { return $GLOBALS['rfe_secret'] ?? 'site-one-test-secret'; }
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Recipient\FieldApprovalProof;
use Certloom\Application\Recipient\RecipientFieldEditValidator;
$passed = 0; $failed = 0;
function rfe_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$policy = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'recipient', 'writer' => 'recipient', 'collect_when' => 'profile', 'approval_required' => true, 'show_on_certificate' => false, 'help' => '' );
$field = array( 'key' => 'id_number', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => $policy );
$definitions = array( 'id_number' => $field ); $validator = new RecipientFieldEditValidator();
rfe_check( array( 'id_number' => '0001-009' ) === $validator->validate( array( 'id_number' => '0001-009' ), $definitions, 'recipient' ), 'personal text identifiers preserve their exact leading zeros' );
foreach ( array( array( 'wp_user_id' => 2 ), array( 'id_number' => array( 'value' => '123' ) ), array( 'id_number' => true ), array( 'id_number' => INF ) ) as $patch ) { rfe_check( is_wp_error( $validator->validate( $patch, $definitions, 'recipient' ) ), 'unknown or structured values cannot bypass the edit contract' ); }
rfe_check( is_wp_error( $validator->validate( array( 'id_number' => '123' ), $definitions, 'registration' ) ), 'profile data cannot masquerade as Program registration data' );
$staff = $field; $staff['collection_policy']['writer'] = 'staff'; $staff['collection_policy']['collect_when'] = 'never'; $staff['collection_policy']['approval_required'] = false;
rfe_check( is_wp_error( $validator->validate( array( 'id_number' => '123' ), array( 'id_number' => $staff ), 'recipient' ) ), 'staff-owned fields cannot be submitted by a recipient' );
$score = $field; $score['key'] = 'score'; $score['type'] = 'number';
rfe_check( is_wp_error( $validator->validate( array( 'score' => 0 ), array( 'score' => $score ), 'recipient' ) ), 'zero is not permission to edit a trusted score' );
$proofs = new FieldApprovalProof(); $proof = $proofs->digest( $field, 1, 'recipient', 1, '0001-009' );
$submission = array( 'status' => 'approved', 'decided_by' => 2, 'decided_at' => '2026-10-02 00:00:00', 'candidate_value' => '0001-009', 'policy_sha256' => $proof );
rfe_check( is_string( $proof ) && $proofs->matches( $submission, $field, 1, 'recipient', 1, '0001-009' ), 'approval matches only the exact accepted value' );
rfe_check( ! $proofs->matches( $submission, $field, 1, 'recipient', 1, '001-009' ), 'editing the value invalidates approval' );
rfe_check( ! $proofs->matches( $submission, $field, 7, 'recipient', 7, '0001-009' ), 'another account cannot reuse the same approval' );
$changed = $field; $changed['type'] = 'number';
rfe_check( ! $proofs->matches( $submission, $changed, 1, 'recipient', 1, '0001-009' ), 'changing field semantics requires a fresh decision' );
$pending = $submission; $pending['status'] = 'pending';
rfe_check( ! $proofs->matches( $pending, $field, 1, 'recipient', 1, '0001-009' ), 'a pending candidate is not an approved value' );
$restored = $submission; $restored['decided_by'] = null;
rfe_check( ! $proofs->matches( $restored, $field, 1, 'recipient', 1, '0001-009' ), 'restored numeric actor references cannot grant approval' );
$GLOBALS['rfe_secret'] = 'another-site-secret';
rfe_check( ! $proofs->matches( $submission, $field, 1, 'recipient', 1, '0001-009' ), 'copied approval proofs are invalid on a fresh site' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
