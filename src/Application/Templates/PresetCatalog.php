<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\LayoutSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned factory template catalog.
 *
 * Factory presets are canonical layout candidates, not production-rendered
 * authority. Their stable keys and immutable versions allow future plugin
 * releases to add a new preset revision without rewriting customer history.
 */
final class PresetCatalog {
	const CATALOG_VERSION = 2;
	const PRESET_VERSION  = 2;

	/** @return array<string,array<string,string>> */
	public static function families() {
		return array(
			'classic'   => array( 'name' => 'Classic',   'description' => 'Formal double-border certificate with a traditional centered hierarchy.' ),
			'modern'    => array( 'name' => 'Modern',    'description' => 'Bold asymmetric certificate with a strong accent rail and editorial spacing.' ),
			'corporate' => array( 'name' => 'Corporate', 'description' => 'Structured business certificate with a branded header and disciplined information grid.' ),
			'academic'  => array( 'name' => 'Academic',  'description' => 'Institutional certificate with formal framing and a prominent achievement statement.' ),
			'elegant'   => array( 'name' => 'Elegant',   'description' => 'Refined presentation certificate with restrained decorative geometry and generous whitespace.' ),
			'minimal'   => array( 'name' => 'Minimal',   'description' => 'Quiet, typography-led certificate with minimal ornament and maximum content clarity.' ),
		);
	}

