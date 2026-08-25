<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the WordPress Site Icon into a bounded, verified local raster asset
 * suitable for embedding inside a verification QR.
 *
 * No remote URL is trusted or fetched. The attachment must resolve to a real
 * PNG/JPEG file inside the site's uploads directory and is re-inspected from
 * bytes before it can be emitted as a data URI.
 */
final class Ecapla_CertVerify_QR_Logo {
	const MAX_BYTES        = 262144; // 256 KiB keeps REST/HTML payloads tightly bounded.
	const MIN_DIMENSION_PX = 48;
	const MAX_DIMENSION_PX = 1024;

	/** @var array<string,mixed>|WP_Error|null */
	private static $cache = null;

	/** @return array<string,mixed>|WP_Error */
	public static function asset() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'get_attached_file' ) || ! function_exists( 'wp_upload_dir' ) ) {
			return self::$cache = new WP_Error( 'ecapla_certverify_qr_logo_unavailable', __( 'The local WordPress Site Icon is unavailable.', 'certloom' ) );
		}
		$attachment_id = absint( get_option( 'site_icon', 0 ) );
		if ( $attachment_id <= 0 ) {
			return self::$cache = new WP_Error( 'ecapla_certverify_qr_logo_missing', __( 'Set a WordPress Site Icon before enabling the QR center logo.', 'certloom' ) );
		}

		$uploads = wp_upload_dir( null, false );
		$base_dir = is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$original = get_attached_file( $attachment_id, true );
		$original = is_string( $original ) && '' !== $original ? realpath( $original ) : false;
		if ( false === $base_dir || false === $original || ! self::inside( $original, $base_dir ) ) {
			return self::$cache = new WP_Error( 'ecapla_certverify_qr_logo_not_local', __( 'The Site Icon must be a local WordPress uploads asset.', 'certloom' ) );
		}

		$candidates = self::candidate_paths( $attachment_id, $original, $base_dir );
		foreach ( $candidates as $path ) {
			$asset = self::inspect( $path );
			if ( ! is_wp_error( $asset ) ) {
				self::$cache = $asset;
				return $asset;
			}
		}
		return self::$cache = new WP_Error( 'ecapla_certverify_qr_logo_invalid', __( 'The Site Icon is not a supported bounded local PNG/JPEG for QR use.', 'certloom' ) );
	}

	/** Test/support hook; never used by public request input. */
	public static function reset_cache() {
		self::$cache = null;
	}

	/** @return array<int,string> */
	private static function candidate_paths( $attachment_id, $original, $base_dir ) {
		$items = array();
		$meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $original );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) ) {
					continue;
				}
				$path = realpath( $dir . DIRECTORY_SEPARATOR . basename( (string) $size['file'] ) );
				if ( false === $path || ! self::inside( $path, $base_dir ) ) {
					continue;
				}
				$width = isset( $size['width'] ) ? absint( $size['width'] ) : PHP_INT_MAX;
				$height = isset( $size['height'] ) ? absint( $size['height'] ) : PHP_INT_MAX;
				$items[] = array( 'path' => $path, 'score' => max( $width, $height ) );
			}
		}
		$items[] = array( 'path' => $original, 'score' => PHP_INT_MAX - 1 );
		usort( $items, function ( $a, $b ) { return (int) $a['score'] <=> (int) $b['score']; } );
		$paths = array();
		foreach ( $items as $item ) {
			if ( ! in_array( $item['path'], $paths, true ) ) {
				$paths[] = $item['path'];
			}
		}
		return $paths;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function inspect( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_unreadable', __( 'The Site Icon file is not readable.', 'certloom' ) );
		}
		$bytes = filesize( $path );
		if ( false === $bytes || $bytes <= 0 || $bytes > self::MAX_BYTES ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_size', __( 'The Site Icon file is too large for QR embedding.', 'certloom' ) );
		}
		$image = @getimagesize( $path );
		if ( ! is_array( $image ) || empty( $image[0] ) || empty( $image[1] ) || empty( $image['mime'] ) ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_image', __( 'The Site Icon is not a readable raster image.', 'certloom' ) );
		}
		$width = (int) $image[0];
		$height = (int) $image[1];
		$mime = strtolower( trim( (string) $image['mime'] ) );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) || min( $width, $height ) < self::MIN_DIMENSION_PX || max( $width, $height ) > self::MAX_DIMENSION_PX ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_format', __( 'QR center logos must be local PNG/JPEG images between 48px and 1024px.', 'certloom' ) );
		}
		$content = file_get_contents( $path ); // Local validated uploads path only.
		if ( ! is_string( $content ) || strlen( $content ) !== (int) $bytes ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_read', __( 'The Site Icon could not be read safely.', 'certloom' ) );
		}
		$decoded = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $content ) : false;
		if ( ! is_array( $decoded ) || (int) $decoded[0] !== $width || (int) $decoded[1] !== $height || strtolower( (string) ( $decoded['mime'] ?? '' ) ) !== $mime ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_changed', __( 'The Site Icon changed while it was being validated.', 'certloom' ) );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$detected = strtolower( trim( (string) @finfo_buffer( $finfo, $content ) ) );
				if ( is_resource( $finfo ) ) { finfo_close( $finfo ); }
				if ( in_array( $detected, array( 'image/png', 'image/jpeg' ), true ) && $detected !== $mime ) {
					return new WP_Error( 'ecapla_certverify_qr_logo_mime', __( 'The Site Icon content detectors disagree about its image type.', 'certloom' ) );
				}
			}
		}
		return array(
			'data_uri'  => 'data:' . $mime . ';base64,' . base64_encode( $content ),
			'mime'      => $mime,
			'bytes'     => (int) $bytes,
			'width_px'  => $width,
			'height_px' => $height,
			'sha256'    => hash( 'sha256', $content ),
		);
	}

	/** @return bool */
	private static function inside( $path, $base_dir ) {
		$path = rtrim( str_replace( '\\', '/', (string) $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', (string) $base_dir ), '/' );
		return '' !== $path && '' !== $base && ( $path === $base || 0 === strpos( $path . '/', $base . '/' ) );
	}
}
