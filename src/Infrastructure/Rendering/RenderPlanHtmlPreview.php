<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Rendering\DesignerFontCatalog;
use InvalidArgumentException;

/**
 * Browser presenter for a canonical Certloom Render Plan.
 *
 * It does not read canonical layouts and therefore cannot create a competing
 * geometry/QR interpretation. Exact PDF/PNG/JPEG typography remains governed
 * by accepted artifact renderers; this presenter is the administration preview.
 */
final class RenderPlanHtmlPreview {
	private $fonts;

	public function __construct( ?DesignerFontCatalog $fonts = null ) { $this->fonts = $fonts ?: new DesignerFontCatalog(); }

	const PX_PER_MM = 2.6;
	const MAX_INLINE_ASSET_BYTES = 2097152;

	/** @return array{html:string,warnings:string[]} */
	public function present( array $plan ) {
		if ( ! isset( $plan['_meta']['schema'], $plan['_meta']['version'], $plan['page'], $plan['operations'] ) || RenderPlanBuilder::SCHEMA !== (string) $plan['_meta']['schema'] || RenderPlanBuilder::VERSION !== (int) $plan['_meta']['version'] || ! is_array( $plan['page'] ) || ! is_array( $plan['operations'] ) ) {
			throw new InvalidArgumentException( 'Template Preview requires certloom-render-plan v1.' );
		}
		$width_mm = (float) ( $plan['page']['width_mm'] ?? 0 );
		$height_mm = (float) ( $plan['page']['height_mm'] ?? 0 );
		if ( $width_mm <= 0 || $height_mm <= 0 ) { throw new InvalidArgumentException( 'Template Preview page geometry is invalid.' ); }
		$width_px = $this->px( $width_mm );
		$height_px = $this->px( $height_mm );
		$warnings = array();
		$html = '<div class="certloom-template-preview-scroll" tabindex="0" data-certloom-preview-width="' . esc_attr( $this->num( $width_px ) ) . '" data-certloom-preview-height="' . esc_attr( $this->num( $height_px ) ) . '"><div class="certloom-template-preview-page" style="width:' . esc_attr( $this->num( $width_px ) ) . 'px;height:' . esc_attr( $this->num( $height_px ) ) . 'px" role="img" aria-label="' . esc_attr__( 'Template composition preview', 'certloom' ) . '">';
		foreach ( $plan['operations'] as $operation ) {
			if ( ! is_array( $operation ) ) { continue; }
			$html .= $this->operation( $plan, $operation, $warnings );
		}
		$html .= '</div></div>';
		return array( 'html' => $html, 'warnings' => array_values( array_unique( $warnings ) ) );
	}

	/** @return string */
	private function operation( array $plan, array $operation, array &$warnings ) {
		$type = (string) ( $operation['type'] ?? '' );
		$geometry = isset( $operation['geometry'] ) && is_array( $operation['geometry'] ) ? $operation['geometry'] : array();
		$box = isset( $geometry['box'] ) && is_array( $geometry['box'] ) ? $geometry['box'] : array();
		if ( ! isset( $box['x_mm'], $box['y_mm'], $box['width_mm'], $box['height_mm'] ) ) { return ''; }
		$style = $this->box_style( $operation, $box );
		$classes = 'certloom-template-preview-op certloom-template-preview-op-' . sanitize_html_class( $type );
		switch ( $type ) {
			case 'text':
				return '<div class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style . $this->text_style( (array) ( $operation['text'] ?? array() ) ) ) . '"><span>' . esc_html( (string) ( $operation['text']['value'] ?? '' ) ) . '</span></div>';
			case 'shape':
				$shape = (array) ( $operation['shape'] ?? array() );
				$fill = $this->css_color( (array) ( $shape['fill'] ?? array() ) );
				$stroke = $this->css_color( (array) ( $shape['stroke'] ?? array() ) );
				$border = $this->px( (float) ( $shape['stroke_width_mm'] ?? 0 ) );
				$radius = 'ellipse' === (string) ( $shape['kind'] ?? '' ) ? '50%' : '0';
				return '<div aria-hidden="true" class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style . 'background:' . $fill . ';border:' . $this->num( $border ) . 'px solid ' . $stroke . ';border-radius:' . $radius . ';box-sizing:border-box;' ) . '"></div>';
			case 'line':
				return $this->line( $operation, $style, $classes );
			case 'qr':
				return $this->machine_code( $operation, 'qr', $style, $classes );
			case 'barcode':
				return $this->machine_code( $operation, 'barcode', $style, $classes );
			case 'image':
			case 'logo':
			case 'signature':
				return $this->image( $plan, $operation, $style, $classes, $warnings );
		}
		return '';
	}

