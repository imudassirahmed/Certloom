<?php
/** Shared field resolution: provenance boundaries, completeness and typed values. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code, $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
require_once __DIR__ . '/bootstrap-autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
use Certloom\Application\Fields\FieldValueResolver;
use Certloom\Application\Fields\ScopedFieldContract;
$passed = 0; $failed = 0;
function fvr_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$catalog = array();
foreach ( array( 'first_name' => 'text', 'grade' => 'text', 'score' => 'number', 'registration_number' => 'text' ) as $key => $type ) {
	$catalog[ $key ] = array( 'key' => $key, 'label' => $key, 'type' => $type );
}
$scopes = new ScopedFieldContract();
$contract = $scopes->resolve( $scopes->normalize_program( array( 'first_name' => 'required', 'grade' => 'required', 'score' => 'required', 'registration_number' => 'optional' ), $catalog ), null, $catalog );
$resolver = new FieldValueResolver();
$recipient = array( 'first_name' => 'Amina', 'custom_fields' => array( 'score' => '99', 'registration_number' => 'forged-profile-number' ) );
$request = array( 'grade' => 'Fail', 'custom_merge_fields' => array( 'score' => 0, 'registration_number' => '001-27' ) );
$resolved = $resolver->resolve( $contract, $recipient, $request, $catalog );
fvr_check( ! is_wp_error( $resolved ) && $resolved['ready'], 'required fields are complete with zero score' );
fvr_check( '0' === $resolved['values']['score'], 'zero is preserved as a supplied result' );
fvr_check( '001-27' === $resolved['values']['registration_number'], 'text identifiers preserve leading zeros and punctuation' );
fvr_check( 'Amina' === $resolved['values']['first_name'], 'identity comes from the canonical recipient' );
fvr_check( 'Fail' === $resolved['values']['grade'], 'grade comes from the reviewed issuance request' );
fvr_check( '0' === $resolved['values']['score'], 'recipient custom values cannot replace issuance-owned results' );
fvr_check( $recipient['custom_fields']['score'] === '99', 'resolution does not mutate profile values' );
$missing = $resolver->resolve( $contract, array(), array(), $catalog );
fvr_check( ! is_wp_error( $missing ) && ! $missing['ready'] && 3 === $missing['missing_count'], 'all missing required fields are reported together' );
fvr_check( count( $missing['fields'] ) === 4 && count( $missing['missing_fields'] ) === 3, 'an absent optional field does not become a blocker' );
fvr_check( is_wp_error( $resolver->required_error( $missing ) ) && 'certloom_manual_issue_required_field_missing' === $resolver->required_error( $missing )->get_error_code(), 'issuance retains its established required-field error' );
fvr_check( null === $resolver->required_error( $resolved ), 'complete values need no required-field error' );
fvr_check( false === strpos( json_encode( $resolved['fields'] ), '001-27' ), 'readiness metadata does not expose private field values' );
$bad = $request; $bad['custom_merge_fields']['score'] = 'not-a-number';
fvr_check( is_wp_error( $resolver->resolve( $contract, $recipient, $bad, $catalog ) ), 'invalid typed results are rejected before review' );
$bad = $request; $bad['custom_merge_fields']['score'] = array( '99' );
fvr_check( is_wp_error( $resolver->resolve( $contract, $recipient, $bad, $catalog ) ), 'nested input cannot become a scalar field value' );
$badContract = $contract; $badContract['fields'][0]['value_source'] = 'browser_user_meta';
fvr_check( is_wp_error( $resolver->resolve( $badContract, $recipient, $request, $catalog ) ), 'unknown providers fail closed' );
$noGrade = $scopes->resolve( $scopes->normalize_program( array( 'score' => 'required' ), $catalog ), null, $catalog );
fvr_check( 'certloom_manual_issue_unassigned_grade' === $resolver->resolve( $noGrade, $recipient, $request, $catalog )->get_error_code(), 'unassigned grade remains rejected' );
echo "\n$passed field resolver checks, $failed failures\n";
exit( $failed ? 1 : 0 );
