<?php
/**
 * Lightweight pure-PHP domain tests.
 *
 * These deliberately do not boot WordPress so the credential lifecycle and
 * public-ID rules remain independently testable. WordPress integration tests
 * will be added when the database/public-route milestones are wired.
 */

require_once dirname( __DIR__ ) . '/src/Domain/Credential/States.php';
require_once dirname( __DIR__ ) . '/src/Domain/Credential/StatusResult.php';
require_once dirname( __DIR__ ) . '/src/Domain/Credential/StatusService.php';
require_once dirname( __DIR__ ) . '/src/Domain/Credential/StateMachine.php';
require_once dirname( __DIR__ ) . '/src/Domain/Credential/PublicCredentialId.php';

use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\StateMachine;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;

$tests = array();

function certloom_test( $name, callable $fn ) {
	global $tests;
	$tests[] = array( $name, $fn );
}

function certloom_assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function certloom_assert_true( $actual, $message = '' ) {
	certloom_assert_same( true, (bool) $actual, $message );
}

function certloom_assert_false( $actual, $message = '' ) {
	certloom_assert_same( false, (bool) $actual, $message );
}

function certloom_expect_exception( callable $fn, $class ) {
	try {
		$fn();
	} catch ( Throwable $e ) {
		if ( $e instanceof $class ) {
			return;
		}
		throw $e;
	}
	throw new RuntimeException( 'Expected exception ' . $class );
}

certloom_test( 'current issued active credential is valid', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_ACTIVE,
			'valid_from' => '2026-01-01',
			'expires_at' => '2026-12-31',
			'compliance_state' => States::COMPLIANCE_COMPLIANT,
		),
		'2026-08-22'
	);
	certloom_assert_true( $status->is_valid() );
	certloom_assert_same( 'valid', $status->code() );
	certloom_assert_same( States::TEMPORAL_CURRENT, $status->temporal_state() );
} );

certloom_test( 'expiry is derived at read time and expiry date remains valid through that date', function () {
	$service = new StatusService();
	$data = array(
		'issuance_state' => States::ISSUANCE_ISSUED,
		'administrative_state' => States::ADMIN_ACTIVE,
		'expires_at' => '2026-08-22',
		'compliance_state' => States::COMPLIANCE_NOT_APPLICABLE,
	);
	certloom_assert_true( $service->evaluate( $data, '2026-08-22' )->is_valid() );
	$expired = $service->evaluate( $data, '2026-08-23' );
	certloom_assert_false( $expired->is_valid() );
	certloom_assert_same( 'expired', $expired->code() );
} );

certloom_test( 'legacy explicit expired compatibility flag remains expired', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_ACTIVE,
			'legacy_expired' => true,
			'expires_at' => '2030-01-01',
			'compliance_state' => States::COMPLIANCE_NOT_APPLICABLE,
		),
		'2026-08-22'
	);
	certloom_assert_false( $status->is_valid() );
	certloom_assert_same( 'expired', $status->code() );
	certloom_assert_same( 'legacy_expired_compatibility', $status->reason_code() );
	certloom_assert_true( in_array( 'compatibility:legacy_expired', $status->contributors(), true ) );
} );

certloom_test( 'future valid-from is not yet valid', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_ACTIVE,
			'valid_from' => '2026-09-01',
			'compliance_state' => States::COMPLIANCE_NOT_APPLICABLE,
		),
		'2026-08-22'
	);
	certloom_assert_same( 'not_yet_valid', $status->code() );
} );

certloom_test( 'administrative revocation wins as primary explanation while retaining other contributors', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_REVOKED,
			'expires_at' => '2026-01-01',
			'compliance_state' => States::COMPLIANCE_NONCOMPLIANT,
		),
		'2026-08-22'
	);
	certloom_assert_same( 'revoked', $status->code() );
	certloom_assert_true( in_array( 'temporal:expired', $status->contributors(), true ) );
	certloom_assert_true( in_array( 'compliance:noncompliant', $status->contributors(), true ) );
} );

certloom_test( 'suspended credential is invalid', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_SUSPENDED,
			'compliance_state' => States::COMPLIANCE_COMPLIANT,
		),
		'2026-08-22'
	);
	certloom_assert_same( 'suspended', $status->code() );
	certloom_assert_false( $status->is_valid() );
} );

