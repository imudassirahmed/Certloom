<?php
namespace Certloom\Infrastructure\Integration;

/**
 * Authenticated-encryption boundary for third-party integration credentials.
 *
 * Secrets never fall back to plaintext. If the host cannot provide a supported
 * authenticated cipher or WordPress salts, connector configuration fails closed.
 */
final class IntegrationSecretVault {
	const CONTEXT = 'certloom-integration-secrets-v1';

	/** @return string|\WP_Error */
	public static function seal( array $payload ) {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'certloom_integration_secret_encode_failed', __( 'Certloom could not prepare the integration connection securely.', 'certloom' ) );
		}
		$key = self::key();
		if ( is_wp_error( $key ) ) { return $key; }

		if ( function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) {
			try {
				$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $json, $nonce, $key );
				return 'v1:sodium:' . base64_encode( $nonce . $cipher );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'certloom_integration_secret_encrypt_failed', __( 'Certloom could not encrypt the integration connection securely.', 'certloom' ) );
			}
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) ) {
			try { $iv = random_bytes( 12 ); }
			catch ( \Throwable $error ) { return new \WP_Error( 'certloom_integration_secret_encrypt_failed', __( 'Certloom could not encrypt the integration connection securely.', 'certloom' ) ); }
			$tag    = '';
			$cipher = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT, 16 );
			if ( ! is_string( $cipher ) || 16 !== strlen( $tag ) ) {
				return new \WP_Error( 'certloom_integration_secret_encrypt_failed', __( 'Certloom could not encrypt the integration connection securely.', 'certloom' ) );
			}
			return 'v1:gcm:' . base64_encode( $iv . $tag . $cipher );
		}
		return new \WP_Error( 'certloom_integration_secret_crypto_unavailable', __( 'This server does not provide the authenticated encryption required for third-party integration credentials.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function open( $sealed ) {
		$sealed = trim( (string) $sealed );
		if ( '' === $sealed ) { return array(); }
		$parts = explode( ':', $sealed, 3 );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] || ! in_array( $parts[1], array( 'sodium', 'gcm' ), true ) ) {
			return new \WP_Error( 'certloom_integration_secret_format_invalid', __( 'The saved integration credentials are not in a supported secure format.', 'certloom' ) );
		}
		$raw = base64_decode( $parts[2], true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error( 'certloom_integration_secret_format_invalid', __( 'The saved integration credentials are not in a supported secure format.', 'certloom' ) );
		}
		$key = self::key();
		if ( is_wp_error( $key ) ) { return $key; }
		$plain = false;
		if ( 'sodium' === $parts[1] ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || ! defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return new \WP_Error( 'certloom_integration_secret_crypto_unavailable', __( 'This server cannot open the saved integration credentials securely.', 'certloom' ) );
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
		} else {
			if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) <= 28 ) {
				return new \WP_Error( 'certloom_integration_secret_crypto_unavailable', __( 'This server cannot open the saved integration credentials securely.', 'certloom' ) );
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT );
		}
		if ( ! is_string( $plain ) ) {
			return new \WP_Error( 'certloom_integration_secret_decrypt_failed', __( 'The saved integration credentials could not be verified. Reconnect the integration.', 'certloom' ) );
		}
		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'certloom_integration_secret_payload_invalid', __( 'The saved integration credentials are invalid. Reconnect the integration.', 'certloom' ) );
		}
		return $data;
	}

	/** @return string|\WP_Error 32 raw bytes. */
	private static function key() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return new \WP_Error( 'certloom_integration_secret_key_unavailable', __( 'WordPress security salts are unavailable, so Certloom cannot protect integration credentials.', 'certloom' ) );
		}
		$material = (string) wp_salt( 'auth' ) . '|' . (string) wp_salt( 'secure_auth' ) . '|' . self::CONTEXT;
		if ( strlen( $material ) < 32 ) {
			return new \WP_Error( 'certloom_integration_secret_key_unavailable', __( 'WordPress security salts are unavailable, so Certloom cannot protect integration credentials.', 'certloom' ) );
		}
		return hash( 'sha256', $material, true );
	}
}
