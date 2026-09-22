<?php
namespace Certloom\Infrastructure\Issuance;

use Certloom\Application\Contract\PublicCredentialUrlInterface;
use Certloom\Domain\Credential\PublicCredentialId;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Self-hosted, human-facing normalized credential profile URL. */
final class NormalizedPublicCredentialUrl implements PublicCredentialUrlInterface {
	/** @var PublicCredentialId */ private $ids;
	public function __construct( ?PublicCredentialId $ids = null ) { $this->ids = $ids ?: new PublicCredentialId(); }

	/** @return string|\WP_Error */
	public function verification_url( $public_id ) {
		$public_id = $this->ids->normalize( $public_id );
		if ( ! $this->ids->is_valid( $public_id ) ) { return new \WP_Error( 'certloom_manual_issue_public_id_invalid', __( 'Certloom could not build a verification link for the generated public credential ID.', 'certloom' ) ); }
		$display = $this->ids->format( $public_id );
		if ( '' !== (string) get_option( 'permalink_structure', '' ) ) {
			$url = home_url( '/certloom/credential/id/' . rawurlencode( $display ) . '/' );
		} else {
			$url = add_query_arg(
				array(
					'certloom_route' => 'series',
					'certloom_token' => $display,
				),
				home_url( '/' )
			);
		}
		return is_string( $url ) && '' !== $url ? $url : new \WP_Error( 'certloom_manual_issue_verification_url_failed', __( 'Certloom could not build the self-hosted verification URL.', 'certloom' ) );
	}

	/** Machine-readable adapter URL for integrations that explicitly need JSON. @return string|\WP_Error */
	public function api_url( $public_id ) {
		$public_id = $this->ids->normalize( $public_id );
		if ( ! $this->ids->is_valid( $public_id ) ) { return new \WP_Error( 'certloom_public_api_id_invalid', __( 'Certloom could not build the credential API URL for that public ID.', 'certloom' ) ); }
		$url = add_query_arg( array( 'id' => $public_id ), rest_url( 'certloom/v1/credential' ) );
		return is_string( $url ) && '' !== $url ? $url : new \WP_Error( 'certloom_public_api_url_failed', __( 'Certloom could not build the credential API URL.', 'certloom' ) );
	}
}
