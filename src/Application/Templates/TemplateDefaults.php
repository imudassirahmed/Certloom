<?php
namespace Certloom\Application\Templates;

use Certloom\Domain\Rendering\LayoutSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical empty template layouts. Factory artwork belongs to PRESET-01/TPL-02. */
final class TemplateDefaults {
	/** @return array<string,mixed> */
	public static function blank_a4_landscape() {
		return self::blank( LayoutSchema::PAGE_A4, LayoutSchema::ORIENTATION_LANDSCAPE );
	}

	/** Canonical empty document for TPLUX creation flows. @return array<string,mixed> */
	public static function blank( $size, $orientation ) {
		$size = in_array( (string) $size, array( LayoutSchema::PAGE_A4, LayoutSchema::PAGE_LETTER ), true ) ? (string) $size : LayoutSchema::PAGE_A4;
		$orientation = in_array( (string) $orientation, array( LayoutSchema::ORIENTATION_LANDSCAPE, LayoutSchema::ORIENTATION_PORTRAIT ), true ) ? (string) $orientation : LayoutSchema::ORIENTATION_LANDSCAPE;
		$dimensions = LayoutSchema::page_dimensions();
		list( $width, $height ) = $dimensions[ $size ][ $orientation ];
		return array(
			'_meta' => array( 'schema' => LayoutSchema::NAME, 'schema_version' => LayoutSchema::VERSION ),
			'page' => array( 'size' => $size, 'orientation' => $orientation, 'width_mm' => (float) $width, 'height_mm' => (float) $height ),
			'elements' => array(),
		);
	}
}
