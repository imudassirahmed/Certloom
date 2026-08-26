<?php
namespace Certloom\Application\Rendering;

/**
 * Preview-only font contract used to let the browser consume the canonical
 * RenderPlan composition before PDF-04 exact font bytes are release-authorized.
 *
 * The hashes identify browser-preview variants, not font files. This manifest
 * must never be handed to ArtifactRenderCoordinator or an artifact renderer.
 */
final class BrowserPreviewFontManifest {
	/** @return array<string,mixed> */
	public function for_document( array $resolved_document ) {
		$families = array();
		foreach ( isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? $resolved_document['elements'] : array() as $element ) {
			if ( ! is_array( $element ) || 'text' !== (string) ( $element['type'] ?? '' ) ) { continue; }
			$style = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array();
			$family = strtolower( trim( (string) ( $style['font_family'] ?? 'sans-serif' ) ) );
			$weight = (int) ( $style['font_weight'] ?? 400 );
			$variant = $weight . '-' . ( ! empty( $style['italic'] ) ? 'italic' : 'normal' );
			$hash = hash( 'sha256', 'certloom-browser-preview-font-contract-v1|' . $family . '|' . $variant );
			$families[ $family ][ $variant ] = array(
				'faces' => array(
					array(
						'sha256' => $hash,
						'format' => 'ttf',
						'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ),
					),
				),
			);
		}
		if ( empty( $families ) ) {
			$families['sans-serif']['400-normal'] = array(
				'faces' => array( array( 'sha256' => hash( 'sha256', 'certloom-browser-preview-font-contract-v1|sans-serif|400-normal' ), 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ),
			);
		}
		return ( new FontManifest() )->normalize( array( 'schema' => FontManifest::SCHEMA, 'version' => FontManifest::VERSION, 'families' => $families ) );
	}
}
