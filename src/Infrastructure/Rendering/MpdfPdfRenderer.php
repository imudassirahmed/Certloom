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

	public function __construct( $version, $candidate_manifest_sha256, array $font_manifest, $temp_dir = '', DeterministicPdfNormalizer $normalizer = null, CanonicalJson $canonical = null ) {
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
			'fontDir' => $font_dirs,
			'fontdata' => $font_data,
		);
		if ( ! empty( $font_aliases ) ) { $config['default_font'] = reset( $font_aliases ); }
		if ( '' !== $this->temp_dir ) { $config['tempDir'] = $this->temp_dir; }
		$mpdf = new $class( $config );
		if ( property_exists( $mpdf, 'autoScriptToLang' ) ) { $mpdf->autoScriptToLang = false; }
		if ( property_exists( $mpdf, 'autoLangToFont' ) ) { $mpdf->autoLangToFont = false; }
		if ( property_exists( $mpdf, 'useSubstitutions' ) ) { $mpdf->useSubstitutions = false; }
		if ( method_exists( $mpdf, 'SetCreator' ) ) { $mpdf->SetCreator( 'Certloom' ); }
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
			if ( 'text' !== (string) ( $operation['type'] ?? '' ) ) { continue; }
			$faces = (array) ( $operation['text']['font']['faces'] ?? array() );
			if ( 1 !== count( $faces ) ) { throw new RuntimeException( 'Initial mPDF candidate requires one exact proven face per text operation; multi-face fallback awaits shaped font runs.' ); }
			$hash = strtolower( (string) $faces[0]['sha256'] );
			if ( empty( $runtime[ $hash ]['path'] ) || ! is_file( $runtime[ $hash ]['path'] ) ) { throw new RuntimeException( 'mPDF candidate is missing an exact runtime font path.' ); }
			$path = realpath( $runtime[ $hash ]['path'] );
			if ( false === $path ) { throw new RuntimeException( 'mPDF exact runtime font path cannot be resolved.' ); }
			$alias = 'clf' . substr( $hash, 0, 20 );
			$aliases[ $hash ] = $alias;
			$data[ $alias ] = array( 'R' => basename( $path ), 'useOTL' => 0xFF );
			$dirs[ dirname( $path ) ] = dirname( $path );
		}
		ksort( $data, SORT_STRING ); ksort( $dirs, SORT_STRING ); ksort( $aliases, SORT_STRING );
		return array( $data, array_values( $dirs ), $aliases );
	}

	private function draw_operation( $mpdf, array $plan, array $op, array $font_aliases ) {
		$opacity = max( 0.0, min( 1.0, (float) ( $op['opacity'] ?? 1.0 ) ) );
		if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( $opacity ); }
		switch ( (string) $op['type'] ) {
			case 'text': $this->draw_text( $mpdf, $op, $font_aliases ); break;
			case 'qr': $this->draw_qr( $mpdf, $op ); break;
			case 'shape': $this->draw_shape( $mpdf, $op ); break;
			case 'line': $this->draw_line( $mpdf, $op ); break;
			case 'image': case 'logo': case 'signature': $this->draw_image( $mpdf, $plan, $op ); break;
			default: throw new RuntimeException( 'mPDF candidate received an unsupported Render Plan operation.' );
		}
		if ( method_exists( $mpdf, 'SetAlpha' ) ) { $mpdf->SetAlpha( 1.0 ); }
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

	private function draw_qr( $mpdf, array $op ) {
		if ( method_exists( $mpdf, 'SetFillColor' ) ) { $mpdf->SetFillColor( 0, 0, 0 ); }
		foreach ( (array) $op['qr']['dark_runs'] as $run ) { $mpdf->Rect( $run['x_mm'], $run['y_mm'], $run['width_mm'], $run['height_mm'], 'F' ); }
	}
	private function draw_shape( $mpdf, array $op ) {
		$box=$op['geometry']['box']; $shape=$op['shape']; $fill=$shape['fill']; $stroke=$shape['stroke'];
		if ( method_exists($mpdf,'SetFillColor') ){$mpdf->SetFillColor((int)$fill['r'],(int)$fill['g'],(int)$fill['b']);}
		if ( method_exists($mpdf,'SetDrawColor') ){$mpdf->SetDrawColor((int)$stroke['r'],(int)$stroke['g'],(int)$stroke['b']);}
		if ( method_exists($mpdf,'SetLineWidth') ){$mpdf->SetLineWidth((float)$shape['stroke_width_mm']);}
		$style=(int)$fill['alpha']>0&&$shape['stroke_width_mm']>0?'DF':((int)$fill['alpha']>0?'F':'D');
		if('rectangle'===$shape['kind']){$mpdf->Rect($box['x_mm'],$box['y_mm'],$box['width_mm'],$box['height_mm'],$style);}else{$mpdf->Ellipse($box['x_mm']+$box['width_mm']/2,$box['y_mm']+$box['height_mm']/2,$box['width_mm'],$box['height_mm'],$style);}
	}
	private function draw_line( $mpdf, array $op ) { $l=$op['line']; $c=$l['stroke']; if(method_exists($mpdf,'SetDrawColor')){$mpdf->SetDrawColor((int)$c['r'],(int)$c['g'],(int)$c['b']);} if(method_exists($mpdf,'SetLineWidth')){$mpdf->SetLineWidth((float)$l['stroke_width_mm']);} $mpdf->Line($l['from']['x_mm'],$l['from']['y_mm'],$l['to']['x_mm'],$l['to']['y_mm']); }
	private function draw_image( $mpdf, array $plan, array $op ) {
		$hash=(string)$op['image']['asset']['sha256']; $path=$plan['_runtime']['assets'][$hash]['path']??''; if(''===$path||!is_file($path)){throw new RuntimeException('mPDF candidate local image path is unavailable.');}
		$p=$op['image']['placement']; if('cover'===$p['mode']){throw new RuntimeException('Initial mPDF candidate fails closed for image cover/crop until production acceptance includes that primitive.');}
		$d=$p['destination']; $mpdf->Image($path,$d['x_mm'],$d['y_mm'],$d['width_mm'],$d['height_mm'],'','',true,false);
	}
	private function num( $value ) { return rtrim( rtrim( sprintf( '%.6F', (float) $value ), '0' ), '.' ); }
}
