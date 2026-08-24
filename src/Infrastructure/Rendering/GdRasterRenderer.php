<?php
namespace Certloom\Infrastructure\Rendering;

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

	public function __construct( $version, $candidate_manifest_sha256, array $font_manifest, GdDriverInterface $driver = null, ArabicPresentationShaper $shaper = null ) {
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
		if ( abs( $rotation ) < 0.0001 ) { $this->draw_unrotated( $canvas, $plan, $op, 0.0, 0.0 ); return; }
		$box = $op['geometry']['box'];
		$local_w = max( 1, $this->mm_px( $box['width_mm'] ) );
		$local_h = max( 1, $this->mm_px( $box['height_mm'] ) );
		$local = $this->driver->create_transparent_canvas( $local_w, $local_h );
		try {
			$this->draw_unrotated( $local, $plan, $op, -1.0 * (float) $box['x_mm'], -1.0 * (float) $box['y_mm'] );
			$rotated = $this->driver->rotate( $local, $rotation );
			try {
				list( $rw, $rh ) = $this->driver->image_size( $rotated );
				$cx = $this->mm_px_float( $op['geometry']['center']['x_mm'] );
				$cy = $this->mm_px_float( $op['geometry']['center']['y_mm'] );
				$this->driver->copy( $canvas, $rotated, (int) round( $cx - $rw / 2.0 ), (int) round( $cy - $rh / 2.0 ) );
			} finally { $this->driver->destroy( $rotated ); }
		} finally { $this->driver->destroy( $local ); }
	}

	private function draw_unrotated( $canvas, array $plan, array $op, $offset_x_mm, $offset_y_mm ) {
		switch ( (string) $op['type'] ) {
			case 'text': $this->draw_text( $canvas, $plan, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'qr': $this->draw_qr( $canvas, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'shape': $this->draw_shape( $canvas, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'line': $this->draw_line( $canvas, $op, $offset_x_mm, $offset_y_mm ); break;
			case 'image': case 'logo': case 'signature': $this->draw_image( $canvas, $plan, $op, $offset_x_mm, $offset_y_mm ); break;
			default: throw new RuntimeException( 'GD candidate received an unsupported Render Plan operation.' );
		}
	}

	private function draw_text( $canvas, array $plan, array $op, $ox, $oy ) {
		$text = $op['text']; $font = $text['font'];
		if ( abs( (float) $font['letter_spacing_pt'] ) > 0.0001 ) { throw new RuntimeException( 'Initial GD candidate fails closed for non-zero letter spacing until glyph-run spacing is centralized.' ); }
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
		$lines = $this->layout_lines( (string) $text['value'], (string) $text['direction'], (string) $text['overflow'], $font_path, $size, $w, $h, (float) $font['line_height'] );
		$size = $lines['size_pt']; $rows = $lines['lines'];
		$line_px = $size * self::DPI / 72.0 * (float) $font['line_height'];
		$total_h = count( $rows ) * $line_px;
		$top = $y;
		if ( 'middle' === $text['vertical_align'] ) { $top = $y + ( $h - $total_h ) / 2.0; }
		elseif ( 'bottom' === $text['vertical_align'] ) { $top = $y + $h - $total_h; }
		$color = $this->driver->allocate_color( $canvas, $text['color'], (float) $op['opacity'] );
		foreach ( $rows as $index => $row ) {
			$visual = $this->visual_text( $row, (string) $text['direction'] );
			$metrics = $this->bbox_metrics( $this->driver->text_box( $size, 0.0, $font_path, $visual ) );
			$draw_x = $x - $metrics['min_x'];
			if ( 'center' === $text['align'] ) { $draw_x = $x + ( $w - $metrics['width'] ) / 2.0 - $metrics['min_x']; }
			elseif ( 'right' === $text['align'] ) { $draw_x = $x + $w - $metrics['width'] - $metrics['min_x']; }
			$line_top = $top + $index * $line_px;
			$baseline = $line_top - $metrics['min_y'] + max( 0.0, ( $line_px - $metrics['height'] ) / 2.0 );
			$this->driver->text( $canvas, $size, 0.0, $draw_x, $baseline, $color, $font_path, $visual );
		}
	}

	/** @return array{size_pt:float,lines:string[]} */
	private function layout_lines( $value, $direction, $overflow, $font_path, $size, $width_px, $height_px, $line_height ) {
		$min = 5.0;
		if ( 'wrap' === $overflow ) { return array( 'size_pt' => $size, 'lines' => $this->wrap_lines( $value, $direction, $font_path, $size, $width_px ) ); }
		$lines = preg_split( '/\R/u', $value ); if ( false === $lines || empty( $lines ) ) { $lines = array( $value ); }
		if ( 'shrink' === $overflow ) {
			while ( $size > $min ) {
				$max = 0.0; foreach ( $lines as $line ) { $max = max( $max, $this->measure_width( $this->visual_text( $line, $direction ), $font_path, $size ) ); }
				$total = count( $lines ) * $size * self::DPI / 72.0 * $line_height;
				if ( $max <= $width_px + 0.01 && $total <= $height_px + 0.01 ) { break; }
				$size = max( $min, $size - 0.25 );
			}
		}
		return array( 'size_pt' => $size, 'lines' => array_values( $lines ) );
	}

	/** @return string[] */
	private function wrap_lines( $value, $direction, $font_path, $size, $width_px ) {
		$paragraphs = preg_split( '/\R/u', $value ); if ( false === $paragraphs ) { return array( $value ); }
		$out = array();
		foreach ( $paragraphs as $paragraph ) {
			$words = preg_split( '/\s+/u', trim( $paragraph ) ); if ( false === $words || empty( $words ) || '' === $words[0] ) { $out[] = ''; continue; }
			$line = '';
			foreach ( $words as $word ) {
				$candidate = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && $this->measure_width( $this->visual_text( $candidate, $direction ), $font_path, $size ) > $width_px ) { $out[] = $line; $line = $word; }
				else { $line = $candidate; }
			}
			$out[] = $line;
		}
		return $out ?: array( '' );
	}

	private function visual_text( $value, $direction ) { return $this->contains_arabic( $value ) ? $this->shaper->visual_text( $value, $direction ) : $value; }
	private function measure_width( $visual, $font_path, $size ) { $m=$this->bbox_metrics($this->driver->text_box($size,0.0,$font_path,$visual)); return $m['width']; }
	/** @return array<string,float> */
	private function bbox_metrics( array $box ) { $xs=array($box[0],$box[2],$box[4],$box[6]); $ys=array($box[1],$box[3],$box[5],$box[7]); $minx=min($xs);$maxx=max($xs);$miny=min($ys);$maxy=max($ys); return array('min_x'=>(float)$minx,'max_x'=>(float)$maxx,'min_y'=>(float)$miny,'max_y'=>(float)$maxy,'width'=>(float)($maxx-$minx),'height'=>(float)($maxy-$miny)); }

	private function draw_qr( $canvas, array $op, $ox, $oy ) {
		$black = $this->driver->allocate_color( $canvas, array( 'r'=>0,'g'=>0,'b'=>0,'alpha_unit'=>1.0 ), (float)$op['opacity'] );
		foreach ( (array)$op['qr']['dark_runs'] as $run ) {
			$x1=$this->mm_px_float($run['x_mm']+$ox);$y1=$this->mm_px_float($run['y_mm']+$oy);$x2=$this->mm_px_float($run['x_mm']+$run['width_mm']+$ox);$y2=$this->mm_px_float($run['y_mm']+$run['height_mm']+$oy);
			$this->driver->filled_rectangle($canvas,(int)floor($x1),(int)floor($y1),(int)ceil($x2)-1,(int)ceil($y2)-1,$black);
		}
	}
	private function draw_shape( $canvas, array $op, $ox, $oy ) {
		$b=$op['geometry']['box'];$s=$op['shape'];$x1=$this->mm_px_float($b['x_mm']+$ox);$y1=$this->mm_px_float($b['y_mm']+$oy);$x2=$this->mm_px_float($b['x_mm']+$b['width_mm']+$ox);$y2=$this->mm_px_float($b['y_mm']+$b['height_mm']+$oy);$fill=$this->driver->allocate_color($canvas,$s['fill'],(float)$op['opacity']);$stroke=$this->driver->allocate_color($canvas,$s['stroke'],(float)$op['opacity']);$th=max(1,(int)round($this->mm_px_float($s['stroke_width_mm'])));
		if('rectangle'===$s['kind']){if((int)$s['fill']['alpha']>0){$this->driver->filled_rectangle($canvas,$x1,$y1,$x2,$y2,$fill);}if($s['stroke_width_mm']>0){$this->driver->rectangle($canvas,$x1,$y1,$x2,$y2,$stroke,$th);}}else{$cx=($x1+$x2)/2;$cy=($y1+$y2)/2;$w=$x2-$x1;$h=$y2-$y1;if((int)$s['fill']['alpha']>0){$this->driver->filled_ellipse($canvas,$cx,$cy,$w,$h,$fill);}if($s['stroke_width_mm']>0){$this->driver->ellipse($canvas,$cx,$cy,$w,$h,$stroke,$th);}}
	}
	private function draw_line( $canvas, array $op, $ox, $oy ) { $l=$op['line'];$c=$this->driver->allocate_color($canvas,$l['stroke'],(float)$op['opacity']);$th=max(1,(int)round($this->mm_px_float($l['stroke_width_mm'])));$this->driver->line($canvas,$this->mm_px_float($l['from']['x_mm']+$ox),$this->mm_px_float($l['from']['y_mm']+$oy),$this->mm_px_float($l['to']['x_mm']+$ox),$this->mm_px_float($l['to']['y_mm']+$oy),$c,$th); }
	private function draw_image( $canvas, array $plan, array $op, $ox, $oy ) {
		$asset=$op['image']['asset'];$hash=(string)$asset['sha256'];$path=$plan['_runtime']['assets'][$hash]['path']??'';$this->assert_exact_image_file($path,$asset);$src=$this->driver->load_image($path,$asset['mime']);
		try{list($sw,$sh)=$this->driver->image_size($src);$p=$op['image']['placement'];$d=$p['destination'];$crop=$p['source_crop'];$dx=$this->mm_px_float($d['x_mm']+$ox);$dy=$this->mm_px_float($d['y_mm']+$oy);$dw=$this->mm_px_float($d['width_mm']);$dh=$this->mm_px_float($d['height_mm']);$sx=$crop['x']*$sw;$sy=$crop['y']*$sh;$cw=$crop['width']*$sw;$ch=$crop['height']*$sh;$this->driver->copy_resampled($canvas,$src,$dx,$dy,$dw,$dh,$sx,$sy,$cw,$ch);}finally{$this->driver->destroy($src);}
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
	private function mm_px_float( $mm ) { return (float) $mm / 25.4 * self::DPI; }
}
