<?php
namespace Certloom\Infrastructure\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\GdDriverInterface;
use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\ArabicPresentationShaper;
use InvalidArgumentException;
use RuntimeException;

/**
 * First concrete Certloom PNG/JPEG PDF-04 candidate using ext-gd at 96 DPI.
 *
 * The candidate is deliberately bounded and fail-closed. It uses only exact
 * runtime fonts/assets supplied by ArtifactRenderCoordinator; no system font
 * discovery, network fetch, or external process is permitted.
 */
final class GdRasterRenderer implements RendererInterface {
	const ID = 'certloom-gd-raster';
	const DPI = 96.0;
	private $version;
	private $candidate_hash;
	private $font_manifest;
	private $driver;
	private $shaper;
	const ROTATED_PRIMITIVE_SUPERSAMPLE = 4.0;

	/** Internal scale for an isolated operation canvas; the final page remains 96 DPI. */
	private $pixel_scale = 1.0;

	public function __construct( $version, $candidate_manifest_sha256, array $font_manifest, ?GdDriverInterface $driver = null, ?ArabicPresentationShaper $shaper = null ) {
		$version = trim( (string) $version );
		$candidate_manifest_sha256 = strtolower( trim( (string) $candidate_manifest_sha256 ) );
		if ( '' === $version || strlen( $version ) > 64 || ! preg_match( '/^[0-9a-f]{64}$/', $candidate_manifest_sha256 ) ) {
			throw new InvalidArgumentException( 'GD candidate requires a bounded version and canonical candidate-manifest SHA-256.' );
		}
		$this->version = $version;
		$this->candidate_hash = $candidate_manifest_sha256;
		$this->font_manifest = $font_manifest;
		$this->driver = $driver ?: new NativeGdDriver();
		$this->shaper = $shaper ?: new ArabicPresentationShaper();
	}

	public function id() { return self::ID; }
	public function version() { return $this->version; }
	public function formats() { return array( 'png', 'jpeg' ); }
	public function requirements() {
		return array(
			'php_min' => '7.4',
			'extensions' => array( 'gd' ),
			'classes' => array(),
			'functions' => array( 'imagettfbbox', 'imagettftext' ),
		);
	}
	public function context() {
		return array(
			'candidate_manifest_sha256' => $this->candidate_hash,
			'font_manifest' => $this->font_manifest,
			'adapter' => 'certloom-gd-render-plan-v1',
			'input_schema' => 'certloom-render-plan:1',
			'raster_dpi' => self::DPI,
			'jpeg' => array( 'quality' => 95, 'progressive' => false ),
			'network' => 'none',
			'remote_assets' => false,
		);
	}

	public function render( array $render_plan, $format ) {
		$format = strtolower( trim( (string) $format ) );
		if ( ! in_array( $format, array( 'png', 'jpeg' ), true ) ) { throw new InvalidArgumentException( 'GD candidate owns PNG/JPEG only.' ); }
		$this->assert_plan( $render_plan );
		$width = $this->mm_px( $render_plan['page']['width_mm'] );
		$height = $this->mm_px( $render_plan['page']['height_mm'] );
		$canvas = $this->driver->create_canvas( $width, $height );
		try {
			foreach ( $render_plan['operations'] as $operation ) { $this->draw_operation( $canvas, $render_plan, $operation ); }
			return $this->driver->encode( $canvas, $format );
		} finally { $this->driver->destroy( $canvas ); }
	}

	private function assert_plan( array $plan ) {
		if ( ! isset( $plan['_meta']['schema'], $plan['_meta']['version'], $plan['page']['width_mm'], $plan['page']['height_mm'], $plan['operations'] ) || 'certloom-render-plan' !== $plan['_meta']['schema'] || 1 !== $plan['_meta']['version'] || ! is_array( $plan['operations'] ) ) {
			throw new InvalidArgumentException( 'GD candidate requires certloom-render-plan v1.' );
		}
	}