certloom_test( 'voided credential is invalid even if otherwise active', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_VOIDED,
			'administrative_state' => States::ADMIN_ACTIVE,
			'compliance_state' => States::COMPLIANCE_COMPLIANT,
		),
		'2026-08-22'
	);
	certloom_assert_same( 'voided', $status->code() );
} );

certloom_test( 'pending compliance fails closed', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_ACTIVE,
			'compliance_state' => States::COMPLIANCE_PENDING,
		),
		'2026-08-22'
	);
	certloom_assert_same( 'pending_review', $status->code() );
	certloom_assert_false( $status->is_valid() );
} );

certloom_test( 'noncompliance is distinct from administrative suspension', function () {
	$status = ( new StatusService() )->evaluate(
		array(
			'issuance_state' => States::ISSUANCE_ISSUED,
			'administrative_state' => States::ADMIN_ACTIVE,
			'compliance_state' => States::COMPLIANCE_NONCOMPLIANT,
		),
		'2026-08-22'
	);
	certloom_assert_same( 'suspended_noncompliant', $status->code() );
	certloom_assert_same( States::ADMIN_ACTIVE, $status->administrative_state() );
} );

certloom_test( 'invalid lifecycle values fail rather than falling back to valid', function () {
	certloom_expect_exception( function () {
		( new StatusService() )->evaluate(
			array(
				'issuance_state' => 'banana',
				'administrative_state' => States::ADMIN_ACTIVE,
			),
			'2026-08-22'
		);
	}, InvalidArgumentException::class );
} );

certloom_test( 'state machine permits issue suspend reinstate revoke', function () {
	$machine = new StateMachine();
	$state = $machine->issue( States::ISSUANCE_DRAFT, States::ADMIN_ACTIVE );
	certloom_assert_same( States::ISSUANCE_ISSUED, $state['issuance_state'] );
	$state = $machine->suspend( $state['issuance_state'], $state['administrative_state'] );
	certloom_assert_same( States::ADMIN_SUSPENDED, $state['administrative_state'] );
	$state = $machine->reinstate( $state['issuance_state'], $state['administrative_state'] );
	certloom_assert_same( States::ADMIN_ACTIVE, $state['administrative_state'] );
	$state = $machine->revoke( $state['issuance_state'], $state['administrative_state'] );
	certloom_assert_same( States::ADMIN_REVOKED, $state['administrative_state'] );
} );

certloom_test( 'illegal lifecycle transitions fail', function () {
	certloom_expect_exception( function () {
		( new StateMachine() )->suspend( States::ISSUANCE_DRAFT, States::ADMIN_ACTIVE );
	}, DomainException::class );
} );

certloom_test( 'issuing cannot silently clear an administrative revoke', function () {
	certloom_expect_exception( function () {
		( new StateMachine() )->issue( States::ISSUANCE_DRAFT, States::ADMIN_REVOKED );
	}, DomainException::class );
} );

certloom_test( 'public credential ID round trip and normalization', function () {
	$ids = new PublicCredentialId();
	for ( $i = 0; $i < 100; $i++ ) {
		$id = $ids->generate();
		certloom_assert_true( $ids->is_valid( $id ) );
		$formatted = $ids->format( $id );
		certloom_assert_same( 14, strlen( $formatted ) );
		certloom_assert_same( $id, $ids->normalize( strtolower( $formatted ) ) );
	}
} );

certloom_test( 'public credential checksum rejects corruption', function () {
	$ids = new PublicCredentialId();
	$id = $ids->generate();
	$replacement = '0' === $id[2] ? '1' : '0';
	$bad = substr( $id, 0, 2 ) . $replacement . substr( $id, 3 );
	certloom_assert_false( $ids->is_valid( $bad ) );
} );

$failed = 0;
foreach ( $tests as $test ) {
	list( $name, $fn ) = $test;
	try {
		$fn();
		echo "PASS  $name\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL  $name\n      " . $e->getMessage() . "\n";
	}
}

echo "\n" . count( $tests ) . " tests, $failed failures\n";
exit( $failed > 0 ? 1 : 0 );
