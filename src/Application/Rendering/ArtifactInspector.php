<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/** Validates that renderer bytes actually match the requested artifact format. */
final class ArtifactInspector {
	/** @return array<string,mixed> */
	public function inspect( $format, $bytes ) {
		$format = strtolower( trim( (string) $format ) );
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			throw new InvalidArgumentException( 'Artifact bytes must be a non-empty string.' );
		}
		switch ( $format ) {
			case 'pdf':
				return $this->pdf( $bytes );
			case 'png':
				return $this->raster( $bytes, 'image/png', IMAGETYPE_PNG, 'png' );
			case 'jpeg':
				return $this->raster( $bytes, 'image/jpeg', IMAGETYPE_JPEG, 'jpeg' );
			default:
				throw new InvalidArgumentException( 'Unsupported Certloom artifact format.' );
		}
	}

	/** @return array<string,mixed> */
	private function pdf( $bytes ) {
		if ( ! preg_match( '/^%PDF-(1\.[0-9]|2\.0)(?:\r?\n|\r)/', $bytes, $match ) ) {
			throw new InvalidArgumentException( 'Renderer output does not contain a valid PDF header.' );
		}
		$tail = substr( $bytes, -2048 );
		if ( false === strpos( $tail, '%%EOF' ) ) {
			throw new InvalidArgumentException( 'Renderer PDF output does not contain an EOF marker near the end of the file.' );
		}
		return array( 'mime' => 'application/pdf', 'pdf_version' => $match[1] );
	}

	/** @return array<string,mixed> */
	private function raster( $bytes, $mime, $image_type, $format ) {
		if ( 'png' === $format && 0 !== strncmp( $bytes, "\x89PNG\r\n\x1a\n", 8 ) ) {
			throw new InvalidArgumentException( 'Renderer output does not contain a valid PNG signature.' );
		}
		if ( 'jpeg' === $format && ( strlen( $bytes ) < 4 || "\xFF\xD8" !== substr( $bytes, 0, 2 ) || "\xFF\xD9" !== substr( $bytes, -2 ) ) ) {
			throw new InvalidArgumentException( 'Renderer output does not contain valid JPEG boundary markers.' );
		}
		$image = @getimagesizefromstring( $bytes );
		if ( ! is_array( $image ) || empty( $image[0] ) || empty( $image[1] ) || (int) $image[2] !== $image_type || (string) ( $image['mime'] ?? '' ) !== $mime ) {
			throw new InvalidArgumentException( 'Renderer raster output cannot be decoded as the requested image format.' );
		}
		return array( 'mime' => $mime, 'width_px' => (int) $image[0], 'height_px' => (int) $image[1] );
	}
}
