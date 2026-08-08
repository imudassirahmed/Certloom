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

		$result  = update_option( self::OPTION_KEY, $clean );
		$success = $result || $clean === get_option( self::OPTION_KEY, array() );
		if ( $success && ! Ecapla_CertVerify_Backup::is_internal_restore_write() ) {
			Ecapla_CertVerify_Backup::mark_mutation();
		}
		return $success;
	}
}
