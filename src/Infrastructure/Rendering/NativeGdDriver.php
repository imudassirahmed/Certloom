<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\GdDriverInterface;
use RuntimeException;

/** Thin, no-network wrapper around ext-gd primitives. */
final class NativeGdDriver implements GdDriverInterface {
	public function create_canvas( $width, $height ) {
		$image = imagecreatetruecolor( (int) $width, (int) $height );
		if ( false === $image ) { throw new RuntimeException( 'GD could not allocate the raster canvas.' ); }
		$white = imagecolorallocate( $image, 255, 255, 255 );
		imagefill( $image, 0, 0, $white );
		return $image;
	}

	public function create_transparent_canvas( $width, $height ) {
		$image = imagecreatetruecolor( max( 1, (int) $width ), max( 1, (int) $height ) );
		if ( false === $image ) { throw new RuntimeException( 'GD could not allocate a transparent operation canvas.' ); }
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		$clear = imagecolorallocatealpha( $image, 255, 255, 255, 127 );
		imagefill( $image, 0, 0, $clear );
		imagealphablending( $image, true );
		return $image;
	}

	public function allocate_color( $image, array $color, $opacity = 1.0 ) {
		$opacity = max( 0.0, min( 1.0, (float) $opacity ) ) * max( 0.0, min( 1.0, (float) ( $color['alpha_unit'] ?? 1.0 ) ) );
		$alpha = 127 - (int) round( 127 * $opacity );
		return imagecolorallocatealpha( $image, (int) $color['r'], (int) $color['g'], (int) $color['b'], max( 0, min( 127, $alpha ) ) );
	}

	public function fill( $image, $x, $y, $color ) { imagefill( $image, (int) $x, (int) $y, $color ); }
	public function filled_rectangle( $image, $x1, $y1, $x2, $y2, $color ) { imagefilledrectangle( $image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color ); }
	public function rectangle( $image, $x1, $y1, $x2, $y2, $color, $thickness = 1 ) { imagesetthickness( $image, max( 1, (int) $thickness ) ); imagerectangle( $image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color ); imagesetthickness( $image, 1 ); }
	public function filled_ellipse( $image, $cx, $cy, $width, $height, $color ) { imagefilledellipse( $image, (int) $cx, (int) $cy, max( 1, (int) $width ), max( 1, (int) $height ), $color ); }
	public function ellipse( $image, $cx, $cy, $width, $height, $color, $thickness = 1 ) { imagesetthickness( $image, max( 1, (int) $thickness ) ); imageellipse( $image, (int) $cx, (int) $cy, max( 1, (int) $width ), max( 1, (int) $height ), $color ); imagesetthickness( $image, 1 ); }
	public function line( $image, $x1, $y1, $x2, $y2, $color, $thickness = 1 ) { imagesetthickness( $image, max( 1, (int) $thickness ) ); imageline( $image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color ); imagesetthickness( $image, 1 ); }

	public function text_box( $size_pt, $angle_deg, $font_path, $text ) {
		$box = imagettfbbox( (float) $size_pt, (float) $angle_deg, (string) $font_path, (string) $text );
		if ( false === $box ) { throw new RuntimeException( 'GD could not measure exact-font text.' ); }
		return $box;
	}
	public function text( $image, $size_pt, $angle_deg, $x, $baseline_y, $color, $font_path, $text ) {
		$result = imagettftext( $image, (float) $size_pt, (float) $angle_deg, (int) round( $x ), (int) round( $baseline_y ), $color, (string) $font_path, (string) $text );
		if ( false === $result ) { throw new RuntimeException( 'GD could not draw exact-font text.' ); }
	}

	public function load_image( $path, $mime ) {
		$bytes = @file_get_contents( (string) $path );
		if ( false === $bytes || '' === $bytes ) { throw new RuntimeException( 'GD local image asset could not be read.' ); }
		$image = @imagecreatefromstring( $bytes );
		if ( false === $image ) { throw new RuntimeException( 'GD local image asset could not be decoded.' ); }
		return $image;
	}
	public function image_size( $image ) { return array( imagesx( $image ), imagesy( $image ) ); }
	public function copy_resampled( $destination, $source, $dst_x, $dst_y, $dst_w, $dst_h, $src_x, $src_y, $src_w, $src_h ) {
		if ( ! imagecopyresampled( $destination, $source, (int) $dst_x, (int) $dst_y, (int) $src_x, (int) $src_y, max( 1, (int) $dst_w ), max( 1, (int) $dst_h ), max( 1, (int) $src_w ), max( 1, (int) $src_h ) ) ) { throw new RuntimeException( 'GD could not place a verified local image.' ); }
	}
	public function rotate( $image, $angle_deg ) {
		$transparent = imagecolorallocatealpha( $image, 255, 255, 255, 127 );
		$rotated = imagerotate( $image, -1.0 * (float) $angle_deg, $transparent );
		if ( false === $rotated ) { throw new RuntimeException( 'GD could not rotate an operation canvas.' ); }
		imagealphablending( $rotated, true ); imagesavealpha( $rotated, true );
		return $rotated;
	}
	public function copy( $destination, $source, $dst_x, $dst_y ) {
		if ( ! imagecopy( $destination, $source, (int) $dst_x, (int) $dst_y, 0, 0, imagesx( $source ), imagesy( $source ) ) ) { throw new RuntimeException( 'GD could not composite a rotated operation.' ); }
	}
	public function encode( $image, $format ) {
		ob_start();
		$ok = 'png' === $format ? imagepng( $image, null, 9, PNG_ALL_FILTERS ) : imagejpeg( $image, null, 95 );
		$bytes = ob_get_clean();
		if ( ! $ok || ! is_string( $bytes ) || '' === $bytes ) { throw new RuntimeException( 'GD could not encode the requested raster artifact.' ); }
		return $bytes;
	}
	public function destroy( $image ) { if ( is_resource( $image ) ) { imagedestroy( $image ); } }
}
