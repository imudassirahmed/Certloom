<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Authenticated, read-only admin search routes.
 *
 * These endpoints improve admin responsiveness only. They never create or
 * mutate Certloom domain authority, and every returned choice/result is
 * revalidated by the normal server-side save/issuance paths.
 */
final class AdminSearchRest {
	const NAMESPACE = 'certloom/v1';

	/** @return void */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/** @return void */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/admin/context',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'context' ),
				'permission_callback' => array( $this, 'context_permission' ),
				'args'                => array(
					'kind'           => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'query'          => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'program_id'     => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'program_status' => array( 'default' => 'active', 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);


		register_rest_route(
			self::NAMESPACE,
			'/admin/template-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'template_preview' ),
				'permission_callback' => array( $this, 'template_preview_permission' ),
				'args'                => array(
					'template_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/credentials',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'credentials' ),
				'permission_callback' => array( $this, 'credentials_permission' ),
				'args'                => array(
					's'                 => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'credential_status' => array( 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
					'program_id'        => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'orderby'           => array( 'default' => 'recent', 'sanitize_callback' => 'sanitize_key' ),
					'order'             => array( 'default' => 'desc', 'sanitize_callback' => 'sanitize_key' ),
					'paged'             => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/** @return bool */
	public function context_permission() {
		return is_user_logged_in() && ScopedFieldAdminAjax::can_lookup();
	}

	/** @return bool */
	public function credentials_permission() {
		return is_user_logged_in() && current_user_can( \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS );
	}


	/** @return bool */
	public function template_preview_permission() {
		return is_user_logged_in() && (
			current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS )
			|| current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS )
			|| current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES )
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function context( \WP_REST_Request $request ) {
		$choices = ( new ScopedFieldAdminAjax() )->choices(
			(string) $request->get_param( 'kind' ),
			(string) $request->get_param( 'query' ),
			absint( $request->get_param( 'program_id' ) ),
			(string) $request->get_param( 'program_status' )
		);
		if ( is_wp_error( $choices ) ) { return $choices; }
		return rest_ensure_response( array( 'choices' => $choices, 'limit' => 20 ) );
	}


	/** @return \WP_REST_Response|\WP_Error */
	public function template_preview( \WP_REST_Request $request ) {
		$template_id = absint( $request->get_param( 'template_id' ) );
		if ( ! $template_id ) {
			return rest_ensure_response( array( 'template_id' => 0, 'name' => __( 'Inherited design', 'certloom' ), 'description' => __( 'Choose a published design to preview a one-off override.', 'certloom' ), 'html' => '' ) );
		}
		$template = ( new \Certloom\Infrastructure\Repository\TemplateRepository() )->find_published( $template_id );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) ) { return new \WP_Error( 'certloom_template_preview_missing', __( 'That published design is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }
		$plan = ( new \Certloom\Infrastructure\Rendering\TemplatePreviewService() )->build( $template, 'published' );
		if ( is_wp_error( $plan ) ) { return $plan; }
		try {
			$preview = ( new \Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview() )->present( $plan['render_plan'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_preview_failed', __( 'Certloom could not build that design preview.', 'certloom' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array(
			'template_id' => $template_id,
			'name'        => (string) ( $template['name'] ?? '' ),
			'description' => (string) ( $template['description'] ?? '' ),
			'revision'    => absint( $plan['revision_number'] ?? 0 ),
			'html'        => (string) ( $preview['html'] ?? '' ),
		) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function credentials( \WP_REST_Request $request ) {
		$result = ( new DomainAdmin() )->credential_results_payload(
			array(
				's'                 => (string) $request->get_param( 's' ),
				'credential_status' => (string) $request->get_param( 'credential_status' ),
				'program_id'        => absint( $request->get_param( 'program_id' ) ),
				'orderby'           => (string) $request->get_param( 'orderby' ),
				'order'             => (string) $request->get_param( 'order' ),
				'paged'             => max( 1, absint( $request->get_param( 'paged' ) ) ),
			)
		);
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}
}
