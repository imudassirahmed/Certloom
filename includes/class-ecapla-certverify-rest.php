<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public exact-match certificate verification endpoint.
 */
class Ecapla_CertVerify_REST {

	const NAMESPACE_        = 'certloom/v1';
	const RATE_LIMIT       = 30;

	public static function register_routes() {
		foreach ( array( self::NAMESPACE_ ) as $namespace ) {
			$number_arg = array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_number' ),
				'validate_callback' => array( __CLASS__, 'validate_number' ),
			);
			register_rest_route(
				$namespace,
				'/verify',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'verify' ),
						'permission_callback' => '__return_true',
						'args'                => array( 'number' => $number_arg ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'verify' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'number' => $number_arg,
							'code'   => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/credential',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'verify_credential' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
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
		$number = self::sanitize_number( $request->get_param( 'number' ) );
		$code = self::secondary_code_from_request( $request );
		$presentation = Ecapla_CertVerify_Public_Presentation::verify( $number, true, 'verification', $code );
		if ( is_wp_error( $presentation ) ) {
			$rate_limited = 'ecapla_certverify_rate_limited' === $presentation->get_error_code();
			$response = new WP_REST_Response(
				array(
					'code'    => $rate_limited ? 'ecapla_certverify_rate_limited' : 'ecapla_certverify_temporarily_unavailable',
					'message' => $rate_limited ? $presentation->get_error_message() : __( 'Certificate verification is temporarily unavailable. Please try again later.', 'certloom' ),
				),
				$rate_limited ? 429 : 503
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		if ( empty( $presentation['found'] ) ) {
			return self::add_private_headers( new WP_REST_Response( array( 'found' => false ), 200 ) );
		}

		$qr = null;
		if ( class_exists( 'Ecapla_CertVerify_QR' ) && ! empty( $presentation['verification_url'] ) ) {
			$qr_candidate = Ecapla_CertVerify_QR::public_payload( (string) $presentation['verification_url'] );
			if ( ! is_wp_error( $qr_candidate ) ) { $qr = $qr_candidate; }
		}

		$barcode = null;
		if ( class_exists( 'Ecapla_CertVerify_Barcode' ) && ! empty( $presentation['certificate_number'] ) ) {
			$barcode_candidate = Ecapla_CertVerify_Barcode::public_payload( (string) $presentation['certificate_number'] );
			if ( ! is_wp_error( $barcode_candidate ) ) { $barcode = $barcode_candidate; }
		}

		/* Keep the established fields contract while adding one shared presentation model. */
		$payload = array(
			'found'        => true,
			'fields'       => $presentation['fields'],
			'presentation' => array(
				'certificate_number' => $presentation['certificate_number'],
				'status'             => $presentation['status'],
				'issuer'             => $presentation['issuer'],
				'verified_at'        => $presentation['verified_at'],
				'verification_url'   => $presentation['verification_url'],
				'profile_url'        => $presentation['profile_url'],
				'visibility'         => $presentation['visibility'],
				'actions'            => $presentation['actions'],
				'labels'             => $presentation['labels'],
				'secondary_proof'    => isset( $presentation['secondary_proof'] ) ? $presentation['secondary_proof'] : 'not_required',
				'qr'                 => $qr,
				'barcode'            => $barcode,
			),
		);
		return self::add_private_headers( new WP_REST_Response( $payload, 200 ) );
	}

	/**
	 * Gated public verification adapter for the new Credential Series model.
	 *
	 * The endpoint exists in 1.5.2 code but stays unavailable until the explicit
	 * schema + complete legacy-migration cutover gate has been enabled.
	 */

	/** Secondary proof is accepted from POST bodies only, never query strings/URLs. */
	private static function secondary_code_from_request( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) || 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return '';
		}
		$params = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : array();
		if ( ! is_array( $params ) || ! array_key_exists( 'code', $params ) ) {
			$params = method_exists( $request, 'get_body_params' ) ? $request->get_body_params() : array();
		}
		return is_array( $params ) && isset( $params['code'] ) ? sanitize_text_field( (string) $params['code'] ) : '';
	}

	public static function verify_credential( $request ) {
		$gate = new \Certloom\Infrastructure\Verification\PublicVerificationGate();
		if ( ! $gate->is_ready() ) {
			$response = new WP_REST_Response(
				array(
					'code'    => 'certloom_series_verification_not_ready',
					'message' => __( 'Credential verification is temporarily unavailable while Certloom completes its in-place data upgrade.', 'certloom' ),
				),
				503
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		if ( self::is_rate_limited( 'credential_verify', true ) ) {
			$response = new WP_REST_Response(
				array( 'code' => 'certloom_rate_limited', 'message' => __( 'Too many verification requests. Please wait a minute and try again.', 'certloom' ) ),
				429
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		$repo = new \Certloom\Infrastructure\Repository\CredentialRepository();
		$result = ( new \Certloom\Application\CredentialVerificationService( $repo ) )->verify_public_id( self::sanitize_number( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $result ) ) {
			$response = new WP_REST_Response(
				array( 'code' => 'certloom_verification_temporarily_unavailable', 'message' => __( 'Credential verification is temporarily unavailable. Please try again later.', 'certloom' ) ),
				503
			);
			$response->header( 'Retry-After', '60' );
			return self::add_private_headers( $response );
		}

		$payload = ( new \Certloom\Application\PublicCredentialPresenter() )->present( $result );
		$filtered = apply_filters( 'certloom_public_verification_response', $payload, $result );
		if ( ! is_array( $filtered ) ) {
			$filtered = $payload;
		}
		/* Generic extensions may add fields, but cannot weaken/replace Core truth. */
		foreach ( $payload as $key => $value ) {
			$filtered[ $key ] = $value;
		}
		return self::add_private_headers( new WP_REST_Response( $filtered, 200 ) );
	}

	public static function is_rate_limited( $scope = 'verify', $fail_closed = false ) {
		$legacy_limit = max( 1, absint( apply_filters( 'ecapla_certverify_rate_limit', self::RATE_LIMIT, sanitize_key( (string) $scope ) ) ) );
		return ( new \Certloom\Infrastructure\Security\RateLimiter() )->is_limited( $scope, $fail_closed, $legacy_limit );
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
