<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Independently verifies local raster assets before any renderer can decode them.
 *
 * Resolver metadata is treated as an assertion, not as authority. Content hash,
 * byte size, image format and dimensions are re-derived from the local file.
 */
final class AssetInspector {
	const MAX_BYTES = 26214400; // 25 MiB.
	const MAX_DIMENSION_PX = 12000;
	const MAX_PIXELS = 40000000;

	/** @return array<string,mixed> */
	public function inspect( $asset ) {
		if ( ! is_array( $asset ) || ! isset( $asset['path'], $asset['sha256'], $asset['mime'], $asset['size'] ) ) {
			throw new InvalidArgumentException( 'Asset resolver returned an incomplete local asset.' );
		}

		$path = (string) $asset['path'];
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new InvalidArgumentException( 'Resolved asset is not a readable local file.' );
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_BYTES || (int) $size !== (int) $asset['size'] ) {
			throw new InvalidArgumentException( 'Resolved asset byte size is invalid or outside the supported limit.' );
		}

		$hash = strtolower( trim( (string) $asset['sha256'] ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $hash ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) {
			throw new InvalidArgumentException( 'Resolved asset content does not match its SHA-256.' );
		}

		$image = @getimagesize( $path );
		if ( ! is_array( $image ) || empty( $image[0] ) || empty( $image[1] ) || empty( $image['mime'] ) ) {
			throw new InvalidArgumentException( 'Resolved asset is not a supported raster image.' );
		}
		$width = (int) $image[0];
		$height = (int) $image[1];
		if ( $width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION_PX || $height > self::MAX_DIMENSION_PX || ( $width * $height ) > self::MAX_PIXELS ) {
			throw new InvalidArgumentException( 'Resolved asset dimensions are outside the supported safety limits.' );
		}

		$detected_mime = strtolower( trim( (string) $image['mime'] ) );
		if ( ! in_array( $detected_mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			throw new InvalidArgumentException( 'Layout schema v1 accepts only local PNG or JPEG assets.' );
		}
		$reported_mime = strtolower( trim( (string) $asset['mime'] ) );
		if ( $reported_mime !== $detected_mime ) {
			throw new InvalidArgumentException( 'Resolved asset MIME metadata does not match the file content.' );
		}

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$finfo_mime = strtolower( trim( (string) @finfo_file( $finfo, $path ) ) );
				finfo_close( $finfo );
				if ( in_array( $finfo_mime, array( 'image/png', 'image/jpeg' ), true ) && $finfo_mime !== $detected_mime ) {
					throw new InvalidArgumentException( 'Resolved asset content detectors disagree about its MIME type.' );
				}
			}
		}

		return array(
			'path' => $path,
			'sha256' => $hash,
			'mime' => $detected_mime,
			'size' => (int) $size,
			'width_px' => $width,
			'height_px' => $height,
		);
	}
}
