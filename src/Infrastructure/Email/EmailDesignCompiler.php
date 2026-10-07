<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Rendering\DesignerFontCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side compiler for Certloom's structured email builder.
 *
 * Studio exposes a modern nested Container mental model. Delivery remains
 * deterministic and email-client-safe: horizontal Containers compile to
 * nested presentation tables rather than CSS flexbox/grid.
 */
final class EmailDesignCompiler {
	const MAX_BLOCKS          = 80;
	const MAX_CONTAINER_DEPTH = 4;
	const RESPONSIVE_CSS = '@media only screen and (max-width:600px){.certloom-email-stack-cell{display:block!important;width:100%!important;max-width:100%!important;box-sizing:border-box!important;}}';

	/** Remove document CSS before WordPress HTML sanitization, which otherwise leaves visible CSS text. */
	public static function sanitize_inline_html( $html ) {
		$without_styles = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', (string) $html );
		// Repair templates previously stored after KSES removed the compiler's style tag.
		$without_styles = self::without_legacy_compiler_css( (string) $without_styles );
		return wp_kses_post( is_string( $without_styles ) ? $without_styles : '' );
	}

	/** Accept only known inert compiler repair and equivalent apostrophe entities. */
	public static function same_inline_html( $stored, $normalized ) {
		if ( ! is_string( $stored ) || ! is_string( $normalized ) ) { return false; }
		$canonical = static function ( $html ) { return preg_replace( '/&(?:apos|#0*39|#x0*27);/i', '&#039;', self::without_legacy_compiler_css( $html ) ); };
		return $canonical( $stored ) === $canonical( $normalized );
	}

	/** Older KSES output starts with this exact compiler-owned style tag's text. */
	private static function without_legacy_compiler_css( $html ) {
		return 0 === strpos( $html, self::RESPONSIVE_CSS ) ? substr( $html, strlen( self::RESPONSIVE_CSS ) ) : $html;
	}

	/** @return array<int,string> */
	private static function allowed_fonts() {
		return ( new DesignerFontCatalog() )->css_values();
	}

	/** @return string */
	private static function normalize_font_stack( $font ) {
		$font = trim( (string) $font );
		if ( '' === $font ) {
			return '';
		}
		if ( in_array( $font, self::allowed_fonts(), true ) ) {
			return $font;
		}

		// Device-local families discovered by Local Font Access are accepted only
		// through the shared inert CSS font-stack sanitizer. Remote URLs, CSS
		// variables and declarations remain rejected.
		return ( new DesignerFontCatalog() )->safe_css_stack( $font );
	}

	/** @return array<string,mixed> */
	public static function default_theme() {
		return array(
			'background'                  => '#f4f7f6',
			'background_image'            => '',
			'background_size'             => 'cover',
			'background_position'         => 'center',
			'background_repeat'           => 'no-repeat',
			'background_opacity'          => 100,
			'background_overlay_color'    => '#000000',
			'background_overlay_opacity'  => 0,
			'content_background'          => '#ffffff',
			'content_background_image'    => '',
			'content_background_size'     => 'cover',
			'content_background_position' => 'center',
			'content_background_repeat'   => 'no-repeat',
			'content_background_opacity'  => 100,
			'content_background_overlay_color'   => '#000000',
			'content_background_overlay_opacity' => 0,
			'text_color'                  => '#24322e',
			'heading_color'               => '#173f34',
			'button_background'           => '#1369fc',
			'button_text'                 => '#ffffff',
			'link_color'                  => '#0f6e56',
			'font_family'                 => 'Arial, Helvetica, sans-serif',
			'email_width'                 => 640,
			'border_radius'               => 12,
		);
	}