	private function draw_operation( $canvas, array $plan, array $op ) {
		$rotation = (float) ( $op['geometry']['rotation_deg'] ?? 0.0 );
		$type = (string) ( $op['type'] ?? '' );
		if ( abs( $rotation ) < 0.0001 && ! in_array( $type, array( 'rich_text', 'table' ), true ) ) { $this->draw_unrotated( $canvas, $plan, $op, 0.0, 0.0 ); return; }
		$box = $op['geometry']['box'];
		$supersample = abs( $rotation ) >= 0.0001 && in_array( $type, array( 'shape', 'image', 'logo', 'signature' ), true ) ? self::ROTATED_PRIMITIVE_SUPERSAMPLE : 1.0;
		$previous_scale = $this->pixel_scale;
		$this->pixel_scale = $supersample;
		$local = null; $rotated = null;
		try {
			$local_w = max( 1, $this->mm_px( $box['width_mm'] ) );
			$local_h = max( 1, $this->mm_px( $box['height_mm'] ) );
			$local = $this->driver->create_transparent_canvas( $local_w, $local_h );
			$this->draw_unrotated( $local, $plan, $op, -1.0 * (float) $box['x_mm'], -1.0 * (float) $box['y_mm'] );
			if ( abs( $rotation ) < 0.0001 ) {
				$this->pixel_scale = $previous_scale;
				$this->driver->copy( $canvas, $local, (int) round( $this->mm_px_float( $box['x_mm'] ) ), (int) round( $this->mm_px_float( $box['y_mm'] ) ) );
				return;
			}
			$rotated = $this->driver->rotate( $local, $rotation );
		} finally {
			$this->pixel_scale = $previous_scale;
			if ( null !== $local ) { $this->driver->destroy( $local ); }
		}
		try {
			list( $hi_w, $hi_h ) = $this->driver->image_size( $rotated );
			if ( $supersample <= 1.0001 ) {
				$cx = $this->mm_px_float( $op['geometry']['center']['x_mm'] );
				$cy = $this->mm_px_float( $op['geometry']['center']['y_mm'] );
				$this->driver->copy( $canvas, $rotated, (int) round( $cx - $hi_w / 2.0 ), (int) round( $cy - $hi_h / 2.0 ) );
				return;
			}
			$target_w = max( 1, (int) round( $hi_w / $supersample ) );
			$target_h = max( 1, (int) round( $hi_h / $supersample ) );
			$down = $this->driver->create_transparent_canvas( $target_w, $target_h );
			try {
				$this->driver->copy_resampled( $down, $rotated, 0, 0, $target_w, $target_h, 0, 0, $hi_w, $hi_h );
				$cx = $this->mm_px_float( $op['geometry']['center']['x_mm'] );
				$cy = $this->mm_px_float( $op['geometry']['center']['y_mm'] );
				$this->driver->copy( $canvas, $down, (int) round( $cx - $target_w / 2.0 ), (int) round( $cy - $target_h / 2.0 ) );
			} finally { $this->driver->destroy( $down ); }
		} finally { if ( null !== $rotated ) { $this->driver->destroy( $rotated ); } }
	}

