<?php
namespace Certloom\Domain\Rendering;

/**
 * Canonical, renderer-independent certificate layout contract.
 *
 * The visual designer, PDF engine and image engine must all speak this schema.
 * Third-party renderer capabilities are adapters to this contract, never the
 * source of truth for stored certificate designs.
 */
final class LayoutSchema {
	const NAME = 'certloom-layout';
	const VERSION = 1;

	const PAGE_A4 = 'A4';
	const PAGE_LETTER = 'Letter';

	const ORIENTATION_PORTRAIT = 'portrait';
	const ORIENTATION_LANDSCAPE = 'landscape';

	const DIRECTION_LTR = 'ltr';
	const DIRECTION_RTL = 'rtl';
	const DIRECTION_AUTO = 'auto';

	const OVERFLOW_WRAP = 'wrap';
	const OVERFLOW_SHRINK = 'shrink';
	const OVERFLOW_CLIP = 'clip';

	/** @return array<string,array<string,array{0:float,1:float}>> */
	public static function page_dimensions() {
		return array(
			self::PAGE_A4 => array(
				self::ORIENTATION_PORTRAIT  => array( 210.0, 297.0 ),
				self::ORIENTATION_LANDSCAPE => array( 297.0, 210.0 ),
			),
			self::PAGE_LETTER => array(
				self::ORIENTATION_PORTRAIT  => array( 215.9, 279.4 ),
				self::ORIENTATION_LANDSCAPE => array( 279.4, 215.9 ),
			),
		);
	}

	/** @return string[] */
	public static function element_types() {
		return array( 'text', 'merge', 'image', 'logo', 'signature', 'shape', 'line', 'qr' );
	}
}
