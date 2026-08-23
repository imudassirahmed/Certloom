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
		return array(
			'_meta' => array( 'schema' => LayoutSchema::NAME, 'schema_version' => LayoutSchema::VERSION ),
			'page' => array( 'size' => 'A4', 'orientation' => 'landscape', 'width_mm' => 297.0, 'height_mm' => 210.0 ),
			'elements' => array(),
		);
	}
}
