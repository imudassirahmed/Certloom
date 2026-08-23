<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide Certloom settings.
 */
class Ecapla_CertVerify_Settings {

	const OPTION_KEY = 'certverify_settings';

	public static function defaults() {
		return array(
			'accent_color'            => '#0f6e56',
			'show_credit'             => false,
			'credit_text'             => __( 'Verification powered by Certloom.', 'certloom' ),
			'status_desc'             => array(),
			'credit_color'            => '',
			'credit_size'             => '',
			'credit_align'            => 'center',
			'delete_data_on_uninstall' => false,
			'privacy_erasure_policy'   => 'retain',
			'verification_branding_enabled' => false,
			'verification_brand_name'    => '',
			'verification_brand_tagline' => '',
			'verification_surface_color' => '#ffffff',
			'verification_text_color'    => '#17201d',
			'verification_use_site_icon' => false,
			'verification_qr_enabled'     => true,
			'verification_qr_logo_enabled' => false,
			'verification_barcode_enabled' => false,
			'numbering_mode'             => 'manual',
			'numbering_prefix'           => 'CERT-',
			'numbering_suffix'           => '',
			'numbering_start'            => 1,
			'numbering_padding'          => 6,
		);
	}

	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key, $default = '' ) {
		$all = self::get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Stores settings after sanitizing every value.
	 *
	 * Sanitization is repeated here rather than trusted from the caller so that
	 * any future integration calling this method cannot persist unsafe values.
	 *
	 * @param array $data Raw settings.
	 * @return bool
	 */
	public static function save( $data ) {
		if ( is_wp_error( Ecapla_CertVerify_Backup::assert_writes_allowed() ) ) {
			return false;
		}
		$data  = wp_parse_args( is_array( $data ) ? $data : array(), self::defaults() );
		$clean = self::defaults();

		$accent                 = sanitize_hex_color( (string) $data['accent_color'] );
		$clean['accent_color']  = $accent ? $accent : '#0f6e56';
		$credit_color           = sanitize_hex_color( (string) $data['credit_color'] );
		$clean['credit_color']  = is_string( $credit_color ) ? $credit_color : '';
		$clean['show_credit']   = ! empty( $data['show_credit'] );
		$clean['credit_text']   = sanitize_text_field( (string) $data['credit_text'] );
		$clean['credit_size']   = is_numeric( $data['credit_size'] ) ? min( 24, max( 8, absint( $data['credit_size'] ) ) ) : '';
		$clean['credit_align']  = in_array( $data['credit_align'], array( 'left', 'center', 'right' ), true ) ? $data['credit_align'] : 'center';
		$clean['delete_data_on_uninstall'] = ! empty( $data['delete_data_on_uninstall'] );
		$clean['privacy_erasure_policy'] = isset( $data['privacy_erasure_policy'] ) && 'anonymize_mutable_recipient' === sanitize_key( (string) $data['privacy_erasure_policy'] ) ? 'anonymize_mutable_recipient' : 'retain';
		$clean['verification_branding_enabled'] = ! empty( $data['verification_branding_enabled'] );
		$clean['verification_brand_name'] = sanitize_text_field( (string) $data['verification_brand_name'] );
		$clean['verification_brand_name'] = function_exists( 'mb_substr' ) ? mb_substr( $clean['verification_brand_name'], 0, 120 ) : substr( $clean['verification_brand_name'], 0, 120 );
		$clean['verification_brand_tagline'] = sanitize_text_field( (string) $data['verification_brand_tagline'] );
		$clean['verification_brand_tagline'] = function_exists( 'mb_substr' ) ? mb_substr( $clean['verification_brand_tagline'], 0, 240 ) : substr( $clean['verification_brand_tagline'], 0, 240 );
		$surface = sanitize_hex_color( (string) $data['verification_surface_color'] );
		$text = sanitize_hex_color( (string) $data['verification_text_color'] );
		$clean['verification_surface_color'] = $surface ? $surface : '#ffffff';
		$clean['verification_text_color'] = self::accessible_text_color( $clean['verification_surface_color'], $text ? $text : '#17201d' );
		$clean['verification_use_site_icon'] = ! empty( $data['verification_use_site_icon'] );
		$clean['verification_qr_enabled'] = ! empty( $data['verification_qr_enabled'] );
		$clean['verification_qr_logo_enabled'] = ! empty( $data['verification_qr_logo_enabled'] );
		$clean['verification_barcode_enabled'] = ! empty( $data['verification_barcode_enabled'] );

		if ( ! class_exists( 'Ecapla_CertVerify_Numbering' ) ) {
			return false;
		}
		$numbering = Ecapla_CertVerify_Numbering::normalize_policy( $data );
		if ( is_wp_error( $numbering ) ) {
			return false;
		}
		foreach ( $numbering as $key => $value ) {
			$clean[ $key ] = $value;
		}

		$clean['status_desc'] = array();
		if ( isset( $data['status_desc'] ) && is_array( $data['status_desc'] ) ) {
			foreach ( $data['status_desc'] as $key => $desc ) {
				$key = sanitize_key( $key );
				if ( '' === $key ) {
					continue;
				}
				$desc = sanitize_textarea_field( (string) $desc );
				$clean['status_desc'][ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 1000 ) : substr( $desc, 0, 1000 );
			}
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( $clean === $stored ) {
			return true;
		}
		$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return false;
		}
		$result  = update_option( self::OPTION_KEY, $clean );
		$success = $result || $clean === get_option( self::OPTION_KEY, array() );
		return $success;
	}

	/** Keeps clean-route brand text at WCAG AA contrast for normal text. */
	public static function accessible_text_color( $surface, $preferred ) {
		$surface   = self::normalize_hex( $surface, '#ffffff' );
		$preferred = self::normalize_hex( $preferred, '#17201d' );
		if ( self::contrast_ratio( $surface, $preferred ) >= 4.5 ) {
			return $preferred;
		}
		$black = '#111111';
		$white = '#ffffff';
		return self::contrast_ratio( $surface, $black ) >= self::contrast_ratio( $surface, $white ) ? $black : $white;
	}

	public static function contrast_ratio( $first, $second ) {
		$a = self::relative_luminance( self::normalize_hex( $first, '#ffffff' ) );
		$b = self::relative_luminance( self::normalize_hex( $second, '#000000' ) );
		$light = max( $a, $b );
		$dark  = min( $a, $b );
		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	private static function normalize_hex( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : $fallback;
	}

	private static function relative_luminance( $hex ) {
		$rgb = array( hexdec( substr( $hex, 1, 2 ) ) / 255, hexdec( substr( $hex, 3, 2 ) ) / 255, hexdec( substr( $hex, 5, 2 ) ) / 255 );
		foreach ( $rgb as &$channel ) {
			$channel = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		}
		unset( $channel );
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

}
