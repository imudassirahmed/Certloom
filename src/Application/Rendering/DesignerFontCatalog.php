<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Stable Designer font-family keys that are safe to author before artifact
 * renderer authority is opened.
 *
 * These are semantic local/browser roles, never remote URLs or arbitrary CSS.
 * Production PDF/PNG/JPEG still requires the renderer's exact content-addressed
 * FontManifest to provide the selected key + weight/style face; no system-font
 * fallback is created by this catalog.
 */
final class DesignerFontCatalog {
	const VERSION = 1;

	/** @return array<string,array{label:string,css:string}> */
	public function definitions() {
		return array(
			'sans-serif' => array(
				'label' => 'Sans Serif',
				'css' => 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif',
			),
			'serif' => array(
				'label' => 'Serif',
				'css' => 'ui-serif,Georgia,Cambria,"Times New Roman",Times,serif',
			),
			'monospace' => array(
				'label' => 'Monospace',
				'css' => 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace',
			),
		);
	}

	/** @return array<int,string> */
	public function keys() { return array_keys( $this->definitions() ); }

	/** @return bool */
	public function contains( $family ) {
		$family = strtolower( trim( (string) $family ) );
		$definitions = $this->definitions();
		return isset( $definitions[ $family ] );
	}

	/** @return string */
	public function normalize_key( $family ) {
		$family = strtolower( trim( (string) $family ) );
		if ( ! $this->contains( $family ) ) { throw new InvalidArgumentException( 'Designer font family is not in the controlled catalog.' ); }
		return $family;
	}

	/** @return string */
	public function browser_css( $family ) {
		$family = $this->normalize_key( $family );
		$definitions = $this->definitions();
		return (string) $definitions[ $family ]['css'];
	}

	/** @return array<string,array{label:string,css:string}> */
	public function browser_contract() { return $this->definitions(); }
}
