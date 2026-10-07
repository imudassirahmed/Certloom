<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-local Coursera xAPI client-credential boundary.
 *
 * Certloom acts as the receiving learning platform: the administrator configures
 * Coursera with a generated client ID/secret plus this site's OAuth/token and
 * xAPI statement URLs. The client secret and bearer token are never stored in
 * plaintext; only SHA-256 digests of high-entropy random values are persisted.
 */
final class CourseraXapiCredentials {
	const OPTION           = 'certloom_coursera_xapi_credentials_v1';
	const TOKEN_TTL        = 3600;
	const CLIENT_ID_PREFIX = 'clm_';

	/** @return array<string,mixed> */
	public static function status() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$client_id = self::valid_client_id( $stored['client_id'] ?? '' ) ? (string) $stored['client_id'] : '';
		return array(
			'configured'        => '' !== $client_id && self::valid_sha( $stored['client_secret_sha256'] ?? '' ),
			'client_id'         => $client_id,
			'token_url'         => function_exists( 'rest_url' ) ? rest_url( 'certloom/v1/integrations/coursera/token' ) : '',
			'statement_url'     => function_exists( 'rest_url' ) ? rest_url( 'certloom/v1/integrations/coursera/xapi/statements' ) : '',
			'token_expires_at'  => absint( $stored['token_expires_at'] ?? 0 ),
			'updated_at'        => sanitize_text_field( (string) ( $stored['updated_at'] ?? '' ) ),
		);
	}

	/** Generate/rotate credentials. The raw client secret is returned once. @return array<string,string>|\WP_Error */
	public static function rotate() {
		try {
			$client_id = self::CLIENT_ID_PREFIX . bin2hex( random_bytes( 12 ) );
			$secret    = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_coursera_credentials_random_failed', __( 'Certloom could not generate secure Coursera connection credentials.', 'certloom' ) );
		}
		$payload = array(
			'client_id'            => $client_id,
			'client_secret_sha256' => hash( 'sha256', $secret ),
			'token_sha256'         => '',
			'token_expires_at'     => 0,
			'updated_at'           => current_time( 'mysql', true ),
		);
		$saved = ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( self::OPTION, $payload );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( ! $saved && $payload !== get_option( self::OPTION, null ) ) {
			return new \WP_Error( 'certloom_coursera_credentials_save_failed', __( 'Certloom could not save the Coursera xAPI connection safely.', 'certloom' ) );
		}
		return array( 'client_id' => $client_id, 'client_secret' => $secret );
	}

	/** @return true|\WP_Error */
	public static function disconnect() {
		return ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->remove( self::OPTION );
	}

	/** @return array{access_token:string,token_type:string,expires_in:int}|\WP_Error */
	public static function issue_token( $client_id, $client_secret ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! self::valid_client_id( $client_id ) || ! self::valid_sha( $stored['client_secret_sha256'] ?? '' ) ) {
			return new \WP_Error( 'certloom_coursera_client_invalid', __( 'Coursera could not authenticate this Certloom xAPI connection.', 'certloom' ) );
		}
		if ( ! hash_equals( (string) ( $stored['client_id'] ?? '' ), (string) $client_id ) || ! hash_equals( (string) $stored['client_secret_sha256'], hash( 'sha256', (string) $client_secret ) ) ) {
			return new \WP_Error( 'certloom_coursera_client_invalid', __( 'Coursera could not authenticate this Certloom xAPI connection.', 'certloom' ) );
		}
		try { $token = bin2hex( random_bytes( 32 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_coursera_token_random_failed', __( 'Certloom could not create a secure Coursera access token.', 'certloom' ) ); }
		$expires = time() + self::TOKEN_TTL;
		$stored['token_sha256']     = hash( 'sha256', $token );
		$stored['token_expires_at'] = $expires;
		$stored['updated_at']       = current_time( 'mysql', true );
		$saved = ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( self::OPTION, $stored );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( ! $saved && $stored !== get_option( self::OPTION, null ) ) { return new \WP_Error( 'certloom_coursera_token_save_failed', __( 'Certloom could not save the Coursera access-token state safely.', 'certloom' ) ); }
		return array( 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => self::TOKEN_TTL );
	}

	/** @return bool */
	public static function verify_bearer( $token ) {
		$token = trim( (string) $token );
		$stored = get_option( self::OPTION, array() );
		if ( '' === $token || ! is_array( $stored ) || ! self::valid_sha( $stored['token_sha256'] ?? '' ) || time() >= absint( $stored['token_expires_at'] ?? 0 ) ) { return false; }
		return hash_equals( (string) $stored['token_sha256'], hash( 'sha256', $token ) );
	}

	/** @return bool */
	private static function valid_client_id( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^clm_[a-f0-9]{24}$/', $value );
	}
	/** @return bool */
	private static function valid_sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ); }
}
