<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Clean, privacy-safe public routes for legacy verification/profile presentation. */
class Ecapla_CertVerify_Public_Router {
	const ROUTE_VAR       = 'certloom_route';
	const TOKEN_VAR       = 'certloom_token';
	const REWRITE_OPTION  = 'certverify_public_rewrite_version';
	const REWRITE_VERSION = '1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ), 5 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrites' ) );
	}

	public static function register_rewrites() {
		if ( ! function_exists( 'add_rewrite_rule' ) ) {
			return;
		}
		add_rewrite_rule( '^certloom/verify/([^/]{1,180})/?$', 'index.php?' . self::ROUTE_VAR . '=verify&' . self::TOKEN_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^certloom/credential/([^/]{1,180})/?$', 'index.php?' . self::ROUTE_VAR . '=credential&' . self::TOKEN_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::ROUTE_VAR;
		$vars[] = self::TOKEN_VAR;
		return array_values( array_unique( $vars ) );
	}

	public static function maybe_flush_rewrites() {
		if ( self::REWRITE_VERSION === (string) get_option( self::REWRITE_OPTION, '' ) ) {
			return;
		}
		self::register_rewrites();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	public static function activate() {
		self::register_rewrites();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	public static function current_route() {
		$route = function_exists( 'get_query_var' ) ? sanitize_key( (string) get_query_var( self::ROUTE_VAR ) ) : '';
		if ( ! in_array( $route, array( 'verify', 'credential' ), true ) ) {
			return '';
		}
		return $route;
	}

	public static function requested_number() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only verification routing.
		foreach ( array( 'certloom_number', 'cv_number' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				return self::sanitize_number( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$token = function_exists( 'get_query_var' ) ? (string) get_query_var( self::TOKEN_VAR ) : '';
		return self::number_from_token( $token );
	}

	public static function token_for_number( $number ) {
		$number = self::sanitize_number( $number );
		if ( '' === $number ) {
			return '';
		}
		if ( '~' !== substr( $number, 0, 1 ) && preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $number ) ) {
			return rawurlencode( $number );
		}
		return '~' . rtrim( strtr( base64_encode( $number ), '+/', '-_' ), '=' );
	}

	public static function number_from_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || strlen( $token ) > 180 ) {
			return '';
		}
		if ( '~' === substr( $token, 0, 1 ) ) {
			$encoded = substr( $token, 1 );
			if ( '' === $encoded || ! preg_match( '/^[A-Za-z0-9_-]+$/', $encoded ) ) {
				return '';
			}
			$padding = strlen( $encoded ) % 4;
			if ( $padding ) {
				$encoded .= str_repeat( '=', 4 - $padding );
			}
			$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );
			return false === $decoded ? '' : self::sanitize_number( $decoded );
		}
		return self::sanitize_number( rawurldecode( $token ) );
	}

	public static function verification_url( $number ) {
		return self::route_url( 'verify', $number );
	}

	public static function profile_url( $number ) {
		return self::route_url( 'credential', $number );
	}

	private static function route_url( $route, $number ) {
		$token = self::token_for_number( $number );
		if ( '' === $token ) {
			return '';
		}
		$pretty = '' !== (string) get_option( 'permalink_structure', '' );
		if ( $pretty ) {
			return home_url( '/certloom/' . $route . '/' . $token . '/' );
		}
		return add_query_arg(
			array(
				self::ROUTE_VAR => $route,
				self::TOKEN_VAR => $token,
			),
			home_url( '/' )
		);
	}

	public static function template_include( $template ) {
		if ( '' === self::current_route() ) {
			return $template;
		}
		$route_template = ECAPLA_CERTVERIFY_PATH . 'public/views/credential-route.php';
		return file_exists( $route_template ) ? $route_template : $template;
	}

	public static function document_title( $title ) {
		$route = self::current_route();
		if ( 'credential' === $route ) {
			$number = self::requested_number();
			return '' !== $number
				? sprintf( __( 'Credential %s', 'certloom' ), $number )
				: __( 'Credential', 'certloom' );
		}
		if ( 'verify' === $route ) {
			return __( 'Verify a Certificate', 'certloom' );
		}
		return $title;
	}

	public static function robots( $robots ) {
		if ( '' === self::current_route() ) {
			return $robots;
		}
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	public static function send_headers() {
		if ( '' === self::current_route() || headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Content-Type-Options: nosniff', true );
	}

	private static function sanitize_number( $number ) {
		$number = trim( sanitize_text_field( (string) $number ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $number ) : strlen( $number );
		return '' !== $number && $length <= 100 ? $number : '';
	}
}
