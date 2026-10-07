<?php
/** Bulk columns preserve the same typed ownership used by canonical issuance. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $v ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
use Certloom\Application\Issuance\BulkIssuanceCsvSchema;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Fields\FieldCollectionPolicy;
$passed = 0; $failed = 0;
function csv_assert( $ok, $message ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS $message\n"; } else { ++$failed; echo "FAIL $message\n"; } }
$catalog = array();
foreach ( array( 'qa_person' => array( 'recipient', 'recipient', 'profile' ), 'qa_registration' => array( 'recipient', 'registration', 'registration' ), 'qa_source' => array( 'source', 'achievement', 'never' ), 'qa_default' => array( 'staff', 'program', 'never' ), 'qa_result' => array( 'staff', 'achievement', 'never' ) ) as $key => $choice ) {
 $catalog[ $key ] = array( 'key' => $key, 'label' => $key, 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => $choice[0], 'lifetime' => $choice[1], 'collect_when' => $choice[2], 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) );
}
$scoped = new ScopedFieldContract(); $program = $scoped->normalize_program( array_fill_keys( array_keys( $catalog ), 'required' ), $catalog );
$contract = $scoped->resolve( $program, null, $catalog ); $service = new BulkIssuanceCsvSchema(); $schema = $service->describe( $contract, $catalog );
csv_assert( ! is_wp_error( $schema ) && array( 'qa_result' ) === array_keys( $schema['custom'] ) && 4 === count( $schema['managed'] ), 'typed source policy keeps only staff achievement facts as CSV values' );
csv_assert( true === $service->validate( array( 'recipient_id', 'certificate_number', 'custom:qa_result' ), array(), $schema ), 'required customer, registration, source and default fields do not demand a replacement CSV column' );
foreach ( array_keys( $schema['managed'] ) as $key ) {
 $error = $service->validate( array( 'recipient_id', 'custom:qa_result', 'custom:' . $key ), array(), $schema );
 csv_assert( is_wp_error( $error ) && 'certloom_bulk_csv_managed_field' === $error->get_error_code(), 'CSV cannot overwrite its declared authority: ' . $key );
}
$error = $service->validate( array( 'recipient_id' ), array(), $schema );
csv_assert( is_wp_error( $error ) && 'certloom_bulk_csv_required_field_header_missing' === $error->get_error_code(), 'required staff result still needs its CSV column' );
csv_assert( array( 'recipient_id', 'registration_id', 'certificate_number', 'custom:qa_result' ) === $service->columns( $schema ) && array( 'recipient_id', 'registration_id', 'custom:qa_result' ) === $service->columns( $schema, true ), 'downloaded headers use exact record IDs and reader names while omitting generated numbers' );
$legacy = $catalog; unset( $legacy['qa_result']['collection_policy'] );
$legacy_schema = $service->describe( $contract, $legacy ); csv_assert( array( 'qa_result' ) === array_keys( $legacy_schema['custom'] ), 'legacy custom result fields retain existing CSV behavior' );
$bad_catalog = $catalog; unset( $bad_catalog['qa_result'] ); csv_assert( is_wp_error( $service->describe( $contract, $bad_catalog ) ), 'missing definitions fail closed instead of producing an incomplete template' );
$unconfigured = $service->describe( ScopedFieldContract::empty_global(), array() ); csv_assert( true === $service->validate( array( 'custom:legacy' ), array(), $unconfigured ), 'unconfigured legacy CSV intake remains compatible' );
$large = $schema; for ( $i = 0; $i < 50; ++$i ) { $large['custom'][ 'extra_' . $i ] = array(); } csv_assert( is_wp_error( $service->columns( $large ) ), 'too many columns produce guidance instead of an invalid downloadable CSV' );
echo "\n$passed passed, $failed failures\n"; exit( $failed ? 1 : 0 );
