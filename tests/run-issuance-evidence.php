<?php
/** Qualification and trusted dynamic fields fail closed at contract boundaries. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message = '' ) {} }
function __( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
use Certloom\Application\Issuance\IssuanceEvidence;
$passed = 0; $failed = 0;
function ie_check( $ok, $label ) { global $passed, $failed; $ok ? ++$passed : ++$failed; echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
$reader = new IssuanceEvidence();
$evidence = array( '_meta' => array( 'schema' => IssuanceEvidence::SCHEMA, 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => '2026-10-02 10:00:00', 'valid_until' => '2026-10-02 11:00:00', 'fields' => array( 'score' => array( 'value' => '0', 'writer' => 'source', 'revision' => 'attempt-7:result-1' ) ) );
ie_check( '0' === $reader->normalize( $evidence )['fields']['score']['value'], 'a trusted zero result is preserved' );
ie_check( $reader->is_current( $evidence, '2026-10-02 10:30:00' ), 'eligible evidence is current inside its validity interval' );
ie_check( ! $reader->is_current( $evidence, '2026-10-02 09:59:59' ), 'future qualification is not current' );
ie_check( ! $reader->is_current( $evidence, '2026-10-02 11:00:00' ), 'qualification expires at the exact boundary' );
foreach ( array( 'unknown', 'not_eligible' ) as $state ) { $row = $evidence; $row['eligibility'] = $state; ie_check( ! $reader->is_current( $row, '2026-10-02 10:30:00' ), 'registration or ineligibility does not grant qualification' ); }
$invalid = array();
$row = $evidence; $row['_meta']['schema_version'] = '1'; $invalid[] = $row;
$row = $evidence; $row['_meta']['recipient_id'] = 1; $invalid[] = $row;
$row = $evidence; $row['fields']['score']['writer'] = 'recipient'; $invalid[] = $row;
$row = $evidence; $row['fields']['score']['revision'] = 2; $invalid[] = $row;
$row = $evidence; $row['fields']['score']['value'] = array( 'score' => '0' ); $invalid[] = $row;
$row = $evidence; $row['checked_at'] = '2026-02-30 10:00:00'; $invalid[] = $row;
$row = $evidence; $row['checked_at'] = null; $invalid[] = $row;
$row = $evidence; $row['valid_until'] = $row['checked_at']; $invalid[] = $row;
foreach ( $invalid as $row ) { ie_check( is_wp_error( $reader->normalize( $row ) ), 'invalid metadata, source, revision, scalar or date is rejected' ); }
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
