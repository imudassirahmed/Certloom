<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Content-addressed, coverage-aware font manifest shared by every artifact renderer.
 *
 * A family/weight/style variant is an ordered stack of exact font faces. Each
 * face is bound by SHA-256 and declares the Unicode scalar ranges it is allowed
 * to satisfy. Production adapters may use only these declared faces; silent
 * system-font fallback or synthetic weight/style substitution is forbidden.
 */
final class FontManifest {
	const SCHEMA = 'certloom-font-manifest';
	const VERSION = 2;
	private $canonical;
	private $unicode;

	public function __construct( CanonicalJson $canonical = null, UnicodeCoverage $unicode = null ) {
		$this->canonical = $canonical ?: new CanonicalJson();
		$this->unicode = $unicode ?: new UnicodeCoverage();
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
				if ( array_diff( array_keys( $descriptor ), array( 'faces' ) ) || ! isset( $descriptor['faces'] ) || ! is_array( $descriptor['faces'] ) || empty( $descriptor['faces'] ) ) {
					throw new InvalidArgumentException( 'Font variant must contain a non-empty ordered face stack.' );
				}
				$faces = array();
				$seen_hashes = array();
				foreach ( array_values( $descriptor['faces'] ) as $face ) {
					if ( ! is_array( $face ) || array_diff( array_keys( $face ), array( 'sha256', 'format', 'coverage' ) ) || ! isset( $face['sha256'], $face['format'], $face['coverage'] ) || ! is_array( $face['coverage'] ) ) {
						throw new InvalidArgumentException( 'Font face descriptor is incomplete.' );
					}
					$sha256 = strtolower( trim( (string) $face['sha256'] ) );
					$format = strtolower( trim( (string) $face['format'] ) );
					if ( ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) || ! in_array( $format, array( 'ttf', 'otf' ), true ) ) {
						throw new InvalidArgumentException( 'Font face must identify TTF/OTF bytes by SHA-256.' );
					}
					if ( isset( $seen_hashes[ $sha256 ] ) ) {
						throw new InvalidArgumentException( 'A font face may appear only once in a variant fallback stack.' );
					}
					$seen_hashes[ $sha256 ] = true;
					$faces[] = array(
						'sha256' => $sha256,
						'format' => $format,
						'coverage' => $this->unicode->normalize_ranges( $face['coverage'] ),
					);
				}
				$normalized_variants[ $variant ] = array( 'faces' => $faces );
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
			if ( '' === $family || empty( $manifest['families'][ $family ][ $variant ]['faces'] ) ) {
				throw new InvalidArgumentException( 'Renderer font manifest does not provide the exact family/weight/style required by the resolved document.' );
			}
			$this->unicode->assert_text_covered( (string) ( $element['text'] ?? '' ), $manifest['families'][ $family ][ $variant ]['faces'] );
		}
	}

	/**
	 * Returns the exact font faces a resolved document can require.
	 *
	 * Paths are deliberately absent: SHA-256 + format are portable identity.
	 * The ArtifactRenderCoordinator resolves these identities to verified local
	 * files only after the render-request fingerprint has been computed.
	 *
	 * @return array<int,array{sha256:string,format:string}>
	 */
	public function required_faces( array $resolved_document, array $manifest ) {
		$manifest = $this->normalize( $manifest );
		$this->assert_document_supported( $resolved_document, $manifest );
		$required = array();
		foreach ( isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? $resolved_document['elements'] : array() as $element ) {
			if ( ! is_array( $element ) || 'text' !== (string) ( $element['type'] ?? '' ) ) { continue; }
			$style = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array();
			$family = strtolower( trim( (string) ( $style['font_family'] ?? '' ) ) );
			$weight = (int) ( $style['font_weight'] ?? 400 );
			$variant = $weight . '-' . ( ! empty( $style['italic'] ) ? 'italic' : 'normal' );
			foreach ( (array) $manifest['families'][ $family ][ $variant ]['faces'] as $face ) {
				$key = (string) $face['sha256'];
				$required[ $key ] = array( 'sha256' => $key, 'format' => (string) $face['format'] );
			}
		}
		ksort( $required, SORT_STRING );
		return array_values( $required );
	}

	/** @return bool */
	public function document_uses_text( array $resolved_document ) {
		foreach ( isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? $resolved_document['elements'] : array() as $element ) {
			if ( is_array( $element ) && 'text' === (string) ( $element['type'] ?? '' ) ) { return true; }
		}
		return false;
	}
}
