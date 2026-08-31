<?php
namespace Certloom\Infrastructure\Backup;

/**
 * Narrow Ecapla-hosted Google OAuth handshake for zero-config Drive connection.
 *
 * The relay owns only Google's confidential OAuth client secret. Credential,
 * Recipient, Program, verification and backup authority stay on WordPress.
 */
final class EcaplaOAuthRelay {
	const BASE_URL         = 'https://auth.ecapla.com';
	const START_URL        = self::BASE_URL . '/v1/google/start';
	const EXCHANGE_URL     = self::BASE_URL . '/v1/google/exchange';
	const REFRESH_URL      = self::BASE_URL . '/v1/google/refresh';
	const PRODUCT          = 'certloom';
	const STATE_PREFIX     = 'certloom_gdrive_relay_';
	const STATE_TTL        = 600;
	const MAX_RESPONSE     = 262144;
	const MAX_TOKEN_BYTES  = 8192;

	public static function available() {
		return ! ( defined( 'CERTLOOM_DISABLE_ECAPLA_OAUTH_RELAY' ) && CERTLOOM_DISABLE_ECAPLA_OAUTH_RELAY );
	}

	/** @return string|\WP_Error */
	public static function authorization_url( $context = 'backup' ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'certloom_google_relay_disabled', __( 'The managed Google connection is disabled on this WordPress site. Use the site-owned Google OAuth option instead.', 'certloom' ) );
		}
		$return_url = self::return_uri();
		if ( is_wp_error( $return_url ) ) { return $return_url; }
		try { $state = bin2hex( random_bytes( 32 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_google_relay_state_failed', __( 'Certloom could not start a secure Google connection.', 'certloom' ) ); }
		$context = in_array( sanitize_key( (string) $context ), array( 'backup', 'popup' ), true ) ? sanitize_key( (string) $context ) : 'backup';
		set_transient(
			self::STATE_PREFIX . hash( 'sha256', $state ),
			array(
				'user_id'    => absint( get_current_user_id() ),
				'created'    => time(),
				'return_url' => $return_url,
				'context'    => $context,
			),
			self::STATE_TTL
		);
		return self::START_URL . '?' . http_build_query(
			array(
				'product'      => self::PRODUCT,
				'return_url'   => $return_url,
				'client_state' => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/** Returns the pending popup/full-page context without consuming local state. */
	public static function pending_context( $state ) {
		$pending = self::pending_state( $state );
		if ( ! is_array( $pending ) ) { return ''; }
		$context = sanitize_key( (string) ( $pending['context'] ?? '' ) );
		return in_array( $context, array( 'backup', 'popup' ), true ) ? $context : '';
	}

	/** @return true|\WP_Error */
	public static function complete_authorization( $state, $handoff_code ) {
		$state        = trim( (string) $state );
		$handoff_code = trim( (string) $handoff_code );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $state ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $handoff_code ) ) {
			return new \WP_Error( 'certloom_google_relay_response_invalid', __( 'The managed Google connection returned an invalid response.', 'certloom' ) );
		}
		$key     = self::STATE_PREFIX . hash( 'sha256', $state );
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) || absint( $pending['user_id'] ?? 0 ) !== absint( get_current_user_id() ) || absint( $pending['created'] ?? 0 ) < time() - self::STATE_TTL ) {
			return new \WP_Error( 'certloom_google_relay_state_invalid', __( 'The managed Google connection expired or did not match this administrator. Start the connection again.', 'certloom' ) );
		}
		$return_url = self::return_uri();
		if ( is_wp_error( $return_url ) || ! hash_equals( (string) ( $pending['return_url'] ?? '' ), (string) $return_url ) ) {
			return new \WP_Error( 'certloom_google_relay_return_changed', __( 'The WordPress admin URL changed during Google connection. Start the connection again.', 'certloom' ) );
		}
		$response = self::post( self::EXCHANGE_URL, array( 'product' => self::PRODUCT, 'handoff_code' => $handoff_code, 'return_url' => $return_url ) );
		if ( is_wp_error( $response ) ) { return $response; }
		return GoogleDriveOAuth::accept_relay_authorization( $response );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function refresh_access_token( $refresh_token ) {
		$refresh_token = trim( (string) $refresh_token );
		if ( '' === $refresh_token || strlen( $refresh_token ) > self::MAX_TOKEN_BYTES ) {
			return new \WP_Error( 'certloom_google_relay_refresh_invalid', __( 'The saved Google Drive connection cannot be refreshed safely. Reconnect Google Drive.', 'certloom' ) );
		}
		return self::post( self::REFRESH_URL, array( 'product' => self::PRODUCT, 'refresh_token' => $refresh_token ) );
	}

	/** @return string|\WP_Error */
	public static function return_uri() {
		$url   = admin_url( 'admin-post.php?action=certloom_google_drive_relay_callback' );
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$https = 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( ! $https && ! $local ) {
			return new \WP_Error( 'certloom_google_https_required', __( 'Managed Google connection requires WordPress admin to use HTTPS.', 'certloom' ) );
		}
		return $url;
	}

	/** @return array<string,mixed>|null */
	private static function pending_state( $state ) {
		$state = trim( (string) $state );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $state ) ) { return null; }
		$pending = get_transient( self::STATE_PREFIX . hash( 'sha256', $state ) );
		if ( ! is_array( $pending ) || absint( $pending['user_id'] ?? 0 ) !== absint( get_current_user_id() ) || absint( $pending['created'] ?? 0 ) < time() - self::STATE_TTL ) { return null; }
		return $pending;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function post( $url, array $body ) {
		if ( ! self::reviewed_url( $url ) ) {
			return new \WP_Error( 'certloom_google_relay_endpoint_invalid', __( 'Certloom refused an unreviewed managed OAuth endpoint.', 'certloom' ) );
		}
		$response = wp_safe_remote_post( $url, array(
			'timeout'             => 20,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_RESPONSE,
			'headers'             => array( 'Accept' => 'application/json' ),
			'body'                => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'certloom_google_relay_transport_failed', __( 'Certloom could not reach the managed Google connection service. Try again or use the site-owned OAuth option.', 'certloom' ) );
		}
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'certloom_google_relay_failed', __( 'The managed Google connection could not be completed. Try again or use the site-owned OAuth option.', 'certloom' ) );
		}
		return $data;
	}

	private static function reviewed_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& 'auth.ecapla.com' === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& in_array( (string) ( $parts['path'] ?? '' ), array( '/v1/google/exchange', '/v1/google/refresh' ), true );
	}
}
