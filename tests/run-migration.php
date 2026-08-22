<?php
/** Pure tests for legacy 1.5 row -> new Credential Series/Version mapping. */
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Migration\LegacyCertificateMapper;
use Certloom\Application\Migration\LegacyMigrationPreflightService;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;

$tests = array();
function cmt( $name, callable $fn ) { global $tests; $tests[] = array( $name, $fn ); }
function cmeq( $expected, $actual, $message = '' ) { if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); } }
function cmtrue( $value, $message = '' ) { cmeq( true, (bool) $value, $message ); }
function cmfalse( $value, $message = '' ) { cmeq( false, (bool) $value, $message ); }
function cmthrows( callable $fn ) { try { $fn(); } catch ( InvalidArgumentException $e ) { return; } throw new RuntimeException( 'Expected InvalidArgumentException.' ); }

function cmrow( array $override = array() ) {
	return array_merge(
		array(
			'id' => 41,
			'cert_number' => 'CERT-0041',
			'status' => 'valid',
			'status_source' => 'manual',
			'holder_name' => 'Migration Recipient',
			'issue_date' => '2026-01-15',
			'expiry_date' => '2026-12-31',
			'custom_fields' => array( 'Department' => 'QA' ),
			'created_at' => '2026-01-15 10:30:00',
			'updated_at' => '2026-01-15 10:30:00',
		),
		$override
	);
}

function cmstatus( array $plan, $today ) {
	return ( new StatusService() )->evaluate(
		array(
			'issuance_state' => $plan['version']['issuance_state'],
			'administrative_state' => $plan['series']['administrative_state'],
			'legacy_expired' => $plan['version']['legacy_expired'],
			'valid_from' => $plan['version']['valid_from'],
			'expires_at' => $plan['version']['expires_at'],
			'compliance_state' => States::COMPLIANCE_NOT_APPLICABLE,
		),
		$today
	);
}

cmt( 'valid current legacy row remains valid', function () {
	$plan = ( new LegacyCertificateMapper() )->map( cmrow() );
	cmeq( States::ADMIN_ACTIVE, $plan['series']['administrative_state'] );
	cmfalse( $plan['version']['legacy_expired'] );
	cmtrue( cmstatus( $plan, '2026-08-22' )->is_valid() );
	cmeq( 'CERT-0041', $plan['version']['display_number'] );
	cmeq( array( 'Department' => 'QA' ), $plan['version']['snapshot']['custom_fields'] );
} );

cmt( 'date-derived legacy expiry remains derived from expiry_date', function () {
	$plan = ( new LegacyCertificateMapper() )->map( cmrow( array( 'expiry_date' => '2026-08-21' ) ) );
	cmfalse( $plan['version']['legacy_expired'] );
	cmeq( 'expired', cmstatus( $plan, '2026-08-22' )->code() );
	cmeq( 'validity_window_expired', cmstatus( $plan, '2026-08-22' )->reason_code() );
} );

cmt( 'stored legacy Expired remains expired even with future or missing expiry date', function () {
	$mapper = new LegacyCertificateMapper();
	$future = $mapper->map( cmrow( array( 'status' => 'expired', 'status_source' => 'legacy', 'expiry_date' => '2030-01-01' ) ) );
	cmtrue( $future['version']['legacy_expired'] );
	cmeq( 'expired', cmstatus( $future, '2026-08-22' )->code() );
	cmeq( 'legacy_expired_compatibility', cmstatus( $future, '2026-08-22' )->reason_code() );
	$missing = $mapper->map( cmrow( array( 'status' => 'valid', 'status_source' => 'legacy', 'expiry_date' => null ) ) );
	cmeq( 'expired', cmstatus( $missing, '2026-08-22' )->code() );
} );

cmt( 'legacy suspension retains administrative precedence over date expiry', function () {
	$plan = ( new LegacyCertificateMapper() )->map( cmrow( array( 'status' => 'suspended', 'expiry_date' => '2026-01-01' ) ) );
	cmeq( States::ADMIN_SUSPENDED, $plan['series']['administrative_state'] );
	cmeq( 'suspended', cmstatus( $plan, '2026-08-22' )->code() );
} );

cmt( 'legacy Withdrawn maps explicitly to Revoked', function () {
	$plan = ( new LegacyCertificateMapper() )->map( cmrow( array( 'status' => 'withdrawn' ) ) );
	cmeq( States::ADMIN_REVOKED, $plan['series']['administrative_state'] );
	cmeq( 'revoked', cmstatus( $plan, '2026-08-22' )->code() );
	cmeq( 'withdrawn', $plan['version']['snapshot']['legacy_status'] );
} );