	/** @return array<string,mixed> */
	private static function default_container( $direction = 'vertical', $children = array() ) {
		return array(
			'type'                => 'container',
			'direction'           => 'horizontal' === $direction ? 'horizontal' : 'vertical',
			'gap'                 => 16,
			'basis'               => 100,
			'valign'              => 'top',
			'align'               => 'left',
			'stack_mobile'        => true,
			'padding'             => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
			'background_color'    => '',
			'background_opacity'  => 100,
			'background_image'    => '',
			'overlay_color'        => '#000000',
			'overlay_opacity'      => 0,
			'background_size'     => 'cover',
			'background_position' => 'center',
			'background_repeat'   => 'no-repeat',
			'border_color'        => '',
			'border_width'        => 0,
			'border_style'        => 'solid',
			'border_radius'       => 0,
			'min_height'          => 0,
			'children'            => is_array( $children ) ? array_values( $children ) : array(),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function default_blocks() {
		$left = self::default_container(
			'vertical',
			array(
				array(
					'type' => 'text',
					'html' => '<p><strong>' . esc_html__( 'Credential', 'certloom' ) . '</strong><br>{{credential_number}}</p>',
				),
			)
		);
		$left['basis'] = 50;

		$right = self::default_container(
			'vertical',
			array(
				array(
					'type' => 'text',
					'html' => '<p><strong>' . esc_html__( 'Issuer', 'certloom' ) . '</strong><br>{{issuer_name}}</p>',
				),
			)
		);
		$right['basis'] = 50;

		$details        = self::default_container( 'horizontal', array( $left, $right ) );
		$details['gap'] = 20;

		return array(
			array(
				'type'  => 'heading',
				'text'  => __( 'Your credential is ready', 'certloom' ),
				'level' => 1,
			),
			array(
				'type' => 'text',
				'html' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p><p>' . esc_html__( 'Your credential for {{program_title}} has been issued.', 'certloom' ) . '</p>',
			),
			array(
				'type'  => 'button',
				'text'  => __( 'View credential', 'certloom' ),
				'url'   => '{{verification_url}}',
				'align' => 'left',
			),
			array( 'type' => 'divider' ),
			$details,
		);
	}

	/** @return array<string,mixed> */
	public function normalize_theme( $theme ) {
		$theme = is_array( $theme ) ? $theme : array();
		$out   = self::default_theme();

		foreach ( array( 'background', 'content_background', 'background_overlay_color', 'content_background_overlay_color', 'text_color', 'heading_color', 'button_background', 'button_text', 'link_color' ) as $key ) {
			if ( ! isset( $theme[ $key ] ) ) {
				continue;
			}
			$hex = sanitize_hex_color( (string) $theme[ $key ] );
			if ( $hex ) {
				$out[ $key ] = $hex;
			}
		}

		// Migrate only exact former product-default color pairs. Deliberate
		// customer colors remain untouched.
		$button_background = strtolower( (string) $out['button_background'] );
		$button_text       = strtolower( (string) $out['button_text'] );
		if (
			( '#0f6e56' === $button_background && '#ffffff' === $button_text ) ||
			( '#01c174' === $button_background && '#01133d' === $button_text )
		) {
			$out['button_background'] = '#1369fc';
			$out['button_text']       = '#ffffff';
		}

		foreach ( array( 'background_image', 'content_background_image' ) as $key ) {
			if ( isset( $theme[ $key ] ) ) {
				$out[ $key ] = esc_url_raw( (string) $theme[ $key ] );
			}
		}

		foreach ( array( 'background_size', 'content_background_size' ) as $key ) {
			$value = sanitize_key( (string) ( $theme[ $key ] ?? $out[ $key ] ) );
			if ( in_array( $value, array( 'auto', 'cover', 'contain' ), true ) ) {
				$out[ $key ] = $value;
			}
		}

		foreach ( array( 'background_position', 'content_background_position' ) as $key ) {
			$value = sanitize_key( (string) ( $theme[ $key ] ?? $out[ $key ] ) );
			if ( in_array( $value, array( 'center', 'top', 'bottom', 'left', 'right' ), true ) ) {
				$out[ $key ] = $value;
			}
		}

		foreach ( array( 'background_repeat', 'content_background_repeat' ) as $key ) {
			$value = sanitize_key( (string) ( $theme[ $key ] ?? $out[ $key ] ) );
			if ( in_array( $value, array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ), true ) ) {
				$out[ $key ] = $value;
			}
		}

		foreach ( array( 'background_opacity', 'background_overlay_opacity', 'content_background_opacity', 'content_background_overlay_opacity' ) as $key ) {
			if ( isset( $theme[ $key ] ) && is_numeric( $theme[ $key ] ) ) {
				$out[ $key ] = max( 0, min( 100, (int) round( (float) $theme[ $key ] ) ) );
			}
		}

		$font = self::normalize_font_stack( $theme['font_family'] ?? '' );
		if ( '' !== $font ) {
			$out['font_family'] = $font;
		}

		$out['email_width']   = max( 480, min( 760, absint( $theme['email_width'] ?? $out['email_width'] ) ) );
		$out['border_radius'] = max( 0, min( 32, absint( $theme['border_radius'] ?? $out['border_radius'] ) ) );

		return $out;
	}

	/** @return array<string,mixed> */
	private function sanitize_block_style( $block, $type ) {
		$style = is_array( $block['style'] ?? null ) ? $block['style'] : array();
		$out   = array();

		$font = self::normalize_font_stack( $style['font_family'] ?? '' );
		if ( '' !== $font ) {
			$out['font_family'] = $font;
		}

		$size_bounds = 'heading' === $type ? array( 12, 72 ) : ( 'button' === $type ? array( 10, 28 ) : array( 10, 32 ) );
		if ( isset( $style['font_size'] ) && '' !== (string) $style['font_size'] ) {
			$out['font_size'] = max( $size_bounds[0], min( $size_bounds[1], absint( $style['font_size'] ) ) );
		}
		if ( isset( $style['font_weight'] ) && '' !== (string) $style['font_weight'] ) {
			$weight = absint( $style['font_weight'] );
			if ( in_array( $weight, array( 400, 500, 600, 700, 800, 900 ), true ) ) {
				$out['font_weight'] = $weight;
			}
		}
		if ( ! empty( $style['italic'] ) ) {
			$out['italic'] = true;
		}
		if ( ! empty( $style['underline'] ) ) {
			$out['underline'] = true;
		}

		foreach ( array( 'color', 'background_color' ) as $key ) {
			if ( ! isset( $style[ $key ] ) ) {
				continue;
			}
			$hex = sanitize_hex_color( (string) $style[ $key ] );
			if ( $hex ) {
				$out[ $key ] = $hex;
			}
		}

		if ( in_array( $type, array( 'heading', 'text', 'button' ), true ) ) {
			if ( isset( $style['letter_spacing'] ) && is_numeric( $style['letter_spacing'] ) ) {
				$out['letter_spacing'] = max( -5, min( 20, round( (float) $style['letter_spacing'], 2 ) ) );
			}
			$transform = sanitize_key( (string) ( $style['text_transform'] ?? '' ) );
			if ( in_array( $transform, array( 'none', 'uppercase', 'lowercase', 'capitalize' ), true ) ) {
				$out['text_transform'] = $transform;
			}
		}

		if ( in_array( $type, array( 'heading', 'text' ), true ) ) {
			$align = (string) ( $style['align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
				$out['align'] = $align;
			}
			if ( isset( $style['line_height'] ) && is_numeric( $style['line_height'] ) ) {
				$out['line_height'] = max( 1.0, min( 2.5, round( (float) $style['line_height'], 2 ) ) );
			}
		}

		if ( 'button' === $type ) {
			if ( isset( $style['border_radius'] ) ) {
				$out['border_radius'] = max( 0, min( 32, absint( $style['border_radius'] ) ) );
			}
			if ( isset( $style['padding_x'] ) ) {
				$out['padding_x'] = max( 8, min( 48, absint( $style['padding_x'] ) ) );
			}
			if ( isset( $style['padding_y'] ) ) {
				$out['padding_y'] = max( 6, min( 32, absint( $style['padding_y'] ) ) );
			}
			if ( isset( $style['margin_y'] ) ) {
				$out['margin_y'] = max( 0, min( 60, absint( $style['margin_y'] ) ) );
			}
			if ( isset( $style['background_opacity'] ) && is_numeric( $style['background_opacity'] ) ) {
				$out['background_opacity'] = max( 0, min( 100, (int) round( (float) $style['background_opacity'] ) ) );
			}
			if ( isset( $style['border_color'] ) ) {
				$hex = sanitize_hex_color( (string) $style['border_color'] );
				if ( $hex ) { $out['border_color'] = $hex; }
			}
			if ( isset( $style['border_width'] ) ) {
				$out['border_width'] = max( 0, min( 8, absint( $style['border_width'] ) ) );
			}
			$border_style = sanitize_key( (string) ( $style['border_style'] ?? '' ) );
			if ( in_array( $border_style, array( 'solid', 'dashed', 'dotted' ), true ) ) {
				$out['border_style'] = $border_style;
			}
			if ( ! empty( $style['full_width'] ) ) {
				$out['full_width'] = true;
			}
		}

		if ( 'image' === $type ) {
			$align = (string) ( $style['align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
				$out['align'] = $align;
			}
			if ( isset( $style['width'] ) ) {
				$out['width'] = max( 10, min( 100, absint( $style['width'] ) ) );
			}
			if ( isset( $style['border_radius'] ) ) {
				$out['border_radius'] = max( 0, min( 32, absint( $style['border_radius'] ) ) );
			}
			if ( isset( $style['opacity'] ) && is_numeric( $style['opacity'] ) ) {
				$out['opacity'] = max( 0, min( 100, (int) round( (float) $style['opacity'] ) ) );
			}
			if ( isset( $style['border_color'] ) ) {
				$hex = sanitize_hex_color( (string) $style['border_color'] );
				if ( $hex ) { $out['border_color'] = $hex; }
			}
			if ( isset( $style['border_width'] ) ) {
				$out['border_width'] = max( 0, min( 8, absint( $style['border_width'] ) ) );
			}
			$border_style = sanitize_key( (string) ( $style['border_style'] ?? '' ) );
			if ( in_array( $border_style, array( 'solid', 'dashed', 'dotted' ), true ) ) {
				$out['border_style'] = $border_style;
			}
		}

		if ( 'divider' === $type ) {
			if ( isset( $style['color'] ) ) {
				$hex = sanitize_hex_color( (string) $style['color'] );
				if ( $hex ) {
					$out['color'] = $hex;
				}
			}
			if ( isset( $style['width'] ) ) {
				$out['width'] = max( 10, min( 100, absint( $style['width'] ) ) );
			}
			if ( isset( $style['thickness'] ) ) {
				$out['thickness'] = max( 1, min( 8, absint( $style['thickness'] ) ) );
			}
			if ( isset( $style['margin_y'] ) ) {
				$out['margin_y'] = max( 4, min( 60, absint( $style['margin_y'] ) ) );
			}
			$line_style = sanitize_key( (string) ( $style['line_style'] ?? '' ) );
			if ( in_array( $line_style, array( 'solid', 'dashed', 'dotted' ), true ) ) {
				$out['line_style'] = $line_style;
			}
			$align = sanitize_key( (string) ( $style['align'] ?? '' ) );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
				$out['align'] = $align;
			}
		}

		return $out;
	}

	/** @return string */
	public function sanitize_custom_html( $html ) {
		$html = (string) $html;
		if ( ! function_exists( 'wp_kses' ) ) {
			return wp_kses_post( $html );
		}

		$common = array(
			'style'  => true,
			'align'  => true,
			'valign' => true,
			'width'  => true,
			'height' => true,
			'title'  => true,
		);
		$allowed = array(
			'table'  => $common + array( 'role' => true, 'cellspacing' => true, 'cellpadding' => true, 'border' => true, 'bgcolor' => true ),
			'thead'  => $common,
			'tbody'  => $common,
			'tfoot'  => $common,
			'tr'     => $common,
			'td'     => $common + array( 'colspan' => true, 'rowspan' => true, 'bgcolor' => true ),
			'th'     => $common + array( 'colspan' => true, 'rowspan' => true, 'scope' => true, 'bgcolor' => true ),
			'div'    => $common,
			'span'   => $common,
			'p'      => $common,
			'h1'     => $common,
			'h2'     => $common,
			'h3'     => $common,
			'h4'     => $common,
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			's'      => array(),
			'br'     => array(),
			'hr'     => $common,
			'ul'     => $common,
			'ol'     => $common,
			'li'     => $common,
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true, 'title' => true, 'style' => true ),
			'img'    => array( 'src' => true, 'alt' => true, 'title' => true, 'width' => true, 'height' => true, 'style' => true, 'align' => true, 'border' => true ),
		);
		return wp_kses( $html, $allowed );
	}

	/** @return array<string,mixed> */
	private function legacy_column_to_block( $column ) {
		$column = is_array( $column ) ? $column : array();
		$kind   = sanitize_key( (string) ( $column['kind'] ?? 'text' ) );
		if ( ! in_array( $kind, array( 'text', 'image', 'html' ), true ) ) {
			$kind = 'text';
		}

		if ( 'image' === $kind ) {
			return array(
				'type'  => 'image',
				'url'   => esc_url_raw( (string) ( $column['url'] ?? '' ) ),
				'alt'   => sanitize_text_field( (string) ( $column['alt'] ?? '' ) ),
				'link'  => $this->sanitize_url_or_token( $column['link'] ?? '' ),
				'style' => array(
					'width' => max( 10, min( 100, absint( $column['width'] ?? 100 ) ) ),
					'align' => in_array( (string) ( $column['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $column['align'] : 'center',
				),
			);
		}

		return array(
			'type' => 'html' === $kind ? 'html' : 'text',
			'html' => 'html' === $kind ? $this->sanitize_custom_html( $column['html'] ?? '' ) : wp_kses_post( (string) ( $column['html'] ?? '' ) ),
		);
	}

	/** @return array<string,mixed> */
	private function legacy_columns_to_container( array $block ) {
		$count = in_array( absint( $block['count'] ?? 2 ), array( 2, 3 ), true ) ? absint( $block['count'] ?? 2 ) : 2;
		$ratio = sanitize_key( (string) ( $block['ratio'] ?? ( 3 === $count ? '33-33-33' : '50-50' ) ) );
		$map   = array(
			'50-50'    => array( 50, 50 ),
			'33-67'    => array( 33, 67 ),
			'67-33'    => array( 67, 33 ),
			'40-60'    => array( 40, 60 ),
			'60-40'    => array( 60, 40 ),
			'33-33-33' => array( 33, 34, 33 ),
			'25-50-25' => array( 25, 50, 25 ),
		);
		$widths = $map[ $ratio ] ?? ( 3 === $count ? array( 33, 34, 33 ) : array( 50, 50 ) );
		$columns = is_array( $block['columns'] ?? null ) ? array_slice( $block['columns'], 0, $count ) : array();

		if ( ! $columns && ( isset( $block['left_html'] ) || isset( $block['right_html'] ) ) ) {
			$columns = array(
				array( 'kind' => 'text', 'html' => $block['left_html'] ?? '' ),
				array( 'kind' => 'text', 'html' => $block['right_html'] ?? '' ),
			);
			$count   = 2;
			$widths  = array( 50, 50 );
		}
		while ( count( $columns ) < $count ) {
			$columns[] = array( 'kind' => 'text', 'html' => '<p></p>' );
		}

		$children = array();
		foreach ( $columns as $index => $column ) {
			$child          = self::default_container( 'vertical', array( $this->legacy_column_to_block( $column ) ) );
			$child['basis'] = absint( $widths[ $index ] ?? ( 100 / $count ) );
			$children[]     = $child;
		}

		$out        = self::default_container( 'horizontal', $children );
		$out['gap'] = max( 0, min( 40, absint( $block['gap'] ?? 20 ) ) );
		return $out;
	}

	/** @return array<string,mixed> */
	private function sanitize_container( array $block, $depth, &$count ) {
		$out = self::default_container( (string) ( $block['direction'] ?? 'vertical' ) );

		$out['gap']          = max( 0, min( 48, absint( $block['gap'] ?? 16 ) ) );
		$out['basis']        = max( 5, min( 100, absint( $block['basis'] ?? 100 ) ) );
		$out['valign']       = in_array( (string) ( $block['valign'] ?? '' ), array( 'top', 'middle', 'bottom' ), true ) ? (string) $block['valign'] : 'top';
		$out['align']        = in_array( (string) ( $block['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $block['align'] : 'left';
		$out['stack_mobile'] = array_key_exists( 'stack_mobile', $block ) ? ! empty( $block['stack_mobile'] ) : true;

		$padding = is_array( $block['padding'] ?? null ) ? $block['padding'] : array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $edge ) {
			$out['padding'][ $edge ] = max( 0, min( 80, absint( $padding[ $edge ] ?? 0 ) ) );
		}

		if ( isset( $block['background_color'] ) ) {
			$hex = sanitize_hex_color( (string) $block['background_color'] );
			if ( $hex ) {
				$out['background_color'] = $hex;
			}
		}
		if ( isset( $block['background_opacity'] ) && is_numeric( $block['background_opacity'] ) ) {
			$out['background_opacity'] = max( 0, min( 100, (int) round( (float) $block['background_opacity'] ) ) );
		}
		$out['background_image'] = esc_url_raw( (string) ( $block['background_image'] ?? '' ) );
		if ( isset( $block['overlay_color'] ) ) {
			$hex = sanitize_hex_color( (string) $block['overlay_color'] );
			if ( $hex ) { $out['overlay_color'] = $hex; }
		}
		if ( isset( $block['overlay_opacity'] ) && is_numeric( $block['overlay_opacity'] ) ) {
			$out['overlay_opacity'] = max( 0, min( 100, (int) round( (float) $block['overlay_opacity'] ) ) );
		}

		$size = sanitize_key( (string) ( $block['background_size'] ?? 'cover' ) );
		if ( in_array( $size, array( 'auto', 'cover', 'contain' ), true ) ) {
			$out['background_size'] = $size;
		}
		$position = sanitize_key( (string) ( $block['background_position'] ?? 'center' ) );
		if ( in_array( $position, array( 'center', 'top', 'bottom', 'left', 'right' ), true ) ) {
			$out['background_position'] = $position;
		}
		$repeat = sanitize_key( (string) ( $block['background_repeat'] ?? 'no-repeat' ) );
		if ( in_array( $repeat, array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ), true ) ) {
			$out['background_repeat'] = $repeat;
		}

		if ( isset( $block['border_color'] ) ) {
			$hex = sanitize_hex_color( (string) $block['border_color'] );
			if ( $hex ) {
				$out['border_color'] = $hex;
			}
		}
		$out['border_width']  = max( 0, min( 8, absint( $block['border_width'] ?? 0 ) ) );
		$out['border_radius'] = max( 0, min( 40, absint( $block['border_radius'] ?? 0 ) ) );
		$out['min_height']    = max( 0, min( 800, absint( $block['min_height'] ?? 0 ) ) );

		$border_style = sanitize_key( (string) ( $block['border_style'] ?? 'solid' ) );
		if ( in_array( $border_style, array( 'solid', 'dashed', 'dotted' ), true ) ) {
			$out['border_style'] = $border_style;
		}

		$out['children'] = $depth < self::MAX_CONTAINER_DEPTH
			? $this->sanitize_block_list( $block['children'] ?? array(), $depth + 1, $count )
			: array();

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function sanitize_block_list( $blocks, $depth, &$count ) {
		$blocks = is_array( $blocks ) ? $blocks : array();
		$out    = array();

		foreach ( $blocks as $block ) {
			if ( $count >= self::MAX_BLOCKS || ! is_array( $block ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $block['type'] ?? '' ) );
			if ( 'columns' === $type ) {
				$block = $this->legacy_columns_to_container( $block );
				$type  = 'container';
			}

			$row            = null;
			$already_counted = false;

			switch ( $type ) {
				case 'heading':
					$text = sanitize_text_field( (string) ( $block['text'] ?? '' ) );
					if ( '' !== $text ) {
						$row = array(
							'type'  => 'heading',
							'text'  => $text,
							'level' => max( 1, min( 3, absint( $block['level'] ?? 2 ) ) ),
						);
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) {
							$row['style'] = $style;
						}
					}
					break;

				case 'text':
					$html = wp_kses_post( (string) ( $block['html'] ?? '' ) );
					if ( '' !== trim( wp_strip_all_tags( $html ) ) || false !== strpos( $html, '{{' ) ) {
						$row = array( 'type' => 'text', 'html' => $html );
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) {
							$row['style'] = $style;
						}
					}
					break;

				case 'html':
					$html = $this->sanitize_custom_html( $block['html'] ?? '' );
					if ( '' !== trim( $html ) ) {
						$row = array( 'type' => 'html', 'html' => $html );
					}
					break;

				case 'button':
					$text = sanitize_text_field( (string) ( $block['text'] ?? '' ) );
					$url  = $this->sanitize_url_or_token( $block['url'] ?? '' );
					if ( '' !== $text && '' !== $url ) {
						$row = array(
							'type'  => 'button',
							'text'  => $text,
							'url'   => $url,
							'align' => in_array( (string) ( $block['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $block['align'] : 'left',
						);
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) {
							$row['style'] = $style;
						}
					}
					break;

				case 'image':
					$url = esc_url_raw( (string) ( $block['url'] ?? '' ) );
					if ( '' !== $url ) {
						$row = array(
							'type' => 'image',
							'url'  => $url,
							'alt'  => sanitize_text_field( (string) ( $block['alt'] ?? '' ) ),
							'link' => $this->sanitize_url_or_token( $block['link'] ?? '' ),
						);
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) {
							$row['style'] = $style;
						}
					}
					break;

				case 'divider':
					$row   = array( 'type' => 'divider' );
					$style = $this->sanitize_block_style( $block, $type );
					if ( $style ) {
						$row['style'] = $style;
					}
					break;

				case 'spacer':
					$row = array(
						'type'   => 'spacer',
						'height' => max( 8, min( 80, absint( $block['height'] ?? 24 ) ) ),
					);
					break;

				case 'container':
					// Reserve the Container's own block slot before recursing so deeply
					// nested input can never exceed MAX_BLOCKS by one parent per branch.
					$count++;
					$already_counted = true;
					$row             = $this->sanitize_container( $block, $depth, $count );
					break;
			}

			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! $already_counted ) {
				$count++;
			}
			$out[] = $row;
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function sanitize_blocks( $blocks ) {
		$count = 0;
		return $this->sanitize_block_list( $blocks, 0, $count );
	}

	/** @return array<int,array<string,mixed>> */
	public function normalize_blocks( $blocks ) {
		$normalized = $this->sanitize_blocks( $blocks );
		return $normalized ? $normalized : self::default_blocks();
	}

	/** Collect custom font stacks actually referenced by the normalized design. @return array<string,bool> */
	private function used_font_stacks( array $theme, array $blocks ) {
		$used = array();
		if ( ! empty( $theme['font_family'] ) ) { $used[ (string) $theme['font_family'] ] = true; }
		$walk = function( array $items ) use ( &$walk, &$used ) {
			foreach ( $items as $block ) {
				if ( ! is_array( $block ) ) { continue; }
				$style = isset( $block['style'] ) && is_array( $block['style'] ) ? $block['style'] : array();
				if ( ! empty( $style['font_family'] ) ) { $used[ (string) $style['font_family'] ] = true; }
				if ( 'container' === (string) ( $block['type'] ?? '' ) && ! empty( $block['children'] ) && is_array( $block['children'] ) ) { $walk( $block['children'] ); }
			}
		};
		$walk( $blocks );
		return $used;
	}

	/**
	 * Emit only the site-local custom faces the current email actually uses.
	 * Unsupported mail clients keep the existing safe fallback stack.
	 * @return string
	 */
	private function custom_font_face_css( array $theme, array $blocks ) {
		$used = $this->used_font_stacks( $theme, $blocks );
		if ( empty( $used ) || ! class_exists( DesignerFontCatalog::class ) ) { return ''; }
		$rules = array();
		foreach ( ( new DesignerFontCatalog() )->definitions() as $definition ) {
			if ( empty( $definition['custom'] ) || empty( $definition['css'] ) || empty( $definition['preview_family'] ) || empty( $definition['faces'] ) || ! isset( $used[ (string) $definition['css'] ] ) ) { continue; }
			$family = (string) $definition['preview_family'];
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,96}$/', $family ) ) { continue; }
			foreach ( (array) $definition['faces'] as $face ) {
				$format = strtolower( (string) ( $face['format'] ?? '' ) );
				$url = esc_url( (string) ( $face['url'] ?? '' ) );
				$weight = (int) ( $face['weight'] ?? 400 );
				$style = 'italic' === (string) ( $face['style'] ?? '' ) ? 'italic' : 'normal';
				if ( '' === $url || ! in_array( $format, array( 'ttf', 'otf' ), true ) || $weight < 100 || $weight > 900 || 0 !== $weight % 100 ) { continue; }
				$format_label = 'otf' === $format ? 'opentype' : 'truetype';
				$rules[] = '@font-face{font-family:"' . $family . '";src:url("' . $url . '") format("' . $format_label . '");font-weight:' . $weight . ';font-style:' . $style . ';font-display:swap;}';
			}
		}
		return implode( '', $rules );
	}

	/** @return string */
	private function rgba_color( $hex, $opacity ) {
		$hex = sanitize_hex_color( (string) $hex );
		if ( ! $hex ) { return ''; }
		$raw = substr( $hex, 1 );
		$alpha = max( 0, min( 100, (float) $opacity ) ) / 100;
		return 'rgba(' . hexdec( substr( $raw, 0, 2 ) ) . ',' . hexdec( substr( $raw, 2, 2 ) ) . ',' . hexdec( substr( $raw, 4, 2 ) ) . ',' . rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) . ')';
	}

	/** @return string */
	private function style_value( array $style, $key, $fallback ) {
		return isset( $style[ $key ] ) && '' !== (string) $style[ $key ] ? (string) $style[ $key ] : (string) $fallback;
	}

	/** @return string */
	private function text_style( array $style, array $theme, $kind ) {
		$default_size   = 'heading' === $kind ? 24 : 16;
		$default_weight = 'heading' === $kind ? 700 : 400;
		$default_color  = 'heading' === $kind ? $theme['heading_color'] : $theme['text_color'];
		$family         = $this->style_value( $style, 'font_family', $theme['font_family'] );
		$size           = absint( $this->style_value( $style, 'font_size', $default_size ) );
		$weight         = absint( $this->style_value( $style, 'font_weight', $default_weight ) );
		$color          = $this->style_value( $style, 'color', $default_color );
		$align          = $this->style_value( $style, 'align', 'left' );
		$line_height    = $this->style_value( $style, 'line_height', 'heading' === $kind ? 1.25 : 1.65 );
		$font_style     = ! empty( $style['italic'] ) ? 'italic' : 'normal';
		$text_decoration = ! empty( $style['underline'] ) ? 'underline' : 'none';
		$letter_spacing  = isset( $style['letter_spacing'] ) ? (float) $style['letter_spacing'] : 0;
		$text_transform  = in_array( (string) ( $style['text_transform'] ?? '' ), array( 'none', 'uppercase', 'lowercase', 'capitalize' ), true ) ? (string) $style['text_transform'] : 'none';

		return 'color:' . esc_attr( $color ) . ';font-family:' . esc_attr( $family ) . ';font-size:' . $size . 'px;font-weight:' . $weight . ';font-style:' . $font_style . ';text-decoration:' . $text_decoration . ';text-align:' . esc_attr( $align ) . ';line-height:' . esc_attr( $line_height ) . ';letter-spacing:' . esc_attr( $letter_spacing ) . 'px;text-transform:' . esc_attr( $text_transform ) . ';';
	}

	/** @return string */
	private function compile_block( array $block, array $theme, array &$text, $depth = 0 ) {
		$style = is_array( $block['style'] ?? null ) ? $block['style'] : array();

		switch ( $block['type'] ) {
			case 'heading':
				$default_size = 1 === $block['level'] ? 30 : ( 2 === $block['level'] ? 24 : 19 );
				if ( ! isset( $style['font_size'] ) ) {
					$style['font_size'] = $default_size;
				}
				$text[] = (string) $block['text'];
				return '<h' . absint( $block['level'] ) . ' style="margin:0 0 18px;' . $this->text_style( $style, $theme, 'heading' ) . '">' . esc_html( $block['text'] ) . '</h' . absint( $block['level'] ) . '>';

			case 'text':
				$text[] = trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $block['html'] ) ) );
				return '<div style="margin:0 0 18px;' . $this->text_style( $style, $theme, 'text' ) . '">' . wp_kses_post( $block['html'] ) . '</div>';

			case 'html':
				$safe  = $this->sanitize_custom_html( $block['html'] );
				$plain = trim( wp_strip_all_tags( $safe ) );
				if ( '' !== $plain ) {
					$text[] = $plain;
				}
				return '<div style="margin:0 0 18px;">' . $safe . '</div>';

			case 'button':
				$family          = $this->style_value( $style, 'font_family', $theme['font_family'] );
				$size            = absint( $this->style_value( $style, 'font_size', 16 ) );
				$weight          = absint( $this->style_value( $style, 'font_weight', 700 ) );
				$color           = $this->style_value( $style, 'color', $theme['button_text'] );
				$background      = $this->style_value( $style, 'background_color', $theme['button_background'] );
				$background_alpha = isset( $style['background_opacity'] ) ? (int) $style['background_opacity'] : 100;
				$radius          = absint( $this->style_value( $style, 'border_radius', $theme['border_radius'] ) );
				$padding_x       = absint( $this->style_value( $style, 'padding_x', 20 ) );
				$padding_y       = absint( $this->style_value( $style, 'padding_y', 13 ) );
				$margin_y        = absint( $this->style_value( $style, 'margin_y', 4 ) );
				$font_style      = ! empty( $style['italic'] ) ? 'italic' : 'normal';
				$text_decoration = ! empty( $style['underline'] ) ? 'underline' : 'none';
				$letter_spacing  = isset( $style['letter_spacing'] ) ? (float) $style['letter_spacing'] : 0;
				$text_transform  = in_array( (string) ( $style['text_transform'] ?? '' ), array( 'none', 'uppercase', 'lowercase', 'capitalize' ), true ) ? (string) $style['text_transform'] : 'none';
				$border_width    = absint( $style['border_width'] ?? 0 );
				$border_color    = (string) ( $style['border_color'] ?? '' );
				$border_style    = in_array( (string) ( $style['border_style'] ?? '' ), array( 'solid', 'dashed', 'dotted' ), true ) ? (string) $style['border_style'] : 'solid';
				$display         = ! empty( $style['full_width'] ) ? 'block' : 'inline-block';
				$width_css       = ! empty( $style['full_width'] ) ? 'width:100%;box-sizing:border-box;text-align:center;' : '';
				$background_css  = 'background:' . esc_attr( $background ) . ';background-color:' . esc_attr( $background ) . ';';
				if ( $background_alpha < 100 ) {
					$background_css .= 'background-color:' . esc_attr( $this->rgba_color( $background, $background_alpha ) ) . ';';
				}
				$border_css = $border_width > 0 && $border_color ? 'border:' . $border_width . 'px ' . esc_attr( $border_style ) . ' ' . esc_attr( $border_color ) . ';' : 'border:0;';
				$text[]     = (string) $block['text'] . ': ' . (string) $block['url'];
				return '<div style="margin:' . $margin_y . 'px 0 22px;text-align:' . esc_attr( $block['align'] ) . ';"><a href="' . esc_attr( $block['url'] ) . '" style="display:' . $display . ';' . $width_css . $background_css . 'color:' . esc_attr( $color ) . ';font-family:' . esc_attr( $family ) . ';font-size:' . $size . 'px;font-weight:' . $weight . ';font-style:' . $font_style . ';line-height:1.2;text-decoration:' . $text_decoration . ';letter-spacing:' . esc_attr( $letter_spacing ) . 'px;text-transform:' . esc_attr( $text_transform ) . ';padding:' . $padding_y . 'px ' . $padding_x . 'px;' . $border_css . 'border-radius:' . $radius . 'px;">' . esc_html( $block['text'] ) . '</a></div>';

			case 'image':
				$align  = $this->style_value( $style, 'align', 'left' );
				$width  = absint( $this->style_value( $style, 'width', 100 ) );
				$radius       = absint( $this->style_value( $style, 'border_radius', 0 ) );
				$opacity      = max( 0, min( 100, (int) ( $style['opacity'] ?? 100 ) ) );
				$border_width = absint( $style['border_width'] ?? 0 );
				$border_color = (string) ( $style['border_color'] ?? '' );
				$border_style = in_array( (string) ( $style['border_style'] ?? '' ), array( 'solid', 'dashed', 'dotted' ), true ) ? (string) $style['border_style'] : 'solid';
				$border_css   = $border_width > 0 && $border_color ? $border_width . 'px ' . esc_attr( $border_style ) . ' ' . esc_attr( $border_color ) : '0';
				$image  = '<img src="' . esc_url( $block['url'] ) . '" alt="' . esc_attr( $block['alt'] ) . '" style="display:inline-block;width:' . $width . '%;max-width:100%;height:auto;border:' . $border_css . ';border-radius:' . $radius . 'px;opacity:' . esc_attr( $opacity / 100 ) . ';">';
				if ( ! empty( $block['link'] ) ) {
					$image = '<a href="' . esc_attr( $block['link'] ) . '" style="text-decoration:none;">' . $image . '</a>';
				}
				if ( ! empty( $block['alt'] ) ) {
					$text[] = (string) $block['alt'];
				}
				return '<div style="margin:0 0 20px;text-align:' . esc_attr( $align ) . ';">' . $image . '</div>';

			case 'divider':
				$color     = $this->style_value( $style, 'color', '#dce6e1' );
				$width     = absint( $this->style_value( $style, 'width', 100 ) );
				$thickness = absint( $this->style_value( $style, 'thickness', 1 ) );
				$margin     = absint( $this->style_value( $style, 'margin_y', 24 ) );
				$line_style = in_array( (string) ( $style['line_style'] ?? '' ), array( 'solid', 'dashed', 'dotted' ), true ) ? (string) $style['line_style'] : 'solid';
				$align      = in_array( (string) ( $style['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $style['align'] : 'center';
				$margin_lr  = 'left' === $align ? '0 auto' : ( 'right' === $align ? 'auto 0' : 'auto' );
				return '<div style="border-top:' . $thickness . 'px ' . esc_attr( $line_style ) . ' ' . esc_attr( $color ) . ';width:' . $width . '%;margin:' . $margin . 'px ' . $margin_lr . ';"></div>';

			case 'spacer':
				return '<div style="height:' . absint( $block['height'] ) . 'px;line-height:' . absint( $block['height'] ) . 'px;font-size:1px;">&nbsp;</div>';

			case 'container':
				return $this->compile_container( $block, $theme, $text, $depth );
		}

		return '';
	}

	/** @return string */
	private function container_cell_style( array $container ) {
		$padding = $container['padding'];
		$style   = 'padding:' . absint( $padding['top'] ) . 'px ' . absint( $padding['right'] ) . 'px ' . absint( $padding['bottom'] ) . 'px ' . absint( $padding['left'] ) . 'px;';

		if ( ! empty( $container['background_color'] ) ) {
			$style .= 'background-color:' . esc_attr( $container['background_color'] ) . ';';
			$opacity = isset( $container['background_opacity'] ) ? (int) $container['background_opacity'] : 100;
			if ( $opacity < 100 ) {
				$style .= 'background-color:' . esc_attr( $this->rgba_color( $container['background_color'], $opacity ) ) . ';';
			}
		}
		if ( ! empty( $container['background_image'] ) ) {
			$image = "url('" . esc_url( $container['background_image'] ) . "')";
			$overlay_opacity = isset( $container['overlay_opacity'] ) ? (int) $container['overlay_opacity'] : 0;
			if ( $overlay_opacity > 0 && ! empty( $container['overlay_color'] ) ) {
				$overlay = $this->rgba_color( $container['overlay_color'], $overlay_opacity );
				$image   = 'linear-gradient(' . $overlay . ',' . $overlay . '),' . $image;
			}
			$style .= 'background-image:' . $image . ';background-size:' . esc_attr( $container['background_size'] ) . ';background-position:' . esc_attr( $container['background_position'] ) . ';background-repeat:' . esc_attr( $container['background_repeat'] ) . ';';
		}
		if ( ! empty( $container['border_width'] ) && ! empty( $container['border_color'] ) ) {
			$style .= 'border:' . absint( $container['border_width'] ) . 'px ' . esc_attr( $container['border_style'] ) . ' ' . esc_attr( $container['border_color'] ) . ';';
		}
		if ( ! empty( $container['border_radius'] ) ) {
			$style .= 'border-radius:' . absint( $container['border_radius'] ) . 'px;';
		}
		if ( ! empty( $container['min_height'] ) ) {
			$style .= 'min-height:' . absint( $container['min_height'] ) . 'px;';
		}
		return $style;
	}

	/** @return string */
	private function compile_container( array $container, array $theme, array &$text, $depth ) {
		$children        = is_array( $container['children'] ?? null ) ? $container['children'] : array();
		$background_attr = ! empty( $container['background_image'] ) ? ' background="' . esc_url( $container['background_image'] ) . '"' : '';
		$cell_style      = $this->container_cell_style( $container );
		$valign          = esc_attr( $container['valign'] ?? 'top' );
		$align           = esc_attr( $container['align'] ?? 'left' );

		if ( 'horizontal' === ( $container['direction'] ?? 'vertical' ) ) {
			$gap      = absint( $container['gap'] ?? 0 );
			$total    = 0;
			$fallback = count( $children ) ? floor( 100 / count( $children ) ) : 100;
			foreach ( $children as $child ) {
				$total += max( 5, min( 100, absint( $child['basis'] ?? 0 ) ) );
			}

			$cells = '';
			foreach ( $children as $index => $child ) {
				$basis = max( 5, min( 100, absint( $child['basis'] ?? $fallback ) ) );
				$width = $total > 0 ? max( 5, min( 100, round( $basis * 100 / $total ) ) ) : $fallback;
				$left  = $index > 0 ? intval( $gap / 2 ) : 0;
				$right = $index < count( $children ) - 1 ? intval( ( $gap + 1 ) / 2 ) : 0;
				$inner = $this->compile_block( $child, $theme, $text, $depth + 1 );
				$class = ! empty( $container['stack_mobile'] ) ? ' class="certloom-email-stack-cell"' : '';
				$cells .= '<td' . $class . ' valign="' . $valign . '" width="' . $width . '%" style="width:' . $width . '%;padding:0 ' . $right . 'px 0 ' . $left . 'px;">' . $inner . '</td>';
			}
			$inner = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;"><tr>' . $cells . '</tr></table>';
		} else {
			$parts = array();
			foreach ( $children as $index => $child ) {
				$parts[] = $this->compile_block( $child, $theme, $text, $depth + 1 );
				if ( $index < count( $children ) - 1 && ! empty( $container['gap'] ) ) {
					$parts[] = '<div style="height:' . absint( $container['gap'] ) . 'px;line-height:' . absint( $container['gap'] ) . 'px;font-size:1px;">&nbsp;</div>';
				}
			}
			$inner = implode( '', $parts );
		}

		$margin = 0 === (int) $depth ? 'margin:0 0 20px;' : 'margin:0;';
		return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"' . $background_attr . ' style="width:100%;' . $margin . '"><tr><td valign="' . $valign . '" align="' . $align . '" style="' . $cell_style . '">' . $inner . '</td></tr></table>';
	}

	/** @return array{html_body:string,text_body:string,theme:array<string,mixed>,blocks:array<int,array<string,mixed>>} */
	public function compile( $blocks, $theme ) {
		$theme  = $this->normalize_theme( $theme );
		$blocks = $this->normalize_blocks( $blocks );
		$body   = '';
		$text   = array();

		foreach ( $blocks as $block ) {
			$body .= $this->compile_block( $block, $theme, $text, 0 );
		}

		$page_bg   = 'background-color:' . esc_attr( $theme['background'] ) . ';';
		$page_attr = '';
		if ( $theme['background_opacity'] < 100 ) {
			$page_bg .= 'background-color:' . esc_attr( $this->rgba_color( $theme['background'], $theme['background_opacity'] ) ) . ';';
		}
		if ( '' !== $theme['background_image'] ) {
			$page_attr = ' background="' . esc_url( $theme['background_image'] ) . '"';
			$page_image = "url('" . esc_url( $theme['background_image'] ) . "')";
			if ( $theme['background_overlay_opacity'] > 0 ) {
				$overlay    = $this->rgba_color( $theme['background_overlay_color'], $theme['background_overlay_opacity'] );
				$page_image = 'linear-gradient(' . $overlay . ',' . $overlay . '),' . $page_image;
			}
			$page_bg .= 'background-image:' . $page_image . ';background-size:' . esc_attr( $theme['background_size'] ) . ';background-position:' . esc_attr( $theme['background_position'] ) . ';background-repeat:' . esc_attr( $theme['background_repeat'] ) . ';';
		}

		$content_bg   = 'background-color:' . esc_attr( $theme['content_background'] ) . ';';
		$content_attr = '';
		if ( $theme['content_background_opacity'] < 100 ) {
			$content_bg .= 'background-color:' . esc_attr( $this->rgba_color( $theme['content_background'], $theme['content_background_opacity'] ) ) . ';';
		}
		if ( '' !== $theme['content_background_image'] ) {
			$content_attr  = ' background="' . esc_url( $theme['content_background_image'] ) . '"';
			$content_image = "url('" . esc_url( $theme['content_background_image'] ) . "')";
			if ( $theme['content_background_overlay_opacity'] > 0 ) {
				$overlay       = $this->rgba_color( $theme['content_background_overlay_color'], $theme['content_background_overlay_opacity'] );
				$content_image = 'linear-gradient(' . $overlay . ',' . $overlay . '),' . $content_image;
			}
			$content_bg .= 'background-image:' . $content_image . ';background-size:' . esc_attr( $theme['content_background_size'] ) . ';background-position:' . esc_attr( $theme['content_background_position'] ) . ';background-repeat:' . esc_attr( $theme['content_background_repeat'] ) . ';';
		}

		$custom_font_css = $this->custom_font_face_css( $theme, $blocks );
		$responsive = '<style>' . $custom_font_css . self::RESPONSIVE_CSS . '</style>';
		$html       = $responsive
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"' . $page_attr . ' style="width:100%;' . $page_bg . 'margin:0;padding:0;">'
			. '<tr><td align="center" style="padding:28px 14px;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"' . $content_attr . ' style="width:100%;max-width:' . absint( $theme['email_width'] ) . 'px;' . $content_bg . 'border-radius:' . absint( $theme['border_radius'] ) . 'px;">'
			. '<tr><td style="padding:36px;">' . $body . '</td></tr></table></td></tr></table>';

		return array(
			'html_body' => $html,
			'text_body' => trim( implode( "\n\n", array_filter( $text ) ) ),
			'theme'     => $theme,
			'blocks'    => $blocks,
		);
	}

	/** @return string */
	private function sanitize_url_or_token( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\{\{[a-z0-9_.:-]+\}\}$/i', $value ) ) {
			return $value;
		}
		return esc_url_raw( $value );
	}
}
