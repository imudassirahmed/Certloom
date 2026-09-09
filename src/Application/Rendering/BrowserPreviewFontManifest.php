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
	private $catalog;

	public function __construct( ?DesignerFontCatalog $catalog = null ) { $this->catalog = $catalog ?: new DesignerFontCatalog(); }

	/** @return array<string,mixed> */
	public function for_document( array $resolved_document ) {
		$families = array();
		foreach ( isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? $resolved_document['elements'] : array() as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$type = (string) ( $element['type'] ?? '' );
			$styles = array();
			if ( 'text' === $type ) { $styles[] = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array(); }
			elseif ( 'rich_text' === $type ) {
				$base = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array();
				$styles[] = $base;
				foreach ( isset( $element['runs'] ) && is_array( $element['runs'] ) ? $element['runs'] : array() as $run ) { if ( is_array( $run ) ) { $styles[] = array_merge( $base, isset( $run['style'] ) && is_array( $run['style'] ) ? $run['style'] : array() ); } }
			} elseif ( 'table' === $type || 'data_table' === $type ) {
				$base = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array();
				$styles[] = $base;
				$header = $base;
				$header['font_weight'] = (int) ( $element['style']['header_font_weight'] ?? 600 );
				$styles[] = $header;
			}
			foreach ( $styles as $style ) {
				$family = $this->catalog->normalize_key( $style['font_family'] ?? 'sans-serif' );
				$weight = (int) ( $style['font_weight'] ?? 400 );
				$variant = $weight . '-' . ( ! empty( $style['italic'] ) ? 'italic' : 'normal' );
				$hash = hash( 'sha256', 'certloom-browser-preview-font-contract-v1|' . $family . '|' . $variant );
				$families[ $family ][ $variant ] = array(
					'faces' => array( array( 'sha256' => $hash, 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ),
				);
			}
		}
		if ( empty( $families ) ) {
			$families['sans-serif']['400-normal'] = array(
				'faces' => array( array( 'sha256' => hash( 'sha256', 'certloom-browser-preview-font-contract-v1|sans-serif|400-normal' ), 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ),
			);
		}
		return ( new FontManifest() )->normalize( array( 'schema' => FontManifest::SCHEMA, 'version' => FontManifest::VERSION, 'families' => $families ) );
	}
}