	/** @return string */
	private function box_style( array $operation, array $box ) {
		$rotation = (float) ( $operation['geometry']['rotation_deg'] ?? 0 );
		$opacity = max( 0.0, min( 1.0, (float) ( $operation['opacity'] ?? 1 ) ) );
		return 'left:' . $this->num( $this->px( (float) $box['x_mm'] ) ) . 'px;top:' . $this->num( $this->px( (float) $box['y_mm'] ) ) . 'px;width:' . $this->num( $this->px( (float) $box['width_mm'] ) ) . 'px;height:' . $this->num( $this->px( (float) $box['height_mm'] ) ) . 'px;z-index:' . (int) ( $operation['z'] ?? 0 ) . ';opacity:' . $this->num( $opacity ) . ';transform:rotate(' . $this->num( $rotation ) . 'deg);transform-origin:center center;';
	}

	/** @return string */
	private function text_style( array $text ) {
		$font = isset( $text['font'] ) && is_array( $text['font'] ) ? $text['font'] : array();
		$vertical = (string) ( $text['vertical_align'] ?? 'middle' );
		$justify = 'top' === $vertical ? 'flex-start' : ( 'bottom' === $vertical ? 'flex-end' : 'center' );
		$size_mm = (float) ( $font['size_mm'] ?? 0 );
		$letter_mm = (float) ( $font['letter_spacing_mm'] ?? 0 );
		$family = (string) ( $font['family'] ?? 'sans-serif' );
		$family_css = $this->fonts->browser_css( $family );
		return 'display:flex;flex-direction:column;justify-content:' . $justify . ';overflow:hidden;box-sizing:border-box;text-align:' . (string) ( $text['align'] ?? 'center' ) . ';direction:' . (string) ( $text['direction'] ?? 'ltr' ) . ';font-family:' . $family_css . ';font-size:' . $this->num( $this->px( $size_mm ) ) . 'px;font-weight:' . (int) ( $font['weight'] ?? 400 ) . ';font-style:' . ( ! empty( $font['italic'] ) ? 'italic' : 'normal' ) . ';line-height:' . $this->num( (float) ( $font['line_height'] ?? 1.2 ) ) . ';letter-spacing:' . $this->num( $this->px( $letter_mm ) ) . 'px;color:' . $this->css_color( (array) ( $text['color'] ?? array() ) ) . ';unicode-bidi:plaintext;';
	}

	/** @return string */
	private function line( array $operation, $style, $classes ) {
		$line = (array) ( $operation['line'] ?? array() );
		$box = (array) ( $operation['geometry']['box'] ?? array() );
		$w = max( 0.001, (float) ( $box['width_mm'] ?? 0.001 ) );
		$h = max( 0.001, (float) ( $box['height_mm'] ?? 0.001 ) );
		$stroke = $this->css_color( (array) ( $line['stroke'] ?? array() ) );
		$stroke_px = max( 0.5, $this->px( (float) ( $line['stroke_width_mm'] ?? 0.3 ) ) );
		return '<svg aria-hidden="true" class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '" viewBox="0 0 ' . esc_attr( $this->num( $w ) ) . ' ' . esc_attr( $this->num( $h ) ) . '" preserveAspectRatio="none"><line x1="0" y1="0" x2="' . esc_attr( $this->num( $w ) ) . '" y2="' . esc_attr( $this->num( $h ) ) . '" stroke="' . esc_attr( $stroke ) . '" stroke-width="' . esc_attr( $this->num( $stroke_px / self::PX_PER_MM ) ) . '"/></svg>';
	}

