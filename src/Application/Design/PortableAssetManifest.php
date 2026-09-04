<?php
namespace Certloom\Application\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical embedded-media contract shared by portable Certloom design packages.
 *
 * Package assets are intentionally narrow: local PNG/JPEG files only. Remote
 * URLs remain references, while site-local media is carried with the package so
 * importing on another WordPress site cannot accidentally bind to an unrelated
 * attachment ID.
 */
final class PortableAssetManifest {
	const MAX_ASSETS      = 24;
	const MAX_ASSET_BYTES = 4 * 1024 * 1024;
	const MAX_TOTAL_BYTES = 12 * 1024 * 1024;

	/**
	 * @param array<string,mixed> $assets
	 * @return array<string,array<string,mixed>>|\WP_Error
	 */
	public function validate( array $assets ) {
		if ( count( $assets ) > self::MAX_ASSETS ) {
			return new \WP_Error(
				'certloom_design_assets_count',
				__( 'This design package contains more embedded images than Certloom allows.', 'certloom' )
			);
		}

		$validated   = array();
		$total_bytes = 0;

		foreach ( $assets as $key => $asset ) {
			$key = (string) $key;
			if ( ! preg_match( '/^media-[a-f0-9]{24}$/', $key ) || ! is_array( $asset ) ) {
				return new \WP_Error(
					'certloom_design_asset_contract',
					__( 'This design package contains an invalid embedded-media entry.', 'certloom' )
				);
			}

			$keys = array_keys( $asset );
			sort( $keys, SORT_STRING );
			if ( array( 'data', 'filename', 'mime', 'sha256', 'size' ) !== $keys ) {
				return new \WP_Error(
					'certloom_design_asset_contract',
					__( 'This design package contains unsupported embedded-media fields.', 'certloom' )
				);
			}

			$filename = basename( (string) $asset['filename'] );
			$mime     = strtolower( trim( (string) $asset['mime'] ) );
			$sha256   = strtolower( trim( (string) $asset['sha256'] ) );
			$size     = absint( $asset['size'] );
			$data     = (string) $asset['data'];

			if (
				'' === $filename ||
				$filename !== (string) $asset['filename'] ||
				false !== strpos( $filename, "\0" ) ||
				strlen( $filename ) > 190 ||
				! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ||
				! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ||
				$size < 1 ||
				$size > self::MAX_ASSET_BYTES
			) {
				return new \WP_Error(
					'certloom_design_asset_metadata',
					__( 'This design package contains invalid embedded-media metadata.', 'certloom' )
				);
			}

			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if (
				( 'image/png' === $mime && 'png' !== $extension ) ||
				( 'image/jpeg' === $mime && ! in_array( $extension, array( 'jpg', 'jpeg' ), true ) )
			) {
				return new \WP_Error(
					'certloom_design_asset_extension',
					__( 'An embedded image filename does not match its recorded media type.', 'certloom' )
				);
			}

			$bytes = base64_decode( $data, true );
			if ( false === $bytes || strlen( $bytes ) !== $size || ! hash_equals( $sha256, hash( 'sha256', $bytes ) ) ) {
				return new \WP_Error(
					'certloom_design_asset_integrity',
					__( 'An embedded image failed its integrity check.', 'certloom' )
				);
			}

			$detected_mime = $this->detect_mime( $bytes );
			if ( $mime !== $detected_mime ) {
				return new \WP_Error(
					'certloom_design_asset_type_mismatch',
					__( 'An embedded image does not match its recorded media type.', 'certloom' )
				);
			}

			$expected_key = 'media-' . substr( $sha256, 0, 24 );
			if ( ! hash_equals( $expected_key, $key ) ) {
				return new \WP_Error(
					'certloom_design_asset_key_mismatch',
					__( 'An embedded image does not match its recorded package identity.', 'certloom' )
				);
			}

			$total_bytes += $size;
			if ( $total_bytes > self::MAX_TOTAL_BYTES ) {
				return new \WP_Error(
					'certloom_design_assets_total_size',
					__( 'The embedded images in this design package exceed Certloom’s safe size limit.', 'certloom' )
				);
			}

			$validated[ $key ] = array(
				'filename' => $filename,
				'mime'     => $mime,
				'sha256'   => $sha256,
				'size'     => $size,
				'data'     => $data,
			);
		}

		ksort( $validated, SORT_STRING );
		return $validated;
	}

	/** @return string */
	private function detect_mime( $bytes ) {
		$bytes = (string) $bytes;
		if ( strlen( $bytes ) >= 8 && "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) ) {
			return 'image/png';
		}
		if ( strlen( $bytes ) >= 3 && "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) ) {
			return 'image/jpeg';
		}
		return '';
	}
}
