<?php
/** New customer collection must not leak through legacy merge namespaces or unrelated snapshots. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\FieldSnapshotProjection;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Templates\TemplateFieldCompatibility;
$passed = 0; $failed = 0;
function cfp_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$policy = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'recipient', 'writer' => 'recipient', 'collect_when' => 'registration', 'approval_required' => true, 'show_on_certificate' => false, 'help' => '' );
$private = array( 'key' => 'cnic', 'label' => 'CNIC', 'type' => 'text', 'sensitivity' => 'sensitive', 'public_exposure' => 'private', 'collection_policy' => $policy );
$public = $private; $public['key'] = 'department'; $public['label'] = 'Department'; $public['sensitivity'] = 'personal'; $public['public_exposure'] = 'profile';
$catalog = array( 'cnic' => $private, 'department' => $public, 'legacy_detail' => array( 'key' => 'legacy_detail', 'type' => 'text' ) );
$recipient = array( 'id' => 1, 'custom_fields' => array( 'cnic' => '0001-PRIVATE', 'department' => 'Science', 'legacy_detail' => 'Legacy value' ) );
$one_off = array( 'cnic' => 'FORGED', 'department' => 'Wrong department', 'legacy_detail' => 'Legacy override' );
$projection = new FieldSnapshotProjection(); $stripped = $projection->strip_managed( $recipient, $one_off, $catalog );
cfp_check( array( 'legacy_detail' => 'Legacy value' ) === $stripped['recipient']['custom_fields'], 'newly managed account details cannot be copied indiscriminately into a credential snapshot' );
cfp_check( array( 'legacy_detail' => 'Legacy override' ) === $stripped['one_off'], 'one-off values cannot smuggle managed account details into artwork or raw snapshot inputs' );
cfp_check( '0001-PRIVATE' === $recipient['custom_fields']['cnic'], 'snapshot projection leaves the reusable customer record unchanged' );
$artwork = $projection->artwork_values( array( 'cnic' => '0001-PRIVATE', 'department' => 'Science', 'legacy_detail' => 'Legacy' ), $catalog );
cfp_check( ! isset( $artwork['cnic'] ) && 'Science' === $artwork['department'] && 'Legacy' === $artwork['legacy_detail'], 'private resolved facts are excluded from artwork while printable and legacy facts remain available' );
$guard = new TemplateFieldCompatibility();
$contract = array( 'fields' => array( array( 'key' => 'cnic' ), array( 'key' => 'department' ) ) );
foreach ( array( 'credential.custom.cnic', 'recipient.custom.cnic', 'custom:cnic', 'recipient.cnic', 'credential.cnic', 'custom.cnic', 'credential:cnic', 'cnic' ) as $token ) {
	$layout = array( 'elements' => array( array( 'type' => 'merge', 'token' => $token ) ) );
	$valid = $guard->validate_collection( $layout, $contract, $catalog );
	cfp_check( is_wp_error( $valid ) && 'certloom_template_collected_field_private' === $valid->get_error_code(), 'private collected details stay off artwork under namespace ' . $token );
}
$layout = array( 'elements' => array( array( 'type' => 'merge', 'token' => 'credential.custom.address' ) ) );
cfp_check( is_wp_error( $guard->validate_collection( $layout, $contract, $catalog, array( 'address' => 'cnic' ) ) ), 'a historical design alias cannot rename a private identifier to bypass privacy' );
$layout['elements'][0]['token'] = 'credential.custom.department';
cfp_check( true === $guard->validate_collection( $layout, $contract, $catalog ), 'an assigned printable customer field can be used by its design' );
$unassigned = $guard->validate_collection( $layout, array( 'fields' => array() ), $catalog );
cfp_check( is_wp_error( $unassigned ) && 'certloom_template_collected_field_unassigned' === $unassigned->get_error_code(), 'collection-enabled account details must be assigned even in legacy design mode' );
$layout['elements'][0]['token'] = 'custom:legacy_detail';
cfp_check( true === $guard->validate_collection( $layout, array( 'fields' => array() ), $catalog ), 'unconfigured legacy design semantics remain unchanged' );
$layout['elements'][0]['token'] = 'custom:cnic';
cfp_check( is_wp_error( $guard->validate( $layout, ScopedFieldContract::empty_program(), null, $catalog ) ), 'the normal template assignment guard also protects private collection in legacy Programs' );
$broken = $catalog; $broken['cnic']['collection_policy']['writer'] = 'unknown';
cfp_check( is_wp_error( $projection->strip_managed( $recipient, $one_off, $broken ) ), 'malformed stored collection settings fail closed before snapshot or merge creation' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
