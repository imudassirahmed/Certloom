<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side compiler for Certloom's structured email builder.
 *
 * The builder stores semantic blocks and bounded style values. Delivery always
 * uses server-compiled HTML/text, never browser-authored executable markup.
 */
final class EmailDesignCompiler {
	const MAX_BLOCKS = 40;

	/** @return array<int,string> */
	private static function allowed_fonts() {
		return array(
			'Arial, Helvetica, sans-serif',
			'Georgia, Times New Roman, serif',
			'Verdana, Geneva, sans-serif',
			'Trebuchet MS, Arial, sans-serif',
		);
	}

	/** @return array<string,mixed> */
	public static function default_theme() {
		return array(
			'background'         => '#f4f7f6',
			'content_background' => '#ffffff',
			'text_color'         => '#24322e',
			'heading_color'      => '#173f34',
			'button_background'  => '#0f6e56',
			'button_text'        => '#ffffff',
			'link_color'         => '#0f6e56',
			'font_family'        => 'Arial, Helvetica, sans-serif',
			'email_width'        => 640,
			'border_radius'      => 12,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function default_blocks() {
		return array(
			array( 'type' => 'heading', 'text' => __( 'Your credential is ready', 'certloom' ), 'level' => 1 ),
			array( 'type' => 'text', 'html' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p><p>' . esc_html__( 'Your credential for {{program_title}} has been issued.', 'certloom' ) . '</p>' ),
			array( 'type' => 'button', 'text' => __( 'View credential', 'certloom' ), 'url' => '{{verification_url}}', 'align' => 'left' ),
			array( 'type' => 'divider' ),
			array( 'type' => 'text', 'html' => '<p><strong>' . esc_html__( 'Credential number:', 'certloom' ) . '</strong> {{credential_number}}</p>' ),
		);
	}

	/** @return array<string,mixed> */
	public function normalize_theme( $theme ) {
		$theme = is_array( $theme ) ? $theme : array();
		$out   = self::default_theme();
		foreach ( array( 'background', 'content_background', 'text_color', 'heading_color', 'button_background', 'button_text', 'link_color' ) as $key ) {
			if ( isset( $theme[ $key ] ) ) {
				$hex = sanitize_hex_color( (string) $theme[ $key ] );
				if ( $hex ) { $out[ $key ] = $hex; }
			}
		}
		$font = isset( $theme['font_family'] ) ? (string) $theme['font_family'] : '';
		if ( in_array( $font, self::allowed_fonts(), true ) ) { $out['font_family'] = $font; }
		$out['email_width']   = max( 480, min( 760, absint( $theme['email_width'] ?? $out['email_width'] ) ) );
		$out['border_radius'] = max( 0, min( 32, absint( $theme['border_radius'] ?? $out['border_radius'] ) ) );
		return $out;
	}

	/** @return array<string,mixed> */
	private function sanitize_block_style( $block, $type ) {
		$style = is_array( $block['style'] ?? null ) ? $block['style'] : array();
		$out   = array();

		$font = isset( $style['font_family'] ) ? (string) $style['font_family'] : '';
		if ( in_array( $font, self::allowed_fonts(), true ) ) { $out['font_family'] = $font; }

		$size_bounds = 'heading' === $type ? array( 12, 72 ) : ( 'button' === $type ? array( 10, 28 ) : array( 10, 32 ) );
		if ( isset( $style['font_size'] ) && '' !== (string) $style['font_size'] ) {
			$out['font_size'] = max( $size_bounds[0], min( $size_bounds[1], absint( $style['font_size'] ) ) );
		}

		if ( isset( $style['font_weight'] ) && '' !== (string) $style['font_weight'] ) {
			$weight = absint( $style['font_weight'] );
			if ( in_array( $weight, array( 400, 500, 600, 700, 800, 900 ), true ) ) { $out['font_weight'] = $weight; }
		}
		if ( ! empty( $style['italic'] ) ) { $out['italic'] = true; }
		if ( ! empty( $style['underline'] ) ) { $out['underline'] = true; }

		foreach ( array( 'color', 'background_color' ) as $key ) {
			if ( isset( $style[ $key ] ) ) {
				$hex = sanitize_hex_color( (string) $style[ $key ] );
				if ( $hex ) { $out[ $key ] = $hex; }
			}
		}

		if ( in_array( $type, array( 'heading', 'text' ), true ) ) {
			$align = (string) ( $style['align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) { $out['align'] = $align; }
			if ( isset( $style['line_height'] ) && is_numeric( $style['line_height'] ) ) {
				$out['line_height'] = max( 1.0, min( 2.5, round( (float) $style['line_height'], 2 ) ) );
			}
		}

		if ( 'button' === $type ) {
			if ( isset( $style['border_radius'] ) ) { $out['border_radius'] = max( 0, min( 32, absint( $style['border_radius'] ) ) ); }
			if ( isset( $style['padding_x'] ) ) { $out['padding_x'] = max( 8, min( 40, absint( $style['padding_x'] ) ) ); }
			if ( isset( $style['padding_y'] ) ) { $out['padding_y'] = max( 6, min( 24, absint( $style['padding_y'] ) ) ); }
		}

		if ( 'image' === $type ) {
			$align = (string) ( $style['align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) { $out['align'] = $align; }
			if ( isset( $style['width'] ) ) { $out['width'] = max( 10, min( 100, absint( $style['width'] ) ) ); }
			if ( isset( $style['border_radius'] ) ) { $out['border_radius'] = max( 0, min( 32, absint( $style['border_radius'] ) ) ); }
		}

		if ( 'divider' === $type ) {
			if ( isset( $style['color'] ) ) { $hex = sanitize_hex_color( (string) $style['color'] ); if ( $hex ) { $out['color'] = $hex; } }
			if ( isset( $style['width'] ) ) { $out['width'] = max( 10, min( 100, absint( $style['width'] ) ) ); }
			if ( isset( $style['thickness'] ) ) { $out['thickness'] = max( 1, min( 8, absint( $style['thickness'] ) ) ); }
			if ( isset( $style['margin_y'] ) ) { $out['margin_y'] = max( 4, min( 60, absint( $style['margin_y'] ) ) ); }
		}

		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function sanitize_blocks( $blocks ) {
		$blocks = is_array( $blocks ) ? array_slice( $blocks, 0, self::MAX_BLOCKS ) : array();
		$out    = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) { continue; }
			$type = sanitize_key( (string) ( $block['type'] ?? '' ) );
			switch ( $type ) {
				case 'heading':
					$text = sanitize_text_field( (string) ( $block['text'] ?? '' ) );
					if ( '' !== $text ) {
						$row = array( 'type' => 'heading', 'text' => $text, 'level' => max( 1, min( 3, absint( $block['level'] ?? 2 ) ) ) );
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) { $row['style'] = $style; }
						$out[] = $row;
					}
					break;
				case 'text':
					$html = wp_kses_post( (string) ( $block['html'] ?? '' ) );
					if ( '' !== trim( wp_strip_all_tags( $html ) ) || false !== strpos( $html, '{{' ) ) {
						$row = array( 'type' => 'text', 'html' => $html );
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) { $row['style'] = $style; }
						$out[] = $row;
					}
					break;
				case 'button':
					$text = sanitize_text_field( (string) ( $block['text'] ?? '' ) );
					$url  = $this->sanitize_url_or_token( $block['url'] ?? '' );
					if ( '' !== $text && '' !== $url ) {
						$row = array( 'type' => 'button', 'text' => $text, 'url' => $url, 'align' => in_array( (string) ( $block['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $block['align'] : 'left' );
						$style = $this->sanitize_block_style( $block, $type );
						if ( $style ) { $row['style'] = $style; }
						$out[] = $row;
					}
					break;
				case 'image':
					$url = esc_url_raw( (string) ( $block['url'] ?? '' ) );
					if ( '' !== $url ) { $row = array( 'type' => 'image', 'url' => $url, 'alt' => sanitize_text_field( (string) ( $block['alt'] ?? '' ) ) ); $style = $this->sanitize_block_style( $block, $type ); if ( $style ) { $row['style'] = $style; } $out[] = $row; }
					break;
				case 'divider':
					$row = array( 'type' => 'divider' ); $style = $this->sanitize_block_style( $block, $type ); if ( $style ) { $row['style'] = $style; } $out[] = $row;
					break;
				case 'spacer':
					$out[] = array( 'type' => 'spacer', 'height' => max( 8, min( 80, absint( $block['height'] ?? 24 ) ) ) );
					break;
				case 'columns':
					$left  = wp_kses_post( (string) ( $block['left_html'] ?? '' ) );
					$right = wp_kses_post( (string) ( $block['right_html'] ?? '' ) );
					if ( '' !== trim( $left . $right ) ) { $out[] = array( 'type' => 'columns', 'left_html' => $left, 'right_html' => $right ); }
					break;
			}
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function normalize_blocks( $blocks ) {
		$normalized = $this->sanitize_blocks( $blocks );
		return $normalized ?: self::default_blocks();
	}

	/** @return string */
	private function style_value( array $style, $key, $fallback ) {
		return isset( $style[ $key ] ) && '' !== (string) $style[ $key ] ? (string) $style[ $key ] : (string) $fallback;
	}

	/** @return string */
	private function text_style( array $style, array $theme, $kind ) {
		$default_size = 'heading' === $kind ? 24 : 16;
		$default_weight = 'heading' === $kind ? 700 : 400;
		$default_color = 'heading' === $kind ? $theme['heading_color'] : $theme['text_color'];
		$family = $this->style_value( $style, 'font_family', $theme['font_family'] );
		$size = absint( $this->style_value( $style, 'font_size', $default_size ) );
		$weight = absint( $this->style_value( $style, 'font_weight', $default_weight ) );
		$color = $this->style_value( $style, 'color', $default_color );
		$align = $this->style_value( $style, 'align', 'left' );
		$line_height = $this->style_value( $style, 'line_height', 'heading' === $kind ? 1.25 : 1.65 );
		$font_style = ! empty( $style['italic'] ) ? 'italic' : 'normal';
		$text_decoration = ! empty( $style['underline'] ) ? 'underline' : 'none';
		return 'color:' . esc_attr( $color ) . ';font-family:' . esc_attr( $family ) . ';font-size:' . $size . 'px;font-weight:' . $weight . ';font-style:' . $font_style . ';text-decoration:' . $text_decoration . ';text-align:' . esc_attr( $align ) . ';line-height:' . esc_attr( $line_height ) . ';';
	}

	/** @return array{html_body:string,text_body:string,theme:array<string,mixed>,blocks:array<int,array<string,mixed>>} */
	public function compile( $blocks, $theme ) {
		$theme  = $this->normalize_theme( $theme );
		$blocks = $this->normalize_blocks( $blocks );
		$body   = '';
		$text   = array();
		foreach ( $blocks as $block ) {
			$style = is_array( $block['style'] ?? null ) ? $block['style'] : array();
			switch ( $block['type'] ) {
				case 'heading':
					$default_size = 1 === $block['level'] ? 30 : ( 2 === $block['level'] ? 24 : 19 );
					if ( ! isset( $style['font_size'] ) ) { $style['font_size'] = $default_size; }
					$body .= '<h' . absint( $block['level'] ) . ' style="margin:0 0 18px;' . $this->text_style( $style, $theme, 'heading' ) . '">' . esc_html( $block['text'] ) . '</h' . absint( $block['level'] ) . '>';
					$text[] = (string) $block['text'];
					break;
				case 'text':
					$body .= '<div style="margin:0 0 18px;' . $this->text_style( $style, $theme, 'text' ) . '">' . wp_kses_post( $block['html'] ) . '</div>';
					$text[] = trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $block['html'] ) ) );
					break;
				case 'button':
					$family = $this->style_value( $style, 'font_family', $theme['font_family'] );
					$size = absint( $this->style_value( $style, 'font_size', 16 ) );
					$weight = absint( $this->style_value( $style, 'font_weight', 700 ) );
					$color = $this->style_value( $style, 'color', $theme['button_text'] );
					$background = $this->style_value( $style, 'background_color', $theme['button_background'] );
					$radius = absint( $this->style_value( $style, 'border_radius', $theme['border_radius'] ) );
					$padding_x = absint( $this->style_value( $style, 'padding_x', 20 ) );
					$padding_y = absint( $this->style_value( $style, 'padding_y', 13 ) );
					$font_style = ! empty( $style['italic'] ) ? 'italic' : 'normal';
					$text_decoration = ! empty( $style['underline'] ) ? 'underline' : 'none';
					$body .= '<div style="margin:4px 0 22px;text-align:' . esc_attr( $block['align'] ) . ';"><a href="' . esc_attr( $block['url'] ) . '" style="display:inline-block;background:' . esc_attr( $background ) . ';color:' . esc_attr( $color ) . ';font-family:' . esc_attr( $family ) . ';font-size:' . $size . 'px;font-weight:' . $weight . ';font-style:' . $font_style . ';line-height:1.2;text-decoration:' . $text_decoration . ';padding:' . $padding_y . 'px ' . $padding_x . 'px;border-radius:' . $radius . 'px;">' . esc_html( $block['text'] ) . '</a></div>';
					$text[] = (string) $block['text'] . ': ' . (string) $block['url'];
					break;
				case 'image':
					$align = $this->style_value( $style, 'align', 'left' );
					$width = absint( $this->style_value( $style, 'width', 100 ) );
					$radius = absint( $this->style_value( $style, 'border_radius', 0 ) );
					$body .= '<div style="margin:0 0 20px;text-align:' . esc_attr( $align ) . ';"><img src="' . esc_url( $block['url'] ) . '" alt="' . esc_attr( $block['alt'] ) . '" style="display:inline-block;width:' . $width . '%;max-width:100%;height:auto;border:0;border-radius:' . $radius . 'px;"></div>';
					break;
				case 'divider':
					$divider_color = $this->style_value( $style, 'color', '#dce6e1' );
					$divider_width = absint( $this->style_value( $style, 'width', 100 ) );
					$divider_thickness = absint( $this->style_value( $style, 'thickness', 1 ) );
					$divider_margin = absint( $this->style_value( $style, 'margin_y', 24 ) );
					$body .= '<div style="border-top:' . $divider_thickness . 'px solid ' . esc_attr( $divider_color ) . ';width:' . $divider_width . '%;margin:' . $divider_margin . 'px auto;"></div>';
					break;
				case 'spacer':
					$body .= '<div style="height:' . absint( $block['height'] ) . 'px;line-height:' . absint( $block['height'] ) . 'px;font-size:1px;">&nbsp;</div>';
					break;
				case 'columns':
					$body .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 20px;"><tr><td valign="top" width="50%" style="padding:0 10px 0 0;color:' . esc_attr( $theme['text_color'] ) . ';font-family:' . esc_attr( $theme['font_family'] ) . ';font-size:15px;line-height:1.6;">' . wp_kses_post( $block['left_html'] ) . '</td><td valign="top" width="50%" style="padding:0 0 0 10px;color:' . esc_attr( $theme['text_color'] ) . ';font-family:' . esc_attr( $theme['font_family'] ) . ';font-size:15px;line-height:1.6;">' . wp_kses_post( $block['right_html'] ) . '</td></tr></table>';
					$text[] = trim( wp_strip_all_tags( $block['left_html'] ) );
					$text[] = trim( wp_strip_all_tags( $block['right_html'] ) );
					break;
			}
		}
		$html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:' . esc_attr( $theme['background'] ) . ';margin:0;padding:0;"><tr><td align="center" style="padding:28px 14px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:' . absint( $theme['email_width'] ) . 'px;background:' . esc_attr( $theme['content_background'] ) . ';border-radius:' . absint( $theme['border_radius'] ) . 'px;"><tr><td style="padding:36px;">' . $body . '</td></tr></table></td></tr></table>';
		return array( 'html_body' => $html, 'text_body' => trim( implode( "\n\n", array_filter( $text ) ) ), 'theme' => $theme, 'blocks' => $blocks );
	}

	/** @return string */
	private function sanitize_url_or_token( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\{\{(?:verification_url)\}\}$/', $value ) ) { return $value; }
		return esc_url_raw( $value );
	}
}