	/** @return string */
	private function machine_code( array $operation, $payload_key, $style, $classes ) {
		$payload = isset( $operation[ $payload_key ] ) && is_array( $operation[ $payload_key ] ) ? $operation[ $payload_key ] : array();
		$box = (array) ( $operation['geometry']['box'] ?? array() );
		$x0 = (float) ( $box['x_mm'] ?? 0 );
		$y0 = (float) ( $box['y_mm'] ?? 0 );
		$w = max( 0.001, (float) ( $box['width_mm'] ?? 0.001 ) );
		$h = max( 0.001, (float) ( $box['height_mm'] ?? 0.001 ) );
		$rects = '';
		foreach ( isset( $payload['dark_runs'] ) && is_array( $payload['dark_runs'] ) ? $payload['dark_runs'] : array() as $run ) {
			if ( ! is_array( $run ) || ! isset( $run['x_mm'], $run['y_mm'], $run['width_mm'], $run['height_mm'] ) ) { continue; }
			$rects .= '<rect x="' . esc_attr( $this->num( (float) $run['x_mm'] - $x0 ) ) . '" y="' . esc_attr( $this->num( (float) $run['y_mm'] - $y0 ) ) . '" width="' . esc_attr( $this->num( (float) $run['width_mm'] ) ) . '" height="' . esc_attr( $this->num( (float) $run['height_mm'] ) ) . '"/>';
		}
		return '<svg aria-hidden="true" class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style . 'background:#fff;' ) . '" viewBox="0 0 ' . esc_attr( $this->num( $w ) ) . ' ' . esc_attr( $this->num( $h ) ) . '" shape-rendering="crispEdges"><rect x="0" y="0" width="' . esc_attr( $this->num( $w ) ) . '" height="' . esc_attr( $this->num( $h ) ) . '" fill="#fff"/><g fill="#111">' . $rects . '</g></svg>';
	}

	/** @return string */
	private function image( array $plan, array $operation, $style, $classes, array &$warnings ) {
		$image = (array) ( $operation['image'] ?? array() );
		$asset = (array) ( $image['asset'] ?? array() );
		$hash = strtolower( (string) ( $asset['sha256'] ?? '' ) );
		$path = isset( $plan['_runtime']['assets'][ $hash ]['path'] ) ? (string) $plan['_runtime']['assets'][ $hash ]['path'] : '';
		$size = (int) ( $asset['size'] ?? 0 );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || $size < 1 || $size > self::MAX_INLINE_ASSET_BYTES ) {
			$warnings[] = $size > self::MAX_INLINE_ASSET_BYTES ? __( 'A local image is larger than the 2 MiB inline browser-preview limit; artifact rendering remains unaffected.', 'certloom' ) : __( 'A local image could not be embedded in the browser preview; artifact rendering remains fail-closed.', 'certloom' );
			return '<div class="' . esc_attr( $classes . ' certloom-template-preview-asset-placeholder' ) . '" style="' . esc_attr( $style ) . '"><span>' . esc_html__( 'Local image', 'certloom' ) . '</span></div>';
		}
		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $size ) {
			$warnings[] = __( 'A local image could not be read completely for browser Preview.', 'certloom' );
			return '<div class="' . esc_attr( $classes . ' certloom-template-preview-asset-placeholder' ) . '" style="' . esc_attr( $style ) . '"><span>' . esc_html__( 'Local image', 'certloom' ) . '</span></div>';
		}
		$mime = (string) ( $asset['mime'] ?? '' );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) { return ''; }
		$fit = (string) ( $image['placement']['mode'] ?? 'contain' );
		return '<div class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style . 'overflow:hidden;' ) . '"><img alt="" src="' . esc_attr( 'data:' . $mime . ';base64,' . base64_encode( $bytes ) ) . '" style="width:100%;height:100%;object-fit:' . esc_attr( $fit ) . ';display:block"></div>';
	}

	/** @return string */
	private function css_color( array $color ) {
		$hex = strtoupper( trim( (string) ( $color['hex'] ?? '#000000' ) ) );
		return preg_match( '/^#[0-9A-F]{6}(?:[0-9A-F]{2})?$/', $hex ) ? $hex : '#000000';
	}

	/** @return float */
	private function px( $mm ) { return (float) $mm * self::PX_PER_MM; }

	/** @return string */
	private function num( $value ) {
		$value = round( (float) $value, 4 );
		$text = number_format( $value, 4, '.', '' );
		return rtrim( rtrim( $text, '0' ), '.' );
	}
}
