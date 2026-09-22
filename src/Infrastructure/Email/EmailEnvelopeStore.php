<?php
namespace Certloom\Infrastructure\Email;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Infrastructure\Files\PrivateJobFileStore;

/** Protected short-lived envelope containing destination and rendered message bodies. */
final class EmailEnvelopeStore {
	/** @var PrivateJobFileStore */ private $files;
	public function __construct( ?PrivateJobFileStore $files = null ) { $this->files = $files ?: new PrivateJobFileStore(); }

	/** @return string|\WP_Error */
	public function write( $delivery_key, array $envelope ) {
		$path = $this->files->create_path( $delivery_key, 'email-envelope' );
		if ( is_wp_error( $path ) ) { return $path; }
		$json = wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) > 262144 ) { return new \WP_Error( 'certloom_email_envelope_invalid', __( 'The credential email envelope could not be encoded safely.', 'certloom' ) ); }
		$handle = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private exclusive job file.
		if ( false === $handle ) { return new \WP_Error( 'certloom_email_envelope_open_failed', __( 'Certloom could not create its private email envelope.', 'certloom' ) ); }
		$written = fwrite( $handle, $json );
		$flushed = fflush( $handle ); fclose( $handle ); @chmod( $path, 0600 );
		if ( false === $written || strlen( $json ) !== $written || ! $flushed ) { @unlink( $path ); return new \WP_Error( 'certloom_email_envelope_write_failed', __( 'Certloom could not finish writing its private email envelope.', 'certloom' ) ); }
		return $path;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function read( $path ) {
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { return new \WP_Error( 'certloom_email_envelope_missing', __( 'The private credential email envelope is unavailable.', 'certloom' ) ); }
		$size = @filesize( $path ); if ( false === $size || $size < 2 || $size > 262144 ) { return new \WP_Error( 'certloom_email_envelope_size_invalid', __( 'The private credential email envelope has an invalid size.', 'certloom' ) ); }
		$json = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded Certloom-owned private file.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) && JSON_ERROR_NONE === json_last_error()
			? $data
			: new \WP_Error( 'certloom_email_envelope_decode_failed', __( 'The private credential email envelope is invalid.', 'certloom' ) );
	}

	/** @return bool|\WP_Error */ public function delete( $path ) { return $this->files->delete( $path ); }
	public function owns( $path ) { return $this->files->owns( $path ); }
}
