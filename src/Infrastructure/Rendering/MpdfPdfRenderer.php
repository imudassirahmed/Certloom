<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\ArabicPresentationShaper;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\DeterministicPdfNormalizer;
use InvalidArgumentException;
use RuntimeException;

/**
 * First concrete Certloom PDF-04 candidate: dependency-prefixed mPDF.
 *
 * This class is intentionally not registered by Plugin bootstrap and does not
 * grant artifact authority. It can only execute after the normal coordinator
 * authority/capability/font boundaries (or the source-only PDF-04 evaluator).
 */
final class MpdfPdfRenderer implements RendererInterface {
	const ID = 'certloom-mpdf-pdf';
	private $version;
	private $candidate_hash;
	private $font_manifest;
	private $temp_dir;
	private $normalizer;
	private $canonical;

	public function __construct( $version, $candidate_manifest_sha256, array $font_manifest, $temp_dir = '', ?DeterministicPdfNormalizer $normalizer = null, ?CanonicalJson $canonical = null ) {
		$version = trim( (string) $version );
		$candidate_manifest_sha256 = strtolower( trim( (string) $candidate_manifest_sha256 ) );
		if ( '' === $version || strlen( $version ) > 64 || ! preg_match( '/^[0-9a-f]{64}$/', $candidate_manifest_sha256 ) ) {
			throw new InvalidArgumentException( 'mPDF candidate requires a bounded version and canonical candidate-manifest SHA-256.' );
		}
		if ( '' !== (string) $temp_dir && ( false !== strpos( (string) $temp_dir, "\0" ) || ! is_dir( (string) $temp_dir ) || ! is_writable( (string) $temp_dir ) ) ) {
			throw new InvalidArgumentException( 'mPDF candidate temp directory must be an existing writable local directory.' );
		}
		$this->version = $version;
		$this->candidate_hash = $candidate_manifest_sha256;
		$this->font_manifest = $font_manifest;
		$this->temp_dir = rtrim( (string) $temp_dir, '/\\' );
		$this->normalizer = $normalizer ?: new DeterministicPdfNormalizer();
		$this->canonical = $canonical ?: new CanonicalJson();
	}

	public function id() { return self::ID; }
	public function version() { return $this->version; }
	public function formats() { return array( 'pdf' ); }
	public function requirements() {
		return array(
			'php_min' => '7.4',
			'extensions' => array( 'gd', 'mbstring' ),
			'classes' => array( 'CertloomVendor\\Mpdf\\Mpdf' ),
			'functions' => array(),
		);
	}
	public function context() {
		return array(
			'candidate_manifest_sha256' => $this->candidate_hash,
			'font_manifest' => $this->font_manifest,
			'adapter' => 'certloom-mpdf-render-plan-v2',
			'input_schema' => 'certloom-render-plan:1',
			'network' => 'none',
			'remote_assets' => false,
		);
	}

	public function render( array $render_plan, $format ) {
		if ( 'pdf' !== strtolower( trim( (string) $format ) ) ) { throw new InvalidArgumentException( 'mPDF candidate owns PDF only.' ); }
		$this->assert_plan( $render_plan );
		$class = 'CertloomVendor\\Mpdf\\Mpdf';
		if ( ! class_exists( $class ) ) { throw new RuntimeException( 'Dependency-prefixed mPDF candidate is unavailable.' ); }
		list( $font_data, $font_dirs, $font_aliases ) = $this->font_configuration( $render_plan );
		$page = $render_plan['page'];
		$config = array(
			'mode' => 'utf-8',
			'format' => array( (float) $page['width_mm'], (float) $page['height_mm'] ),
			'margin_left' => 0,
			'margin_right' => 0,
			'margin_top' => 0,
			'margin_bottom' => 0,
			'margin_header' => 0,
			'margin_footer' => 0,
		);
		if ( ! empty( $font_data ) ) {
			$config['fontDir'] = $font_dirs;
			$config['fontdata'] = $font_data;
			$config['default_font'] = reset( $font_aliases );
		}
		if ( '' !== $this->temp_dir ) { $config['tempDir'] = $this->temp_dir; }
		$mpdf = new $class( $config );
		if ( property_exists( $mpdf, 'autoScriptToLang' ) ) { $mpdf->autoScriptToLang = false; }
		if ( property_exists( $mpdf, 'autoLangToFont' ) ) { $mpdf->autoLangToFont = false; }
		if ( property_exists( $mpdf, 'useSubstitutions' ) ) { $mpdf->useSubstitutions = false; }
		if ( method_exists( $mpdf, 'SetCreator' ) ) { $mpdf->SetCreator( 'Certloom' ); }
		$mpdf->AddPage();
		foreach ( $render_plan['operations'] as $operation ) { $this->draw_operation( $mpdf, $render_plan, $operation, $font_aliases ); }
		$bytes = $mpdf->Output( '', 'S' );
		if ( ! is_string( $bytes ) || 0 !== strpos( $bytes, '%PDF-' ) ) { throw new RuntimeException( 'mPDF candidate did not return PDF bytes.' ); }
		$portable = $render_plan; unset( $portable['_runtime'] );
		return $this->normalizer->normalize( $bytes, $this->canonical->hash( $portable ) );
	}

