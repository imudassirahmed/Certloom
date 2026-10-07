<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Anonymous transport is authorized by the bounded route-bound signature. */
final class WebhookV2Rest {
	public static function register() { add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }
	public static function routes() {
		$base = '/integrations/webhook/(?P<source>v2-[a-f0-9]{12})';
		foreach ( array( '' => 'intake', '/test' => 'test', '/requests/(?P<request_ref>[a-f0-9]{32})/evidence' => 'evidence' ) as $suffix => $mode ) {
			register_rest_route( 'certloom/v2', $base . $suffix, array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static function ( $request ) use ( $mode ) { return self::receive( $request, $mode ); } ) );
		}
	}
	public static function receive( $request, $mode ) {
		// Query/JSON parameters must not replace the matched, signed route identity.
		$route = $request->get_url_params();
		$result = ( new WebhookV2Bridge() )->handle( $route['source'] ?? null, $mode, $request->get_header( 'x-certloom-timestamp' ), $request->get_header( 'x-certloom-signature' ), $request->get_body(), 'evidence' === $mode ? ( $route['request_ref'] ?? '' ) : '' );
		$status = 202;
		if ( is_wp_error( $result ) ) { $code = $result->get_error_code(); $status = false !== strpos( $code, 'signature' ) || false !== strpos( $code, 'timestamp' ) || false !== strpos( $code, 'connection_unavailable' ) ? 401 : ( false !== strpos( $code, 'authority_closed' ) ? 503 : 409 ); $result = array( 'error' => $code, 'message' => $result->get_error_message() ); }
		elseif ( 'test' === $mode ) { $status = 200; }
		$response = new \WP_REST_Response( $result, $status ); $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); return $response;
	}
}
