<?php
namespace Certloom\Application\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use InvalidArgumentException;

/**
 * Stable local/system font-family keys shared by both Certloom design studios.
 *
 * The catalog intentionally never contains remote URLs. Each named family is a
 * local-first CSS stack with conservative fallbacks. Browser authoring can use
 * any key in this catalog; Certificate artifact rendering still fails closed
 * unless the exact renderer FontManifest provides the selected key + variant.
 */
final class DesignerFontCatalog {
	const VERSION = 3;
	const LOCAL_KEY_PREFIX = 'local-';

	/** @return array<string,array{label:string,css:string,group:string,local_names:array<int,string>,generic?:bool}> */
	public function definitions() {
		$definitions = array(
			'sans-serif' => array( 'label' => 'System Sans', 'css' => 'ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif', 'group' => 'System', 'local_names' => array(), 'generic' => true ),
			'serif' => array( 'label' => 'System Serif', 'css' => 'ui-serif,Georgia,Cambria,"Times New Roman",Times,serif', 'group' => 'System', 'local_names' => array(), 'generic' => true ),
			'monospace' => array( 'label' => 'System Mono', 'css' => 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace', 'group' => 'System', 'local_names' => array(), 'generic' => true ),

			'arial' => array( 'label' => 'Arial', 'css' => 'Arial, Helvetica, sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Arial' ) ),
			'helvetica' => array( 'label' => 'Helvetica', 'css' => 'Helvetica,Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Helvetica', 'Helvetica Neue' ) ),
			'segoe-ui' => array( 'label' => 'Segoe UI', 'css' => '"Segoe UI",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Segoe UI' ) ),
			'calibri' => array( 'label' => 'Calibri', 'css' => 'Calibri,"Segoe UI",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Calibri' ) ),
			'aptos' => array( 'label' => 'Aptos', 'css' => 'Aptos,Calibri,"Segoe UI",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Aptos', 'Aptos Display' ) ),
			'verdana' => array( 'label' => 'Verdana', 'css' => 'Verdana, Geneva, sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Verdana' ) ),
			'tahoma' => array( 'label' => 'Tahoma', 'css' => 'Tahoma,Verdana,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Tahoma' ) ),
			'trebuchet-ms' => array( 'label' => 'Trebuchet MS', 'css' => 'Trebuchet MS, Arial, sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Trebuchet MS' ) ),
			'candara' => array( 'label' => 'Candara', 'css' => 'Candara,Calibri,"Segoe UI",sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Candara' ) ),
			'century-gothic' => array( 'label' => 'Century Gothic', 'css' => '"Century Gothic",CenturyGothic,AppleGothic,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Century Gothic' ) ),
			'franklin-gothic' => array( 'label' => 'Franklin Gothic', 'css' => '"Franklin Gothic Medium","Arial Narrow",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Franklin Gothic Medium', 'Franklin Gothic' ) ),
			'gill-sans' => array( 'label' => 'Gill Sans', 'css' => '"Gill Sans","Gill Sans MT",Calibri,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Gill Sans', 'Gill Sans MT' ) ),
			'optima' => array( 'label' => 'Optima', 'css' => 'Optima,"Segoe UI",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Optima' ) ),
			'futura' => array( 'label' => 'Futura', 'css' => 'Futura,"Trebuchet MS",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Futura' ) ),
			'avenir' => array( 'label' => 'Avenir', 'css' => 'Avenir,"Segoe UI",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Avenir', 'Avenir Next' ) ),
			'noto-sans' => array( 'label' => 'Noto Sans', 'css' => '"Noto Sans",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Noto Sans' ) ),
			'liberation-sans' => array( 'label' => 'Liberation Sans', 'css' => '"Liberation Sans",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'Liberation Sans' ) ),
			'dejavu-sans' => array( 'label' => 'DejaVu Sans', 'css' => '"DejaVu Sans",Arial,sans-serif', 'group' => 'Sans serif', 'local_names' => array( 'DejaVu Sans' ) ),

			'georgia' => array( 'label' => 'Georgia', 'css' => 'Georgia, Times New Roman, serif', 'group' => 'Serif', 'local_names' => array( 'Georgia' ) ),
			'times-new-roman' => array( 'label' => 'Times New Roman', 'css' => '"Times New Roman",Times,serif', 'group' => 'Serif', 'local_names' => array( 'Times New Roman' ) ),
			'cambria' => array( 'label' => 'Cambria', 'css' => 'Cambria,Georgia,"Times New Roman",serif', 'group' => 'Serif', 'local_names' => array( 'Cambria' ) ),
			'constantia' => array( 'label' => 'Constantia', 'css' => 'Constantia,Georgia,"Times New Roman",serif', 'group' => 'Serif', 'local_names' => array( 'Constantia' ) ),
			'garamond' => array( 'label' => 'Garamond', 'css' => 'Garamond,"Times New Roman",serif', 'group' => 'Serif', 'local_names' => array( 'Garamond' ) ),
			'baskerville' => array( 'label' => 'Baskerville', 'css' => 'Baskerville,Georgia,"Times New Roman",serif', 'group' => 'Serif', 'local_names' => array( 'Baskerville' ) ),
			'palatino' => array( 'label' => 'Palatino', 'css' => '"Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif', 'group' => 'Serif', 'local_names' => array( 'Palatino Linotype', 'Palatino', 'Book Antiqua' ) ),
			'didot' => array( 'label' => 'Didot', 'css' => 'Didot,"Times New Roman",serif', 'group' => 'Serif', 'local_names' => array( 'Didot' ) ),
			'noto-serif' => array( 'label' => 'Noto Serif', 'css' => '"Noto Serif",Georgia,serif', 'group' => 'Serif', 'local_names' => array( 'Noto Serif' ) ),
			'liberation-serif' => array( 'label' => 'Liberation Serif', 'css' => '"Liberation Serif","Times New Roman",serif', 'group' => 'Serif', 'local_names' => array( 'Liberation Serif' ) ),
			'dejavu-serif' => array( 'label' => 'DejaVu Serif', 'css' => '"DejaVu Serif",Georgia,serif', 'group' => 'Serif', 'local_names' => array( 'DejaVu Serif' ) ),

			'courier-new' => array( 'label' => 'Courier New', 'css' => '"Courier New",Courier,monospace', 'group' => 'Monospace', 'local_names' => array( 'Courier New' ) ),
			'consolas' => array( 'label' => 'Consolas', 'css' => 'Consolas,"Liberation Mono","Courier New",monospace', 'group' => 'Monospace', 'local_names' => array( 'Consolas' ) ),
			'menlo' => array( 'label' => 'Menlo', 'css' => 'Menlo,Monaco,Consolas,monospace', 'group' => 'Monospace', 'local_names' => array( 'Menlo' ) ),
			'monaco' => array( 'label' => 'Monaco', 'css' => 'Monaco,Menlo,Consolas,monospace', 'group' => 'Monospace', 'local_names' => array( 'Monaco' ) ),
			'liberation-mono' => array( 'label' => 'Liberation Mono', 'css' => '"Liberation Mono","Courier New",monospace', 'group' => 'Monospace', 'local_names' => array( 'Liberation Mono' ) ),
			'dejavu-sans-mono' => array( 'label' => 'DejaVu Sans Mono', 'css' => '"DejaVu Sans Mono",Consolas,monospace', 'group' => 'Monospace', 'local_names' => array( 'DejaVu Sans Mono' ) ),

			'impact' => array( 'label' => 'Impact', 'css' => 'Impact,Haettenschweiler,"Arial Narrow Bold",sans-serif', 'group' => 'Display', 'local_names' => array( 'Impact' ) ),
			'rockwell' => array( 'label' => 'Rockwell', 'css' => 'Rockwell,"Courier New",serif', 'group' => 'Display', 'local_names' => array( 'Rockwell' ) ),
		);

		return $this->with_wordpress_fonts( $definitions );
	}

	/**
	 * Add fonts that WordPress already exposes through theme/global typography
	 * settings, including locally-hosted Font Library families. No remote font
	 * discovery is performed here.
	 *
	 * @param array<string,array<string,mixed>> $definitions Base definitions.
	 * @return array<string,array<string,mixed>>
	 */
	private function with_wordpress_fonts( array $definitions ) {
		if ( ! function_exists( 'wp_get_global_settings' ) ) { return $definitions; }
		$settings = wp_get_global_settings();
		$families = is_array( $settings ) && isset( $settings['typography']['fontFamilies'] ) && is_array( $settings['typography']['fontFamilies'] ) ? $settings['typography']['fontFamilies'] : array();
		foreach ( $families as $source => $items ) {
			if ( isset( $items['fontFamily'] ) ) { $items = array( $items ); }
			if ( ! is_array( $items ) ) { continue; }
			foreach ( $items as $definition ) {
				if ( ! is_array( $definition ) || empty( $definition['fontFamily'] ) ) { continue; }
				$css = $this->safe_css_stack( $definition['fontFamily'] );
				if ( '' === $css ) { continue; }
				$label = trim( wp_strip_all_tags( (string) ( $definition['name'] ?? $definition['slug'] ?? $css ) ) );
				if ( '' === $label ) { continue; }
				$slug = strtolower( trim( (string) ( $definition['slug'] ?? '' ) ) );
				$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
				$slug = trim( (string) $slug, '-' );
				if ( '' === $slug ) { $slug = substr( hash( 'sha256', $css ), 0, 16 ); }
				$key = 'wp-' . substr( $slug, 0, 60 );
				if ( isset( $definitions[ $key ] ) ) {
					if ( $css === (string) ( $definitions[ $key ]['css'] ?? '' ) ) { continue; }
					$key = 'wp-' . substr( $slug, 0, 48 ) . '-' . substr( hash( 'sha256', $css ), 0, 8 );
				}
				$definitions[ $key ] = array(
					'label' => $label,
					'css' => $css,
					'group' => 'WordPress local fonts',
					'local_names' => $this->local_names_from_css( $css ),
					'wordpress' => true,
				);
			}
		}
		return $definitions;
	}

	/**
	 * Validate a local/system CSS font-family stack without ever accepting a
	 * remote source or executable CSS construct. Used by Email Studio for
	 * browser-discovered local families, whose names cannot be known server-side.
	 *
	 * @return string Empty string when the stack is unsafe.
	 */
	public function safe_css_stack( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 240 || preg_match( '/[{};<>\x00-\x1F]/', $value ) || preg_match( '/(?:url|expression|var)\s*\(/i', $value ) ) { return ''; }
		if ( ! preg_match( '/^[\p{L}\p{N}\p{M}\s,._\-"\'&+()\/]+$/u', $value ) ) { return ''; }
		return $value;
	}

	/** @return string|null */
	public function local_name_for_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		if ( 0 !== strpos( $key, self::LOCAL_KEY_PREFIX ) ) { return null; }
		$encoded = substr( $key, strlen( self::LOCAL_KEY_PREFIX ) );
		if ( '' === $encoded || ! preg_match( '/^[a-z2-7]+$/', $encoded ) ) { return null; }
		$bytes = $this->base32_decode( $encoded );
		if ( null === $bytes || '' === $bytes || strlen( $bytes ) > 76 || 1 !== preg_match( '//u', $bytes ) ) { return null; }
		$name = trim( $bytes );
		if ( '' === $name || preg_match( '/[{};<>\x00-\x1F]/u', $name ) ) { return null; }
		return self::LOCAL_KEY_PREFIX . $this->base32_encode( $name ) === $key ? $name : null;
	}

	/** @return string|null */
	public function key_for_local_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name || strlen( $name ) > 76 || 1 !== preg_match( '//u', $name ) || preg_match( '/[{};<>\x00-\x1F]/u', $name ) ) { return null; }
		$key = self::LOCAL_KEY_PREFIX . $this->base32_encode( $name );
		return strlen( $key ) <= 128 ? $key : null;
	}

	/** @return string */
	private function base32_encode( $bytes ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$out = ''; $buffer = 0; $bits = 0;
		for ( $i = 0, $length = strlen( $bytes ); $i < $length; $i++ ) {
			$buffer = ( $buffer << 8 ) | ord( $bytes[ $i ] ); $bits += 8;
			while ( $bits >= 5 ) { $bits -= 5; $out .= $alphabet[ ( $buffer >> $bits ) & 31 ]; }
			$buffer &= ( 1 << $bits ) - 1;
		}
		if ( $bits > 0 ) { $out .= $alphabet[ ( $buffer << ( 5 - $bits ) ) & 31 ]; }
		return $out;
	}

	/** @return string|null */
	private function base32_decode( $encoded ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$out = ''; $buffer = 0; $bits = 0;
		for ( $i = 0, $length = strlen( $encoded ); $i < $length; $i++ ) {
			$value = strpos( $alphabet, $encoded[ $i ] );
			if ( false === $value ) { return null; }
			$buffer = ( $buffer << 5 ) | $value; $bits += 5;
			if ( $bits >= 8 ) { $bits -= 8; $out .= chr( ( $buffer >> $bits ) & 255 ); $buffer &= ( 1 << $bits ) - 1; }
		}
		if ( $bits > 0 && 0 !== $buffer ) { return null; }
		return $out;
	}

	/** @return array<int,string> */
	private function local_names_from_css( $css ) {
		$generic = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-sans-serif', 'ui-serif', 'ui-monospace' );
		$out = array();
		foreach ( explode( ',', (string) $css ) as $name ) {
			$name = trim( $name, " \t\n\r\0\x0B\"'" );
			if ( '' === $name || in_array( strtolower( $name ), $generic, true ) ) { continue; }
			$out[] = $name;
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array<int,string> */
	public function keys() { return array_keys( $this->definitions() ); }

	/** @return bool */
	public function contains( $family ) {
		$family = strtolower( trim( (string) $family ) );
		$definitions = $this->definitions();
		return isset( $definitions[ $family ] ) || null !== $this->local_name_for_key( $family );
	}

	/** @return string */
	public function normalize_key( $family ) {
		$family = strtolower( trim( (string) $family ) );
		if ( ! $this->contains( $family ) ) { throw new InvalidArgumentException( 'Designer font family is not in the controlled catalog or a valid local-device font key.' ); }
		return $family;
	}

	/** @return string */
	public function browser_css( $family ) {
		$family = $this->normalize_key( $family );
		$definitions = $this->definitions();
		if ( isset( $definitions[ $family ] ) ) { return (string) $definitions[ $family ]['css']; }
		$local = $this->local_name_for_key( $family );
		if ( null === $local ) { return 'sans-serif'; }
		$escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $local );
		return '"' . $escaped . '",Arial,sans-serif';
	}

	/** @return array<int,string> */
	public function css_values() {
		$values = array();
		foreach ( $this->definitions() as $definition ) { $values[] = (string) $definition['css']; }
		return array_values( array_unique( $values ) );
	}

	/** @return string|null */
	public function key_for_css( $css ) {
		$css = trim( (string) $css );
		foreach ( $this->definitions() as $key => $definition ) {
			if ( $css === (string) $definition['css'] ) { return (string) $key; }
		}
		return null;
	}

	/** @return array<string,array{label:string,css:string,group:string,local_names:array<int,string>,generic?:bool}> */
	public function browser_contract() { return $this->definitions(); }
}
