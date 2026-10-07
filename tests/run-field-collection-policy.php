<?php
/** Explicit collection permission must never be inferred from legacy fields. */
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
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Fields\FieldCollectionPolicy;
$passed = 0; $failed = 0;
function fcp_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$service = new FieldCollectionPolicy();
$field = array( 'key' => 'id_number', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private' );
$policy = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'recipient', 'writer' => 'recipient', 'collect_when' => 'profile', 'approval_required' => true, 'show_on_certificate' => false, 'help' => 'Used to confirm your identity.' );
$legacy = $service->for_definition( $field );
fcp_check( ! is_wp_error( $legacy ) && ! $service->recipient_editable( $legacy ), 'legacy custom fields do not grant recipient edit permission' );
$normalized = $service->normalize( $policy, $field );
fcp_check( ! is_wp_error( $normalized ) && $service->recipient_editable( $normalized ), 'explicit reusable personal details may be recipient editable' );
fcp_check( $normalized['approval_required'], 'approval requirement survives normalization' );
$registration = $policy; $registration['lifetime'] = 'registration'; $registration['collect_when'] = 'registration';
fcp_check( ! is_wp_error( $service->normalize( $registration, $field ) ), 'Program registration values have their own collection scope' );
foreach ( array( 'achievement', 'program' ) as $lifetime ) {
	$bad = $policy; $bad['lifetime'] = $lifetime;
	fcp_check( is_wp_error( $service->normalize( $bad, $field ) ), 'recipient cannot author ' . $lifetime . ' facts' );
}
foreach ( array( 'grade', 'score', 'result', 'marks_percentage', 'external_id', 'email', 'first_name' ) as $key ) {
	$protected = $field; $protected['key'] = $key;
	fcp_check( is_wp_error( $service->normalize( $policy, $protected ) ), $key . ' cannot be converted into an ordinary recipient input' );
}
$bad = $policy; $bad['_meta']['schema_version'] = 2;
fcp_check( is_wp_error( $service->normalize( $bad, $field ) ), 'unsupported policy version fails closed' );
$bad = $policy; $bad['approval_required'] = 'false';
fcp_check( is_wp_error( $service->normalize( $bad, $field ) ), 'boolean-like strings cannot disable review' );
$bad = $policy; $bad['custom_authority'] = 'admin';
fcp_check( is_wp_error( $service->normalize( $bad, $field ) ), 'unknown policy properties are rejected' );
$bad = $policy; $bad['show_on_certificate'] = true;
fcp_check( is_wp_error( $service->normalize( $bad, $field ) ), 'show on certificate cannot override private exposure' );
$public = $field; $public['public_exposure'] = 'both';
fcp_check( ! is_wp_error( $service->normalize( $bad, $public ) ), 'explicit publicly permitted artwork placement is supported' );
$internal = $field; $internal['sensitivity'] = 'internal';
fcp_check( is_wp_error( $service->normalize( $policy, $internal ) ), 'internal fields cannot be recipient editable' );
$enrollment = $policy; $enrollment['collect_when'] = 'registration';
fcp_check( ! is_wp_error( $service->normalize( $enrollment, $field ) ), 'reusable personal details can be requested during Program enrollment' );
$bad = $registration; $bad['collect_when'] = 'profile';
fcp_check( is_wp_error( $service->normalize( $bad, $field ) ), 'Program-specific details cannot become an unscoped personal profile field' );
$source = $policy; $source['writer'] = 'source'; $source['lifetime'] = 'achievement'; $source['approval_required'] = false; $source['collect_when'] = 'never';
fcp_check( ! is_wp_error( $service->normalize( $source, $field ) ), 'trusted results have an explicit source-owned policy' );
$source['collect_when'] = 'before_issue';
fcp_check( is_wp_error( $service->normalize( $source, $field ) ), 'trusted source data cannot appear as a recipient form input' );
$broken = $field; $broken[ FieldCollectionPolicy::KEY ] = 'recipient';
fcp_check( is_wp_error( $service->for_definition( $broken ) ), 'corrupt stored policies cannot become legacy editable defaults' );
echo "\n$passed collection policy checks, $failed failures\n";
exit( $failed ? 1 : 0 );
