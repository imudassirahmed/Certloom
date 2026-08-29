<?php
namespace Certloom\Infrastructure\Backup;

/** Minimal Google Drive API v3 client for Certloom-owned backup files only. */
class GoogleDriveClient {
	const API_BASE    = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
	const CHUNK_BYTES = 1048576; // 1 MiB bounded memory per resumable upload request.

	/** @return string|\WP_Error */
	public function generate_file_id() {
		$response = $this->json_request( 'GET', self::API_BASE . '/files/generateIds?count=1&space=drive&type=files' );
		if ( is_wp_error( $response ) ) { return $response; }
		$id = GoogleDriveOAuth::clean_file_id( $response['ids'][0] ?? '' );
		return '' !== $id ? $id : new \WP_Error( 'certloom_google_file_id_missing', __( 'Google Drive did not return a safe file identifier.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_folder( $name, array $app_properties ) {
		$payload = array(
			'name'          => sanitize_text_field( (string) $name ),
			'mimeType'      => 'application/vnd.google-apps.folder',
			'appProperties' => $this->clean_app_properties( $app_properties ),
		);
		return $this->json_request(
			'POST',
			self::API_BASE . '/files?fields=id,name,mimeType,appProperties,trashed',
			array( 'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ), 'body' => wp_json_encode( $payload ) )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_file( $file_id ) {
		$file_id = GoogleDriveOAuth::clean_file_id( $file_id );
		if ( '' === $file_id ) { return new \WP_Error( 'certloom_google_file_id_invalid', __( 'Google Drive returned an invalid file identifier.', 'certloom' ) ); }
		$fields = rawurlencode( 'id,name,mimeType,size,md5Checksum,appProperties,parents,createdTime,trashed' );
		return $this->json_request( 'GET', self::API_BASE . '/files/' . rawurlencode( $file_id ) . '?fields=' . $fields );
	}

	/** @return true|\WP_Error */
	public function delete_file( $file_id ) {
		$file_id = GoogleDriveOAuth::clean_file_id( $file_id );
		if ( '' === $file_id ) { return new \WP_Error( 'certloom_google_file_id_invalid', __( 'Google Drive returned an invalid file identifier.', 'certloom' ) ); }
		$response = $this->request( 'DELETE', self::API_BASE . '/files/' . rawurlencode( $file_id ), array(), true );
		if ( is_wp_error( $response ) ) { return $response; }
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		return in_array( $code, array( 200, 204, 404 ), true ) ? true : new \WP_Error( 'certloom_google_delete_failed', __( 'Google Drive could not delete the selected Certloom backup.', 'certloom' ) );
	}

	/**
	 * Resumable binary upload. A pre-generated ID makes retries idempotent.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function upload_file( $path, $file_id, $name, $folder_id, array $app_properties ) {
		$path      = (string) $path;
		$file_id   = GoogleDriveOAuth::clean_file_id( $file_id );
		$folder_id = GoogleDriveOAuth::clean_file_id( $folder_id );
		$name      = sanitize_file_name( (string) $name );
		if ( '' === $file_id || '' === $folder_id || '' === $name || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return new \WP_Error( 'certloom_google_upload_input_invalid', __( 'Certloom refused invalid Google Drive backup upload input.', 'certloom' ) );
		}
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Guarded below.
		if ( false === $size || (int) $size <= 0 ) {
			return new \WP_Error( 'certloom_google_upload_file_invalid', __( 'The local backup file is not available for Google Drive upload.', 'certloom' ) );
		}
		$metadata = array(
			'id'            => $file_id,
			'name'          => $name,
			'parents'       => array( $folder_id ),
			'appProperties' => $this->clean_app_properties( $app_properties ),
		);
		$init = $this->request(
			'POST',
			self::UPLOAD_BASE . '/files?uploadType=resumable&fields=' . rawurlencode( 'id,name,size,md5Checksum,appProperties,parents,createdTime,trashed' ),
			array(
				'headers' => array(
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => 'application/x-certloom-backup',
					'X-Upload-Content-Length' => (string) (int) $size,
				),
				'body' => wp_json_encode( $metadata ),
			),
			true
		);
		if ( is_wp_error( $init ) ) { return $init; }
		$code = absint( wp_remote_retrieve_response_code( $init ) );
		if ( 409 === $code ) {
			// The deterministic pre-generated ID may already have completed on an earlier retry.
			return $this->get_file( $file_id );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'certloom_google_upload_start_failed', __( 'Google Drive could not start the Certloom backup upload.', 'certloom' ) );
		}
		$session = (string) wp_remote_retrieve_header( $init, 'location' );
		if ( ! $this->valid_google_url( $session, true ) ) {
			return new \WP_Error( 'certloom_google_upload_session_invalid', __( 'Google Drive returned an invalid upload session URL.', 'certloom' ) );
		}

		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded local backup stream.
		if ( false === $handle ) { return new \WP_Error( 'certloom_google_upload_open_failed', __( 'Certloom could not read the local backup for Google Drive upload.', 'certloom' ) ); }
		$offset = 0;
		$final  = null;
		while ( $offset < (int) $size ) {
			$want  = min( self::CHUNK_BYTES, (int) $size - $offset );
			$chunk = fread( $handle, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded stream chunk.
			if ( ! is_string( $chunk ) || strlen( $chunk ) !== $want ) {
				fclose( $handle );
				return new \WP_Error( 'certloom_google_upload_read_failed', __( 'Certloom could not read the complete local backup for Google Drive upload.', 'certloom' ) );
			}
			$end = $offset + $want - 1;
			$put = $this->request(
				'PUT',
				$session,
				array(
					'timeout' => 45,
					'headers' => array(
						'Content-Type'   => 'application/x-certloom-backup',
						'Content-Length' => (string) $want,
						'Content-Range'  => 'bytes ' . $offset . '-' . $end . '/' . (int) $size,
					),
					'body' => $chunk,
				),
				false
			);
			if ( is_wp_error( $put ) ) { fclose( $handle ); return $put; }
			$status = absint( wp_remote_retrieve_response_code( $put ) );
			if ( 308 === $status ) { $offset += $want; continue; }
			if ( $status >= 200 && $status < 300 ) {
				$data = json_decode( (string) wp_remote_retrieve_body( $put ), true );
				if ( ! is_array( $data ) ) { fclose( $handle ); return new \WP_Error( 'certloom_google_upload_response_invalid', __( 'Google Drive returned invalid metadata after the backup upload.', 'certloom' ) ); }
				$final  = $data;
				$offset = (int) $size;
				break;
			}
			fclose( $handle );
			return new \WP_Error( 'certloom_google_upload_failed', __( 'Google Drive could not finish the Certloom backup upload. The verified local backup is still available.', 'certloom' ) );
		}
		fclose( $handle );
		return is_array( $final ) ? $final : $this->get_file( $file_id );
	}

	/** @return true|\WP_Error */
	public function download_file( $file_id, $target_path ) {
		$file_id = GoogleDriveOAuth::clean_file_id( $file_id );
		$target_path = (string) $target_path;
		if ( '' === $file_id || '' === $target_path || file_exists( $target_path ) || is_link( $target_path ) ) {
			return new \WP_Error( 'certloom_google_download_input_invalid', __( 'Certloom refused an invalid Google Drive download target.', 'certloom' ) );
		}
		$response = $this->request(
			'GET',
			self::API_BASE . '/files/' . rawurlencode( $file_id ) . '?alt=media',
			array( 'timeout' => 60, 'stream' => true, 'filename' => $target_path ),
			true
		);
		if ( is_wp_error( $response ) ) { @unlink( $target_path ); return $response; }
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		if ( 200 !== $code || ! is_file( $target_path ) ) {
			@unlink( $target_path );
			return new \WP_Error( 'certloom_google_download_failed', __( 'Certloom could not download the selected Google Drive backup.', 'certloom' ) );
		}
		@chmod( $target_path, 0600 );
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function json_request( $method, $url, array $args = array() ) {
		$response = $this->request( $method, $url, $args, true );
		if ( is_wp_error( $response ) ) { return $response; }
		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error( 'certloom_google_api_failed', __( 'Google Drive did not complete the requested Certloom backup operation.', 'certloom' ) );
		}
		return $data;
	}

	/** @return array<string,mixed>|\WP_Error WordPress HTTP response. */
	private function request( $method, $url, array $args = array(), $retry_auth = true ) {
		if ( ! $this->valid_google_url( $url, false ) ) {
			return new \WP_Error( 'certloom_google_url_refused', __( 'Certloom refused an unexpected Google Drive network destination.', 'certloom' ) );
		}
		$token = GoogleDriveOAuth::access_token();
		if ( is_wp_error( $token ) ) { return $token; }
		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$headers['Authorization'] = 'Bearer ' . $token;
		$headers['Accept']        = 'application/json';
		$args['headers']     = $headers;
		$args['method']      = strtoupper( (string) $method );
		$args['timeout']     = isset( $args['timeout'] ) ? max( 5, min( 90, absint( $args['timeout'] ) ) ) : 20;
		$args['redirection'] = 0;
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'certloom_google_transport_failed', __( 'Certloom could not reach Google Drive. The verified local backup remains available.', 'certloom' ) );
		}
		if ( 401 === absint( wp_remote_retrieve_response_code( $response ) ) && $retry_auth ) {
			$cleared = GoogleDriveOAuth::invalidate_access_token();
			if ( is_wp_error( $cleared ) ) { return $cleared; }
			return $this->request( $method, $url, $args, false );
		}
		return $response;
	}

	private function valid_google_url( $url, $upload_session = false ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) { return false; }
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 'www.googleapis.com' !== $host ) { return false; }
		$path = (string) ( $parts['path'] ?? '' );
		if ( $upload_session ) { return 0 === strpos( $path, '/upload/drive/v3/files' ); }
		return 0 === strpos( $path, '/drive/v3/' ) || 0 === strpos( $path, '/upload/drive/v3/' );
	}

	/** @return array<string,string> */
	private function clean_app_properties( array $properties ) {
		$out = array();
		foreach ( array_slice( $properties, 0, 30, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			$value = sanitize_text_field( (string) $value );
			if ( '' === $key || '' === $value || strlen( $key ) > 64 || strlen( $value ) > 124 ) { continue; }
			$out[ $key ] = $value;
		}
		return $out;
	}
}
