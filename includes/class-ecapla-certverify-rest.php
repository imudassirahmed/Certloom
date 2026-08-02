<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public exact-match certificate verification endpoint.
 */
class Ecapla_CertVerify_REST {

	const NAMESPACE_        = 'certverify/v1';
	const LEGACY_NAMESPACE = 'ecapla-certverify/v1';
	const RATE_LIMIT       = 30;

	public static function register_routes() {
		foreach ( array( self::NAMESPACE_, self::LEGACY_NAMESPACE ) as $namespace ) {
			register_rest_route(
				$namespace,
				'/verify',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'verify' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'number' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_number' ),
							'validate_callback' => array( __CLASS__, 'validate_number' ),
						),
					),
				)
			);
		}
	}

	public static function sanitize_number( $value ) {
		return trim( sanitize_text_field( (string) $value ) );
	}

	public static function validate_number( $value ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
		return '' !== trim( (string) $value ) && $length <= 100;
	}

	public static function verify( $request ) {
		if ( function_exists( 'ecapla_certverify_verification_is_available' ) && ! ecapla_certverify_verification_is_available() ) {
			$response = new WP_REST_Response(
				array(
					'code'    => 'ecapla_certverify_temporarily_unavailable',
					'message' => __( 'Certificate verification is temporarily unavailable. Please try again later.', 'certverify' ),
				),
				503
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		if ( self::is_rate_limited() ) {
			$response = new WP_REST_Response(
				array(
					'code'    => 'ecapla_certverify_rate_limited',
					'message' => __( 'Too many verification requests. Please wait a minute and try again.', 'certverify' ),
				),
				429
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		$number      = self::sanitize_number( $request->get_param( 'number' ) );
		$certificate = Ecapla_CertVerify_Database::get_by_cert_number( $number );
		if ( is_wp_error( $certificate ) ) {
			$response = new WP_REST_Response(
				array(
					'code'    => 'ecapla_certverify_temporarily_unavailable',
					'message' => __( 'Certificate verification is temporarily unavailable. Please try again later.', 'certverify' ),
				),
				503
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		$response = new WP_REST_Response(
			$certificate
				? array(
					'found'  => true,
					'fields' => Ecapla_CertVerify_Render::fields_for_output( $certificate ),
				)
				: array( 'found' => false ),
			200
		);
		return self::add_private_headers( $response );
	}

	public static function is_rate_limited() {
		$limit      = max( 1, absint( apply_filters( 'ecapla_certverify_rate_limit', self::RATE_LIMIT ) ) );
		$identifier = self::get_ip() . '|' . self::get_user_agent();
		$identifier = (string) apply_filters( 'ecapla_certverify_rate_limit_identifier', $identifier );
		$bucket     = (string) floor( time() / MINUTE_IN_SECONDS );
		$key        = 'ecapla_certverify_rl_' . hash_hmac( 'sha256', $identifier . '|' . $bucket, wp_salt( 'nonce' ) );

		if ( wp_using_ext_object_cache() ) {
			$group = 'certverify';
			if ( wp_cache_add( $key, 1, $group, 70 ) ) {
				return false;
			}
			$count = wp_cache_incr( $key, 1, $group );
			if ( false === $count ) {
				wp_cache_set( $key, 1, $group, 70 );
				return false;
			}
			return (int) $count > $limit;
		}

		global $wpdb;
		$option_key = 'certverify_rl_' . $bucket . '_' . substr( hash_hmac( 'sha256', $identifier, wp_salt( 'nonce' ) ), 0, 40 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A single atomic INSERT ... ON DUPLICATE KEY UPDATE prevents race-prone transient counters.
		$increment  = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$wpdb->options,
				$option_key
			)
		);
		wp_cache_delete( $option_key, 'options' );
		if ( false === $increment ) {
			return false; // Availability takes priority if the protective counter cannot be written.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the just-updated atomic counter directly so the rate-limit decision cannot use stale option-cache data.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option_key )
		);

		if ( 1 === wp_rand( 1, 100 ) ) {
			$current_prefix = $wpdb->esc_like( 'certverify_rl_' . $bucket . '_' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Probabilistic cleanup removes expired internal rate-limit buckets; caching these ephemeral rows would be counterproductive.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s',
					$wpdb->options,
					$wpdb->esc_like( 'certverify_rl_' ) . '%',
					$current_prefix
				)
			);
		}
		return $count > $limit;
	}

	private static function add_private_headers( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	private static function get_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip );
	}

	private static function get_user_agent() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $user_agent, 0, 200 ) : substr( $user_agent, 0, 200 );
	}
}
