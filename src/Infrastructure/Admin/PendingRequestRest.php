<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Issuance\PendingRequestAdminService;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Migration\DomainFeatureGate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Cookie/nonce staff boundary. All commands also authorize inside the service. */
final class PendingRequestRest {
	const BASE = '/admin/certificate-requests';
	public function register() { add_action( 'rest_api_init', array( $this, 'routes' ) ); add_filter( 'rest_post_dispatch', array( $this, 'private_headers' ), 10, 3 ); }
	public function routes() {
		$cursor = array( 'type' => 'integer', 'default' => 0, 'validate_callback' => array( $this, 'valid_id' ) );
		register_rest_route( 'certloom/v1', self::BASE . '/policy', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'policy' ), 'permission_callback' => array( $this, 'permission' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_policy' ), 'permission_callback' => array( $this, 'permission' ) ),
		) );
		register_rest_route( 'certloom/v1', self::BASE . '/health', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'health' ), 'permission_callback' => array( $this, 'permission' ) ) );
		register_rest_route( 'certloom/v1', self::BASE . '/programs', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'programs' ), 'permission_callback' => array( $this, 'permission' ), 'args' => array( 'after_id' => $cursor ) ) );
		register_rest_route( 'certloom/v1', self::BASE . '/repair-schedule', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'repair_schedule' ), 'permission_callback' => array( $this, 'can_recheck' ) ) );
		register_rest_route( 'certloom/v1', self::BASE, array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'queue' ), 'permission_callback' => array( $this, 'permission' ), 'args' => array(
			'status' => array( 'type' => 'string', 'default' => 'waiting', 'enum' => array_merge( array( 'all' ), \Certloom\Domain\Issuance\IssuanceRequestState::all() ) ), 'cursor_id' => $cursor, 'cursor_due' => array( 'type' => 'string', 'default' => '' ),
			'program_id' => $cursor, 'owner' => array( 'type' => 'string', 'default' => 'all', 'enum' => array( 'all', 'recipient', 'organization', 'source' ) ),
			'task' => array( 'type' => 'string', 'default' => 'all', 'enum' => array( 'all', 'details', 'approval', 'configuration', 'qualification' ) ),
			'age' => array( 'type' => 'string', 'default' => 'all', 'enum' => array( 'all', 'day', 'week', 'month' ) ), 'as_of' => array( 'type' => 'string', 'default' => '' ),
		) ) );
		register_rest_route( 'certloom/v1', self::BASE . '/(?P<id>[1-9][0-9]*)/history', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'history' ), 'permission_callback' => array( $this, 'permission' ), 'args' => array( 'id' => $cursor, 'after_id' => $cursor ) ) );
		foreach ( array( 'recheck', 'cancel' ) as $action ) {
			register_rest_route( 'certloom/v1', self::BASE . '/(?P<id>[1-9][0-9]*)/' . $action, array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, $action ), 'permission_callback' => array( $this, 'recheck' === $action ? 'can_recheck' : 'permission' ), 'args' => array( 'id' => $cursor ) ) );
		}
	}
	public function valid_id( $value ) { return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', (string) $value ) && strlen( (string) $value ) < 19 && (float) $value <= PHP_INT_MAX; }
	public function permission() {
		if ( ! is_user_logged_in() ) { return new \WP_Error( 'certloom_request_admin_sign_in', __( 'Sign in to manage certificate requests.', 'certloom' ), array( 'status' => 401 ) ); }
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return new \WP_Error( 'certloom_request_admin_forbidden', __( 'You cannot manage certificate requests.', 'certloom' ), array( 'status' => 403 ) ); }
		// A missing or stale marker still needs a real schema check, without a cache write on GET.
		if ( ! DomainFeatureGate::admin_preview_ready() || ( new DomainMaintenanceLock() )->active() || SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) || ( ! SchemaManager::health_marker_is_fresh() && true !== SchemaManager::verify_health_now( false ) ) ) { return new \WP_Error( 'certloom_request_admin_unavailable', __( 'Certificate requests are temporarily unavailable. Try again shortly.', 'certloom' ), array( 'status' => 503 ) ); }
		return true;
	}
	public function can_recheck() { $allowed = $this->permission(); return is_wp_error( $allowed ) ? $allowed : ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) ? true : new \WP_Error( 'certloom_request_recheck_forbidden', __( 'You need certificate issuance permission to request another assessment.', 'certloom' ), array( 'status' => 403 ) ) ); }
	public function queue( \WP_REST_Request $request ) { return $this->response( ( new PendingRequestAdminService() )->queue( get_current_user_id(), $request['status'], (int) $request['cursor_id'], '' === $request['cursor_due'] ? null : $request['cursor_due'], array( 'program_id' => (int) $request['program_id'], 'owner' => $request['owner'], 'task' => $request['task'], 'age' => $request['age'], 'as_of' => '' === $request['as_of'] ? null : $request['as_of'] ) ) ); }
	public function programs( \WP_REST_Request $request ) { return $this->response( ( new PendingRequestAdminService() )->programs( get_current_user_id(), (int) $request['after_id'] ) ); }
	public function history( \WP_REST_Request $request ) { return $this->response( ( new PendingRequestAdminService() )->history( get_current_user_id(), (int) $request['id'], (int) $request['after_id'] ) ); }
	public function recheck( \WP_REST_Request $request ) { return $this->command( $request, 'recheck' ); }
	public function health() { return $this->response( ( new \Certloom\Application\Issuance\PendingWorkflowOperations() )->health( get_current_user_id() ) ); }
	public function policy() {
		$service = new \Certloom\Application\Issuance\PendingRequestPolicy(); $policy = $service->read();
		return is_wp_error( $policy ) ? $policy : $this->response( array( 'policy' => $policy, 'sha256' => $service->fingerprint( $policy ), 'can_edit' => current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SETTINGS ) ) );
	}
	public function save_policy( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || count( $body ) !== 2 || array_diff( array_keys( $body ), array( 'policy', 'sha256' ) ) || ! is_array( $body['policy'] ) || ! is_string( $body['sha256'] ) ) { return new \WP_Error( 'certloom_request_policy_body_invalid', __( 'Refresh request settings before saving.', 'certloom' ), array( 'status' => 400 ) ); }
		$saved = ( new \Certloom\Application\Issuance\PendingRequestPolicy() )->save( get_current_user_id(), $body['policy'], $body['sha256'] );
		return is_wp_error( $saved ) ? $saved : $this->policy();
	}
	public function repair_schedule( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_keys( $body ) !== array( 'confirm' ) || true !== $body['confirm'] ) { return new \WP_Error( 'certloom_pending_repair_confirmation_required', __( 'Confirm scheduler repair before queuing checks.', 'certloom' ), array( 'status' => 400 ) ); }
		return $this->response( ( new \Certloom\Application\Issuance\PendingWorkflowOperations() )->repair( get_current_user_id() ) );
	}
	public function cancel( \WP_REST_Request $request ) { return $this->command( $request, 'cancel' ); }
	private function command( \WP_REST_Request $request, $action ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array_keys( $body ) !== array( 'revision' ) || ! is_int( $body['revision'] ) || $body['revision'] < 1 ) { return new \WP_Error( 'certloom_request_command_invalid', __( 'Refresh the request before trying again.', 'certloom' ), array( 'status' => 400 ) ); }
		return $this->response( ( new PendingRequestAdminService() )->command( get_current_user_id(), (int) $request['id'], $body['revision'], $action ) );
	}
	public function private_headers( $response, $server, $request ) {
		$route = $request->get_route(); $prefix = '/certloom/v1' . self::BASE;
		if ( ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) && $response instanceof \WP_HTTP_Response ) { $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); $response->header( 'Vary', 'Cookie', false ); $response->header( 'X-Robots-Tag', 'noindex' ); }
		return $response;
	}
	private function response( $result ) { return is_wp_error( $result ) ? $result : $this->private_headers( new \WP_REST_Response( $result ), null, new \WP_REST_Request( 'GET', '/certloom/v1' . self::BASE ) ); }
}
