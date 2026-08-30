<?php
namespace Certloom\Infrastructure\Backup;

/** Google OAuth 2.0 web-server flow for the least-privilege Drive file scope. */
final class GoogleDriveOAuth {
	const CONFIG_OPTION  = 'certloom_google_drive_config';
	const SECRETS_OPTION = 'certloom_google_drive_secrets';
	const SCOPE          = 'https://www.googleapis.com/auth/drive.file';
	const AUTH_URL       = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL      = 'https://oauth2.googleapis.com/token';
	const STATE_PREFIX   = 'certloom_gdrive_oauth_';

	/** @return array<string,mixed> */
	public static function config() {
		$raw = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $raw ) ) { $raw = array(); }
		return array(
			'client_id'    => self::configured_client_id( $raw ),
			'enabled'      => ! empty( $raw['enabled'] ),
			'folder_id'    => self::clean_file_id( $raw['folder_id'] ?? '' ),
			'connected_at' => sanitize_text_field( (string) ( $raw['connected_at'] ?? '' ) ),
			'connected_ts' => absint( $raw['connected_ts'] ?? 0 ),
		);
	}

	/** @return true|\WP_Error */
	public static function save_client_credentials( $client_id, $client_secret ) {
		$client_id = trim( (string) $client_id );
		if ( ! self::valid_client_id( $client_id ) ) {
			return new \WP_Error( 'certloom_google_client_id_invalid', __( 'Enter a valid Google OAuth client ID.', 'certloom' ) );
		}
		$current = self::secret_payload();
		if ( is_wp_error( $current ) ) { return $current; }
		$config_before = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config_before ) ) { $config_before = array(); }
		$previous_client_id = self::configured_client_id( $config_before );
		$previous_secret    = (string) ( $current['client_secret'] ?? '' );
		$submitted_secret   = trim( (string) $client_secret );
		$client_secret      = $submitted_secret;
		if ( '' === $client_secret ) {
			$client_secret = $previous_secret;
		}
		if ( '' === $client_secret && ! self::constant_client_secret() ) {
			return new \WP_Error( 'certloom_google_client_secret_missing', __( 'Enter the Google OAuth client secret before connecting Google Drive.', 'certloom' ) );
		}
		if ( strlen( $client_secret ) > 1024 ) {
			return new \WP_Error( 'certloom_google_client_secret_invalid', __( 'The Google OAuth client secret is too long.', 'certloom' ) );
		}
		$credentials_changed = ( '' !== $previous_client_id && ! hash_equals( $previous_client_id, $client_id ) ) || ( '' !== $submitted_secret && '' !== $previous_secret && ! hash_equals( $previous_secret, $submitted_secret ) );
		if ( $credentials_changed ) {
			unset( $current['refresh_token'], $current['access_token'], $current['access_expiry'] );
		}
		if ( '' !== $client_secret ) { $current['client_secret'] = $client_secret; }
		$sealed = SecretVault::seal( $current );
		if ( is_wp_error( $sealed ) ) { return $sealed; }
		$config = $config_before;
		if ( ! self::constant_client_id() ) { $config['client_id'] = $client_id; }
		if ( $credentials_changed ) {
			$config['enabled']      = 0;
			$config['folder_id']    = '';
			$config['connected_at'] = '';
			$config['connected_ts'] = 0;
		}
		$old_sealed  = get_option( self::SECRETS_OPTION, false );
		$had_secrets = false !== $old_sealed;
		$saved_secret = update_option( self::SECRETS_OPTION, $sealed, false );
		if ( ! $saved_secret && $sealed !== get_option( self::SECRETS_OPTION, '' ) ) {
			return new \WP_Error( 'certloom_google_secret_save_failed', __( 'Certloom could not save the Google Drive connection securely.', 'certloom' ) );
		}
		$saved_config = update_option( self::CONFIG_OPTION, $config, false );
		if ( $saved_config || $config === get_option( self::CONFIG_OPTION, array() ) ) { return true; }
		if ( ! self::restore_secret_option( $had_secrets, $old_sealed ) ) {
			return new \WP_Error( 'certloom_google_config_save_rollback_failed', __( 'Certloom could not save the Google Drive settings and could not safely restore the previous encrypted connection. Re-enter the Google Drive connection settings before reconnecting.', 'certloom' ) );
		}
		return new \WP_Error( 'certloom_google_config_save_failed', __( 'Certloom could not save the Google Drive connection settings. The previous encrypted connection was restored.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	public static function authorization_url( $context = 'backup' ) {
		$credentials = self::client_credentials();
		if ( is_wp_error( $credentials ) ) { return $credentials; }
		$redirect = self::redirect_uri();
		if ( is_wp_error( $redirect ) ) { return $redirect; }
		try { $state = bin2hex( random_bytes( 32 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_google_oauth_state_failed', __( 'Certloom could not start a secure Google Drive connection.', 'certloom' ) ); }
		$context = in_array( sanitize_key( (string) $context ), array( 'backup', 'popup' ), true ) ? sanitize_key( (string) $context ) : 'backup';
		$key = self::STATE_PREFIX . hash( 'sha256', $state );
		set_transient( $key, array( 'user_id' => absint( get_current_user_id() ), 'created' => time(), 'redirect_uri' => $redirect, 'context' => $context ), 10 * MINUTE_IN_SECONDS );
		$query = array(
			'client_id'                => $credentials['client_id'],
			'redirect_uri'             => $redirect,
			'response_type'            => 'code',
			'scope'                    => self::SCOPE,
			'access_type'              => 'offline',
			'include_granted_scopes'   => 'true',
			'prompt'                   => 'consent',
			'state'                    => $state,
		);
		return self::AUTH_URL . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/** True when this site already has a usable OAuth client identity. */
	public static function has_client_credentials() {
		return ! is_wp_error( self::client_credentials() );
	}

	/** Returns the pending OAuth UI context without consuming the state. */
	public static function pending_context( $state ) {
		$state = trim( (string) $state );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $state ) ) { return ''; }
		$pending = get_transient( self::STATE_PREFIX . hash( 'sha256', $state ) );
		if ( ! is_array( $pending ) || absint( $pending['user_id'] ?? 0 ) !== absint( get_current_user_id() ) ) { return ''; }
		$context = sanitize_key( (string) ( $pending['context'] ?? '' ) );
		return in_array( $context, array( 'backup', 'popup' ), true ) ? $context : '';
	}

	/** @return true|\WP_Error */
	public static function complete_authorization( $state, $code ) {
		$state = trim( (string) $state );
		$code  = trim( (string) $code );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $state ) || '' === $code || strlen( $code ) > 4096 ) {
			return new \WP_Error( 'certloom_google_oauth_response_invalid', __( 'Google Drive returned an invalid connection response.', 'certloom' ) );
		}
		$key     = self::STATE_PREFIX . hash( 'sha256', $state );
		$pending = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) || absint( $pending['user_id'] ?? 0 ) !== absint( get_current_user_id() ) || absint( $pending['created'] ?? 0 ) < time() - 10 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'certloom_google_oauth_state_invalid', __( 'The Google Drive connection request expired or did not match this administrator. Start the connection again.', 'certloom' ) );
		}
		$redirect = self::redirect_uri();
		if ( is_wp_error( $redirect ) || ! hash_equals( (string) ( $pending['redirect_uri'] ?? '' ), (string) $redirect ) ) {
			return new \WP_Error( 'certloom_google_oauth_redirect_changed', __( 'The WordPress admin URL changed during Google Drive connection. Start the connection again.', 'certloom' ) );
		}
		$credentials = self::client_credentials();
		if ( is_wp_error( $credentials ) ) { return $credentials; }
		$response = self::token_request( array(
			'client_id'     => $credentials['client_id'],
			'client_secret' => $credentials['client_secret'],
			'code'          => $code,
			'grant_type'    => 'authorization_code',
			'redirect_uri'  => $redirect,
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$scope = preg_split( '/\s+/', trim( (string) ( $response['scope'] ?? '' ) ) );
		if ( ! in_array( self::SCOPE, is_array( $scope ) ? $scope : array(), true ) ) {
			return new \WP_Error( 'certloom_google_scope_missing', __( 'Google Drive did not grant the file permission Certloom needs. Reconnect and approve the requested access.', 'certloom' ) );
		}
		$current = self::secret_payload();
		if ( is_wp_error( $current ) ) { return $current; }
		$refresh = trim( (string) ( $response['refresh_token'] ?? $current['refresh_token'] ?? '' ) );
		if ( '' === $refresh ) {
			return new \WP_Error( 'certloom_google_refresh_token_missing', __( 'Google did not return offline backup access. Disconnect Certloom in your Google account and connect again.', 'certloom' ) );
		}
		$current['refresh_token'] = $refresh;
		$current['access_token']  = trim( (string) ( $response['access_token'] ?? '' ) );
		$current['access_expiry'] = time() + max( 60, absint( $response['expires_in'] ?? 3600 ) );
		if ( '' === $current['access_token'] ) {
			return new \WP_Error( 'certloom_google_access_token_missing', __( 'Google did not return an access token for the Drive connection.', 'certloom' ) );
		}
		$old_sealed  = get_option( self::SECRETS_OPTION, false );
		$had_secrets = false !== $old_sealed;
		$saved = self::save_secret_payload( $current );
		if ( is_wp_error( $saved ) ) { return $saved; }
		$config = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config ) ) { $config = array(); }
		$config['connected_at'] = gmdate( 'c' );
		$config['connected_ts'] = time();
		$config_saved = update_option( self::CONFIG_OPTION, $config, false );
		if ( $config_saved || $config === get_option( self::CONFIG_OPTION, array() ) ) { return true; }
		if ( ! self::restore_secret_option( $had_secrets, $old_sealed ) ) {
			return new \WP_Error( 'certloom_google_oauth_save_rollback_failed', __( 'Google authorized the connection, but Certloom could not save its local connection state safely. Re-enter the Google Drive connection settings and connect again.', 'certloom' ) );
		}
		return new \WP_Error( 'certloom_google_config_save_failed', __( 'Google authorized the connection, but Certloom could not save the connection state. No new Google Drive token was retained; connect again.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	public static function access_token() {
		$payload = self::secret_payload();
		if ( is_wp_error( $payload ) ) { return $payload; }
		$access = trim( (string) ( $payload['access_token'] ?? '' ) );
		$expiry = absint( $payload['access_expiry'] ?? 0 );
		if ( '' !== $access && $expiry > time() + 90 ) { return $access; }
		$refresh = trim( (string) ( $payload['refresh_token'] ?? '' ) );
		if ( '' === $refresh ) {
			return new \WP_Error( 'certloom_google_not_connected', __( 'Google Drive is not connected to Certloom.', 'certloom' ) );
		}
		$credentials = self::client_credentials();
		if ( is_wp_error( $credentials ) ) { return $credentials; }
		$response = self::token_request( array(
			'client_id'     => $credentials['client_id'],
			'client_secret' => $credentials['client_secret'],
			'refresh_token' => $refresh,
			'grant_type'    => 'refresh_token',
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$access = trim( (string) ( $response['access_token'] ?? '' ) );
		if ( '' === $access ) {
			return new \WP_Error( 'certloom_google_access_token_missing', __( 'Google Drive did not return a refreshed access token. Reconnect Google Drive.', 'certloom' ) );
		}
		$payload['access_token']  = $access;
		$payload['access_expiry'] = time() + max( 60, absint( $response['expires_in'] ?? 3600 ) );
		$saved = self::save_secret_payload( $payload );
		return is_wp_error( $saved ) ? $saved : $access;
	}

	public static function is_connected() {
		$payload = self::secret_payload();
		return is_array( $payload ) && '' !== trim( (string) ( $payload['refresh_token'] ?? '' ) );
	}

	/** Forces the next API request to refresh its access token. @return true|\WP_Error */
	public static function invalidate_access_token() {
		$payload = self::secret_payload();
		if ( is_wp_error( $payload ) ) { return $payload; }
		unset( $payload['access_token'], $payload['access_expiry'] );
		return self::save_secret_payload( $payload );
	}

	/** @return true|\WP_Error */
	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;
		if ( $enabled && ! self::is_connected() ) {
			return new \WP_Error( 'certloom_google_not_connected', __( 'Connect Google Drive before enabling automatic Drive copies.', 'certloom' ) );
		}
		$config = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config ) ) { $config = array(); }
		$config['enabled'] = $enabled ? 1 : 0;
		$saved = update_option( self::CONFIG_OPTION, $config, false );
		return ( $saved || $config === get_option( self::CONFIG_OPTION, array() ) ) ? true : new \WP_Error( 'certloom_google_config_save_failed', __( 'Certloom could not save the Google Drive backup setting.', 'certloom' ) );
	}

	/** Removes tokens while retaining configured OAuth client credentials. */
	public static function disconnect() {
		$old_sealed  = get_option( self::SECRETS_OPTION, false );
		$had_secrets = false !== $old_sealed;
		$payload = self::secret_payload();
		if ( is_wp_error( $payload ) ) { $payload = array(); }
		unset( $payload['refresh_token'], $payload['access_token'], $payload['access_expiry'] );
		$saved = self::save_secret_payload( $payload );
		if ( is_wp_error( $saved ) ) { return $saved; }
		$config = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config ) ) { $config = array(); }
		$config['enabled']      = 0;
		$config['folder_id']    = '';
		$config['connected_at'] = '';
		$config['connected_ts'] = 0;
		$config_saved = update_option( self::CONFIG_OPTION, $config, false );
		if ( $config_saved || $config === get_option( self::CONFIG_OPTION, array() ) ) { return true; }
		if ( ! self::restore_secret_option( $had_secrets, $old_sealed ) ) {
			return new \WP_Error( 'certloom_google_disconnect_rollback_failed', __( 'Certloom could not save the Google Drive disconnect state or safely restore the previous encrypted connection. Re-enter the Google Drive connection settings before reconnecting.', 'certloom' ) );
		}
		return new \WP_Error( 'certloom_google_config_save_failed', __( 'Certloom could not save the Google Drive disconnect state. The previous encrypted connection was restored.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public static function save_folder_id( $folder_id ) {
		$folder_id = self::clean_file_id( $folder_id );
		if ( '' === $folder_id ) { return new \WP_Error( 'certloom_google_folder_id_invalid', __( 'Google Drive returned an invalid backup folder identifier.', 'certloom' ) ); }
		$config = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config ) ) { $config = array(); }
		$config['folder_id'] = $folder_id;
		$saved = update_option( self::CONFIG_OPTION, $config, false );
		return ( $saved || $config === get_option( self::CONFIG_OPTION, array() ) ) ? true : new \WP_Error( 'certloom_google_folder_save_failed', __( 'Certloom could not save its Google Drive backup folder.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	public static function redirect_uri() {
		$url = admin_url( 'admin-post.php?action=certloom_google_drive_oauth_callback' );
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$https = 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( ! $https && ! $local ) {
			return new \WP_Error( 'certloom_google_https_required', __( 'Google Drive connection requires WordPress admin to use HTTPS.', 'certloom' ) );
		}
		return $url;
	}

	/** @return array<string,string>|\WP_Error */
	private static function client_credentials() {
		$config = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $config ) ) { $config = array(); }
		$client_id = self::configured_client_id( $config );
		$payload = self::secret_payload();
		if ( is_wp_error( $payload ) ) { return $payload; }
		$secret = self::constant_client_secret();
		if ( '' === $secret ) { $secret = trim( (string) ( $payload['client_secret'] ?? '' ) ); }
		if ( ! self::valid_client_id( $client_id ) || '' === $secret ) {
			return new \WP_Error( 'certloom_google_credentials_missing', __( 'Save a Google OAuth client ID and client secret before connecting Google Drive.', 'certloom' ) );
		}
		return array( 'client_id' => $client_id, 'client_secret' => $secret );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function secret_payload() {
		$sealed = get_option( self::SECRETS_OPTION, '' );
		return SecretVault::open( is_string( $sealed ) ? $sealed : '' );
	}

	/** Restore the exact previous encrypted secret option after a paired config write fails. */
	private static function restore_secret_option( $had_secrets, $old_sealed ) {
		if ( $had_secrets ) {
			$restored = update_option( self::SECRETS_OPTION, $old_sealed, false );
			return $restored || $old_sealed === get_option( self::SECRETS_OPTION, false );
		}
		delete_option( self::SECRETS_OPTION );
		return false === get_option( self::SECRETS_OPTION, false );
	}

	/** @return true|\WP_Error */
	private static function save_secret_payload( array $payload ) {
		$sealed = SecretVault::seal( $payload );
		if ( is_wp_error( $sealed ) ) { return $sealed; }
		$saved = update_option( self::SECRETS_OPTION, $sealed, false );
		return ( $saved || $sealed === get_option( self::SECRETS_OPTION, '' ) ) ? true : new \WP_Error( 'certloom_google_secret_save_failed', __( 'Certloom could not save the Google Drive connection securely.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function token_request( array $body ) {
		$response = wp_safe_remote_post( self::TOKEN_URL, array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array( 'Accept' => 'application/json' ),
			'body'        => $body,
		) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'certloom_google_token_transport_failed', __( 'Certloom could not reach Google to complete the Drive connection.', 'certloom' ) );
		}
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'certloom_google_token_failed', __( 'Google did not accept the Drive connection. Check the OAuth client settings and connect again.', 'certloom' ) );
		}
		return $data;
	}

	private static function configured_client_id( array $config ) {
		$constant = self::constant_client_id();
		return '' !== $constant ? $constant : trim( (string) ( $config['client_id'] ?? '' ) );
	}

	private static function constant_client_id() {
		return defined( 'CERTLOOM_GOOGLE_DRIVE_CLIENT_ID' ) ? trim( (string) CERTLOOM_GOOGLE_DRIVE_CLIENT_ID ) : '';
	}

	private static function constant_client_secret() {
		return defined( 'CERTLOOM_GOOGLE_DRIVE_CLIENT_SECRET' ) ? trim( (string) CERTLOOM_GOOGLE_DRIVE_CLIENT_SECRET ) : '';
	}

	private static function valid_client_id( $value ) {
		$value = trim( (string) $value );
		return strlen( $value ) <= 512 && 1 === preg_match( '/^[A-Za-z0-9._:-]+\.apps\.googleusercontent\.com$/', $value );
	}

	public static function clean_file_id( $value ) {
		$value = trim( (string) $value );
		return strlen( $value ) <= 256 && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ? $value : '';
	}
}
