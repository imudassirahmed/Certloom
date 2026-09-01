<?php
namespace Certloom\Infrastructure\Import;

use Certloom\Infrastructure\Files\PrivateJobFileStore;

/**
 * Short-lived protected upload session used only while an administrator maps a
 * CSV header to Certloom fields. No credential writes occur in this state.
 */
final class DomainImportUploadSession {
	const TRANSIENT_PREFIX = 'certloom_import_map_';
	const TTL              = 30 * MINUTE_IN_SECONDS;

	/** @var PrivateJobFileStore */
	private $files;
	/** @var DomainImportCsvReader */
	private $reader;

	public function __construct( ?PrivateJobFileStore $files = null, ?DomainImportCsvReader $reader = null ) {
		$this->files  = $files ?: new PrivateJobFileStore();
		$this->reader = $reader ?: new DomainImportCsvReader( $this->files );
	}

	/** @return string|\WP_Error Mapping-session token. */
	public function create( $source_path, $original_name, $owner_user_id ) {
		$original_name = sanitize_file_name( (string) $original_name );
		if ( 'csv' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'certloom_import_upload_type_invalid', __( 'Certloom accepts CSV files for this import workflow.', 'certloom' ) );
		}
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_import_mapping_token_failed', __( 'Certloom could not create a secure CSV mapping session.', 'certloom' ) );
		}
		$path = $this->files->store_copy( $token, (string) $source_path, 'import-map' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$header = $this->reader->inspect_header( $path );
		if ( is_wp_error( $header ) ) {
			return $this->cleanup_after_error( $path, $header );
		}
		$payload = array(
			'token'         => $token,
			'owner_user_id' => absint( $owner_user_id ),
			'original_name' => $original_name,
			'path'          => $path,
			'header'        => array_values( (array) $header['header'] ),
			'created_at'    => time(),
		);
		if ( ! set_transient( self::TRANSIENT_PREFIX . $token, $payload, self::TTL ) ) {
			return $this->cleanup_after_error( $path, new \WP_Error( 'certloom_import_mapping_session_failed', __( 'Certloom could not save the temporary CSV mapping session.', 'certloom' ) ) );
		}
		return $token;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get( $token, $owner_user_id ) {
		$token = $this->token( $token );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$payload = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $payload ) || ! hash_equals( $token, (string) ( $payload['token'] ?? '' ) ) ) {
			return new \WP_Error( 'certloom_import_mapping_session_expired', __( 'That CSV mapping session expired. Upload the file again.', 'certloom' ) );
		}
		$owner_user_id = absint( $owner_user_id );
		if ( absint( $payload['owner_user_id'] ?? 0 ) !== $owner_user_id ) {
			return new \WP_Error( 'certloom_import_mapping_owner_mismatch', __( 'That CSV mapping session belongs to a different Certloom administrator.', 'certloom' ) );
		}
		$path = (string) ( $payload['path'] ?? '' );
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			$transient_key = self::TRANSIENT_PREFIX . $token;
			$deleted = delete_transient( $transient_key );
			if ( ! $deleted && false !== get_transient( $transient_key ) ) {
				return new \WP_Error( 'certloom_import_mapping_cleanup_failed', __( 'The protected CSV mapping source is missing and Certloom could not clear its stale mapping session safely.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_import_mapping_source_missing', __( 'The protected CSV mapping source is no longer available. Upload the file again.', 'certloom' ) );
		}
		return $payload;
	}

	/**
	 * Validates the administrator's field mapping without transferring ownership
	 * or staging credential data. Mapping errors therefore do not force another
	 * potentially large upload.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_mapping( $token, $owner_user_id, array $mapping, array $options = array() ) {
		$payload = $this->get( $token, $owner_user_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		return $this->reader->start(
			(string) $payload['path'],
			$mapping,
			! empty( $options['clear_empty'] ),
			absint( $options['max_rows'] ?? DomainImportCsvReader::MAX_ROWS )
		);
	}

	/** @return array<string,string> Machine field => administrator-facing label. */
	public function field_choices() {
		return $this->reader->field_choices();
	}

	/** Removes only the transient after ownership of the private file moves to a preflight job. */
	public function consume( $token, $owner_user_id ) {
		$payload = $this->get( $token, $owner_user_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$key = self::TRANSIENT_PREFIX . (string) $payload['token'];
		$deleted = delete_transient( $key );
		if ( ! $deleted && false !== get_transient( $key ) ) {
			return new \WP_Error( 'certloom_import_mapping_consume_failed', __( 'Certloom could not close the CSV mapping session after transferring its protected source to the import job.', 'certloom' ) );
		}
		return true;
	}

	/** Deletes both the mapping transient and its private file. @return true|\WP_Error */
	public function abandon( $token, $owner_user_id ) {
		$payload = $this->get( $token, $owner_user_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$deleted = $this->files->delete( (string) $payload['path'] );
		if ( is_wp_error( $deleted ) || false === $deleted ) {
			return is_wp_error( $deleted ) ? $deleted : new \WP_Error( 'certloom_import_mapping_file_cleanup_failed', __( 'Certloom could not remove the protected CSV mapping source safely.', 'certloom' ) );
		}
		$key = self::TRANSIENT_PREFIX . (string) $payload['token'];
		$transient_deleted = delete_transient( $key );
		if ( ! $transient_deleted && false !== get_transient( $key ) ) {
			return new \WP_Error( 'certloom_import_mapping_session_cleanup_failed', __( 'The protected CSV file was removed, but Certloom could not clear its temporary mapping-session record.', 'certloom' ) );
		}
		return true;
	}


	/** @return \WP_Error */
	private function cleanup_after_error( $path, \WP_Error $primary ) {
		$deleted = $this->files->delete( (string) $path );
		if ( ! is_wp_error( $deleted ) && false !== $deleted ) { return $primary; }
		/* translators: 1: stop reason, 2: cleanup error message */
		return new \WP_Error( 'certloom_import_mapping_cleanup_failed', sprintf( __( 'Certloom stopped the CSV mapping session safely (%1$s), but could not remove its protected upload copy (%2$s).', 'certloom' ), $primary->get_error_message(), is_wp_error( $deleted ) ? $deleted->get_error_message() : __( 'file deletion failed', 'certloom' ) ) );
	}

	/** @return string|\WP_Error */
	private function token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token )
			? $token
			: new \WP_Error( 'certloom_import_mapping_token_invalid', __( 'The CSV mapping-session identifier is invalid.', 'certloom' ) );
	}
}
