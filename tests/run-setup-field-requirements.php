<?php
/** Profile recommendations remain optional; an actual design can require a grade. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return strtolower( str_replace( ' ', '-', (string) $value ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { public function __construct( $code, $message = '' ) {} }
$plan = new \Certloom\Application\Setup\FirstCredentialSetupPlan();
$optional = $plan->build( null, array( 'grade' ), array() );
if ( empty( $optional['grade_enabled'] ) || ! empty( $optional['grade_required'] ) || $optional['required_tokens'] ) { throw new RuntimeException( 'A recommended grade must be visible and optional without a design.' ); }
$required = $plan->build( array( 'id' => 42, 'name' => 'Graded certificate', 'published_revision' => array( 'layout' => array( 'elements' => array( array( 'type' => 'merge', 'token' => 'credential.grade' ) ) ) ) ), array(), array() );
if ( empty( $required['grade_enabled'] ) || empty( $required['grade_required'] ) ) { throw new RuntimeException( 'An actual grade merge token must still require the value.' ); }
$empty = $plan->build( null, array(), array() );
if ( ! empty( $empty['grade_enabled'] ) || ! empty( $empty['grade_required'] ) ) { throw new RuntimeException( 'Setup must not introduce an unselected grade.' ); }
$catalog = array();
foreach ( array( 'father_name' => array( 'recipient', 'recipient', 'profile' ), 'registration_number' => array( 'registration', 'recipient', 'registration' ), 'training_hours' => array( 'program', 'staff', 'never' ), 'grade' => array( 'achievement', 'source', 'never' ) ) as $key => $settings ) {
	$catalog[$key] = array( 'key' => $key, 'label' => $key, 'type' => 'text', 'public_exposure' => 'both', 'collection_policy' => array( '_meta' => array( 'schema' => \Certloom\Application\Fields\FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => $settings[0], 'writer' => $settings[1], 'collect_when' => $settings[2], 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) );
}
$owned = $plan->build( null, array_keys($catalog), $catalog );
if ( count($owned['collected_fields']) !== 4 || $owned['credential_fields'] || $owned['recipient_fields'] || $owned['grade_enabled'] ) { throw new RuntimeException('Owned values must not become new-recipient text boxes.'); }
$source_grade = $plan->build( array( 'published_revision' => array( 'layout' => array( 'elements' => array( array( 'type' => 'merge', 'token' => 'credential.grade' ) ) ) ) ), array(), $catalog );
if ( $source_grade['grade_enabled'] || $source_grade['grade_required'] || count($source_grade['collected_fields']) !== 1 || !in_array('credential.grade',$source_grade['required_tokens'],true) ) { throw new RuntimeException('A design-required app grade keeps its source requirement without offering a manual text box.'); }
echo "PASS  Optional/design grade rules and canonical customer, enrollment, Program and source controls\n5 checks, 0 failures\n";