	/** @return array<string,array{size:string,orientation:string}> */
	public static function variants() {
		return array(
			'a4-landscape'     => array( 'size' => LayoutSchema::PAGE_A4,     'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE ),
			'a4-portrait'      => array( 'size' => LayoutSchema::PAGE_A4,     'orientation' => LayoutSchema::ORIENTATION_PORTRAIT ),
			'letter-landscape' => array( 'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE ),
			'letter-portrait'  => array( 'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$out = array();
		foreach ( self::families() as $family_key => $family ) {
			foreach ( self::variants() as $variant_key => $variant ) {
				$out[] = $this->build_definition( $family_key, $family, $variant_key, $variant, self::PRESET_VERSION );
			}
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	public function get( $preset_key, $version = self::PRESET_VERSION ) {
		$preset_key = sanitize_key( (string) $preset_key );
		$version = absint( $version );
		if ( $version < 1 || $version > self::PRESET_VERSION ) { return null; }
		foreach ( self::families() as $family_key => $family ) {
			foreach ( self::variants() as $variant_key => $variant ) {
				if ( $family_key . '-' . $variant_key === $preset_key ) { return $this->build_definition( $family_key, $family, $variant_key, $variant, $version ); }
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	private function build_definition( $family_key, array $family, $variant_key, array $variant, $preset_version ) {
		$dimensions = LayoutSchema::page_dimensions();
		list( $width, $height ) = $dimensions[ $variant['size'] ][ $variant['orientation'] ];
		$preset_key = $family_key . '-' . $variant_key;
		$layout = $this->layout( $family_key, $preset_key, $variant['size'], $variant['orientation'], (float) $width, (float) $height, absint( $preset_version ) );
		$layout = ( new LayoutValidator() )->normalize( $layout );
		$hash = ( new CanonicalJson() )->hash( $layout );
		return array(
			'preset_key'    => $preset_key,
			'preset_version'=> absint( $preset_version ),
			'family_key'    => $family_key,
			'family_name'   => $family['name'],
			'variant_key'   => $variant_key,
			'page_size'     => $variant['size'],
			'orientation'   => $variant['orientation'],
			'machine_key'   => 'certloom-preset-' . $preset_key,
			'name'          => $family['name'] . ' — ' . $this->variant_label( $variant['size'], $variant['orientation'] ),
			'description'   => $family['description'],
			'layout'        => $layout,
			'layout_sha256' => $hash,
		);
	}

	/** @return array<string,mixed> */
	private function layout( $family, $seed, $size, $orientation, $w, $h, $preset_version = self::PRESET_VERSION ) {
		$landscape = LayoutSchema::ORIENTATION_LANDSCAPE === $orientation;
		$m = $landscape ? 13.0 : 12.0;
		$inner_x = $m;
		$inner_y = $m;
		$inner_w = $w - ( 2 * $m );
		$inner_h = $h - ( 2 * $m );
		$title_y = $landscape ? 38.0 : 66.0;
		$name_y  = $landscape ? 73.0 : 113.0;
		$program_y = $landscape ? 108.0 : 154.0;
		$footer_y = $h - ( $landscape ? 37.0 : 47.0 );
		$qr = $landscape ? 21.0 : 23.0;
		$content_x = $m + ( $landscape ? 20.0 : 14.0 );
		$content_w = $w - ( 2 * $content_x );
		$elements = array();
		$z = 0;

		$palette = $this->palette( $family );
		$add_shape = function( $key, $x, $y, $width, $height, $shape, $fill, $stroke = '#00000000', $stroke_width = 0.0, $opacity = 1.0 ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id' => $this->uuid( $seed . ':' . $key ), 'type' => 'shape', 'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $width, 'height_mm' => $height, 'z' => $z++, 'rotation_deg' => 0.0, 'opacity' => $opacity, 'shape' => $shape, 'fill' => $fill, 'stroke' => $stroke, 'stroke_width_mm' => $stroke_width );
		};
		$add_line = function( $key, $x, $y, $width, $height, $stroke, $stroke_width = 0.4 ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id' => $this->uuid( $seed . ':' . $key ), 'type' => 'line', 'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $width, 'height_mm' => $height, 'z' => $z++, 'rotation_deg' => 0.0, 'opacity' => 1.0, 'stroke' => $stroke, 'stroke_width_mm' => $stroke_width );
		};
		$add_text = function( $key, $x, $y, $width, $height, $text, $font_size, $weight, $color, $align = 'center', $letter = 0.0 ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id' => $this->uuid( $seed . ':' . $key ), 'type' => 'text', 'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $width, 'height_mm' => $height, 'z' => $z++, 'rotation_deg' => 0.0, 'opacity' => 1.0, 'text' => $text, 'style' => $this->style( $font_size, $weight, $color, $align, 'auto', 'shrink', $letter ) );
		};
		$add_merge = function( $key, $x, $y, $width, $height, $token, $font_size, $weight, $color, $align = 'center' ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id' => $this->uuid( $seed . ':' . $key ), 'type' => 'merge', 'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $width, 'height_mm' => $height, 'z' => $z++, 'rotation_deg' => 0.0, 'opacity' => 1.0, 'token' => $token, 'style' => $this->style( $font_size, $weight, $color, $align, 'auto', 'shrink', 0.0 ) );
		};
		$add_qr = function( $key, $x, $y, $side ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id' => $this->uuid( $seed . ':' . $key ), 'type' => 'qr', 'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $side, 'height_mm' => $side, 'z' => $z++, 'rotation_deg' => 0.0, 'opacity' => 1.0, 'source' => 'verification_url', 'error_correction' => 'H', 'quiet_zone_modules' => 4 );
		};

		switch ( $family ) {
			case 'classic':
				$add_shape( 'outer-frame', $inner_x, $inner_y, $inner_w, $inner_h, 'rectangle', '#FFFFFF00', $palette['primary'], 0.9 );
				$add_shape( 'inner-frame', $inner_x + 4, $inner_y + 4, $inner_w - 8, $inner_h - 8, 'rectangle', '#FFFFFF00', $palette['accent'], 0.35 );
				$add_text( 'eyebrow', $content_x, $title_y - 14, $content_w, 8, 'CERTIFICATE OF ACHIEVEMENT', $landscape ? 10 : 9, 700, $palette['accent'], 'center', 1.2 );
				$add_text( 'title', $content_x, $title_y, $content_w, 18, 'Presented with distinction to', $landscape ? 20 : 18, 400, $palette['primary'] );
				$add_merge( 'recipient', $content_x, $name_y, $content_w, $landscape ? 24 : 31, 'recipient.name', $landscape ? 32 : 29, 700, $palette['primary'] );
				$add_line( 'name-rule', $content_x + ( $content_w * 0.16 ), $name_y + ( $landscape ? 26 : 34 ), $content_w * 0.68, 0.1, $palette['accent'], 0.55 );
				$add_text( 'completion', $content_x, $program_y - 8, $content_w, 8, 'for successfully completing', 10, 400, $palette['muted'] );
				$add_merge( 'program', $content_x, $program_y, $content_w, 18, 'program.name', $landscape ? 18 : 17, 600, $palette['primary'] );
				break;

			case 'modern':
				$rail = $landscape ? 14.0 : 11.0;
				$add_shape( 'accent-rail', $inner_x, $inner_y, $rail, $inner_h, 'rectangle', $palette['accent'], '#00000000', 0 );
				$add_shape( 'accent-block', $w - $m - ( $landscape ? 42 : 30 ), $m, $landscape ? 42 : 30, $landscape ? 42 : 54, 'rectangle', $palette['secondary'], '#00000000', 0, 0.12 );
				$left = $content_x + ( $landscape ? 4 : 2 );
				$cw = $content_w - ( $landscape ? 15 : 4 );
				$add_merge( 'issuer-top', $left, $landscape ? 27 : 38, $cw, 9, 'issuer.name', 9, 700, $palette['accent'], 'left' );
				$add_text( 'title', $left, $title_y, $cw, 22, 'Certificate of Completion', $landscape ? 25 : 23, 700, $palette['primary'], 'left' );
				$add_text( 'completion', $left, $landscape && $preset_version >= 2 ? $name_y - 11 : $name_y - 15, $cw, 8, 'Awarded to', 10, 400, $palette['muted'], 'left' );
				$add_merge( 'recipient', $left, $name_y, $cw, $landscape ? 26 : 34, 'recipient.name', $landscape ? 31 : 28, 700, $palette['primary'], 'left' );
				$add_merge( 'program', $left, $program_y, $cw, 18, 'program.name', $landscape ? 17 : 16, 600, $palette['secondary'], 'left' );
				break;

			case 'corporate':
				$header_h = $landscape ? 31.0 : 39.0;
				$add_shape( 'header', $inner_x, $inner_y, $inner_w, $header_h, 'rectangle', $palette['primary'], '#00000000', 0 );
				$add_merge( 'issuer-header', $inner_x + 10, $inner_y + 7, $inner_w - 20, 10, 'issuer.name', 10, 700, '#FFFFFF', 'left' );
				$add_text( 'header-label', $inner_x + 10, $inner_y + 18, $inner_w - 20, 8, 'OFFICIAL CREDENTIAL', 8, 600, $palette['soft'], 'left', 1.0 );
				$add_text( 'title', $content_x, $title_y + 6, $content_w, 16, 'Certificate of Achievement', $landscape ? 23 : 21, 700, $palette['primary'] );
				$add_text( 'completion', $content_x, $name_y - 11, $content_w, 8, 'This credential is awarded to', 9, 400, $palette['muted'] );
				$add_merge( 'recipient', $content_x, $name_y, $content_w, $landscape ? 24 : 31, 'recipient.name', $landscape ? 30 : 27, 700, $palette['primary'] );
				$add_merge( 'program', $content_x, $program_y, $content_w, 18, 'program.name', $landscape ? 17 : 16, 600, $palette['accent'] );
				$add_line( 'footer-rule', $content_x, $footer_y - 7, $content_w, 0.1, $palette['soft'], 0.35 );
				break;

			case 'academic':
				$add_shape( 'outer-frame', $inner_x, $inner_y, $inner_w, $inner_h, 'rectangle', '#FFFFFF00', $palette['primary'], 1.2 );
				$add_shape( 'inner-frame', $inner_x + 3, $inner_y + 3, $inner_w - 6, $inner_h - 6, 'rectangle', '#FFFFFF00', $palette['accent'], 0.35 );
				$seal = $landscape ? 18.0 : 20.0;
				$add_shape( 'seal-ring', ( $w - $seal ) / 2, $landscape ? 24 : 37, $seal, $seal, 'ellipse', '#FFFFFF00', $palette['accent'], 0.9 );
				$seal_letter_y = ( $landscape ? 24 : 37 ) + ( $landscape && $preset_version >= 2 ? 1 : 3 );
				$add_text( 'seal-letter', ( $w - $seal ) / 2, $seal_letter_y, $seal, $seal - 6, 'C', 16, 700, $palette['primary'] );
				$add_text( 'eyebrow', $content_x, $title_y, $content_w, 8, 'ACADEMIC CERTIFICATE', 9, 700, $palette['accent'], 'center', 1.4 );
				$add_text( 'title', $content_x, $title_y + 10, $content_w, 14, 'Certificate of Excellence', $landscape ? 22 : 20, 700, $palette['primary'] );
				$add_merge( 'recipient', $content_x, $name_y + 6, $content_w, $landscape ? 24 : 30, 'recipient.name', $landscape ? 30 : 27, 700, $palette['primary'] );
				$add_text( 'completion', $content_x, $program_y - ( $preset_version >= 2 ? 4 : 7 ), $content_w, 7, 'has fulfilled the requirements for', 9, 400, $palette['muted'] );
				$add_merge( 'program', $content_x, $program_y + 3, $content_w, 18, 'program.name', $landscape ? 17 : 16, 600, $palette['primary'] );
				break;

			case 'elegant':
				$corner = $landscape ? 54.0 : 46.0;
				$add_shape( 'corner-one', $m, $m, $corner, $corner, 'ellipse', $palette['accent'], '#00000000', 0, 0.10 );
				$add_shape( 'corner-two', $w - $m - $corner, $h - $m - $corner, $corner, $corner, 'ellipse', $palette['secondary'], '#00000000', 0, 0.08 );
				$add_shape( 'fine-frame', $inner_x + 2, $inner_y + 2, $inner_w - 4, $inner_h - 4, 'rectangle', '#FFFFFF00', $palette['accent'], 0.3 );
				$add_merge( 'issuer-top', $content_x, $landscape ? 29 : 46, $content_w, 8, 'issuer.name', 9, 600, $palette['muted'] );
				$add_text( 'eyebrow', $content_x, $title_y, $content_w, 8, 'WITH HONOUR', 9, 600, $palette['accent'], 'center', 1.8 );
				$add_text( 'title', $content_x, $title_y + 10, $content_w, 15, 'Certificate of Distinction', $landscape ? 22 : 20, 400, $palette['primary'] );
				$add_merge( 'recipient', $content_x, $name_y + 5, $content_w, $landscape ? 25 : 31, 'recipient.name', $landscape ? 31 : 28, 600, $palette['primary'] );
				$add_line( 'name-rule', $content_x + ( $content_w * 0.22 ), $name_y + ( $landscape ? 31 : 39 ), $content_w * 0.56, 0.1, $palette['accent'], 0.4 );
				$add_merge( 'program', $content_x, $program_y + 7, $content_w, 18, 'program.name', $landscape ? 16 : 15, 500, $palette['secondary'] );
				break;

			case 'minimal':
			default:
				$left = $content_x;
				$add_merge( 'issuer-top', $left, $landscape ? 26 : 40, $content_w, 8, 'issuer.name', 8, 700, $palette['muted'], 'left' );
				$add_text( 'eyebrow', $left, $title_y, $content_w, 8, 'CERTIFICATE', 8, 700, $palette['accent'], 'left', 1.8 );
				$add_text( 'title', $left, $title_y + 12, $content_w, 16, 'Achievement recognized.', $landscape ? 22 : 20, 400, $palette['primary'], 'left' );
				$add_merge( 'recipient', $left, $name_y + 4, $content_w, $landscape ? 27 : 36, 'recipient.name', $landscape ? 34 : 30, 700, $palette['primary'], 'left' );
				$add_line( 'rule', $left, $program_y - 8, $landscape ? 55 : 44, 0.1, $palette['accent'], 0.8 );
				$add_merge( 'program', $left, $program_y, $content_w, 18, 'program.name', $landscape ? 16 : 15, 500, $palette['secondary'], 'left' );
				break;
		}

		// Shared immutable factual footer. A factory design never invents a second credential identity.
		$details_w = $landscape ? min( 115.0, $content_w * 0.48 ) : $content_w - $qr - 12.0;
		$add_text( 'id-label', $content_x, $footer_y, $details_w, 6, 'CREDENTIAL ID', 6, 700, $palette['muted'], 'left', 0.8 );
		$add_merge( 'display-number', $content_x, $footer_y + 6, $details_w, 8, 'credential.display_number', 8, 600, $palette['primary'], 'left' );
		$add_text( 'date-label', $content_x, $footer_y + 15, $details_w, 6, 'ISSUED', 6, 700, $palette['muted'], 'left', 0.8 );
		$add_merge( 'issue-date', $content_x, $footer_y + 21, $details_w, 8, 'credential.issue_date', 8, 500, $palette['primary'], 'left' );
		$qr_x = $w - $m - $qr - 7.0;
		$qr_y = $h - $m - $qr - 7.0;
		$add_qr( 'verification-qr', $qr_x, $qr_y, $qr );
		$add_text( 'verify-label', $qr_x - 8.0, $qr_y + $qr + 1.5, $qr + 16.0, 5.5, 'SCAN TO VERIFY', 5.5, 700, $palette['muted'] );

		return array(
			'_meta' => array( 'schema' => LayoutSchema::NAME, 'schema_version' => LayoutSchema::VERSION_V1 ),
			'page'  => array( 'size' => $size, 'orientation' => $orientation, 'width_mm' => $w, 'height_mm' => $h ),
			'elements' => $elements,
		);
	}

	/** @return array<string,string> */
	private function palette( $family ) {
		$palettes = array(
			'classic'   => array( 'primary' => '#17233C', 'secondary' => '#6A4E23', 'accent' => '#B08A45', 'muted' => '#667085', 'soft' => '#E8DCC4' ),
			'modern'    => array( 'primary' => '#111827', 'secondary' => '#243B67', 'accent' => '#2563EB', 'muted' => '#667085', 'soft' => '#DCE7FB' ),
			'corporate' => array( 'primary' => '#17324D', 'secondary' => '#244E70', 'accent' => '#2D7E8B', 'muted' => '#607080', 'soft' => '#D8E4E8' ),
			'academic'  => array( 'primary' => '#3B1F2B', 'secondary' => '#603A4A', 'accent' => '#A5783B', 'muted' => '#76666C', 'soft' => '#E8DDD7' ),
			'elegant'   => array( 'primary' => '#26232B', 'secondary' => '#725A6B', 'accent' => '#B18B6A', 'muted' => '#746E78', 'soft' => '#E8E0E5' ),
			'minimal'   => array( 'primary' => '#171717', 'secondary' => '#404040', 'accent' => '#6366F1', 'muted' => '#737373', 'soft' => '#E5E5E5' ),
		);
		return $palettes[ $family ];
	}

	/** @return array<string,mixed> */
	private function style( $size, $weight, $color, $align, $direction = 'auto', $overflow = 'shrink', $letter = 0.0 ) {
		return array(
			'font_family' => 'sans-serif', 'font_size_pt' => (float) $size, 'font_weight' => (int) $weight,
			'italic' => false, 'color' => $color, 'align' => $align, 'vertical_align' => 'middle',
			'direction' => $direction, 'line_height' => 1.2, 'letter_spacing_pt' => (float) $letter, 'overflow' => $overflow,
		);
	}

	private function variant_label( $size, $orientation ) {
		return $size . ' ' . ucfirst( $orientation );
	}

	/** Stable UUIDv4-shaped element identifier derived from a factory-only seed. */
	private function uuid( $seed ) {
		$hex = substr( hash( 'sha256', 'certloom-preset:' . (string) $seed ), 0, 32 );
		$hex[12] = '4';
		$variants = array( '8', '9', 'a', 'b' );
		$hex[16] = $variants[ hexdec( $hex[16] ) % 4 ];
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}
}
