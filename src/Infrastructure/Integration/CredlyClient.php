<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded Credly API client; the only production network boundary for Credly. */
final class CredlyClient {
	const PRODUCTION_BASE = 'https://api.credly.com/v1';
	const SANDBOX_BASE    = 'https://sandbox-api.credly.com/v1';
	/** @var callable|null */ private $transport;
	public function __construct( $transport = null ) { $this->transport = is_callable( $transport ) ? $transport : null; }

	/** @return array<string,mixed>|\WP_Error */
	public function issue_badge( array $connection, array $payload ) {
		$organization_id = strtolower( trim( (string) ( $connection['organization_id'] ?? '' ) ) );
		$token = trim( (string) ( $connection['authorization_token'] ?? '' ) );
		$environment = (string) ( $connection['environment'] ?? 'production' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $organization_id ) || '' === $token ) { return new \WP_Error( 'certloom_credly_connection_invalid', __( 'The Credly connection is incomplete.', 'certloom' ) ); }
		$body = $this->normalize_payload( $payload );
		if ( is_wp_error( $body ) ) { return $body; }
		$base = 'sandbox' === $environment ? self::SANDBOX_BASE : self::PRODUCTION_BASE;
		$url = $base . '/organizations/' . rawurlencode( $organization_id ) . '/badges';
		$parts = wp_parse_url( $url );
		$allowed_host = 'sandbox' === $environment ? 'sandbox-api.credly.com' : 'api.credly.com';
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || $allowed_host !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) { return new \WP_Error( 'certloom_credly_endpoint_invalid', __( 'Certloom refused an unexpected Credly API endpoint.', 'certloom' ) ); }
		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $token . ':' ),
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			),
			'body' => wp_json_encode( $body, JSON_UNESCAPED_SLASHES ),
			'timeout' => 20,
			'redirection' => 0,
			'data_format' => 'body',
			'limit_response_size' => 262144,
		);
		$response = $this->transport ? call_user_func( $this->transport, $url, $args ) : wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) { return new \WP_Error( 'certloom_credly_transport_uncertain', __( 'Credly could not confirm whether the badge request was received. Certloom will not retry automatically.', 'certloom' ), array( 'uncertain' => true ) ); }
		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		/* Credly's v1 API wraps resources under a top-level data object. */
		$resource = is_array( $data ) && is_array( $data['data'] ?? null ) ? $data['data'] : ( is_array( $data ) ? $data : array() );
		if ( $status >= 200 && $status < 300 ) {
			$external_id = trim( (string) ( $resource['id'] ?? $resource['badge_id'] ?? '' ) );
			if ( '' === $external_id || strlen( $external_id ) > 191 ) { return new \WP_Error( 'certloom_credly_response_invalid', __( 'Credly accepted the request but returned an unreadable badge identifier. Review the Credly account before trying again.', 'certloom' ), array( 'uncertain' => true, 'http_status' => $status ) ); }
			return array( 'external_reference' => $external_id, 'http_status' => $status, 'state' => sanitize_key( (string) ( $resource['state'] ?? '' ) ) );
		}
		if ( $status >= 500 || 0 === $status ) { return new \WP_Error( 'certloom_credly_response_uncertain', __( 'Credly returned a server error after the publish request. Certloom will not retry automatically because the external result may be uncertain.', 'certloom' ), array( 'uncertain' => true, 'http_status' => $status ) ); }
		return new \WP_Error( 'certloom_credly_request_rejected', __( 'Credly rejected the badge request. Review the organization connection and badge-template mapping.', 'certloom' ), array( 'uncertain' => false, 'http_status' => $status ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_payload( array $payload ) {
		$template = trim( (string) ( $payload['badge_template_id'] ?? '' ) );
		$email = sanitize_email( (string) ( $payload['recipient_email'] ?? '' ) );
		$issued_at = trim( (string) ( $payload['issued_at'] ?? '' ) );
		$expires_at = trim( (string) ( $payload['expires_at'] ?? '' ) );
		if ( '' === $template || strlen( $template ) > 191 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $template ) ) { return new \WP_Error( 'certloom_credly_template_invalid', __( 'The Credly badge template ID is invalid.', 'certloom' ) ); }
		if ( '' === $email || strlen( $email ) > 320 ) { return new \WP_Error( 'certloom_credly_recipient_email_missing', __( 'Credly publishing needs an email address saved with the issued credential.', 'certloom' ) ); }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issued_at ) ) { return new \WP_Error( 'certloom_credly_issued_date_invalid', __( 'The issued credential does not contain a valid Credly publication date.', 'certloom' ) ); }
		if ( '' !== $expires_at && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) ) { return new \WP_Error( 'certloom_credly_expiry_date_invalid', __( 'The issued credential does not contain a valid Credly expiry date.', 'certloom' ) ); }
		$out = array(
			'badge_template_id' => $template,
			'recipient_email' => $email,
			'issued_at' => $issued_at,
			'expires_at' => '' !== $expires_at ? $expires_at : null,
			'issued_to_first_name' => sanitize_text_field( (string) ( $payload['issued_to_first_name'] ?? '' ) ),
			'issued_to_last_name' => sanitize_text_field( (string) ( $payload['issued_to_last_name'] ?? '' ) ),
			'suppress_badge_notification_email' => ! empty( $payload['suppress_badge_notification_email'] ),
		);
		$issuer_earner_id = sanitize_text_field( (string) ( $payload['issuer_earner_id'] ?? '' ) );
		if ( '' !== $issuer_earner_id ) { $out['issuer_earner_id'] = substr( $issuer_earner_id, 0, 191 ); }
		return $out;
	}
}
