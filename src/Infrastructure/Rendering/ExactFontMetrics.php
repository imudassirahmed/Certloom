<?php
namespace Certloom\Infrastructure\Rendering;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Measure outline geometry without GD's whole-pixel font-size quantization. */
final class ExactFontMetrics {
	const SCALE = 16;
	public static function box( $size, $angle, $path, $text ) {
		$box = imagettfbbox( (float) $size * self::SCALE, (float) $angle, (string) $path, (string) $text );
		if ( false === $box ) { throw new \RuntimeException( 'The exact local font could not be measured.' ); }
		return array_map( static function ( $coordinate ) { return (float) $coordinate / self::SCALE; }, $box );
	}

	/** Padding exposes the pen advance, including side bearings, instead of glyph ink width. */
	public static function advance( $size, $path, $text ) {
		$space = self::box( $size, 0, $path, ' ' );
		$padded = self::box( $size, 0, $path, ' ' . (string) $text . ' ' );
		return max( 0.0, max( $padded[0], $padded[2], $padded[4], $padded[6] ) - 2 * max( $space[0], $space[2], $space[4], $space[6] ) );
	}
}
