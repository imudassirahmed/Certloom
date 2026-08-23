<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ecapla_CertVerify_Public_Policy' ) ) {
	require_once dirname( __FILE__ ) . '/class-ecapla-certverify-public-policy.php';
}

/**
 * Transport-neutral public presentation for the currently authoritative legacy registry.
 *
 * Shortcode, Gutenberg, Elementor, REST and clean/profile routes must consume this
 * class rather than independently deciding which fields, status metadata or
 * public actions are safe to expose.
 */
class Ecapla_CertVerify_Public_Presentation {

	/** @var array<string,mixed> Request-local lookup cache. */
	private static $lookup_cache = array();

	/**
	 * Looks up and presents one legacy certificate.
	 *
	 * @param string $number Certificate number.
	 * @param bool   $rate_limit Whether public rate limiting should be applied.
	 * @return array|WP_Error Presentation array, not-found result, or fail-closed error.
	 */
	public static function verify( $number, $rate_limit = true, $context = 'verification' ) {
		$number = trim( sanitize_text_field( (string) $number ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $number ) : strlen( $number );
		if ( '' === $number || $length > 100 ) {
			return array( 'found' => false );
		}
		if ( function_exists( 'ecapla_certverify_verification_is_available' ) && ! ecapla_certverify_verification_is_available() ) {
			return new WP_Error( 'ecapla_certverify_verification_unavailable', __( 'Certificate verification is temporarily unavailable. Please try again later.', 'certloom' ) );
		}

		$key = Ecapla_CertVerify_Database::normalize_certificate_number( $number );
		if ( ! array_key_exists( $key, self::$lookup_cache ) ) {
			if ( $rate_limit && Ecapla_CertVerify_REST::is_rate_limited( 'legacy_verify' ) ) {
				self::$lookup_cache[ $key ] = new WP_Error( 'ecapla_certverify_rate_limited', __( 'Too many verification requests. Please wait a minute and try again.', 'certloom' ) );
			} else {
				self::$lookup_cache[ $key ] = Ecapla_CertVerify_Database::get_by_cert_number( $number );
			}
		}

		$certificate = self::$lookup_cache[ $key ];
		if ( is_wp_error( $certificate ) ) {
			return $certificate;
		}
		if ( ! is_array( $certificate ) ) {
			return array( 'found' => false );
		}
		return self::present_certificate( $certificate, $context );
	}

	/**
	 * Shapes one already-read legacy row into the shared guest-safe view model.
	 *
	 * @param array $certificate Decoded legacy record.
	 * @return array<string,mixed>
	 */
	public static function present_certificate( array $certificate, $context = 'verification' ) {
		$context   = 'profile' === sanitize_key( (string) $context ) ? 'profile' : 'verification';
		if ( ! Ecapla_CertVerify_Public_Policy::allows_context( $certificate, $context ) ) { return array( 'found' => false ); }
		$number    = isset( $certificate['cert_number'] ) ? (string) $certificate['cert_number'] : '';
		$effective = Ecapla_CertVerify_Database::effective_status( $certificate );
		$statuses  = Ecapla_CertVerify_Fields::get_statuses();
		$status    = isset( $statuses[ $effective ] ) ? $statuses[ $effective ] : array(
			'label' => ucfirst( sanitize_key( $effective ) ),
			'desc'  => '',
		);
		$state     = class_exists( 'Ecapla_CertVerify_Onboarding' ) ? Ecapla_CertVerify_Onboarding::get_state() : array();
		$issuer    = isset( $state['organization_name'] ) ? trim( (string) $state['organization_name'] ) : '';
		$verify    = class_exists( 'Ecapla_CertVerify_Public_Router' ) ? Ecapla_CertVerify_Public_Router::verification_url( $number ) : '';
		$profile   = Ecapla_CertVerify_Public_Policy::MODE_PROFILE === Ecapla_CertVerify_Public_Policy::guest_mode( $certificate ) && class_exists( 'Ecapla_CertVerify_Public_Router' ) ? Ecapla_CertVerify_Public_Router::profile_url( $number ) : '';

		return array(
			'found'              => true,
			'certificate_number' => $number,
			'fields'             => self::fields_for_output( $certificate, $context ),
			'status'             => array(
				'key'         => sanitize_key( $effective ),
				'label'       => isset( $status['label'] ) ? (string) $status['label'] : ucfirst( sanitize_key( $effective ) ),
				'description' => isset( $status['desc'] ) ? (string) $status['desc'] : '',
			),
			'issuer'             => $issuer,
			'verified_at'        => self::verification_timestamp(),
			'verification_url'   => $verify,
			'profile_url'        => $profile,
			'visibility'         => Ecapla_CertVerify_Public_Policy::visibility( $certificate ),
			'actions'            => Ecapla_CertVerify_Public_Policy::actions( $certificate, $context, $verify, $profile ),
			'labels'             => array(
				'issued_by' => __( 'Issued by', 'certloom' ),
				'verified'  => __( 'Verified', 'certloom' ),
			),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function fields_for_output( array $certificate, $context = 'verification' ) {
		$output = array();
		foreach ( Ecapla_CertVerify_Fields::get_visible_fields( $context ) as $field ) {
			$raw = ! empty( $field['column'] )
				? ( isset( $certificate[ $field['key'] ] ) ? $certificate[ $field['key'] ] : '' )
				: ( isset( $certificate['custom_fields'][ $field['key'] ] ) ? $certificate['custom_fields'][ $field['key'] ] : '' );

			if ( 'status' === $field['type'] ) {
				$effective = Ecapla_CertVerify_Database::effective_status( $certificate );
				$statuses  = Ecapla_CertVerify_Fields::get_statuses();
				$output[]  = array(
					'label'      => $field['label'],
					'value'      => isset( $statuses[ $effective ] ) ? $statuses[ $effective ]['label'] : ucfirst( $effective ),
					'is_status'  => true,
					'status_key' => $effective,
				);
				continue;
			}

			if ( 'date' === $field['type'] && $raw ) {
				$raw = Ecapla_CertVerify_Validator::format_date( $raw );
			}
			$output[] = array(
				'label'     => $field['label'],
				'value'     => (string) $raw,
				'is_status' => false,
			);
		}
		return $output;
	}

	private static function verification_timestamp() {
		if ( function_exists( 'current_time' ) ) {
			$timestamp = current_time( 'timestamp', true );
			if ( is_numeric( $timestamp ) ) {
				return gmdate( 'c', (int) $timestamp );
			}
		}
		return gmdate( 'c' );
	}
}