cmt( 'future legacy issue_date does not invent a not-yet-valid rule', function () {
	$plan = ( new LegacyCertificateMapper() )->map( cmrow( array( 'issue_date' => '2027-01-01', 'expiry_date' => null ) ) );
	cmeq( null, $plan['version']['valid_from'] );
	cmtrue( cmstatus( $plan, '2026-08-22' )->is_valid() );
	cmeq( '2027-01-01', $plan['version']['snapshot']['issue_date'] );
} );

cmt( 'legacy source idempotency is deterministic', function () {
	$mapper = new LegacyCertificateMapper();
	$a = $mapper->map( cmrow( array( 'id' => 99 ) ) );
	$b = $mapper->map( cmrow( array( 'id' => 99, 'holder_name' => 'Changed display during preflight' ) ) );
	cmeq( $a['version']['source_idempotency_key'], $b['version']['source_idempotency_key'] );
	cmeq( 64, strlen( $a['version']['source_idempotency_key'] ) );
} );

cmt( 'JSON custom fields are preserved as structured snapshot data', function () {
	$plan = ( new LegacyCertificateMapper() )->map( cmrow( array( 'custom_fields' => '{"Score":"95","City":"Lahore"}' ) ) );
	cmeq( array( 'Score' => '95', 'City' => 'Lahore' ), $plan['version']['snapshot']['custom_fields'] );
} );

cmt( 'malformed legacy semantics fail preflight instead of silently changing truth', function () {
	$mapper = new LegacyCertificateMapper();
	cmthrows( function () use ( $mapper ) { $mapper->map( cmrow( array( 'status' => 'mystery' ) ) ); } );
	cmthrows( function () use ( $mapper ) { $mapper->map( cmrow( array( 'custom_fields' => '{bad json' ) ) ); } );
	cmthrows( function () use ( $mapper ) { $mapper->map( cmrow( array( 'expiry_date' => '2026-99-99' ) ) ); } );
} );

cmt( 'preflight summarizes mixed legacy rows without writing', function () {
	$rows = array(
		cmrow( array( 'id' => 1 ) ),
		cmrow( array( 'id' => 2, 'status' => 'suspended' ) ),
		cmrow( array( 'id' => 3, 'status' => 'withdrawn' ) ),
		cmrow( array( 'id' => 4, 'expiry_date' => '2026-01-01' ) ),
	);
	$result = ( new LegacyMigrationPreflightService() )->preflight( $rows, '2026-08-22' );
	cmeq( 4, $result['total'] );
	cmeq( 4, $result['ready'] );
	cmeq( 0, $result['errors'] );
	cmeq( 1, $result['status_counts']['valid'] );
	cmeq( 1, $result['status_counts']['suspended'] );
	cmeq( 1, $result['status_counts']['revoked'] );
	cmeq( 1, $result['status_counts']['expired'] );
} );

cmt( 'preflight reports malformed rows and caps detailed errors', function () {
	$rows = array(
		cmrow( array( 'id' => 10, 'status' => 'mystery' ) ),
		cmrow( array( 'id' => 11, 'expiry_date' => '2026-99-99' ) ),
		cmrow( array( 'id' => 12, 'custom_fields' => '{broken' ) ),
	);
	$result = ( new LegacyMigrationPreflightService( null, null, 2 ) )->preflight( $rows, '2026-08-22' );
	cmeq( 3, $result['total'] );
	cmeq( 0, $result['ready'] );
	cmeq( 3, $result['errors'] );
	cmeq( 2, count( $result['error_details'] ) );
	cmeq( 10, $result['error_details'][0]['legacy_id'] );
} );

cmt( 'preflight validates its parity date', function () {
	$svc = new LegacyMigrationPreflightService();
	cmthrows( function () use ( $svc ) { $svc->preflight( array(), '2026-99-99' ); } );
} );

$failed = 0;
foreach ( $tests as $test ) {
	list( $name, $fn ) = $test;
	try { $fn(); echo "PASS  $name\n"; }
	catch ( Throwable $e ) { $failed++; echo "FAIL  $name\n      " . $e->getMessage() . "\n"; }
}
echo "\n" . count( $tests ) . " tests, $failed failures\n";
exit( $failed > 0 ? 1 : 0 );
