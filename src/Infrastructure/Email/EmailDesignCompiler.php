<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side compiler for Certloom's structured email builder.
 *
 * The builder stores semantic blocks and theme values. Delivery always uses the
 * server-compiled HTML/text, never browser-authored executable markup.
 */
final class EmailDesignCompiler {
	const MAX_BLOCKS = 40;

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
		$allowed_fonts = array(
			'Arial, Helvetica, sans-serif',
			'Georgia, Times New Roman, serif',
			'Verdana, Geneva, sans-serif',
			'Trebuchet MS, Arial, sans-serif',
		);
		$font = isset( $theme['font_family'] ) ? (string) $theme['font_family'] : '';
		if ( in_array( $font, $allowed_fonts, true ) ) { $out['font_family'] = $font; }
		$out['email_width']   = max( 480, min( 760, absint( $theme['email_width'] ?? $out['email_width'] ) ) );
		$out['border_radius'] = max( 0, min( 32, absint( $theme['border_radius'] ?? $out['border_radius'] ) ) );
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
					if ( '' !== $text ) { $out[] = array( 'type' => 'heading', 'text' => $text, 'level' => max( 1, min( 3, absint( $block['level'] ?? 2 ) ) ) ); }
					break;
				case 'text':
					$html = wp_kses_post( (string) ( $block['html'] ?? '' ) );
					if ( '' !== trim( wp_strip_all_tags( $html ) ) || false !== strpos( $html, '{{' ) ) { $out[] = array( 'type' => 'text', 'html' => $html ); }
					break;
				case 'button':
					$text = sanitize_text_field( (string) ( $block['text'] ?? '' ) );
					$url  = $this->sanitize_url_or_token( $block['url'] ?? '' );
					if ( '' !== $text && '' !== $url ) { $out[] = array( 'type' => 'button', 'text' => $text, 'url' => $url, 'align' => in_array( (string) ( $block['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $block['align'] : 'left' ); }
					break;
				case 'image':
					$url = esc_url_raw( (string) ( $block['url'] ?? '' ) );
					if ( '' !== $url ) { $out[] = array( 'type' => 'image', 'url' => $url, 'alt' => sanitize_text_field( (string) ( $block['alt'] ?? '' ) ) ); }
					break;
				case 'divider':
					$out[] = array( 'type' => 'divider' );
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

	/** @return array{html_body:string,text_body:string,theme:array<string,mixed>,blocks:array<int,array<string,mixed>>} */
	public function compile( $blocks, $theme ) {
		$theme  = $this->normalize_theme( $theme );
		$blocks = $this->normalize_blocks( $blocks );
		$body   = '';
		$text   = array();
		foreach ( $blocks as $block ) {
			switch ( $block['type'] ) {
				case 'heading':
					$size = 1 === $block['level'] ? 30 : ( 2 === $block['level'] ? 24 : 19 );
					$body .= '<h' . absint( $block['level'] ) . ' style="margin:0 0 18px;color:' . esc_attr( $theme['heading_color'] ) . ';font-family:' . esc_attr( $theme['font_family'] ) . ';font-size:' . $size . 'px;line-height:1.25;">' . esc_html( $block['text'] ) . '</h' . absint( $block['level'] ) . '>';
					$text[] = (string) $block['text'];
					break;
				case 'text':
					$body .= '<div style="margin:0 0 18px;color:' . esc_attr( $theme['text_color'] ) . ';font-family:' . esc_attr( $theme['font_family'] ) . ';font-size:16px;line-height:1.65;">' . wp_kses_post( $block['html'] ) . '</div>';
					$text[] = trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $block['html'] ) ) );
					break;
				case 'button':
					$body .= '<div style="margin:4px 0 22px;text-align:' . esc_attr( $block['align'] ) . ';"><a href="' . esc_attr( $block['url'] ) . '" style="display:inline-block;background:' . esc_attr( $theme['button_background'] ) . ';color:' . esc_attr( $theme['button_text'] ) . ';font-family:' . esc_attr( $theme['font_family'] ) . ';font-size:16px;font-weight:700;line-height:1.2;text-decoration:none;padding:13px 20px;border-radius:' . absint( $theme['border_radius'] ) . 'px;">' . esc_html( $block['text'] ) . '</a></div>';
					$text[] = (string) $block['text'] . ': ' . (string) $block['url'];
					break;
				case 'image':
					$body .= '<div style="margin:0 0 20px;"><img src="' . esc_url( $block['url'] ) . '" alt="' . esc_attr( $block['alt'] ) . '" style="display:block;max-width:100%;height:auto;border:0;"></div>';
					break;
				case 'divider':
					$body .= '<div style="border-top:1px solid #dce6e1;margin:24px 0;"></div>';
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
