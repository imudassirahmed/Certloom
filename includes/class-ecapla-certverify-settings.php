<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide Certloom settings.
 */
class Ecapla_CertVerify_Settings {

	const OPTION_KEY = 'certverify_settings';
	/** No runtime defaults, database reads, repairs or issuance permission during preflight. */
	public static function validate_recovery_value( $value, array $catalog = array() ) {
		$defaults = self::defaults(); $invalid = static function () { return new WP_Error( 'certloom_whole_settings_invalid', __( 'Review and save valid Certloom settings before restoring them.', 'certloom' ) ); };
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array_keys( $defaults ) ) ) { return $invalid(); }
		foreach ( $value as $key => $item ) {
			if ( 'credit_size' === $key ) { if ( '' !== $item && ( ! is_int( $item ) || $item < 8 || $item > 24 ) ) { return $invalid(); } continue; }
			if ( gettype( $item ) !== gettype( $defaults[$key] ) ) { return $invalid(); }
			if ( is_string( $item ) && ( strlen( $item ) > 2000 || sanitize_text_field( $item ) !== $item ) ) { return $invalid(); }
		}
		$enums = array( 'credit_align' => array( 'left', 'center', 'right' ), 'privacy_erasure_policy' => array( 'retain', 'anonymize_mutable_recipient' ), 'certificate_layout' => array( 'left', 'right', 'center' ), 'certificate_name_source' => array( 'profile', 'approved_field' ), 'numbering_mode' => array( 'manual', 'sequential', 'random', 'uuid' ) );
		foreach ( $enums as $key => $allowed ) { if ( isset( $value[$key] ) && ! in_array( $value[$key], $allowed, true ) ) { return $invalid(); } }
		foreach ( array( 'accent_color', 'credit_color', 'verification_surface_color', 'verification_text_color' ) as $key ) { if ( isset( $value[$key] ) && ( '' !== $value[$key] || 'credit_color' !== $key ) && ! preg_match( '/^#[0-9a-fA-F]{6}$/D', $value[$key] ) ) { return $invalid(); } }
		foreach ( array( 'certificate_center_width' => array( 320, 1400 ), 'numbering_start' => array( 1, 999999999999 ), 'numbering_padding' => array( 1, 12 ) ) as $key => $range ) { if ( isset( $value[$key] ) && ( $value[$key] < $range[0] || $value[$key] > $range[1] ) ) { return $invalid(); } }
		if ( isset( $value['usage_profile'] ) && ! isset( ( new \Certloom\Application\Setup\PurposePresetEngine() )->profiles()[$value['usage_profile']] ) ) { return $invalid(); }
		if ( isset( $value['status_desc'] ) ) { foreach ( $value['status_desc'] as $key => $text ) { if ( ! is_string( $key ) || sanitize_key( $key ) !== $key || ! is_string( $text ) || strlen( $text ) > 4000 || sanitize_textarea_field( $text ) !== $text ) { return $invalid(); } } }
		$numbering = Ecapla_CertVerify_Numbering::normalize_policy( $value ); if ( is_wp_error( $numbering ) || ! \Certloom\Application\Restore\WholeStateOptionRows::same( array_intersect_key( $value, Ecapla_CertVerify_Numbering::defaults() ), array_intersect_key( $numbering, $value ) ) ) { return $invalid(); }
		$policy = new \Certloom\Application\Issuance\PendingRequestPolicy();
		if ( isset( $value['pending_request_policy'] ) && is_wp_error( $policy->normalize( $value['pending_request_policy'] ) ) ) { return $invalid(); }
		if ( isset( $value['pending_request_notice_since'] ) && is_wp_error( $policy->normalize_notice_since( $value['pending_request_notice_since'] ) ) ) { return $invalid(); }
		$name = ( new \Certloom\Application\Recipient\CertificateNamePolicy() )->normalize( $value['certificate_name_source'] ?? 'profile', $value['certificate_name_field_key'] ?? '', $catalog ); if ( is_wp_error( $name ) ) { return $name; }
		return $value;
	}

	public static function defaults() {
		return array(
			'accent_color'            => '#1450e0',
			'show_credit'             => false,
			'credit_text'             => __( 'Verification powered by Certloom.', 'certloom' ),
			'status_desc'             => array(),
			'credit_color'            => '',
			'credit_size'             => '',
			'credit_align'            => 'center',
			'delete_data_on_uninstall' => false,
			'privacy_erasure_policy'   => 'retain',
			'recipient_user_sync_enabled' => true,
			'certificate_name_source' => 'profile',
			'certificate_name_field_key' => '',
			'verification_branding_enabled' => false,
			'verification_brand_name'    => '',
			'verification_brand_tagline' => '',
			'verification_surface_color' => '#ffffff',
			'verification_text_color'    => '#01133d',
			'verification_use_site_icon' => false,
			'verification_qr_enabled'     => true,
			'verification_qr_logo_enabled' => false,
			'verification_barcode_enabled' => false,
			'certificate_layout'         => 'right',
			'certificate_center_width'   => 960,
			'numbering_mode'             => 'manual',
			'numbering_prefix'           => 'CERT-',
			'numbering_suffix'           => '',
			'numbering_start'            => 1,
			'numbering_padding'          => 6,
			'usage_profile'              => 'general_other',
			'pending_request_policy'     => \Certloom\Application\Issuance\PendingRequestPolicy::defaults(),
			'pending_request_notice_since' => \Certloom\Application\Issuance\PendingRequestPolicy::notice_since_defaults(),
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
		$name_changed = false;
		$result = \Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $data, &$name_changed ) {
			if ( is_wp_error( Ecapla_CertVerify_Backup::assert_writes_allowed() ) ) {
				return false;
			}
			$raw_data = is_array( $data ) ? $data : array();
			$stored_profile = sanitize_key( (string) self::get( 'usage_profile', 'general_other' ) );
			$data  = wp_parse_args( $raw_data, self::defaults() );
			$clean = self::defaults();
			$profile = array_key_exists( 'usage_profile', $raw_data ) ? sanitize_key( (string) $raw_data['usage_profile'] ) : $stored_profile;
			if ( class_exists( '\Certloom\Application\Setup\PurposePresetEngine' ) ) {
				$engine = new \Certloom\Application\Setup\PurposePresetEngine();
				$profile = $engine->normalize_profile( $profile );
				if ( ! isset( $engine->profiles()[ $profile ] ) ) { $profile = 'general_other'; }
			} else { $profile = 'general_other'; }
			$clean['usage_profile'] = $profile;
			$request_policy = ( new \Certloom\Application\Issuance\PendingRequestPolicy() )->normalize( $raw_data['pending_request_policy'] ?? self::get( 'pending_request_policy', \Certloom\Application\Issuance\PendingRequestPolicy::defaults() ) );
			if ( is_wp_error( $request_policy ) ) { return $request_policy; }
			$clean['pending_request_policy'] = $request_policy;
			$notice_since = ( new \Certloom\Application\Issuance\PendingRequestPolicy() )->normalize_notice_since( self::get( 'pending_request_notice_since', \Certloom\Application\Issuance\PendingRequestPolicy::notice_since_defaults() ) );
			if ( is_wp_error( $notice_since ) ) { return $notice_since; }
			$clean['pending_request_notice_since'] = $notice_since;

			$accent                 = sanitize_hex_color( (string) $data['accent_color'] );
			$clean['accent_color']  = $accent ? $accent : '#1450e0';
			$credit_color           = sanitize_hex_color( (string) $data['credit_color'] );
			$clean['credit_color']  = is_string( $credit_color ) ? $credit_color : '';
			$clean['show_credit']   = ! empty( $data['show_credit'] );
			$clean['credit_text']   = sanitize_text_field( (string) $data['credit_text'] );
			$clean['credit_size']   = is_numeric( $data['credit_size'] ) ? min( 24, max( 8, absint( $data['credit_size'] ) ) ) : '';
			$clean['credit_align']  = in_array( $data['credit_align'], array( 'left', 'center', 'right' ), true ) ? $data['credit_align'] : 'center';
			$clean['delete_data_on_uninstall'] = ! empty( $data['delete_data_on_uninstall'] );
			$clean['privacy_erasure_policy'] = isset( $data['privacy_erasure_policy'] ) && 'anonymize_mutable_recipient' === sanitize_key( (string) $data['privacy_erasure_policy'] ) ? 'anonymize_mutable_recipient' : 'retain';
			$clean['recipient_user_sync_enabled'] = ! empty( $data['recipient_user_sync_enabled'] );
			$name_source = $raw_data['certificate_name_source'] ?? self::get( 'certificate_name_source', 'profile' );
			$name_key = $raw_data['certificate_name_field_key'] ?? self::get( 'certificate_name_field_key', '' );
			$name_catalog = 'approved_field' === $name_source && class_exists( 'Ecapla_CertVerify_Fields' ) ? Ecapla_CertVerify_Fields::get_field_map() : array();
			$name_policy = ( new \Certloom\Application\Recipient\CertificateNamePolicy() )->normalize( $name_source, $name_key, $name_catalog );
			if ( is_wp_error( $name_policy ) ) { return $name_policy; }
			$clean['certificate_name_source'] = $name_policy['source']; $clean['certificate_name_field_key'] = $name_policy['field_key'];
			if ( 'approved_field' === $name_policy['source'] && ( self::get( 'certificate_name_source', 'profile' ) !== $name_policy['source'] || self::get( 'certificate_name_field_key', '' ) !== $name_policy['field_key'] ) ) {
				$name_valid = ( new \Certloom\Application\Recipient\CertificateNamePolicy() )->validate_change( $name_policy, $name_catalog );
				if ( is_wp_error( $name_valid ) ) { return $name_valid; }
			}
			$clean['verification_branding_enabled'] = ! empty( $data['verification_branding_enabled'] );
			$clean['verification_brand_name'] = sanitize_text_field( (string) $data['verification_brand_name'] );
			$clean['verification_brand_name'] = function_exists( 'mb_substr' ) ? mb_substr( $clean['verification_brand_name'], 0, 120 ) : substr( $clean['verification_brand_name'], 0, 120 );
			$clean['verification_brand_tagline'] = sanitize_text_field( (string) $data['verification_brand_tagline'] );
			$clean['verification_brand_tagline'] = function_exists( 'mb_substr' ) ? mb_substr( $clean['verification_brand_tagline'], 0, 240 ) : substr( $clean['verification_brand_tagline'], 0, 240 );
			$surface = sanitize_hex_color( (string) $data['verification_surface_color'] );
			$text = sanitize_hex_color( (string) $data['verification_text_color'] );
			$clean['verification_surface_color'] = $surface ? $surface : '#ffffff';
			$clean['verification_text_color'] = self::accessible_text_color( $clean['verification_surface_color'], $text ? $text : '#01133d' );
			$clean['verification_use_site_icon'] = ! empty( $data['verification_use_site_icon'] );
			$clean['verification_qr_enabled'] = ! empty( $data['verification_qr_enabled'] );
			$clean['verification_qr_logo_enabled'] = ! empty( $data['verification_qr_logo_enabled'] );
			$clean['verification_barcode_enabled'] = ! empty( $data['verification_barcode_enabled'] );
			// Appearance is global and survives callers that update unrelated settings.
			$layout = $raw_data['certificate_layout'] ?? self::get( 'certificate_layout', 'right' );
			$width = $raw_data['certificate_center_width'] ?? self::get( 'certificate_center_width', 960 );
			$clean['certificate_layout'] = in_array( $layout, array( 'left', 'right', 'center' ), true ) ? $layout : 'right';
			$clean['certificate_center_width'] = is_numeric( $width ) ? min( 1400, max( 320, (int) $width ) ) : 960;

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
			$success = \Certloom\Infrastructure\Data\LegacyWriteGuard::verify_option( self::OPTION_KEY, $clean );
			if ( $success && ( ( $stored['certificate_name_source'] ?? 'profile' ) !== $clean['certificate_name_source'] || ( $stored['certificate_name_field_key'] ?? '' ) !== $clean['certificate_name_field_key'] ) ) {
				$queued = ( new \Certloom\Infrastructure\Recipient\PendingConfigurationQueue() )->changed();
				if ( is_wp_error( $queued ) ) { return $queued; }
				$name_changed = true;
			}
			return $success;
		} );
		if ( ! is_wp_error( $result ) && true === $result && $name_changed ) { do_action( 'certloom_pending_configuration_changed' ); }
		return ! is_wp_error( $result ) && true === $result;
	}

	/** Keeps clean-route brand text at WCAG AA contrast for normal text. */
	public static function accessible_text_color( $surface, $preferred ) {
		$surface   = self::normalize_hex( $surface, '#ffffff' );
		$preferred = self::normalize_hex( $preferred, '#01133d' );
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