	private function assert_plan( array $plan ) {
		if ( ! isset( $plan['_meta']['schema'], $plan['_meta']['version'], $plan['page']['width_mm'], $plan['page']['height_mm'], $plan['operations'] ) || 'certloom-render-plan' !== $plan['_meta']['schema'] || 1 !== $plan['_meta']['version'] || ! is_array( $plan['operations'] ) ) {
			throw new InvalidArgumentException( 'mPDF candidate requires certloom-render-plan v1.' );
		}
	}

	/** @return array{0:array<string,mixed>,1:string[],2:array<string,string>} */
	private function font_configuration( array $plan ) {
		$runtime = isset( $plan['_runtime']['fonts'] ) && is_array( $plan['_runtime']['fonts'] ) ? $plan['_runtime']['fonts'] : array();
		$data = array(); $dirs = array(); $aliases = array();
		foreach ( $plan['operations'] as $operation ) {
			foreach ( $this->operation_fonts( is_array( $operation ) ? $operation : array() ) as $font ) {
				$faces = isset( $font['faces'] ) && is_array( $font['faces'] ) ? array_values( $font['faces'] ) : array();
				if ( 1 !== count( $faces ) ) { throw new RuntimeException( 'mPDF candidate requires one exact proven face per text run; multi-face fallback awaits shaped font runs.' ); }
				$hash = strtolower( (string) ( $faces[0]['sha256'] ?? '' ) );
				if ( empty( $runtime[ $hash ]['path'] ) || ! is_file( $runtime[ $hash ]['path'] ) ) { throw new RuntimeException( 'mPDF candidate is missing an exact runtime font path.' ); }
				$path = realpath( $runtime[ $hash ]['path'] );
				if ( false === $path ) { throw new RuntimeException( 'mPDF exact runtime font path cannot be resolved.' ); }
				$alias = 'clf' . substr( $hash, 0, 20 );
				$aliases[ $hash ] = $alias;
				$data[ $alias ] = array( 'R' => basename( $path ), 'useOTL' => 0xFF );
				$dirs[ dirname( $path ) ] = dirname( $path );
			}
		}
		ksort( $data, SORT_STRING ); ksort( $dirs, SORT_STRING ); ksort( $aliases, SORT_STRING );
		return array( $data, array_values( $dirs ), $aliases );
	}

