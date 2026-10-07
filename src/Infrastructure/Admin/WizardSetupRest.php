<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Admin\DraftWorkspace;
use Certloom\Infrastructure\Migration\DomainFeatureGate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private administrator read; recipient values and shared defaults never enter this response. */
final class WizardSetupRest {
	const ROUTE = '/certloom/v1/admin/setup/field-check';
	const READINESS_ROUTE = '/certloom/v1/admin/setup/readiness-check';
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'private_response' ), 10, 3 );
	}
	public function routes() {
		$definition = array( 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => array( $this, 'permission' ), 'callback' => array( $this, 'check' ), 'args' => array(
			'run_id' => array( 'required' => true, 'validate_callback' => static function ( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-z0-9-]{1,80}$/D', $value ); } ),
			'revision' => array( 'required' => true, 'validate_callback' => static function ( $value ) { return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $value ); } ),
		) );
		register_rest_route( 'certloom/v1', '/admin/setup/field-check', $definition );
		register_rest_route( 'certloom/v1', '/admin/setup/readiness-check', $definition );
	}
	public function permission() { return is_user_logged_in() && current_user_can( 'manage_options' ); }
	public function check( \WP_REST_Request $request ) {
		if ( ! DomainFeatureGate::admin_preview_ready() ) { return new \WP_Error( 'certloom_setup_check_unavailable', __( 'Setup checks are unavailable while credential configuration is protected.', 'certloom' ), array( 'status' => 503 ) ); }
		$workspace = DraftWorkspace::get( 'setup' );
		if ( ( $workspace['run_id'] ?? '' ) !== $request['run_id'] || (int) ( $workspace['revision'] ?? -1 ) !== (int) $request['revision'] ) { return new \WP_Error( 'certloom_setup_check_stale', __( 'Setup changed after this page loaded. Reload the review to check the saved choices.', 'certloom' ), array( 'status' => 409 ) ); }
		// Inspect the exact captured revision, even if another tab autosaves while this read runs.
		$draft = $workspace['payload']['wizard_draft'] ?? array();
		$result = self::READINESS_ROUTE === $request->get_route() ? ( new WizardSetupReadiness() )->inspect( $draft ) : ( new WizardFieldSetupCheck() )->inspect( $draft );
		if ( is_wp_error( $result ) ) { $result->add_data( array( 'status' => 400 ) ); return $result; }
		$result['run_id'] = $workspace['run_id']; $result['revision'] = $workspace['revision'];
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}
	public function private_response( $response, $server, $request ) {
		if ( in_array( $request->get_route(), array( self::ROUTE, self::READINESS_ROUTE ), true ) && $response instanceof \WP_HTTP_Response ) { $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); $response->header( 'Vary', 'Cookie', false ); $response->header( 'X-Robots-Tag', 'noindex' ); }
		return $response;
	}
}
