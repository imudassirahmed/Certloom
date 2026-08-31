<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Encrypted site-owned connection to an authorized Credly issuer organization. */
final class CredlyConnectionRepository {
	const OPTION = 'certloom_credly_connection_v1';

	/** @return array<string,mixed> */
	public function status() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) { $stored = array(); }
		$organization_id = strtolower( trim( (string) ( $stored['organization_id'] ?? '' ) ) );
		$environment = (string) ( $stored['environment'] ?? 'production' );
		$configured = self::valid_uuid( $organization_id ) && in_array( $environment, array( 'production', 'sandbox' ), true ) && ! empty( $stored['sealed_token'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) ( $stored['token_sha256'] ?? '' ) );
		return array(
			'configured'       => (bool) $configured,
			'organization_id'  => $configured ? $organization_id : '',
			'environment'      => $configured ? $environment : 'production',
			'updated_at'       => $configured ? (string) ( $stored['updated_at'] ?? '' ) : '',
			'configuration_sha256' => $configured ? $this->fingerprint_from_stored( $stored ) : '',
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function connection() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! self::valid_uuid( (string) ( $stored['organization_id'] ?? '' ) ) || empty( $stored['sealed_token'] ) ) {
			return new \WP_Error( 'certloom_credly_not_connected', __( 'Connect an authorized Credly organization before publishing badges.', 'certloom' ) );
		}
		$secret = IntegrationSecretVault::open( (string) $stored['sealed_token'] );
		if ( is_wp_error( $secret ) ) { return $secret; }
		$token = trim( (string) ( $secret['authorization_token'] ?? '' ) );
		if ( '' === $token || strlen( $token ) > 2048 || ! hash_equals( (string) ( $stored['token_sha256'] ?? '' ), hash( 'sha256', $token ) ) ) {
			return new \WP_Error( 'certloom_credly_token_invalid', __( 'The saved Credly authorization token could not be verified. Reconnect Credly.', 'certloom' ) );
		}
		$environment = (string) ( $stored['environment'] ?? 'production' );
		if ( ! in_array( $environment, array( 'production', 'sandbox' ), true ) ) { return new \WP_Error( 'certloom_credly_environment_invalid', __( 'The saved Credly environment is invalid. Reconnect Credly.', 'certloom' ) ); }
		return array(
			'organization_id' => strtolower( (string) $stored['organization_id'] ),
			'environment' => $environment,
			'authorization_token' => $token,
			'configuration_sha256' => $this->fingerprint_from_stored( $stored ),
		);
	}

	/** @return true|\WP_Error */
	public function save( $organization_id, $authorization_token, $environment = 'production' ) {
		$organization_id = strtolower( trim( (string) $organization_id ) );
		$authorization_token = trim( (string) $authorization_token );
		$environment = sanitize_key( (string) $environment );
		if ( ! self::valid_uuid( $organization_id ) ) { return new \WP_Error( 'certloom_credly_organization_invalid', __( 'Enter the Credly Organization ID from your issuer account.', 'certloom' ) ); }
		if ( '' === $authorization_token || strlen( $authorization_token ) < 16 || strlen( $authorization_token ) > 2048 || preg_match( '/\s/', $authorization_token ) ) { return new \WP_Error( 'certloom_credly_token_invalid', __( 'Enter a valid Credly authorization token.', 'certloom' ) ); }
		if ( ! in_array( $environment, array( 'production', 'sandbox' ), true ) ) { return new \WP_Error( 'certloom_credly_environment_invalid', __( 'Choose the production or sandbox Credly environment.', 'certloom' ) ); }
		$sealed = IntegrationSecretVault::seal( array( 'authorization_token' => $authorization_token ) );
		if ( is_wp_error( $sealed ) ) { return $sealed; }
		$payload = array(
			'schema_version' => 1,
			'organization_id' => $organization_id,
			'environment' => $environment,
			'sealed_token' => $sealed,
			'token_sha256' => hash( 'sha256', $authorization_token ),
			'updated_at' => current_time( 'mysql', true ),
		);
		$saved = update_option( self::OPTION, $payload, false );
		return $saved || $payload === get_option( self::OPTION, null ) ? true : new \WP_Error( 'certloom_credly_connection_save_failed', __( 'Certloom could not save the Credly connection securely.', 'certloom' ) );
	}

	/** @return true */
	public function disconnect() { delete_option( self::OPTION ); return true; }

	/** @return string */
	private function fingerprint_from_stored( array $stored ) {
		/*
		 * Bind queued publication work to the external destination identity, not to
		 * the current secret. Token rotation must not manufacture a second badge or
		 * invalidate already reviewed work for the same organization/environment.
		 */
		$data = array(
			'environment' => (string) ( $stored['environment'] ?? '' ),
			'organization_id' => strtolower( (string) ( $stored['organization_id'] ?? '' ) ),
		);
		ksort( $data, SORT_STRING );
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	}

	private static function valid_uuid( $value ) { return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim( (string) $value ) ); }
}
