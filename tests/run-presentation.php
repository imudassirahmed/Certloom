<?php
/** Pure public visibility/presenter tests. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\PublicCredentialPresenter;
use Certloom\Application\VerificationResult;
use Certloom\Application\VisibilityPolicy;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\StatusResult;

function p_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}

$ids = new PublicCredentialId();
$id = $ids->generate();
$status = new StatusResult( true, 'valid', 'all_requirements_met', 'issued', 'active', 'current', 'not_applicable' );
$base = array(
	'public_id' => $id,
	'display_number' => 'CERT-42',
	'visibility' => 'verification_summary',
	'issued_at' => '2026-01-02 12:00:00',
	'expires_at' => '2027-01-02',
);
$presenter = new PublicCredentialPresenter();

$payload = $presenter->present( new VerificationResult( true, $id, 'found', $base, $status ) );
p_same( true, $payload['found'], 'summary credential found' );
p_same( $ids->format( $id ), $payload['credential_id'] );
p_same( 'CERT-42', $payload['certificate_number'] );
p_same( '2026-01-02', $payload['issued_at'] );
p_same( 'valid', $payload['status']['code'] );

foreach ( array( 'private', 'recipient_only', 'organization_member' ) as $visibility ) {
	$row = $base;
	$row['visibility'] = $visibility;
	$hidden = $presenter->present( new VerificationResult( true, $id, 'found', $row, $status ) );
	p_same( false, $hidden['found'], $visibility . ' hidden from guest' );
	p_same( 'not_found', $hidden['reason_code'] );
}

$not_found = $presenter->present( new VerificationResult( false, $id, 'not_found' ) );
p_same( false, $not_found['found'] );
p_same( 'not_found', $not_found['reason_code'] );

$policy = new VisibilityPolicy();
p_same( VisibilityPolicy::MODE_PROFILE, $policy->guest_mode( 'public' ) );
p_same( VisibilityPolicy::MODE_SUMMARY, $policy->guest_mode( 'unlisted' ) );
p_same( VisibilityPolicy::MODE_DENY, $policy->guest_mode( 'private' ) );

echo "PASS  public presenter summary/privacy behavior\n";
echo "PASS  visibility policy modes\n\n2 tests, 0 failures\n";
