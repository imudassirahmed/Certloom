<?php
namespace Certloom\Domain\Rendering;

/**
 * Canonical, renderer-independent certificate layout contract.
 *
 * The visual designer, PDF engine and image engine must all speak this schema.
 * Third-party renderer capabilities are adapters to this contract, never the
 * source of truth for stored certificate designs.
 *
 * Historical versions remain readable forever. VERSION is the version used
 * for newly-authored layouts; supported_versions() is the authoritative read
 * compatibility boundary.
 */
final class LayoutSchema {
	const NAME = 'certloom-layout';
	const VERSION_V1 = 1;
	const VERSION_V2 = 2;
	const VERSION_V3 = 3;
	const VERSION = self::VERSION_V3;

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

	/** @return int[] */
	public static function supported_versions() {
		return array( self::VERSION_V1, self::VERSION_V2, self::VERSION_V3 );
	}

	/** @return bool */
	public static function supports_version( $version ) {
		return in_array( (int) $version, self::supported_versions(), true );
	}

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
	public static function element_types( $version = self::VERSION ) {
		$version = (int) $version;
		if ( ! self::supports_version( $version ) ) { return array(); }
		return ElementRegistry::types_for_schema( $version );
	}
}
