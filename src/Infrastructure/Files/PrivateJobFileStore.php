<?php
namespace Certloom\Infrastructure\Files;

/** Private randomized storage for resumable import/export/backup job files. */
final class PrivateJobFileStore {
	/** @return string|\WP_Error */
	public function create_path( $job_key, $purpose = 'data' ) {
		$job_key = strtolower( trim( (string) $job_key ) );
		$purpose = sanitize_key( (string) $purpose );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_key ) || '' === $purpose ) {
			return new \WP_Error( 'certloom_private_file_identity_invalid', __( 'The Certloom private-file identity is invalid.', 'certloom' ) );
		}
		$dir = $this->ensure_directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		try {
			$random = bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_private_file_random_failed', __( 'Certloom could not generate a secure temporary filename.', 'certloom' ) );
		}
		return trailingslashit( $dir ) . $job_key . '-' . $purpose . '-' . $random . '.data';
	}

	/** @return string|\WP_Error */
	public function store_copy( $job_key, $source_path, $purpose = 'data' ) {
		$source_path = (string) $source_path;
		if ( '' === $source_path || ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			return new \WP_Error( 'certloom_private_file_source_invalid', __( 'The source file is not available for secure Certloom storage.', 'certloom' ) );
		}
		$destination = $this->create_path( $job_key, $purpose );
		if ( is_wp_error( $destination ) ) { return $destination; }
		$in = @fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming controlled local job file.
		$out = @fopen( $destination, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation prevents replacement races.
		if ( false === $in || false === $out ) {
			if ( is_resource( $in ) ) { fclose( $in ); }
			if ( is_resource( $out ) ) { fclose( $out ); }
			@unlink( $destination );
			return new \WP_Error( 'certloom_private_file_open_failed', __( 'Certloom could not open its private job file.', 'certloom' ) );
		}
		$copied = stream_copy_to_stream( $in, $out );
		$flushed = fflush( $out );
		fclose( $in );
		fclose( $out );
		if ( false === $copied || ! $flushed ) {
			@unlink( $destination );
			return new \WP_Error( 'certloom_private_file_copy_failed', __( 'Certloom could not finish copying the private job file.', 'certloom' ) );
		}
		@chmod( $destination, 0600 );
		return $destination;
	}

	/** @return bool|\WP_Error */
	public function delete( $path ) {
		if ( ! $this->owns( $path ) ) {
			return new \WP_Error( 'certloom_private_file_path_invalid', __( 'Certloom refused to delete a file outside its private job directory.', 'certloom' ) );
		}
		return ! file_exists( $path ) || @unlink( $path );
	}

	/** @return bool */
	public function owns( $path ) {
		$path = wp_normalize_path( (string) $path );
		$dir = $this->directory();
		if ( is_wp_error( $dir ) || '' === $path ) { return false; }
		$dir = trailingslashit( wp_normalize_path( $dir ) );
		return 0 === strpos( $path, $dir ) && false === strpos( substr( $path, strlen( $dir ) ), '/' );
	}

	/** @return string|\WP_Error */
	public function ensure_directory() {
		$dir = $this->directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'certloom_private_directory_failed', __( 'Certloom could not create its private job directory.', 'certloom' ) );
		}
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) && false === @file_put_contents( $path, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Internal protection sentinel.
				return new \WP_Error( 'certloom_private_directory_protection_failed', __( 'Certloom could not protect its private job directory.', 'certloom' ) );
			}
		}
		return $dir;
	}


	/** Deletes only Certloom-owned randomized job data files older than the retention window. @return int|\WP_Error */
	public function cleanup_older_than( $age_seconds, $limit = 100 ) {
		return $this->cleanup_matching( null, $age_seconds, $limit );
	}

	/** Deletes expired files for one exact Certloom purpose (for example import-map). @return int|\WP_Error */
	public function cleanup_purpose_older_than( $purpose, $age_seconds, $limit = 100 ) {
		$purpose = sanitize_key( (string) $purpose );
		if ( '' === $purpose ) {
			return new \WP_Error( 'certloom_private_file_cleanup_purpose_invalid', __( 'The Certloom private-file cleanup purpose is invalid.', 'certloom' ) );
		}
		return $this->cleanup_matching( $purpose, $age_seconds, $limit );
	}

	/** @return int|\WP_Error */
	private function cleanup_matching( $purpose, $age_seconds, $limit ) {
		$dir = $this->directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		if ( ! is_dir( $dir ) ) { return 0; }
		$age_seconds = max( 60, absint( $age_seconds ) );
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$cutoff = time() - $age_seconds;
		$deleted = 0;
		$entries = @scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return new \WP_Error( 'certloom_private_file_cleanup_read_failed', __( 'Certloom could not inspect its private job directory for cleanup.', 'certloom' ) );
		}
		foreach ( $entries as $name ) {
			if ( $deleted >= $limit ) { break; }
			if ( ! preg_match( '/^[a-f0-9]{32}-([a-z0-9_-]+)-[a-f0-9]{48}\.data$/', (string) $name, $matches ) ) { continue; }
			if ( null !== $purpose && ! hash_equals( $purpose, (string) $matches[1] ) ) { continue; }
			$path = trailingslashit( $dir ) . $name;
			$mtime = @filemtime( $path );
			if ( false === $mtime || $mtime >= $cutoff ) { continue; }
			if ( ! @unlink( $path ) && file_exists( $path ) ) {
				return new \WP_Error( 'certloom_private_file_cleanup_failed', __( 'Certloom could not remove an expired private job file.', 'certloom' ) );
			}
			$deleted++;
		}
		return $deleted;
	}

	/** @return string|\WP_Error */
	public function directory() {
		$configured = (string) apply_filters( 'certloom_private_job_directory', '' );
		if ( '' !== trim( $configured ) ) {
			return untrailingslashit( wp_normalize_path( $configured ) );
		}
		$temp = function_exists( 'get_temp_dir' ) ? wp_normalize_path( get_temp_dir() ) : '';
		$webroot = defined( 'ABSPATH' ) ? trailingslashit( wp_normalize_path( ABSPATH ) ) : '';
		if ( '' !== $temp && ( '' === $webroot || 0 !== strpos( trailingslashit( $temp ), $webroot ) ) ) {
			return untrailingslashit( trailingslashit( $temp ) . 'certloom-private' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return untrailingslashit( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . 'certloom-private' );
		}
		return new \WP_Error( 'certloom_private_directory_unavailable', __( 'Certloom could not determine a private job directory.', 'certloom' ) );
	}
}