	private function draw_operation( $mpdf, array $plan, array $op, array $font_aliases ) {
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) );
		if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity ); }
		switch ( (string) $op['type'] ) {
			case 'text': $this->draw_text( $mpdf, $plan, $op, $font_aliases ); break;
			case 'rich_text': $this->draw_rich_text( $mpdf, $plan, $op, $font_aliases ); break;
			case 'table': $this->draw_table( $mpdf, $plan, $op, $font_aliases ); break;
			case 'qr': $this->draw_machine_code( $mpdf, $op, 'qr' ); break;
			case 'barcode': $this->draw_machine_code( $mpdf, $op, 'barcode' ); break;
			case 'shape': $this->draw_shape( $mpdf, $op ); break;
			case 'line': $this->draw_line( $mpdf, $op ); break;
			case 'image': case 'logo': case 'signature': $this->draw_image( $mpdf, $plan, $op ); break;
			default: throw new RuntimeException( 'mPDF candidate received an unsupported Render Plan operation.' );
		}
		if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( 1.0 ); }
	}


	/** @return array<int,array<string,mixed>> */
	private function operation_fonts( array $operation ) {
		$type = (string) ( $operation['type'] ?? '' );
		if ( 'text' === $type ) { return array( (array) ( $operation['text']['font'] ?? array() ) ); }
		if ( 'rich_text' === $type ) {
			$fonts = array( (array) ( $operation['rich_text']['base_font'] ?? array() ) );
			foreach ( (array) ( $operation['rich_text']['runs'] ?? array() ) as $run ) { if ( is_array( $run ) ) { $fonts[] = (array) ( $run['font'] ?? array() ); } }
			return $fonts;
		}
		if ( 'table' === $type ) {
			$fonts = array();
			foreach ( (array) ( $operation['table']['rows'] ?? array() ) as $row ) {
				foreach ( is_array( $row ) ? (array) ( $row['cells'] ?? array() ) : array() as $cell ) {
					if ( is_array( $cell ) ) { $fonts[] = (array) ( $cell['text']['font'] ?? array() ); }
				}
			}
			return $fonts;
		}
		return array();
	}

	private function font_alias( array $font, array $aliases ) {
		$faces = isset( $font['faces'] ) && is_array( $font['faces'] ) ? array_values( $font['faces'] ) : array();
		if ( 1 !== count( $faces ) ) { throw new RuntimeException( 'mPDF text run requires one exact proven font face.' ); }
		$hash = strtolower( (string) ( $faces[0]['sha256'] ?? '' ) );
		if ( '' === $hash || empty( $aliases[ $hash ] ) ) { throw new RuntimeException( 'mPDF candidate cannot map the exact font face.' ); }
		return (string) $aliases[ $hash ];
	}

	private function draw_rich_text( $mpdf, array $plan, array $op, array $aliases ) {
		$rich = isset( $op['rich_text'] ) && is_array( $op['rich_text'] ) ? $op['rich_text'] : array();
		$runs = isset( $rich['runs'] ) && is_array( $rich['runs'] ) ? array_values( $rich['runs'] ) : array();
		if ( empty( $runs ) ) { return; }
		$prepared = array();
		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) ) { continue; }
			$font = (array) ( $run['font'] ?? array() );
			list( $alias, $path ) = $this->native_font_runtime( $plan, $font, $aliases );
			$prepared[] = array(
				'value' => (string) ( $run['value'] ?? '' ), 'font' => $font, 'path' => $path, 'alias' => $alias,
				'color' => (array) ( $run['color'] ?? array() ), 'spacing_pt' => (float) ( $font['letter_spacing_pt'] ?? 0.0 ),
			);
		}
		if ( empty( $prepared ) ) { return; }
		$box = (array) ( $op['geometry']['box'] ?? array() );
		$x = $this->mm_to_px( (float) ( $box['x_mm'] ?? 0 ) ); $y = $this->mm_to_px( (float) ( $box['y_mm'] ?? 0 ) );
		$w = $this->mm_to_px( (float) ( $box['width_mm'] ?? 0 ) ); $h = $this->mm_to_px( (float) ( $box['height_mm'] ?? 0 ) );
		$direction = (string) ( $rich['direction'] ?? 'ltr' ); $overflow = (string) ( $rich['overflow'] ?? 'shrink' );
		$base_font = (array) ( $rich['base_font'] ?? array() ); $line_height = max( 0.5, (float) ( $base_font['line_height'] ?? 1.2 ) );
		$scale = 1.0; $layout = $this->native_rich_layout( $prepared, $direction, $overflow, $scale, $w, $line_height );
		if ( 'shrink' === $overflow ) {
			$base_size = max( 1.0, (float) ( $base_font['size_pt'] ?? 12.0 ) ); $min_scale = min( 1.0, 5.0 / $base_size );
			while ( $scale > $min_scale + 0.0001 && ( (float) $layout['max_width'] > $w + 0.01 || (float) $layout['height'] > $h + 0.01 ) ) {
				$scale = max( $min_scale, $scale - 0.02 ); $layout = $this->native_rich_layout( $prepared, $direction, $overflow, $scale, $w, $line_height );
			}
		}
		$total_h = (float) $layout['height']; $top = $y;
		if ( 'middle' === (string) ( $rich['vertical_align'] ?? 'middle' ) ) { $top = $y + ( $h - $total_h ) / 2.0; }
		elseif ( 'bottom' === (string) ( $rich['vertical_align'] ?? 'middle' ) ) { $top = $y + $h - $total_h; }
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) );
		$draw = function () use ( $mpdf, $layout, $direction, $rich, $x, $w, $y, $h, $top, $opacity ) {
			$line_top = $top;
			foreach ( (array) $layout['lines'] as $line ) {
				$line_w = (float) $line['width']; $cursor = $x;
				if ( 'center' === (string) ( $rich['align'] ?? 'center' ) ) { $cursor = $x + ( $w - $line_w ) / 2.0; }
				elseif ( 'right' === (string) ( $rich['align'] ?? 'center' ) ) { $cursor = $x + $w - $line_w; }
				$fragments = 'rtl' === $direction ? array_reverse( (array) $line['fragments'] ) : (array) $line['fragments'];
				foreach ( $fragments as $fragment ) {
					$size = (float) $fragment['size_pt']; $visual = (string) $fragment['visual']; $metrics = (array) $fragment['metrics'];
					$baseline = $line_top - (float) $metrics['min_y'] + max( 0.0, ( (float) $line['height'] - (float) $metrics['height'] ) / 2.0 );
					$color = (array) $fragment['color'];
					if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity * max( 0.0, min( 1.0, (float) ( $color['alpha_unit'] ?? 1.0 ) ) ) ); }
					if ( method_exists( $mpdf, 'SetTextColor' ) ) { $mpdf->SetTextColor( (int) ( $color['r'] ?? 0 ), (int) ( $color['g'] ?? 0 ), (int) ( $color['b'] ?? 0 ) ); }
					$this->native_draw_visual_at_layout( $mpdf, (string) $fragment['alias'], (string) $fragment['path'], $size, $size, $this->px_to_mm( $cursor - (float) $metrics['min_x'] ), $this->px_to_mm( $baseline ), $visual, (float) ( $fragment['spacing_pt'] ?? 0.0 ) );
					$cursor += (float) $fragment['width'];
				}
				$line_top += (float) $line['height'];
				if ( $line_top > $y + $h + 0.01 ) { break; }
			}
		};
		$this->with_rotation( $mpdf, $op, $draw );
	}

	private function draw_table( $mpdf, array $plan, array $op, array $aliases ) {
		$table = isset( $op['table'] ) && is_array( $op['table'] ) ? $op['table'] : array();
		$outer = (array) ( $op['geometry']['box'] ?? array() );
		$grid = isset( $table['grid'] ) && is_array( $table['grid'] ) ? $table['grid'] : array();
		$border_color = (array) ( $grid['border_color'] ?? array() );
		$border_width = max( 0.0, (float) ( $grid['border_width_mm'] ?? 0 ) );
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) );
		$draw = function () use ( $mpdf, $plan, $table, $outer, $border_color, $border_width, $opacity, $aliases ) {
			foreach ( (array) ( $table['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$fill = (array) ( $row['fill'] ?? array() );
				foreach ( (array) ( $row['cells'] ?? array() ) as $cell ) {
					if ( ! is_array( $cell ) ) { continue; }
					$cell_box = (array) ( $cell['box'] ?? array() ); $content = (array) ( $cell['content_box'] ?? array() );
					$x = (float) $outer['x_mm'] + (float) ( $cell_box['x_mm'] ?? 0 ); $y = (float) $outer['y_mm'] + (float) ( $cell_box['y_mm'] ?? 0 );
					$w = (float) ( $cell_box['width_mm'] ?? 0 ); $h = (float) ( $cell_box['height_mm'] ?? 0 );
					if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity * (float) ( $fill['alpha_unit'] ?? 1.0 ) ); }
					$mpdf->SetFillColor( (int) ( $fill['r'] ?? 255 ), (int) ( $fill['g'] ?? 255 ), (int) ( $fill['b'] ?? 255 ) ); $mpdf->Rect( $x, $y, $w, $h, 'F' );
					if ( $border_width > 0 ) {
						if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity * (float) ( $border_color['alpha_unit'] ?? 1.0 ) ); }
						$mpdf->SetDrawColor( (int) ( $border_color['r'] ?? 0 ), (int) ( $border_color['g'] ?? 0 ), (int) ( $border_color['b'] ?? 0 ) ); $mpdf->SetLineWidth( $border_width ); $mpdf->Rect( $x, $y, $w, $h, 'D' );
					}
					$text_op = array(
						'type' => 'text', 'opacity' => $opacity,
						'geometry' => array( 'box' => array( 'x_mm' => (float) $outer['x_mm'] + (float) ( $content['x_mm'] ?? 0 ), 'y_mm' => (float) $outer['y_mm'] + (float) ( $content['y_mm'] ?? 0 ), 'width_mm' => (float) ( $content['width_mm'] ?? 0 ), 'height_mm' => (float) ( $content['height_mm'] ?? 0 ) ), 'rotation_deg' => 0.0, 'center' => array( 'x_mm'=>0.0, 'y_mm'=>0.0 ) ),
						'text' => (array) ( $cell['text'] ?? array() ),
					);
					$this->draw_text( $mpdf, $plan, $text_op, $aliases );
				}
			}
		};
		$this->with_rotation( $mpdf, $op, $draw );
	}

	private function with_rotation( $mpdf, array $op, callable $draw ) {
		$rotation = (float) ( $op['geometry']['rotation_deg'] ?? 0.0 );
		if ( abs( $rotation ) < 0.0001 ) { $draw(); return; }
		if ( ! method_exists( $mpdf, 'StartTransform' ) || ! method_exists( $mpdf, 'transformRotate' ) || ! method_exists( $mpdf, 'StopTransform' ) ) {
			throw new RuntimeException( 'mPDF candidate requires transformRotate() for rotated Render Plan operations.' );
		}
		$center = (array) ( $op['geometry']['center'] ?? array() );
		$mpdf->StartTransform();
		try { $mpdf->transformRotate( $rotation, (float) ( $center['x_mm'] ?? 0 ), (float) ( $center['y_mm'] ?? 0 ) ); $draw(); }
		finally { $mpdf->StopTransform(); }
	}


	private function draw_text( $mpdf, array $plan, array $op, array $aliases ) {
		$text = isset( $op['text'] ) && is_array( $op['text'] ) ? $op['text'] : array();
		$font = isset( $text['font'] ) && is_array( $text['font'] ) ? $text['font'] : array();
		list( $alias, $font_path ) = $this->native_font_runtime( $plan, $font, $aliases );
		$box = isset( $op['geometry']['box'] ) && is_array( $op['geometry']['box'] ) ? $op['geometry']['box'] : array();
		$direction = (string) ( $text['direction'] ?? 'ltr' );
		$overflow = (string) ( $text['overflow'] ?? 'shrink' );
		$spacing_pt = (float) ( $font['letter_spacing_pt'] ?? 0.0 );
		$requested_size = max( 1.0, (float) ( $font['size_pt'] ?? 12.0 ) );
		$size = $requested_size;
		$line_height = max( 0.5, (float) ( $font['line_height'] ?? 1.2 ) );
		$layout = $this->native_layout_lines( (string) ( $text['value'] ?? '' ), $direction, $overflow, $font_path, $size, (float) ( $box['width_mm'] ?? 0 ), (float) ( $box['height_mm'] ?? 0 ), $line_height, $spacing_pt );
		$size = (float) $layout['size_pt']; $rows = (array) $layout['lines'];
		$line_px = $size * 96.0 / 72.0 * $line_height; $total_px = count( $rows ) * $line_px;
		$box_y_px = $this->mm_to_px( (float) ( $box['y_mm'] ?? 0 ) ); $box_h_px = $this->mm_to_px( (float) ( $box['height_mm'] ?? 0 ) );
		$top_px = $box_y_px;
		if ( 'middle' === (string) ( $text['vertical_align'] ?? 'middle' ) ) { $top_px = $box_y_px + ( $box_h_px - $total_px ) / 2.0; }
		elseif ( 'bottom' === (string) ( $text['vertical_align'] ?? 'middle' ) ) { $top_px = $box_y_px + $box_h_px - $total_px; }
		$color = isset( $text['color'] ) && is_array( $text['color'] ) ? $text['color'] : array();
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) ) * max( 0.0, min( 1.0, (float) ( $color['alpha_unit'] ?? 1.0 ) ) );
		$draw = function () use ( $mpdf, $rows, $direction, $font_path, $alias, $size, $requested_size, $overflow, $line_px, $top_px, $box, $text, $spacing_pt, $color, $opacity ) {
			if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity ); }
			if ( method_exists( $mpdf, 'SetTextColor' ) ) { $mpdf->SetTextColor( (int) ( $color['r'] ?? 0 ), (int) ( $color['g'] ?? 0 ), (int) ( $color['b'] ?? 0 ) ); }
			$box_x_px = $this->mm_to_px( (float) ( $box['x_mm'] ?? 0 ) ); $box_w_px = $this->mm_to_px( (float) ( $box['width_mm'] ?? 0 ) );
			foreach ( $rows as $index => $row ) {
				$visual = $this->native_visual_text( (string) $row, $direction );
				$metrics = $this->native_text_metrics( $visual, $font_path, $size, $spacing_pt );
				$draw_x_px = $box_x_px - (float) $metrics['min_x'];
				if ( 'center' === (string) ( $text['align'] ?? 'left' ) ) { $draw_x_px = $box_x_px + ( $box_w_px - (float) $metrics['width'] ) / 2.0 - (float) $metrics['min_x']; }
				elseif ( 'right' === (string) ( $text['align'] ?? 'left' ) ) { $draw_x_px = $box_x_px + $box_w_px - (float) $metrics['width'] - (float) $metrics['min_x']; }
				$line_top_px = $top_px + $index * $line_px;
				$baseline_px = $line_top_px - (float) $metrics['min_y'] + max( 0.0, ( $line_px - (float) $metrics['height'] ) / 2.0 );
				$draw_size = $size;
				// Only compensate when the shared GD layout actually invoked shrink.
				// The target width remains the GD/FreeType width; mPDF's own font advance
				// is used only to correct its emitted glyph scale, never to decide layout.
				if ( 'shrink' === $overflow && $size < $requested_size - 0.0001 && abs( $spacing_pt ) < 0.0001 && method_exists( $mpdf, 'GetStringWidth' ) ) {
					$mpdf->SetFont( $alias, '', $size );
					$advance_mm = (float) $mpdf->GetStringWidth( $visual );
					$target_mm = $this->px_to_mm( (float) $metrics['width'] );
					if ( $advance_mm > 0.0001 && $target_mm > 0.0001 ) {
						$ratio = $target_mm / $advance_mm;
						if ( $ratio < 0.85 || $ratio > 1.15 ) { throw new RuntimeException( 'mPDF shrink font-scale correction escaped the bounded parity range.' ); }
						$draw_size = $size * $ratio;
					}
				}
				$this->native_draw_visual_at_layout( $mpdf, $alias, $font_path, $size, $draw_size, $this->px_to_mm( $draw_x_px ), $this->px_to_mm( $baseline_px ), $visual, $spacing_pt );
			}
		};
		$this->with_rotation( $mpdf, $op, $draw );
	}

	private function draw_machine_code( $mpdf, array $op, $payload_key ) {
		$payload = isset( $op[ $payload_key ] ) && is_array( $op[ $payload_key ] ) ? $op[ $payload_key ] : array(); $box = (array) ( $op['geometry']['box'] ?? array() );
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) );
		$this->with_rotation( $mpdf, $op, function () use ( $mpdf, $payload, $box, $opacity ) {
			if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( 1.0 ); } $mpdf->SetFillColor( 255, 255, 255 );
			$mpdf->Rect( (float)$box['x_mm'], (float)$box['y_mm'], (float)$box['width_mm'], (float)$box['height_mm'], 'F' );
			if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity ); } $mpdf->SetFillColor( 0, 0, 0 );
			foreach ( isset( $payload['dark_runs'] ) && is_array( $payload['dark_runs'] ) ? $payload['dark_runs'] : array() as $run ) {
				if ( ! is_array( $run ) || ! isset( $run['x_mm'],$run['y_mm'],$run['width_mm'],$run['height_mm'] ) ) { continue; }
				$mpdf->Rect( (float)$run['x_mm'], (float)$run['y_mm'], (float)$run['width_mm'], (float)$run['height_mm'], 'F' );
			}
		} );
	}

	private function draw_shape( $mpdf, array $op ) {
		$box = (array) ( $op['geometry']['box'] ?? array() ); $shape = (array) ( $op['shape'] ?? array() ); $fill = (array) ( $shape['fill'] ?? array() ); $stroke = (array) ( $shape['stroke'] ?? array() );
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) ); $stroke_width = max( 0.0, (float) ( $shape['stroke_width_mm'] ?? 0 ) );
		$this->with_rotation( $mpdf, $op, function () use ( $mpdf, $box, $shape, $fill, $stroke, $opacity, $stroke_width ) {
			$x=(float)$box['x_mm'];$y=(float)$box['y_mm'];$w=(float)$box['width_mm'];$h=(float)$box['height_mm'];
			if ( (float) ( $fill['alpha_unit'] ?? 1.0 ) > 0.0001 ) {
				if(method_exists($mpdf,'SetAlpha')){$mpdf->SetAlpha($opacity*(float)($fill['alpha_unit']??1.0));}$mpdf->SetFillColor((int)($fill['r']??0),(int)($fill['g']??0),(int)($fill['b']??0));
				if('rectangle'===(string)($shape['kind']??'')){$mpdf->Rect($x,$y,$w,$h,'F');}else{$mpdf->Ellipse($x+$w/2.0,$y+$h/2.0,$w/2.0,$h/2.0,'F');}
			}
			if($stroke_width>0.0){if(method_exists($mpdf,'SetAlpha')){$mpdf->SetAlpha($opacity*(float)($stroke['alpha_unit']??1.0));}$mpdf->SetDrawColor((int)($stroke['r']??0),(int)($stroke['g']??0),(int)($stroke['b']??0));$mpdf->SetLineWidth($stroke_width);if('rectangle'===(string)($shape['kind']??'')){$mpdf->Rect($x,$y,$w,$h,'D');}else{$mpdf->Ellipse($x+$w/2.0,$y+$h/2.0,$w/2.0,$h/2.0,'D');}}
		} );
	}
	private function draw_line( $mpdf, array $op ) {
		$l=(array)($op['line']??array());$c=(array)($l['stroke']??array());$opacity=max(0.0,min(1.0,(float)($op['opacity']??1.0)))*max(0.0,min(1.0,(float)($c['alpha_unit']??1.0)));
		$this->with_rotation($mpdf,$op,function()use($mpdf,$l,$c,$opacity){if(method_exists($mpdf,'SetAlpha')){$mpdf->SetAlpha($opacity);}$mpdf->SetDrawColor((int)($c['r']??0),(int)($c['g']??0),(int)($c['b']??0));$mpdf->SetLineWidth(max(0.0,(float)($l['stroke_width_mm']??0)));$mpdf->Line((float)$l['from']['x_mm'],(float)$l['from']['y_mm'],(float)$l['to']['x_mm'],(float)$l['to']['y_mm']);});
	}
	private function draw_image( $mpdf, array $plan, array $op ) {
		$asset=(array)($op['image']['asset']??array());$hash=(string)($asset['sha256']??'');$path=$plan['_runtime']['assets'][$hash]['path']??'';$this->assert_exact_image_file($path,$asset);
		$p=(array)($op['image']['placement']??array());$d=(array)($p['destination']??array());$crop=(array)($p['source_crop']??array());$use=$path;$temporary='';
		$crop_x=(float)($crop['x']??0.0);$crop_y=(float)($crop['y']??0.0);$crop_w=(float)($crop['width']??1.0);$crop_h=(float)($crop['height']??1.0);
		if($crop_x>0.000001||$crop_y>0.000001||$crop_w<0.999999||$crop_h<0.999999){$temporary=$this->native_crop_asset($path,$crop_x,$crop_y,$crop_w,$crop_h);$use=$temporary;}
		$opacity=max(0.0,min(1.0,(float)($op['opacity']??1.0)));
		try{$this->with_rotation($mpdf,$op,function()use($mpdf,$use,$d,$opacity){if(method_exists($mpdf,'SetAlpha')){$mpdf->SetAlpha($opacity);} $result=$mpdf->Image($use,(float)$d['x_mm'],(float)$d['y_mm'],(float)$d['width_mm'],(float)$d['height_mm'],'','',true,false);if(false===$result){throw new RuntimeException('mPDF candidate image placement failed.');}});}finally{if(''!==$temporary&&is_file($temporary)){@unlink($temporary);}}
	}
	/** Exact-font native layout helpers mirroring the accepted GD geometry contract. */
	private function native_font_runtime( array $plan, array $font, array $aliases ) {
		$faces=isset($font['faces'])&&is_array($font['faces'])?array_values($font['faces']):array();if(1!==count($faces)){throw new RuntimeException('mPDF native text requires one exact proven font face.');}
		$hash=strtolower((string)($faces[0]['sha256']??''));$path=$plan['_runtime']['fonts'][$hash]['path']??'';if(''===$path||!is_file($path)||empty($aliases[$hash])){throw new RuntimeException('mPDF native text font runtime is unavailable.');}
		return array((string)$aliases[$hash],(string)$path);
	}
	private function native_visual_text( $value, $direction ) { if(1===preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u',(string)$value)){$shaper = new ArabicPresentationShaper();return $shaper->visual_text((string)$value,(string)$direction);}return(string)$value; }
	private function native_text_metrics( $visual, $font_path, $size, $spacing_pt = 0.0 ) {
		$spacing_pt=(float)$spacing_pt;$box=imagettfbbox((float)$size,0.0,(string)$font_path,(string)$visual);if(false===$box){throw new RuntimeException('mPDF candidate could not measure exact-font text.');}
		$xs=array($box[0],$box[2],$box[4],$box[6]);$ys=array($box[1],$box[3],$box[5],$box[7]);$minx=(float)min($xs);$maxx=(float)max($xs);$miny=(float)min($ys);$maxy=(float)max($ys);
		if(abs($spacing_pt)>0.0001){$clusters=$this->native_graphemes($visual);$width=0.0;$first=true;$miny2=0.0;$maxy2=0.0;foreach($clusters as$cluster){$b=imagettfbbox((float)$size,0.0,(string)$font_path,(string)$cluster);if(false===$b){continue;}$xx=array($b[0],$b[2],$b[4],$b[6]);$yy=array($b[1],$b[3],$b[5],$b[7]);$cw=(float)(max($xx)-min($xx));$width+=$cw;if(!$first){$width+=$spacing_pt*96.0/72.0;}$miny2=$first?(float)min($yy):min($miny2,(float)min($yy));$maxy2=$first?(float)max($yy):max($maxy2,(float)max($yy));$first=false;}return array('min_x'=>0.0,'max_x'=>$width,'min_y'=>$miny2,'max_y'=>$maxy2,'width'=>$width,'height'=>$maxy2-$miny2);}
		return array('min_x'=>$minx,'max_x'=>$maxx,'min_y'=>$miny,'max_y'=>$maxy,'width'=>$maxx-$minx,'height'=>$maxy-$miny);
	}
	private function native_graphemes( $value ) {if(''===(string)$value){return array();}if(1===preg_match_all('/\X/u',(string)$value,$m)&&!empty($m[0])){return array_values($m[0]);}$c=preg_split('//u',(string)$value,-1,PREG_SPLIT_NO_EMPTY);return false===$c?array((string)$value):array_values($c);}
	private function native_draw_visual( $mpdf, $alias, $font_path, $size, $x_mm, $baseline_mm, $visual, $spacing_pt = 0.0 ) {
		$mpdf->SetFont($alias,'',(float)$size);if(abs((float)$spacing_pt)<0.0001){$mpdf->Text((float)$x_mm,(float)$baseline_mm,(string)$visual);return;}
		$cursor_px=$this->mm_to_px((float)$x_mm);$spacing_px=(float)$spacing_pt*96.0/72.0;foreach($this->native_graphemes($visual)as$cluster){$m=$this->native_text_metrics($cluster,$font_path,$size,0.0);$mpdf->Text($this->px_to_mm($cursor_px-(float)$m['min_x']),(float)$baseline_mm,(string)$cluster);$cursor_px+=(float)$m['width']+$spacing_px;}
	}
	private function native_layout_lines( $value, $direction, $overflow, $font_path, $size, $width_mm, $height_mm, $line_height, $spacing_pt = 0.0 ) {
		$min=5.0;$width_px=$this->mm_to_px($width_mm);$height_px=$this->mm_to_px($height_mm);
		if('wrap'===$overflow){return array('size_pt'=>$size,'lines'=>$this->native_wrap_lines($value,$direction,$font_path,$size,$width_px,$spacing_pt));}
		$lines=preg_split('/\R/u',(string)$value);if(false===$lines||empty($lines)){$lines=array((string)$value);}if('shrink'===$overflow){while($size>$min){$max=0.0;foreach($lines as$line){$m=$this->native_text_metrics($this->native_visual_text($line,$direction),$font_path,$size,$spacing_pt);$max=max($max,(float)$m['width']);}$total=count($lines)*$size*96.0/72.0*$line_height;if($max<=$width_px+0.01&&$total<=$height_px+0.01){break;}$size=max($min,$size-0.25);}}
		return array('size_pt'=>$size,'lines'=>array_values($lines));
	}
	private function native_wrap_lines( $value, $direction, $font_path, $size, $width_px, $spacing_pt=0.0 ) {$paragraphs=preg_split('/\R/u',(string)$value);if(false===$paragraphs){return array((string)$value);}$out=array();foreach($paragraphs as$paragraph){$words=preg_split('/\s+/u',trim($paragraph));if(false===$words||empty($words)||''===$words[0]){$out[]='';continue;}$line='';foreach($words as$word){$candidate=''===$line?$word:$line.' '.$word;$m=$this->native_text_metrics($this->native_visual_text($candidate,$direction),$font_path,$size,$spacing_pt);if(''!==$line&&(float)$m['width']>$width_px){$out[]=$line;$line=$word;}else{$line=$candidate;}}$out[]=$line;}return$out?:array('');}

	/** Native rich-text layout mirrors the GD candidate's immutable layout geometry. */
	private function native_rich_layout( array $runs, $direction, $overflow, $scale, $width_px, $line_height ) {
		$lines = array(); $current = array(); $current_width = 0.0; $current_height = 0.0; $max_width = 0.0;
		$flush = function () use ( &$lines, &$current, &$current_width, &$current_height, &$max_width ) {
			$height = max( 1.0, $current_height ); $lines[] = array( 'fragments' => $current, 'width' => $current_width, 'height' => $height );
			$max_width = max( $max_width, $current_width ); $current = array(); $current_width = 0.0; $current_height = 0.0;
		};
		foreach ( $runs as $run ) {
			$parts = preg_split( '/(\\R|[ \	]+)/u', (string) $run['value'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			if ( false === $parts ) { $parts = array( (string) $run['value'] ); }
			foreach ( $parts as $part ) {
				if ( 1 === preg_match( '/^\\R$/u', $part ) ) { $flush(); continue; }
				$size = max( 1.0, (float) ( $run['font']['size_pt'] ?? 12.0 ) * (float) $scale );
				$visual = $this->native_visual_text( $part, $direction ); $spacing_pt = (float) ( $run['spacing_pt'] ?? 0.0 ) * (float) $scale;
				$metrics = $this->native_text_metrics( $visual, (string) $run['path'], $size, $spacing_pt ); $fragment_width = (float) $metrics['width'];
				$is_space = 1 === preg_match( '/^[ \	]+$/u', $part );
				if ( 'wrap' === $overflow && ! empty( $current ) && ! $is_space && $current_width + $fragment_width > $width_px + 0.01 ) { $flush(); }
				if ( 'wrap' === $overflow && empty( $current ) && $is_space ) { continue; }
				$fragment_height = max( 1.0, $size * 96.0 / 72.0 * (float) $line_height );
				$current[] = array( 'visual'=>$visual, 'path'=>(string)$run['path'], 'alias'=>(string)$run['alias'], 'size_pt'=>$size, 'color'=>(array)$run['color'], 'metrics'=>$metrics, 'width'=>$fragment_width, 'spacing_pt'=>$spacing_pt );
				$current_width += $fragment_width; $current_height = max( $current_height, $fragment_height );
			}
		}
		if ( ! empty( $current ) || empty( $lines ) ) { $flush(); }
		$height = 0.0; foreach ( $lines as $line ) { $height += (float) $line['height']; }
		return array( 'lines'=>$lines, 'height'=>$height, 'max_width'=>$max_width );
	}

	/** Draw at an mPDF font size while keeping GD/FreeType layout advances authoritative. */
	private function native_draw_visual_at_layout( $mpdf, $alias, $font_path, $layout_size, $draw_size, $x_mm, $baseline_mm, $visual, $spacing_pt = 0.0 ) {
		$mpdf->SetFont( (string) $alias, '', (float) $draw_size );
		if ( abs( (float) $spacing_pt ) < 0.0001 ) { $mpdf->Text( (float) $x_mm, (float) $baseline_mm, (string) $visual ); return; }
		$cursor_px = $this->mm_to_px( (float) $x_mm ); $spacing_px = (float) $spacing_pt * 96.0 / 72.0;
		foreach ( $this->native_graphemes( $visual ) as $cluster ) {
			$layout_metrics = $this->native_text_metrics( $cluster, $font_path, $layout_size, 0.0 );
			$mpdf->Text( $this->px_to_mm( $cursor_px - (float) $layout_metrics['min_x'] ), (float) $baseline_mm, (string) $cluster );
			$cursor_px += (float) $layout_metrics['width'] + $spacing_px;
		}
	}

	private function native_crop_asset( $path, $x, $y, $w, $h ) {$bytes=@file_get_contents((string)$path);$src=is_string($bytes)?@imagecreatefromstring($bytes):false;if(false===$src){throw new RuntimeException('mPDF candidate crop could not decode the local image.');}try{$sw=imagesx($src);$sh=imagesy($src);$sx=(int)($x*$sw);$sy=(int)($y*$sh);$cw=max(1,(int)($w*$sw));$ch=max(1,(int)($h*$sh));$crop=@imagecrop($src,array('x'=>$sx,'y'=>$sy,'width'=>$cw,'height'=>$ch));if(false===$crop){throw new RuntimeException('mPDF candidate local image crop failed.');}try{$base=tempnam( '' !== $this->temp_dir ? $this->temp_dir : sys_get_temp_dir(), 'clmpdf_' );if(false===$base){throw new RuntimeException('mPDF candidate could not allocate a local crop path.');}$tmp=$base.'.png';@unlink($base);imagesavealpha($crop,true);$png_filters = defined( 'PNG_ALL_FILTERS' ) ? PNG_ALL_FILTERS : -1; if(!imagepng($crop,$tmp,9,$png_filters)){throw new RuntimeException('mPDF candidate could not encode the local crop.');}return$tmp;}finally{imagedestroy($crop);}}finally{imagedestroy($src);}}
	private function mm_to_px( $mm ) { return (float)$mm / 25.4 * 96.0; }
	private function px_to_mm( $px ) { return (float)$px / 96.0 * 25.4; }

	private function assert_exact_image_file( $path, array $asset ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) ) { throw new RuntimeException( 'Renderer candidate local image path is unavailable.' ); }
		$size = filesize( $path ); $hash = hash_file( 'sha256', $path ); $image = @getimagesize( $path );
		if ( false === $size || ! is_string( $hash ) || ! hash_equals( (string) $asset['sha256'], $hash ) || (int) $size !== (int) $asset['size'] || ! is_array( $image ) || (int) $image[0] !== (int) $asset['width_px'] || (int) $image[1] !== (int) $asset['height_px'] || strtolower( (string) ( $image['mime'] ?? '' ) ) !== strtolower( (string) $asset['mime'] ) ) {
			throw new RuntimeException( 'Renderer candidate local image changed after coordinator verification.' );
		}
	}
}