	private function draw_unrotated( $canvas, array $plan, array $op, $offset_x_mm, $offset_y_mm ) {
		switch ( (string) $op['type'] ) {
			case 'text': $this->draw_text( $canvas, $plan, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'rich_text': $this->draw_rich_text( $canvas, $plan, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'table': $this->draw_table( $canvas, $plan, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'qr': $this->draw_machine_code( $canvas, $op, 'qr', $offset_x_mm, $offset_y_mm ); break;
			case 'barcode': $this->draw_machine_code( $canvas, $op, 'barcode', $offset_x_mm, $offset_y_mm ); break;
			case 'shape': $this->draw_shape( $canvas, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'line': $this->draw_line( $canvas, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'image': case 'logo': case 'signature': $this->draw_image( $canvas, $plan, $op, $offset_x_mm, $offset_y_mm ); break;
			default: throw new RuntimeException( 'GD candidate received an unsupported Render Plan operation.' );
		}
	}

	private function draw_text( $canvas, array $plan, array $op, $ox, $oy ) {
		$text = $op['text']; $font = $text['font'];
		$faces = (array) $font['faces'];
		if ( 1 !== count( $faces ) ) { throw new RuntimeException( 'Initial GD candidate requires one exact proven face per text operation; multi-face fallback awaits shaped font runs.' ); }
		$hash = strtolower( (string) $faces[0]['sha256'] );
		$font_path = $plan['_runtime']['fonts'][ $hash ]['path'] ?? '';
		if ( '' === $font_path || ! is_file( $font_path ) ) { throw new RuntimeException( 'GD candidate exact runtime font path is unavailable.' ); }
		$this->assert_supported_text( (string) $text['value'] );
		$box = $op['geometry']['box'];
		$x = $this->mm_px_float( $box['x_mm'] + $ox ); $y = $this->mm_px_float( $box['y_mm'] + $oy );
		$w = $this->mm_px_float( $box['width_mm'] ); $h = $this->mm_px_float( $box['height_mm'] );
		$size = (float) $font['size_pt'];
		$spacing_pt = (float) ( $font['letter_spacing_pt'] ?? 0.0 );
		$lines = $this->layout_lines( (string) $text['value'], (string) $text['direction'], (string) $text['overflow'], $font_path, $size, $w, $h, (float) $font['line_height'], $spacing_pt );
		$size = $lines['size_pt']; $rows = $lines['lines'];
		$line_px = $size * self::DPI / 72.0 * (float) $font['line_height'];
		$total_h = count( $rows ) * $line_px;
		$top = $y;
		if ( 'middle' === $text['vertical_align'] ) { $top = $y + ( $h - $total_h ) / 2.0; }
		elseif ( 'bottom' === $text['vertical_align'] ) { $top = $y + $h - $total_h; }
		$color = $this->driver->allocate_color( $canvas, $text['color'], (float) $op['opacity'] );
		foreach ( $rows as $index => $row ) {
			$visual = $this->visual_text( $row, (string) $text['direction'] );
			$metrics = $this->text_metrics( $visual, $font_path, $size, $spacing_pt );
			$draw_x = $x - $metrics['min_x'];
			if ( 'center' === $text['align'] ) { $draw_x = $x + ( $w - $metrics['width'] ) / 2.0 - $metrics['min_x']; }
			elseif ( 'right' === $text['align'] ) { $draw_x = $x + $w - $metrics['width'] - $metrics['min_x']; }
			$line_top = $top + $index * $line_px;
			$baseline = $line_top - $metrics['min_y'] + max( 0.0, ( $line_px - $metrics['height'] ) / 2.0 );
			$this->draw_visual_text( $canvas, $size, $draw_x, $baseline, $color, $font_path, $visual, $spacing_pt );
		}
	}


	private function draw_rich_text( $canvas, array $plan, array $op, $ox, $oy ) {
		$rich = isset( $op['rich_text'] ) && is_array( $op['rich_text'] ) ? $op['rich_text'] : array();
		$runs = isset( $rich['runs'] ) && is_array( $rich['runs'] ) ? array_values( $rich['runs'] ) : array();
		if ( empty( $runs ) ) { return; }
		$prepared = array();
		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) ) { continue; }
			$font = (array) ( $run['font'] ?? array() );
			$faces = isset( $font['faces'] ) && is_array( $font['faces'] ) ? array_values( $font['faces'] ) : array();
			if ( 1 !== count( $faces ) ) { throw new RuntimeException( 'GD rich-text parity requires one exact proven face per inline run.' ); }
			$hash = strtolower( (string) ( $faces[0]['sha256'] ?? '' ) );
			$path = $plan['_runtime']['fonts'][ $hash ]['path'] ?? '';
			if ( '' === $path || ! is_file( $path ) ) { throw new RuntimeException( 'GD rich-text parity is missing an exact runtime font path.' ); }
			$value = (string) ( $run['value'] ?? '' );
			$this->assert_supported_text( $value );
			$prepared[] = array( 'value' => $value, 'font' => $font, 'path' => $path, 'color' => (array) ( $run['color'] ?? array() ), 'spacing_pt' => (float) ( $font['letter_spacing_pt'] ?? 0.0 ) );
		}
		if ( empty( $prepared ) ) { return; }
		$box = (array) ( $op['geometry']['box'] ?? array() );
		$x = $this->mm_px_float( (float) $box['x_mm'] + $ox ); $y = $this->mm_px_float( (float) $box['y_mm'] + $oy );
		$w = $this->mm_px_float( (float) $box['width_mm'] ); $h = $this->mm_px_float( (float) $box['height_mm'] );
		$direction = (string) ( $rich['direction'] ?? 'ltr' ); $overflow = (string) ( $rich['overflow'] ?? 'shrink' );
		$base_font = (array) ( $rich['base_font'] ?? array() ); $line_height = (float) ( $base_font['line_height'] ?? 1.2 );
		$scale = 1.0; $layout = $this->rich_layout( $prepared, $direction, $overflow, $scale, $w, $line_height );
		if ( 'shrink' === $overflow ) {
			$base_size = max( 1.0, (float) ( $base_font['size_pt'] ?? 12.0 ) ); $min_scale = min( 1.0, 5.0 / $base_size );
			while ( $scale > $min_scale + 0.0001 && ( $layout['max_width'] > $w + 0.01 || $layout['height'] > $h + 0.01 ) ) {
				$scale = max( $min_scale, $scale - 0.02 ); $layout = $this->rich_layout( $prepared, $direction, $overflow, $scale, $w, $line_height );
			}
		}
		$total_h = (float) $layout['height']; $top = $y;
		if ( 'middle' === (string) ( $rich['vertical_align'] ?? 'middle' ) ) { $top = $y + ( $h - $total_h ) / 2.0; }
		elseif ( 'bottom' === (string) ( $rich['vertical_align'] ?? 'middle' ) ) { $top = $y + $h - $total_h; }
		$line_top = $top;
		foreach ( $layout['lines'] as $line ) {
			$line_w = (float) $line['width']; $cursor = $x;
			if ( 'center' === (string) ( $rich['align'] ?? 'center' ) ) { $cursor = $x + ( $w - $line_w ) / 2.0; }
			elseif ( 'right' === (string) ( $rich['align'] ?? 'center' ) ) { $cursor = $x + $w - $line_w; }
			$fragments = 'rtl' === $direction ? array_reverse( $line['fragments'] ) : $line['fragments'];
			foreach ( $fragments as $fragment ) {
				$size = (float) $fragment['size_pt']; $visual = (string) $fragment['visual']; $metrics = $fragment['metrics'];
				$baseline = $line_top - (float) $metrics['min_y'] + max( 0.0, ( (float) $line['height'] - (float) $metrics['height'] ) / 2.0 );
				$color = $this->driver->allocate_color( $canvas, (array) $fragment['color'], (float) $op['opacity'] );
				$this->draw_visual_text( $canvas, $size, $cursor - (float) $metrics['min_x'], $baseline, $color, (string) $fragment['path'], $visual, (float) ( $fragment['spacing_pt'] ?? 0.0 ) );
				$cursor += (float) $fragment['width'];
			}
			$line_top += (float) $line['height'];
			if ( $line_top > $y + $h + 0.01 ) { break; }
		}
	}

	/** @return array{lines:array<int,array<string,mixed>>,height:float,max_width:float} */
	private function rich_layout( array $runs, $direction, $overflow, $scale, $width_px, $line_height ) {
		$lines = array(); $current = array(); $current_width = 0.0; $current_height = 0.0; $max_width = 0.0;
		$flush = function () use ( &$lines, &$current, &$current_width, &$current_height, &$max_width ) {
			$height = max( 1.0, $current_height ); $lines[] = array( 'fragments' => $current, 'width' => $current_width, 'height' => $height ); $max_width = max( $max_width, $current_width ); $current = array(); $current_width = 0.0; $current_height = 0.0;
		};
		foreach ( $runs as $run ) {
			$parts = preg_split( '/(\\R|[ \\t]+)/u', (string) $run['value'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			if ( false === $parts ) { $parts = array( (string) $run['value'] ); }
			foreach ( $parts as $part ) {
				if ( 1 === preg_match( '/^\\R$/u', $part ) ) { $flush(); continue; }
				$size = max( 1.0, (float) ( $run['font']['size_pt'] ?? 12.0 ) * $scale );
				$visual = $this->visual_text( $part, $direction ); $spacing_pt = (float) ( $run['spacing_pt'] ?? 0.0 ) * $scale; $metrics = $this->text_metrics( $visual, (string) $run['path'], $size, $spacing_pt ); $fragment_width = (float) $metrics['width'];
				$is_space = 1 === preg_match( '/^[ \\t]+$/u', $part );
				if ( 'wrap' === $overflow && ! empty( $current ) && ! $is_space && $current_width + $fragment_width > $width_px + 0.01 ) { $flush(); }
				if ( 'wrap' === $overflow && empty( $current ) && $is_space ) { continue; }
				$fragment_height = max( 1.0, $size * self::DPI / 72.0 * $line_height );
				$current[] = array( 'visual' => $visual, 'path' => $run['path'], 'size_pt' => $size, 'color' => $run['color'], 'metrics' => $metrics, 'width' => $fragment_width, 'spacing_pt' => $spacing_pt );
				$current_width += $fragment_width; $current_height = max( $current_height, $fragment_height );
			}
		}
		if ( ! empty( $current ) || empty( $lines ) ) { $flush(); }
		$height = 0.0; foreach ( $lines as $line ) { $height += (float) $line['height']; }
		return array( 'lines' => $lines, 'height' => $height, 'max_width' => $max_width );
	}

	private function draw_table( $canvas, array $plan, array $op, $ox, $oy ) {
		$table = isset( $op['table'] ) && is_array( $op['table'] ) ? $op['table'] : array(); $outer = (array) ( $op['geometry']['box'] ?? array() );
		$grid = isset( $table['grid'] ) && is_array( $table['grid'] ) ? $table['grid'] : array(); $border = (array) ( $grid['border_color'] ?? array() ); $thickness = max( 1, (int) round( $this->mm_px_float( (float) ( $grid['border_width_mm'] ?? 0 ) ) ) );
		foreach ( (array) ( $table['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; } $fill = (array) ( $row['fill'] ?? array() );
			foreach ( (array) ( $row['cells'] ?? array() ) as $cell ) {
				if ( ! is_array( $cell ) ) { continue; } $cell_box = (array) ( $cell['box'] ?? array() ); $content = (array) ( $cell['content_box'] ?? array() );
				$x1 = $this->mm_px_float( (float) $outer['x_mm'] + (float) ( $cell_box['x_mm'] ?? 0 ) + $ox ); $y1 = $this->mm_px_float( (float) $outer['y_mm'] + (float) ( $cell_box['y_mm'] ?? 0 ) + $oy );
				$x2 = $this->mm_px_float( (float) $outer['x_mm'] + (float) ( $cell_box['x_mm'] ?? 0 ) + (float) ( $cell_box['width_mm'] ?? 0 ) + $ox ); $y2 = $this->mm_px_float( (float) $outer['y_mm'] + (float) ( $cell_box['y_mm'] ?? 0 ) + (float) ( $cell_box['height_mm'] ?? 0 ) + $oy );
				$fill_color = $this->driver->allocate_color( $canvas, $fill, (float) $op['opacity'] ); $border_color = $this->driver->allocate_color( $canvas, $border, (float) $op['opacity'] );
				$this->driver->filled_rectangle( $canvas, (int) floor( $x1 ), (int) floor( $y1 ), max( (int) floor( $x1 ), (int) ceil( $x2 ) - 1 ), max( (int) floor( $y1 ), (int) ceil( $y2 ) - 1 ), $fill_color );
				if ( (float) ( $grid['border_width_mm'] ?? 0 ) > 0 ) { $this->driver->rectangle( $canvas, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $border_color, $thickness ); }
				$text_op = array( 'type' => 'text', 'opacity' => $op['opacity'], 'geometry' => array( 'box' => array( 'x_mm' => (float) $outer['x_mm'] + (float) ( $content['x_mm'] ?? 0 ), 'y_mm' => (float) $outer['y_mm'] + (float) ( $content['y_mm'] ?? 0 ), 'width_mm' => (float) ( $content['width_mm'] ?? 0 ), 'height_mm' => (float) ( $content['height_mm'] ?? 0 ) ) ), 'text' => (array) ( $cell['text'] ?? array() ) );
				$this->draw_text( $canvas, $plan, $text_op, $ox, $oy );
			}
		}
	}

	/** @return array{size_pt:float,lines:string[]} */
	private function layout_lines( $value, $direction, $overflow, $font_path, $size, $width_px, $height_px, $line_height, $spacing_pt = 0.0 ) {
		$min = 5.0;
		if ( 'wrap' === $overflow ) { return array( 'size_pt' => $size, 'lines' => $this->wrap_lines( $value, $direction, $font_path, $size, $width_px, $spacing_pt ) ); }
		$lines = preg_split( '/\R/u', $value ); if ( false === $lines || empty( $lines ) ) { $lines = array( $value ); }
		if ( 'shrink' === $overflow ) {
			while ( $size > $min ) {
				$max = 0.0; foreach ( $lines as $line ) { $max = max( $max, $this->measure_width( $this->visual_text( $line, $direction ), $font_path, $size, $spacing_pt ) ); }
				$total = count( $lines ) * $size * self::DPI / 72.0 * $line_height;
				if ( $max <= $width_px + 0.01 && $total <= $height_px + 0.01 ) { break; }
				$size = max( $min, $size - 0.25 );
			}
		}
		return array( 'size_pt' => $size, 'lines' => array_values( $lines ) );
	}

	/** @return string[] */
	private function wrap_lines( $value, $direction, $font_path, $size, $width_px, $spacing_pt = 0.0 ) {
		$paragraphs = preg_split( '/\R/u', $value ); if ( false === $paragraphs ) { return array( $value ); }
		$out = array();
		foreach ( $paragraphs as $paragraph ) {
			$words = preg_split( '/\s+/u', trim( $paragraph ) ); if ( false === $words || empty( $words ) || '' === $words[0] ) { $out[] = ''; continue; }
			$line = '';
			foreach ( $words as $word ) {
				$candidate = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && $this->measure_width( $this->visual_text( $candidate, $direction ), $font_path, $size, $spacing_pt ) > $width_px ) { $out[] = $line; $line = $word; }
				else { $line = $candidate; }
			}
			$out[] = $line;
		}
		return $out ?: array( '' );
	}

	private function visual_text( $value, $direction ) { return $this->contains_arabic( $value ) ? $this->shaper->visual_text( $value, $direction ) : $value; }
	private function measure_width( $visual, $font_path, $size, $spacing_pt = 0.0 ) { $m = $this->text_metrics( $visual, $font_path, $size, $spacing_pt ); return $m['width']; }
	private function text_metrics( $visual, $font_path, $size, $spacing_pt = 0.0 ) {
		$spacing_pt = (float) $spacing_pt;
		if ( abs( $spacing_pt ) < 0.0001 ) { return $this->bbox_metrics( $this->driver->text_box( $size, 0.0, $font_path, $visual ) ); }
		$clusters = $this->grapheme_clusters( $visual );
		if ( empty( $clusters ) ) { return array( 'min_x'=>0.0,'max_x'=>0.0,'min_y'=>0.0,'max_y'=>0.0,'width'=>0.0,'height'=>0.0 ); }
		$spacing_px = $spacing_pt * self::DPI / 72.0; $width = 0.0; $min_y = 0.0; $max_y = 0.0; $first = true;
		foreach ( $clusters as $cluster ) {
			$m = $this->bbox_metrics( $this->driver->text_box( $size, 0.0, $font_path, $cluster ) );
			$width += (float) $m['width']; if ( ! $first ) { $width += $spacing_px; }
			$min_y = $first ? (float) $m['min_y'] : min( $min_y, (float) $m['min_y'] ); $max_y = $first ? (float) $m['max_y'] : max( $max_y, (float) $m['max_y'] ); $first = false;
		}
		return array( 'min_x'=>0.0,'max_x'=>$width,'min_y'=>$min_y,'max_y'=>$max_y,'width'=>$width,'height'=>$max_y-$min_y );
	}
	private function draw_visual_text( $canvas, $size, $x, $baseline, $color, $font_path, $visual, $spacing_pt = 0.0 ) {
		$spacing_pt = (float) $spacing_pt;
		if ( abs( $spacing_pt ) < 0.0001 ) { $this->driver->text( $canvas, $size, 0.0, $x, $baseline, $color, $font_path, $visual ); return; }
		$cursor = (float) $x; $spacing_px = $spacing_pt * self::DPI / 72.0;
		foreach ( $this->grapheme_clusters( $visual ) as $cluster ) {
			$m = $this->bbox_metrics( $this->driver->text_box( $size, 0.0, $font_path, $cluster ) );
			$this->driver->text( $canvas, $size, 0.0, $cursor - (float) $m['min_x'], $baseline, $color, $font_path, $cluster );
			$cursor += (float) $m['width'] + $spacing_px;
		}
	}
	/** @return string[] */
	private function grapheme_clusters( $value ) {
		if ( '' === (string) $value ) { return array(); }
		if ( 1 === preg_match_all( '/\X/u', (string) $value, $matches ) && ! empty( $matches[0] ) ) { return array_values( $matches[0] ); }
		$chars = preg_split( '//u', (string) $value, -1, PREG_SPLIT_NO_EMPTY ); return false === $chars ? array( (string) $value ) : array_values( $chars );
	}
	/** @return array<string,float> */
	private function bbox_metrics( array $box ) { $xs=array($box[0],$box[2],$box[4],$box[6]); $ys=array($box[1],$box[3],$box[5],$box[7]); $minx=min($xs);$maxx=max($xs);$miny=min($ys);$maxy=max($ys); return array('min_x'=>(float)$minx,'max_x'=>(float)$maxx,'min_y'=>(float)$miny,'max_y'=>(float)$maxy,'width'=>(float)($maxx-$minx),'height'=>(float)($maxy-$miny)); }

	private function draw_machine_code( $canvas, array $op, $payload_key, $ox, $oy ) {
		$box = (array) ( $op['geometry']['box'] ?? array() );
		$payload = isset( $op[ $payload_key ] ) && is_array( $op[ $payload_key ] ) ? $op[ $payload_key ] : array();
		$white = $this->driver->allocate_color( $canvas, array( 'r'=>255,'g'=>255,'b'=>255,'alpha_unit'=>1.0 ), 1.0 );
		$black = $this->driver->allocate_color( $canvas, array( 'r'=>0,'g'=>0,'b'=>0,'alpha_unit'=>1.0 ), (float)$op['opacity'] );
		$x1 = $this->mm_px_float( (float) ( $box['x_mm'] ?? 0 ) + $ox );
		$y1 = $this->mm_px_float( (float) ( $box['y_mm'] ?? 0 ) + $oy );
		$x2 = $this->mm_px_float( (float) ( $box['x_mm'] ?? 0 ) + (float) ( $box['width_mm'] ?? 0 ) + $ox );
		$y2 = $this->mm_px_float( (float) ( $box['y_mm'] ?? 0 ) + (float) ( $box['height_mm'] ?? 0 ) + $oy );
		$this->driver->filled_rectangle( $canvas, (int) floor( $x1 ), (int) floor( $y1 ), max( (int) floor( $x1 ), (int) ceil( $x2 ) - 1 ), max( (int) floor( $y1 ), (int) ceil( $y2 ) - 1 ), $white );
		foreach ( isset( $payload['dark_runs'] ) && is_array( $payload['dark_runs'] ) ? $payload['dark_runs'] : array() as $run ) {
			if ( ! is_array( $run ) || ! isset( $run['x_mm'], $run['y_mm'], $run['width_mm'], $run['height_mm'] ) ) { continue; }
			$rx1=$this->mm_px_float($run['x_mm']+$ox);$ry1=$this->mm_px_float($run['y_mm']+$oy);$rx2=$this->mm_px_float($run['x_mm']+$run['width_mm']+$ox);$ry2=$this->mm_px_float($run['y_mm']+$run['height_mm']+$oy);
			if ( 'qr' === $payload_key ) {
				/* Quantize shared QR module boundaries once, rather than expanding every
				 * dark run with floor/ceil. Independent expansion can consume narrow
				 * white modules at fractional 96-DPI module widths and make an otherwise
				 * correct matrix undecodable. Round-to-nearest preserves the shared
				 * lattice boundary for adjacent dark/white modules. */
				$left=(int)round($rx1);$top=(int)round($ry1);$right=(int)round($rx2)-1;$bottom=(int)round($ry2)-1;
				if($right<$left||$bottom<$top){throw new RuntimeException('GD QR module grid is below the raster-resolution boundary.');}
				$this->driver->filled_rectangle($canvas,$left,$top,$right,$bottom,$black);
				continue;
			}
			$this->driver->filled_rectangle($canvas,(int)floor($rx1),(int)floor($ry1),(int)ceil($rx2)-1,(int)ceil($ry2)-1,$black);
		}
	}

	private function draw_shape( $canvas, array $op, $ox, $oy ) {
		$b=$op['geometry']['box'];$s=$op['shape'];$x1=$this->mm_px_float($b['x_mm']+$ox);$y1=$this->mm_px_float($b['y_mm']+$oy);$x2=$this->mm_px_float($b['x_mm']+$b['width_mm']+$ox);$y2=$this->mm_px_float($b['y_mm']+$b['height_mm']+$oy);$fill=$this->driver->allocate_color($canvas,$s['fill'],(float)$op['opacity']);$stroke=$this->driver->allocate_color($canvas,$s['stroke'],(float)$op['opacity']);$th=max(1,(int)round($this->mm_px_float($s['stroke_width_mm'])));
		if('rectangle'===$s['kind']){if((int)$s['fill']['alpha']>0){$this->driver->filled_rectangle($canvas,$x1,$y1,$x2,$y2,$fill);}if($s['stroke_width_mm']>0){$this->driver->rectangle($canvas,$x1,$y1,$x2,$y2,$stroke,$th);}}else{$cx=($x1+$x2)/2;$cy=($y1+$y2)/2;$w=$x2-$x1;$h=$y2-$y1;if((int)$s['fill']['alpha']>0){$this->driver->filled_ellipse($canvas,$cx,$cy,$w,$h,$fill);}if($s['stroke_width_mm']>0){$this->driver->ellipse($canvas,$cx,$cy,$w,$h,$stroke,$th);}}
	}
	private function draw_line( $canvas, array $op, $ox, $oy ) { $l=$op['line'];$c=$this->driver->allocate_color($canvas,$l['stroke'],(float)$op['opacity']);$th=max(1,(int)round($this->mm_px_float($l['stroke_width_mm'])));$this->driver->line($canvas,$this->mm_px_float($l['from']['x_mm']+$ox),$this->mm_px_float($l['from']['y_mm']+$oy),$this->mm_px_float($l['to']['x_mm']+$ox),$this->mm_px_float($l['to']['y_mm']+$oy),$c,$th); }
	private function draw_image( $canvas, array $plan, array $op, $ox, $oy ) {
		$asset=$op['image']['asset'];$hash=(string)$asset['sha256'];$path=$plan['_runtime']['assets'][$hash]['path']??'';$this->assert_exact_image_file($path,$asset);$src=$this->driver->load_image($path,$asset['mime']);
		try{$opacity=max(0.0,min(1.0,(float)($op['opacity']??1.0)));if($opacity<0.999999){$this->driver->multiply_opacity($src,$opacity);}list($sw,$sh)=$this->driver->image_size($src);$p=$op['image']['placement'];$d=$p['destination'];$crop=$p['source_crop'];$dx=$this->mm_px_float($d['x_mm']+$ox);$dy=$this->mm_px_float($d['y_mm']+$oy);$dw=$this->mm_px_float($d['width_mm']);$dh=$this->mm_px_float($d['height_mm']);$sx=$crop['x']*$sw;$sy=$crop['y']*$sh;$cw=$crop['width']*$sw;$ch=$crop['height']*$sh;$this->driver->copy_resampled($canvas,$src,$dx,$dy,$dw,$dh,$sx,$sy,$cw,$ch);}finally{$this->driver->destroy($src);}
	}

	private function assert_exact_image_file( $path, array $asset ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) ) { throw new RuntimeException( 'GD candidate local image path is unavailable.' ); }
		$size=filesize($path);$hash=hash_file('sha256',$path);$image=@getimagesize($path);
		if(false===$size||!is_string($hash)||!hash_equals((string)$asset['sha256'],$hash)||(int)$size!==(int)$asset['size']||!is_array($image)||(int)$image[0]!==(int)$asset['width_px']||(int)$image[1]!==(int)$asset['height_px']||strtolower((string)($image['mime']??''))!==strtolower((string)$asset['mime'])){throw new RuntimeException('GD candidate local image changed after coordinator verification.');}
	}

	private function assert_supported_text( $text ) {
		if ( 1 !== preg_match( '//u', $text ) ) { throw new RuntimeException( 'GD candidate requires valid UTF-8 text.' ); }
		$chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);if(false===$chars){throw new RuntimeException('GD candidate could not inspect Unicode text.');}
		foreach($chars as $char){$cp=$this->codepoint($char);if(($cp>=0x0590&&$cp<=0x05FF)||($cp>=0x0700&&$cp<=0x08FF)||($cp>=0x0900&&$cp<=0x0DFF)||($cp>=0x0E00&&$cp<=0x109F)){if(!($cp>=0x0600&&$cp<=0x06FF)||!$this->contains_arabic($char)){throw new RuntimeException('Initial GD candidate fails closed for complex scripts outside its accepted Arabic/Perso-Arabic shaping set.');}}}
	}
	private function contains_arabic( $text ) { return 1 === preg_match( '/[\x{0600}-\x{06FF}]/u', $text ); }
	private function codepoint($char){$b=array_values(unpack('C*',$char));$x=$b[0];if($x<=0x7F)return$x;if(($x&0xE0)===0xC0)return(($x&0x1F)<<6)|($b[1]&0x3F);if(($x&0xF0)===0xE0)return(($x&0x0F)<<12)|(($b[1]&0x3F)<<6)|($b[2]&0x3F);return(($x&0x07)<<18)|(($b[1]&0x3F)<<12)|(($b[2]&0x3F)<<6)|($b[3]&0x3F);}
	private function mm_px( $mm ) { return max( 1, (int) round( $this->mm_px_float( $mm ) ) ); }
	private function mm_px_float( $mm ) { return (float) $mm / 25.4 * self::DPI * (float) $this->pixel_scale; }
}
