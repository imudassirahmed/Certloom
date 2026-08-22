<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Content-addressed font manifest shared by every artifact renderer.
 *
 * The layout stores stable family keys. A production renderer must bind those
 * keys to exact font-byte hashes so PDF/image output cannot silently use
 * different fonts or synthesize missing weight/style variants.
 */
final class FontManifest {
	const SCHEMA = 'certloom-font-manifest';
	const VERSION = 1;
	private $canonical;

	public function __construct( CanonicalJson $canonical = null ) {
		$this->canonical = $canonical ?: new CanonicalJson();
	}

	/** @return array<string,mixed> */
	public function normalize( array $manifest ) {
		$allowed = array( 'schema', 'version', 'families' );
		if ( array_diff( array_keys( $manifest ), $allowed ) || ! isset( $manifest['schema'], $manifest['version'], $manifest['families'] ) ) {
			throw new InvalidArgumentException( 'Font manifest has an invalid top-level shape.' );
		}
		if ( self::SCHEMA !== (string) $manifest['schema'] || ! is_int( $manifest['version'] ) || self::VERSION !== $manifest['version'] || ! is_array( $manifest['families'] ) || empty( $manifest['families'] ) ) {
			throw new InvalidArgumentException( 'Font manifest schema/version/families are invalid.' );
		}

		$families = array();
		foreach ( $manifest['families'] as $family => $variants ) {
			$family = strtolower( trim( (string) $family ) );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $family ) || ! is_array( $variants ) || empty( $variants ) ) {
				throw new InvalidArgumentException( 'Font manifest contains an invalid family or empty variant set.' );
			}
			$normalized_variants = array();
			foreach ( $variants as $variant => $descriptor ) {
				$variant = strtolower( trim( (string) $variant ) );
				if ( ! preg_match( '/^(?:[1-9]00)-(?:normal|italic)$/', $variant ) || ! is_array( $descriptor ) ) {
					throw new InvalidArgumentException( 'Font manifest contains an invalid weight/style variant.' );
				}
				$keys = array_keys( $descriptor );
				if ( array_diff( $keys, array( 'sha256', 'format' ) ) || ! isset( $descriptor['sha256'], $descriptor['format'] ) ) {
					throw new InvalidArgumentException( 'Font variant descriptor is incomplete.' );
				}
				$sha256 = strtolower( trim( (string) $descriptor['sha256'] ) );
				$format = strtolower( trim( (string) $descriptor['format'] ) );
				if ( ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) || ! in_array( $format, array( 'ttf', 'otf' ), true ) ) {
					throw new InvalidArgumentException( 'Font variant must identify TTF/OTF bytes by SHA-256.' );
				}
				$normalized_variants[ $variant ] = array( 'sha256' => $sha256, 'format' => $format );
			}
			ksort( $normalized_variants, SORT_STRING );
			$families[ $family ] = $normalized_variants;
		}
		ksort( $families, SORT_STRING );
		return array( 'schema' => self::SCHEMA, 'version' => self::VERSION, 'families' => $families );
	}

	/** @return string */
	public function hash( array $manifest ) {
		return $this->canonical->hash( $this->normalize( $manifest ) );
	}

	/** @return void */
	public function assert_document_supported( array $resolved_document, array $manifest ) {
		$manifest = $this->normalize( $manifest );
		foreach ( isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? $resolved_document['elements'] : array() as $element ) {
			if ( ! is_array( $element ) || 'text' !== (string) ( $element['type'] ?? '' ) ) { continue; }
			$style = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array();
			$family = strtolower( trim( (string) ( $style['font_family'] ?? '' ) ) );
			$weight = (int) ( $style['font_weight'] ?? 400 );
			$variant = $weight . '-' . ( ! empty( $style['italic'] ) ? 'italic' : 'normal' );
			if ( '' === $family || empty( $manifest['families'][ $family ][ $variant ] ) ) {
				throw new InvalidArgumentException( 'Renderer font manifest does not provide the exact family/weight/style required by the resolved document.' );
			}
		}
	}

	/** @return bool */
	public function document_uses_text( array $resolved_document ) {
		foreach ( isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? $resolved_document['elements'] : array() as $element ) {
			if ( is_array( $element ) && 'text' === (string) ( $element['type'] ?? '' ) ) { return true; }
		}
		return false;
	}
}
