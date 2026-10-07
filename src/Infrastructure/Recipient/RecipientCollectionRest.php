<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Application\Recipient\RecipientIssuanceRequests;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Cookie/nonce-authenticated account API; ownership is never a request parameter. */
final class RecipientCollectionRest {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'private_headers' ), 10, 3 );
	}
	public function private_headers( $response, $server, $request ) {
		$route = $request->get_route();
		if ( in_array( $route, array( '/certloom/v1/me/details', '/certloom/v1/me/certificate-requests' ), true ) || 0 === strpos( $route, '/certloom/v1/admin/recipient-submissions' ) ) {
			if ( $response instanceof \WP_HTTP_Response ) { $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); $response->header( 'Vary', 'Cookie', false ); $response->header( 'X-Robots-Tag', 'noindex' ); }
		}
		return $response;
	}
	public function register_routes() {
		$ids = array( 'type' => 'integer', 'default' => 0, 'validate_callback' => array( $this, 'valid_id' ) );
		register_rest_route( 'certloom/v1', '/me/details', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'read' ), 'permission_callback' => array( $this, 'can_read' ), 'args' => array( 'registration_id' => $ids, 'after_registration_id' => $ids, 'request_id' => $ids ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save' ), 'permission_callback' => array( $this, 'can_read' ) ),
		) );
		register_rest_route( 'certloom/v1', '/me/certificate-requests', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'requests' ), 'permission_callback' => array( $this, 'can_read' ), 'args' => array( 'after_id' => $ids, 'view' => array( 'type' => 'string', 'enum' => array( 'active', 'closed' ), 'default' => 'active' ) ) ) );
		register_rest_route( 'certloom/v1', '/admin/recipient-submissions/(?P<id>[1-9][0-9]*)/decision', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'decide' ), 'permission_callback' => array( $this, 'can_review' ) ) );
		register_rest_route( 'certloom/v1', '/admin/recipient-submissions', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'queue' ), 'permission_callback' => array( $this, 'can_review' ), 'args' => array( 'after_id' => $ids ) ) );
	}

	public function valid_id( $value ) { return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', (string) $value ) && strlen( (string) $value ) < 19; }
	public function can_read() {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) { return new \WP_Error( 'certloom_account_sign_in_required', __( 'Sign in to complete your certificate details.', 'certloom' ), array( 'status' => 401 ) ); }
		if ( ( new DomainMaintenanceLock() )->active() || SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) { return $this->unavailable(); }
		if ( ! SchemaManager::health_marker_is_fresh() && true !== SchemaManager::verify_health_now() ) { return $this->unavailable(); }
		return true;
	}
	public function can_review() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS ) ) { return new \WP_Error( 'certloom_account_review_forbidden', __( 'You cannot review submitted recipient details.', 'certloom' ), array( 'status' => 403 ) ); }
		return $this->can_read();
	}

	public function read( \WP_REST_Request $request ) {
		return $this->response( ( new RecipientCollectionService() )->read_for_user( get_current_user_id(), (int) $request['registration_id'], (int) $request['after_registration_id'], (int) $request['request_id'] ) );
	}
	public function queue( \WP_REST_Request $request ) { return $this->response( ( new RecipientCollectionService() )->review_queue( get_current_user_id(), (int) $request['after_id'] ) ); }
	public function requests( \WP_REST_Request $request ) { return $this->response( ( new RecipientIssuanceRequests() )->read_for_user( get_current_user_id(), (int) $request['after_id'], $request['view'] ?? 'active' ) ); }
	public function save( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), array( 'registration_id', 'request_id', 'revision', 'values' ) ) || ! isset( $body['revision'], $body['values'] ) || ! $this->valid_id( $body['registration_id'] ?? 0 ) || ! $this->valid_id( $body['request_id'] ?? 0 ) ) { return $this->invalid(); }
		return $this->response( ( new RecipientCollectionService() )->save_for_user( get_current_user_id(), (int) ( $body['registration_id'] ?? 0 ), $body['revision'], $body['values'], (int) ( $body['request_id'] ?? 0 ) ) );
	}
	public function decide( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), array( 'revision', 'approve' ) ) || ! isset( $body['revision'], $body['approve'] ) || ! is_int( $body['revision'] ) || $body['revision'] < 1 || ! is_bool( $body['approve'] ) ) { return $this->invalid(); }
		$result = ( new RecipientCollectionService() )->decide( (int) $request['id'], $body['revision'], $body['approve'], get_current_user_id() );
		if ( is_wp_error( $result ) ) { return $result; }
		return $this->response( array( 'id' => (int) $result['id'], 'revision' => (int) $result['revision'], 'status' => $result['status'] ) );
	}

	private function response( $result ) {
		if ( is_wp_error( $result ) ) { return $result; }
		$response = new \WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}
	private function invalid() { return new \WP_Error( 'certloom_account_request_invalid', __( 'The submitted details are invalid. Refresh the form and try again.', 'certloom' ), array( 'status' => 400 ) ); }
	private function unavailable() { return new \WP_Error( 'certloom_account_temporarily_unavailable', __( 'Certificate details are temporarily unavailable. Please try again shortly.', 'certloom' ), array( 'status' => 503 ) ); }
}
