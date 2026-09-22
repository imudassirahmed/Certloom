<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Narrow GD boundary used by the production raster candidate.
 *
 * Tests can replace this driver without defining fake global GD functions. The
 * production implementation remains a thin wrapper around ext-gd only.
 */
interface GdDriverInterface {
	public function create_canvas( $width, $height );
	public function create_transparent_canvas( $width, $height );
	public function allocate_color( $image, array $color, $opacity = 1.0 );
	public function fill( $image, $x, $y, $color );
	public function filled_rectangle( $image, $x1, $y1, $x2, $y2, $color );
	public function rectangle( $image, $x1, $y1, $x2, $y2, $color, $thickness = 1 );
	public function filled_ellipse( $image, $cx, $cy, $width, $height, $color );
	public function ellipse( $image, $cx, $cy, $width, $height, $color, $thickness = 1 );
	public function line( $image, $x1, $y1, $x2, $y2, $color, $thickness = 1 );
	/** @return array<int,int|float> */
	public function text_box( $size_pt, $angle_deg, $font_path, $text );
	public function text( $image, $size_pt, $angle_deg, $x, $baseline_y, $color, $font_path, $text );
	public function load_image( $path, $mime );
	/** @return array{0:int,1:int} */
	public function image_size( $image );
	/** Multiply source per-pixel alpha by operation opacity. */
	public function multiply_opacity( $image, $opacity );
	public function copy_resampled( $destination, $source, $dst_x, $dst_y, $dst_w, $dst_h, $src_x, $src_y, $src_w, $src_h );
	public function rotate( $image, $angle_deg );
	public function copy( $destination, $source, $dst_x, $dst_y );
	public function encode( $image, $format );
	public function destroy( $image );
}
