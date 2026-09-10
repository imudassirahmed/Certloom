<?php
/** Pure application verification tests using an in-memory query repository. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\CredentialVerificationService;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\States;

final class FakeVerificationRepository implements CredentialQueryRepositoryInterface {
	public $rows = array();
	public $reads = 0;
	public $error = null;
	public function find_current_by_public_id( $public_id ) {
		$this->reads++;
		if ( $this->error ) { return $this->error; }
		return $this->rows[ $public_id ] ?? null;
	}
}

$tests = array();
function cvt( $name, callable $fn ) { global $tests; $tests[] = array( $name, $fn ); }
function cveq( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}
function cvtrue( $actual, $message = '' ) { cveq( true, (bool) $actual, $message ); }
function cvfalse( $actual, $message = '' ) { cveq( false, (bool) $actual, $message ); }

function cvrow( $public_id, array $override = array() ) {
	return array_merge(
		array(
			'series_id' => 7,
			'public_id' => $public_id,
			'recipient_id' => 11,
			'program_id' => 13,
			'issuer_id' => 17,
			'current_version_id' => 19,
			'administrative_state' => States::ADMIN_ACTIVE,
			'visibility' => 'verification_summary',
			'version_id' => 19,
			'version_number' => 1,
			'display_number' => 'CERT-2026-000019',
			'issuance_state' => States::ISSUANCE_ISSUED,
			'valid_from' => '2026-01-01',
			'expires_at' => '2026-12-31',
		),
		$override
	);
}

cvt( 'invalid checksum fails before repository access', function () {
	$repo = new FakeVerificationRepository();
	$svc  = new CredentialVerificationService( $repo );
	$result = $svc->verify_public_id( 'NOT-A-CREDENTIAL-ID', '2026-08-22' );
	cvfalse( $result->found() );
	cveq( 'invalid_identifier', $result->reason_code() );
	cveq( 0, $repo->reads );
} );

cvt( 'unknown valid ID returns not found after one direct lookup', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $ids->format( $id ), '2026-08-22' );
	cvfalse( $result->found() );
	cveq( 'not_found', $result->reason_code() );
	cveq( 1, $repo->reads );
} );

cvt( 'current issued credential verifies valid through direct Series binding', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( strtolower( $ids->format( $id ) ), '2026-08-22' );
	cvtrue( $result->found() );
	cveq( $id, $result->public_id() );
	cvtrue( $result->status()->is_valid() );
	cveq( 'valid', $result->status()->code() );
	cveq( 7, $result->credential()['series_id'] );
	cveq( 19, $result->credential()['version_id'] );
} );

cvt( 'generic verification transport shape does not expose raw credential data', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id, array( 'snapshot_json' => '{\"private\":true}', 'source_ref' => 'private-source' ) );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	$transport = $result->to_array();
	cvfalse( array_key_exists( 'credential', $transport ) );
	cvfalse( array_key_exists( 'snapshot_json', $transport ) );
	cvtrue( isset( $transport['status']['code'] ) );
} );

cvt( 'expiry is derived at verification time', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id, array( 'expires_at' => '2026-08-21' ) );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	cvtrue( $result->found() );
	cvfalse( $result->status()->is_valid() );
	cveq( 'expired', $result->status()->code() );
} );

cvt( 'legacy explicit expired read model remains expired', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id, array( 'legacy_expired' => 1, 'expires_at' => '2030-01-01' ) );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	cveq( 'expired', $result->status()->code() );
	cveq( 'legacy_expired_compatibility', $result->status()->reason_code() );
} );

cvt( 'administrative revoke remains authoritative', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id, array( 'administrative_state' => States::ADMIN_REVOKED ) );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	cveq( 'revoked', $result->status()->code() );
	cvfalse( $result->status()->is_valid() );
} );

cvt( 'missing current Version fails closed', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id, array( 'version_id' => null, 'current_version_id' => null ) );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	cvtrue( is_wp_error( $result ) );
	cveq( 'certloom_verification_current_version_missing', $result->get_error_code() );
} );

cvt( 'repository read errors propagate rather than becoming not found', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->error = new WP_Error( 'db_unavailable', 'db unavailable' );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	cvtrue( is_wp_error( $result ) );
	cveq( 'db_unavailable', $result->get_error_code() );
} );

cvt( 'invalid stored lifecycle state fails closed', function () {
	$repo = new FakeVerificationRepository();
	$ids  = new PublicCredentialId();
	$id   = $ids->generate();
	$repo->rows[ $id ] = cvrow( $id, array( 'administrative_state' => 'mystery' ) );
	$result = ( new CredentialVerificationService( $repo, $ids ) )->verify_public_id( $id, '2026-08-22' );
	cvtrue( is_wp_error( $result ) );
	cveq( 'certloom_verification_state_invalid', $result->get_error_code() );
} );

$failed = 0;
foreach ( $tests as $test ) {
	list( $name, $fn ) = $test;
	try { $fn(); echo "PASS  $name\n"; }
	catch ( Throwable $e ) { $failed++; echo "FAIL  $name\n      " . $e->getMessage() . "\n"; }
}
echo "\n" . count( $tests ) . " tests, $failed failures\n";
exit( $failed > 0 ? 1 : 0 );
