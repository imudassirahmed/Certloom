<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\RendererInterface;
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
			'adapter' => 'certloom-mpdf-render-plan-v1',
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
			case 'text': $this->draw_text( $mpdf, $op, $font_aliases ); break;
			case 'rich_text': $this->draw_rich_text( $mpdf, $op, $font_aliases ); break;
			case 'table': $this->draw_table( $mpdf, $op, $font_aliases ); break;
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

	private function html_text_style( array $text, array $aliases ) {
		$font = (array) ( $text['font'] ?? array() );
		$alias = $this->font_alias( $font, $aliases );
		$color = (array) ( $text['color'] ?? array() );
		return 'font-family:' . $alias . ';font-size:' . $this->num( $font['size_pt'] ?? 0 ) . 'pt;line-height:' . $this->num( $font['line_height'] ?? 1.2 ) . ';letter-spacing:' . $this->num( $font['letter_spacing_pt'] ?? 0 ) . 'pt;color:rgb(' . (int) ( $color['r'] ?? 0 ) . ',' . (int) ( $color['g'] ?? 0 ) . ',' . (int) ( $color['b'] ?? 0 ) . ');';
	}

	private function draw_rich_text( $mpdf, array $op, array $aliases ) {
		$rich = isset( $op['rich_text'] ) && is_array( $op['rich_text'] ) ? $op['rich_text'] : array();
		$box = (array) ( $op['geometry']['box'] ?? array() );
		$base = array( 'font' => (array) ( $rich['base_font'] ?? array() ), 'color' => (array) ( $rich['base_color'] ?? array() ) );
		$body = '';
		foreach ( (array) ( $rich['runs'] ?? array() ) as $run ) {
			if ( ! is_array( $run ) ) { continue; }
			$run_text = array( 'font' => (array) ( $run['font'] ?? array() ), 'color' => (array) ( $run['color'] ?? array() ) );
			$body .= '<span style="' . $this->html_text_style( $run_text, $aliases ) . '">' . htmlspecialchars( (string) ( $run['value'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</span>';
		}
		$direction = (string) ( $rich['direction'] ?? 'ltr' );
		$align = (string) ( $rich['align'] ?? 'center' );
		$vertical = (string) ( $rich['vertical_align'] ?? 'middle' );
		$style = $this->html_text_style( $base, $aliases ) . 'text-align:' . $align . ';direction:' . $direction . ';unicode-bidi:plaintext;margin:0;padding:0;white-space:pre-wrap;';
		$html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;height:100%;margin:0;padding:0"><tr><td dir="' . $direction . '" align="' . $align . '" valign="' . $vertical . '" style="' . $style . '">' . $body . '</td></tr></table>';
		$overflow = 'shrink' === (string) ( $rich['overflow'] ?? 'shrink' ) ? 'auto' : 'hidden';
		$this->with_rotation( $mpdf, $op, function () use ( $mpdf, $html, $box, $overflow ) {
			$mpdf->WriteFixedPosHTML( $html, $box['x_mm'], $box['y_mm'], $box['width_mm'], $box['height_mm'], $overflow );
		} );
	}

	private function draw_table( $mpdf, array $op, array $aliases ) {
		$table = isset( $op['table'] ) && is_array( $op['table'] ) ? $op['table'] : array();
		$outer = (array) ( $op['geometry']['box'] ?? array() );
		$grid = isset( $table['grid'] ) && is_array( $table['grid'] ) ? $table['grid'] : array();
		$border_color = (array) ( $grid['border_color'] ?? array() );
		$border_width = max( 0.0, (float) ( $grid['border_width_mm'] ?? 0 ) );
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) );
		$this->with_rotation( $mpdf, $op, function () use ( $mpdf, $table, $outer, $border_color, $border_width, $opacity, $aliases ) {
			foreach ( (array) ( $table['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$fill = (array) ( $row['fill'] ?? array() );
				foreach ( (array) ( $row['cells'] ?? array() ) as $cell ) {
					if ( ! is_array( $cell ) ) { continue; }
					$cell_box = (array) ( $cell['box'] ?? array() );
					$content = (array) ( $cell['content_box'] ?? array() );
					$x = (float) $outer['x_mm'] + (float) ( $cell_box['x_mm'] ?? 0 ); $y = (float) $outer['y_mm'] + (float) ( $cell_box['y_mm'] ?? 0 );
					$w = (float) ( $cell_box['width_mm'] ?? 0 ); $h = (float) ( $cell_box['height_mm'] ?? 0 );
					if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity * (float) ( $fill['alpha_unit'] ?? 1.0 ) ); }
					$mpdf->SetFillColor( (int) ( $fill['r'] ?? 255 ), (int) ( $fill['g'] ?? 255 ), (int) ( $fill['b'] ?? 255 ) );
					$mpdf->Rect( $x, $y, $w, $h, 'F' );
					if ( $border_width > 0 ) {
						if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity * (float) ( $border_color['alpha_unit'] ?? 1.0 ) ); }
						$mpdf->SetDrawColor( (int) ( $border_color['r'] ?? 0 ), (int) ( $border_color['g'] ?? 0 ), (int) ( $border_color['b'] ?? 0 ) );
						$mpdf->SetLineWidth( $border_width ); $mpdf->Rect( $x, $y, $w, $h, 'D' );
					}
					if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity ); }
					$text = (array) ( $cell['text'] ?? array() );
					$cx = (float) $outer['x_mm'] + (float) ( $content['x_mm'] ?? 0 ); $cy = (float) $outer['y_mm'] + (float) ( $content['y_mm'] ?? 0 );
					$cw = (float) ( $content['width_mm'] ?? 0 ); $ch = (float) ( $content['height_mm'] ?? 0 );
					$escaped = htmlspecialchars( (string) ( $text['value'] ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
					$direction = (string) ( $text['direction'] ?? 'ltr' ); $align = (string) ( $text['align'] ?? 'left' ); $vertical = (string) ( $text['vertical_align'] ?? 'middle' );
					$style = $this->html_text_style( $text, $aliases ) . 'text-align:' . $align . ';direction:' . $direction . ';unicode-bidi:plaintext;margin:0;padding:0;';
					$html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;height:100%;margin:0;padding:0"><tr><td dir="' . $direction . '" align="' . $align . '" valign="' . $vertical . '" style="' . $style . '">' . $escaped . '</td></tr></table>';
					$mpdf->WriteFixedPosHTML( $html, $cx, $cy, $cw, $ch, 'shrink' === (string) ( $text['overflow'] ?? 'shrink' ) ? 'auto' : 'hidden' );
				}
			}
		} );
	}

	private function with_rotation( $mpdf, array $op, callable $draw ) {
		$rotation = (float) ( $op['geometry']['rotation_deg'] ?? 0.0 );
		if ( abs( $rotation ) < 0.0001 ) { $draw(); return; }
		if ( ! method_exists( $mpdf, 'StartTransform' ) || ! method_exists( $mpdf, 'Rotate' ) || ! method_exists( $mpdf, 'StopTransform' ) ) {
			throw new RuntimeException( 'mPDF candidate cannot apply the shared Render Plan rotation to this complex operation.' );
		}
		$center = (array) ( $op['geometry']['center'] ?? array() );
		$mpdf->StartTransform();
		try { $mpdf->Rotate( $rotation, (float) ( $center['x_mm'] ?? 0 ), (float) ( $center['y_mm'] ?? 0 ) ); $draw(); }
		finally { $mpdf->StopTransform(); }
	}


	private function draw_text( $mpdf, array $op, array $aliases ) {
		$text = $op['text']; $box = $op['geometry']['box']; $rotation = (float) $op['geometry']['rotation_deg'];
		$faces = (array) $text['font']['faces']; $hash = strtolower( (string) $faces[0]['sha256'] );
		if ( empty( $aliases[ $hash ] ) ) { throw new RuntimeException( 'mPDF candidate cannot map the exact font face.' ); }
		$alias = $aliases[ $hash ];
		$color = $text['color'];
		$escaped = htmlspecialchars( (string) $text['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$style = 'font-family:' . $alias . ';font-size:' . $this->num( $text['font']['size_pt'] ) . 'pt;line-height:' . $this->num( $text['font']['line_height'] ) . ';letter-spacing:' . $this->num( $text['font']['letter_spacing_pt'] ) . 'pt;color:rgb(' . (int) $color['r'] . ',' . (int) $color['g'] . ',' . (int) $color['b'] . ');text-align:' . $text['align'] . ';direction:' . $text['direction'] . ';unicode-bidi:plaintext;margin:0;padding:0;';
		$html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;height:100%;margin:0;padding:0"><tr><td dir="' . $text['direction'] . '" align="' . $text['align'] . '" valign="' . $text['vertical_align'] . '" style="' . $style . '">' . $escaped . '</td></tr></table>';
		$overflow = 'shrink' === $text['overflow'] ? 'auto' : 'hidden';
		if ( abs( $rotation ) < 0.0001 ) {
			$mpdf->WriteFixedPosHTML( $html, $box['x_mm'], $box['y_mm'], $box['width_mm'], $box['height_mm'], $overflow );
			return;
		}
		if ( 'ltr' !== $text['direction'] || 'wrap' === $text['overflow'] ) { throw new RuntimeException( 'Initial mPDF candidate allows arbitrary rotation only for non-wrapping LTR text until rotated complex-text OTL is independently accepted.' ); }
		$center = $op['geometry']['center'];
		$anchor = 'left'; $x = $box['x_mm'];
		if ( 'center' === $text['align'] ) { $anchor = 'middle'; $x = $center['x_mm']; }
		elseif ( 'right' === $text['align'] ) { $anchor = 'end'; $x = $box['x_mm'] + $box['width_mm']; }
		$y = $center['y_mm'] + ( $text['font']['size_mm'] * 0.35 );
		$svg = '<svg width="' . $this->num( $op['geometry']['rotated_bounds']['width_mm'] ) . 'mm" height="' . $this->num( $op['geometry']['rotated_bounds']['height_mm'] ) . 'mm" viewBox="0 0 ' . $this->num( $op['geometry']['rotated_bounds']['width_mm'] ) . ' ' . $this->num( $op['geometry']['rotated_bounds']['height_mm'] ) . '"><text x="' . $this->num( $x - $op['geometry']['rotated_bounds']['x_mm'] ) . '" y="' . $this->num( $y - $op['geometry']['rotated_bounds']['y_mm'] ) . '" text-anchor="' . $anchor . '" font-family="' . $alias . '" font-size="' . $this->num( $text['font']['size_pt'] ) . 'pt" fill="rgb(' . (int) $color['r'] . ',' . (int) $color['g'] . ',' . (int) $color['b'] . ')" transform="rotate(' . $this->num( $rotation ) . ' ' . $this->num( $center['x_mm'] - $op['geometry']['rotated_bounds']['x_mm'] ) . ' ' . $this->num( $center['y_mm'] - $op['geometry']['rotated_bounds']['y_mm'] ) . ')">' . $escaped . '</text></svg>';
		$mpdf->WriteFixedPosHTML( $svg, $op['geometry']['rotated_bounds']['x_mm'], $op['geometry']['rotated_bounds']['y_mm'], $op['geometry']['rotated_bounds']['width_mm'], $op['geometry']['rotated_bounds']['height_mm'], 'hidden' );
	}

	private function draw_machine_code( $mpdf, array $op, $payload_key ) {
		$payload = isset( $op[ $payload_key ] ) && is_array( $op[ $payload_key ] ) ? $op[ $payload_key ] : array();
		$box = $op['geometry']['box'];
		$bounds = $op['geometry']['rotated_bounds'];
		$transform = $this->svg_rotation( $op, $bounds );
		$x = (float) $box['x_mm'] - (float) $bounds['x_mm'];
		$y = (float) $box['y_mm'] - (float) $bounds['y_mm'];
		$body = '<g' . $transform . '><rect x="' . $this->num( $x ) . '" y="' . $this->num( $y ) . '" width="' . $this->num( $box['width_mm'] ) . '" height="' . $this->num( $box['height_mm'] ) . '" fill="#fff"/>';
		foreach ( isset( $payload['dark_runs'] ) && is_array( $payload['dark_runs'] ) ? $payload['dark_runs'] : array() as $run ) {
			if ( ! is_array( $run ) || ! isset( $run['x_mm'], $run['y_mm'], $run['width_mm'], $run['height_mm'] ) ) { continue; }
			$body .= '<rect x="' . $this->num( (float) $run['x_mm'] - (float) $bounds['x_mm'] ) . '" y="' . $this->num( (float) $run['y_mm'] - (float) $bounds['y_mm'] ) . '" width="' . $this->num( $run['width_mm'] ) . '" height="' . $this->num( $run['height_mm'] ) . '" fill="#000"/>';
		}
		$body .= '</g>';
		$this->write_svg_operation( $mpdf, $op, $body );
	}

	private function draw_shape( $mpdf, array $op ) {
		$box = $op['geometry']['box']; $shape = $op['shape']; $bounds = $op['geometry']['rotated_bounds'];
		$x = (float) $box['x_mm'] - (float) $bounds['x_mm']; $y = (float) $box['y_mm'] - (float) $bounds['y_mm'];
		$fill = $shape['fill']; $stroke = $shape['stroke']; $transform = $this->svg_rotation( $op, $bounds );
		$common = ' fill="rgb(' . (int) $fill['r'] . ',' . (int) $fill['g'] . ',' . (int) $fill['b'] . ')" fill-opacity="' . $this->num( $fill['alpha_unit'] ) . '" stroke="rgb(' . (int) $stroke['r'] . ',' . (int) $stroke['g'] . ',' . (int) $stroke['b'] . ')" stroke-opacity="' . $this->num( $stroke['alpha_unit'] ) . '" stroke-width="' . $this->num( $shape['stroke_width_mm'] ) . '"' . $transform;
		if ( 'rectangle' === $shape['kind'] ) {
			$body = '<rect x="' . $this->num( $x ) . '" y="' . $this->num( $y ) . '" width="' . $this->num( $box['width_mm'] ) . '" height="' . $this->num( $box['height_mm'] ) . '"' . $common . '/>';
		} else {
			$body = '<ellipse cx="' . $this->num( $x + $box['width_mm'] / 2 ) . '" cy="' . $this->num( $y + $box['height_mm'] / 2 ) . '" rx="' . $this->num( $box['width_mm'] / 2 ) . '" ry="' . $this->num( $box['height_mm'] / 2 ) . '"' . $common . '/>';
		}
		$this->write_svg_operation( $mpdf, $op, $body );
	}
	private function draw_line( $mpdf, array $op ) {
		$l = $op['line']; $bounds = $op['geometry']['rotated_bounds']; $c = $l['stroke']; $transform = $this->svg_rotation( $op, $bounds );
		$body = '<line x1="' . $this->num( $l['from']['x_mm'] - $bounds['x_mm'] ) . '" y1="' . $this->num( $l['from']['y_mm'] - $bounds['y_mm'] ) . '" x2="' . $this->num( $l['to']['x_mm'] - $bounds['x_mm'] ) . '" y2="' . $this->num( $l['to']['y_mm'] - $bounds['y_mm'] ) . '" stroke="rgb(' . (int) $c['r'] . ',' . (int) $c['g'] . ',' . (int) $c['b'] . ')" stroke-opacity="' . $this->num( $c['alpha_unit'] ) . '" stroke-width="' . $this->num( $l['stroke_width_mm'] ) . '"' . $transform . '/>';
		$this->write_svg_operation( $mpdf, $op, $body );
	}
	private function draw_image( $mpdf, array $plan, array $op ) {
		$asset = $op['image']['asset']; $hash = (string) $asset['sha256']; $path = $plan['_runtime']['assets'][ $hash ]['path'] ?? '';
		$this->assert_exact_image_file( $path, $asset );
		$bytes = @file_get_contents( $path );
		if ( ! is_string( $bytes ) || '' === $bytes ) { throw new RuntimeException( 'mPDF candidate local image bytes became unavailable.' ); }
		$p = $op['image']['placement']; $d = $p['destination']; $bounds = $op['geometry']['rotated_bounds'];
		$preserve = 'cover' === $p['mode'] ? 'xMidYMid slice' : 'none';
		$mime = (string) $asset['mime']; $transform = $this->svg_rotation( $op, $bounds );
		$body = '<image x="' . $this->num( $d['x_mm'] - $bounds['x_mm'] ) . '" y="' . $this->num( $d['y_mm'] - $bounds['y_mm'] ) . '" width="' . $this->num( $d['width_mm'] ) . '" height="' . $this->num( $d['height_mm'] ) . '" preserveAspectRatio="' . $preserve . '" xlink:href="data:' . $mime . ';base64,' . base64_encode( $bytes ) . '"' . $transform . '/>';
		$this->write_svg_operation( $mpdf, $op, $body );
	}
	private function write_svg_operation( $mpdf, array $op, $body ) {
		$bounds = $op['geometry']['rotated_bounds'];
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $this->num( $bounds['width_mm'] ) . 'mm" height="' . $this->num( $bounds['height_mm'] ) . 'mm" viewBox="0 0 ' . $this->num( $bounds['width_mm'] ) . ' ' . $this->num( $bounds['height_mm'] ) . '">' . $body . '</svg>';
		$mpdf->WriteFixedPosHTML( $svg, $bounds['x_mm'], $bounds['y_mm'], $bounds['width_mm'], $bounds['height_mm'], 'hidden' );
	}
	private function svg_rotation( array $op, array $bounds ) {
		$rotation = (float) $op['geometry']['rotation_deg'];
		if ( abs( $rotation ) < 0.0001 ) { return ''; }
		$center = $op['geometry']['center'];
		return ' transform="rotate(' . $this->num( $rotation ) . ' ' . $this->num( $center['x_mm'] - $bounds['x_mm'] ) . ' ' . $this->num( $center['y_mm'] - $bounds['y_mm'] ) . ')"';
	}
	private function assert_exact_image_file( $path, array $asset ) {
		if ( '' === (string) $path || ! is_file( $path ) || ! is_readable( $path ) ) { throw new RuntimeException( 'Renderer candidate local image path is unavailable.' ); }
		$size = filesize( $path ); $hash = hash_file( 'sha256', $path ); $image = @getimagesize( $path );
		if ( false === $size || ! is_string( $hash ) || ! hash_equals( (string) $asset['sha256'], $hash ) || (int) $size !== (int) $asset['size'] || ! is_array( $image ) || (int) $image[0] !== (int) $asset['width_px'] || (int) $image[1] !== (int) $asset['height_px'] || strtolower( (string) ( $image['mime'] ?? '' ) ) !== strtolower( (string) $asset['mime'] ) ) {
			throw new RuntimeException( 'Renderer candidate local image changed after coordinator verification.' );
		}
	}
	private function num( $value ) { return rtrim( rtrim( sprintf( '%.6F', (float) $value ), '0' ), '.' ); }
}
