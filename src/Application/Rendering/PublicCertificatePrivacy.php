<?php
namespace Certloom\Application\Rendering;

use Certloom\Application\Fields\ScopedFieldPresentation;
use Certloom\Application\Issuance\MergeSnapshotBuilder;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Apply field privacy to the visual certificate as well as its details list. */
final class PublicCertificatePrivacy {
	public function assert_safe( array $snapshot ) {
		$layout = ( new LayoutValidator() )->normalize( (array) ( $snapshot['template']['layout'] ?? array() ) );
		$merge = $snapshot['merge']['values'] ?? null; $json = new CanonicalJson();
		if ( ! is_array( $merge ) || ! $json->matches_hash( $layout, (string) ( $snapshot['template']['layout_sha256'] ?? '' ) ) || ! $json->matches_hash( $merge, (string) ( $snapshot['merge']['sha256'] ?? '' ) ) ) { throw new InvalidArgumentException( 'Issued certificate data failed its integrity check.' ); }
		// These facts already form the explicitly public credential profile.
		$core = array_fill_keys( array( 'recipient.name', 'recipient_name', 'program.name', 'program.title', 'program_name', 'course_name', 'issuer.name', 'issuer_name', 'issuer.title', 'signer.name', 'signer.title', 'batch.name', 'batch_name', 'cohort_name', 'credential.display_number', 'certificate_number', 'credential.title', 'credential_title', 'credential.public_id', 'credential.issue_date', 'issue_date', 'credential.expiry_date', 'expiry_date', 'credential.status', 'certificate_status', 'verification.url', 'verification_url', 'site_name', 'issuer.logo_asset', 'issuer.seal_asset', 'signer.signature_asset' ), true );
		$public = array();
		foreach ( ( new ScopedFieldPresentation() )->public_fields( $snapshot, 'profile' ) as $row ) { $public[ $row['key'] ] = $row['value']; }
		$bindings = array( 'recipient.first_name' => 'first_name', 'recipient.middle_name' => 'middle_name', 'recipient.last_name' => 'last_name', 'recipient.email' => 'email', 'recipient.external_id' => 'external_id', 'credential.grade' => 'grade', 'batch.code' => 'batch_code', 'program.code' => 'program_code' );
		$aliases = is_array( $snapshot['issuance']['design_field_aliases'] ?? null ) ? $snapshot['issuance']['design_field_aliases'] : array();
		foreach ( ( new MergeSnapshotBuilder() )->required_tokens( $layout ) as $token ) {
			if ( isset( $core[ $token ] ) ) { continue; }
			$key = $bindings[ $token ] ?? $token;
			foreach ( array( 'credential.custom.', 'recipient.custom.', 'custom:' ) as $prefix ) { if ( 0 === strpos( $key, $prefix ) ) { $key = substr( $key, strlen( $prefix ) ); break; } }
			$key = $aliases[ $key ] ?? $key;
			if ( ! is_string( $key ) || ! array_key_exists( $key, $public ) || ! array_key_exists( $token, $merge ) || ! is_scalar( $merge[ $token ] ) || trim( (string) $merge[ $token ] ) !== $public[ $key ] ) { throw new InvalidArgumentException( 'This visual certificate includes data that is not approved for public display.' ); }
		}
	}
}
