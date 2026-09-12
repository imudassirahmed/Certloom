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
					'mode'        => array( 'default' => 'assigned', 'sanitize_callback' => 'sanitize_key' ),
					'template_id' => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'issuer_id'   => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'program_id'  => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
					'batch_id'    => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/issuance-preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issuance_preview' ),
				'permission_callback' => array( $this, 'template_preview_permission' ),
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
		$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
		if ( ! in_array( $mode, array( 'assigned', 'inherited' ), true ) ) {
			return new \WP_Error( 'certloom_template_preview_mode_invalid', __( 'Choose inherited or assigned design preview.', 'certloom' ), array( 'status' => 400 ) );
		}

		$template_id = absint( $request->get_param( 'template_id' ) );
		$source_scope = 'assigned';
		$source_id = 0;
		$source_label = __( 'Assigned for this credential only', 'certloom' );
		$program = null;
		$issuer = null;
		$batch = null;

		if ( 'inherited' === $mode ) {
			$program_id = absint( $request->get_param( 'program_id' ) );
			$batch_id = absint( $request->get_param( 'batch_id' ) );
			$explicit_issuer_id = absint( $request->get_param( 'issuer_id' ) );

			if ( $program_id ) {
				$program = ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->find( $program_id );
				if ( is_wp_error( $program ) ) { return $program; }
				if ( ! is_array( $program ) || empty( $program['active'] ) ) { return new \WP_Error( 'certloom_template_preview_program_missing', __( 'The selected Program is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }
			}
			if ( $batch_id ) {
				$batch = ( new \Certloom\Infrastructure\Repository\BatchRepository() )->find( $batch_id );
				if ( is_wp_error( $batch ) ) { return $batch; }
				if ( ! is_array( $batch ) || empty( $batch['active'] ) ) { return new \WP_Error( 'certloom_template_preview_batch_missing', __( 'The selected Batch is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }
				if ( ! is_array( $program ) || absint( $batch['program_id'] ?? 0 ) !== absint( $program['id'] ?? 0 ) ) { return new \WP_Error( 'certloom_template_preview_batch_program_mismatch', __( 'The selected Batch does not belong to the selected Program.', 'certloom' ), array( 'status' => 400 ) ); }
			}
			$program_issuer_id = is_array( $program ) ? absint( $program['issuer_id'] ?? 0 ) : 0;
			if ( $program_issuer_id && $explicit_issuer_id && $program_issuer_id !== $explicit_issuer_id ) { return new \WP_Error( 'certloom_template_preview_issuer_program_mismatch', __( 'The selected Issuer does not match the selected Program.', 'certloom' ), array( 'status' => 400 ) ); }
			$issuer_id = $program_issuer_id ?: $explicit_issuer_id;
			if ( $issuer_id ) {
				$issuer = ( new \Certloom\Infrastructure\Repository\IssuerRepository() )->find( $issuer_id );
				if ( is_wp_error( $issuer ) ) { return $issuer; }
				if ( ! is_array( $issuer ) ) { return new \WP_Error( 'certloom_template_preview_issuer_missing', __( 'The selected Issuer is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }
			}

			$templates = new \Certloom\Infrastructure\Repository\TemplateRepository();
			$resolved = $templates->resolve_published_assignment_details( array(
				'issuer_id' => absint( $issuer['id'] ?? 0 ),
				'program_id' => absint( $program['id'] ?? 0 ),
				'batch_id' => absint( $batch['id'] ?? 0 ),
			) );
			if ( is_wp_error( $resolved ) ) { return $resolved; }

			// Match ManualIssuanceService: a direct Batch assignment wins first;
			// otherwise a Program locale variant can override the inherited chain.
			$variant = null;
			if ( is_array( $program ) && ( ! is_array( $resolved ) || 'batch' !== (string) ( $resolved['scope_type'] ?? '' ) ) ) {
				$locale = ( new \Certloom\Application\I18n\CredentialLocale() )->resolve( $program['profile']['credential_locale'] ?? \Certloom\Application\I18n\CredentialLocale::SITE_DEFAULT );
				if ( is_wp_error( $locale ) ) { return $locale; }
				$variant = ( new \Certloom\Application\I18n\ProgramTemplateVariantResolver() )->resolve( isset( $program['profile'] ) && is_array( $program['profile'] ) ? $program['profile'] : array(), $locale['effective'] );
				if ( is_wp_error( $variant ) ) { return $variant; }
			}
			if ( is_array( $variant ) && ! empty( $variant['template_key'] ) ) {
				$template = $templates->find_published_by_machine_key( (string) $variant['template_key'] );
				if ( is_wp_error( $template ) ) { return $template; }
				if ( ! is_array( $template ) ) { return new \WP_Error( 'certloom_template_preview_locale_missing', __( 'The Program language variant does not resolve to an available published design.', 'certloom' ), array( 'status' => 404 ) ); }
				$template_id = absint( $template['id'] ?? 0 );
				$source_scope = 'program_locale';
				$source_id = absint( $program['id'] ?? 0 );
				$source_label = sprintf( /* translators: %1$s: Program title, %2$s: locale. */ __( 'Inherited from Program “%1$s” language variant (%2$s)', 'certloom' ), (string) ( $program['title'] ?? '#' . $source_id ), (string) ( $variant['matched_locale'] ?? '' ) );
			} elseif ( is_array( $resolved ) && ! empty( $resolved['template_id'] ) ) {
				$template_id = absint( $resolved['template_id'] );
				$source_scope = sanitize_key( (string) ( $resolved['scope_type'] ?? 'global' ) );
				$source_id = absint( $resolved['scope_id'] ?? 0 );
				$source_label = $this->template_preview_source_label( $source_scope, $source_id, $program, $issuer, $batch );
			} else {
				return new \WP_Error( 'certloom_template_preview_inherited_missing', __( 'No published design is available from the current inheritance context. Choose an assigned design or configure a Global, Issuer, Program or Batch design.', 'certloom' ), array( 'status' => 404 ) );
			}
		}

		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_preview_missing', __( 'Choose a published design to preview.', 'certloom' ), array( 'status' => 400 ) ); }
		$template = isset( $template ) && is_array( $template ) ? $template : ( new \Certloom\Infrastructure\Repository\TemplateRepository() )->find_published( $template_id );
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
			'template_id'  => $template_id,
			'name'         => (string) ( $template['name'] ?? '' ),
			'description'  => (string) ( $template['description'] ?? '' ),
			'revision'     => absint( $plan['revision_number'] ?? 0 ),
			'html'         => (string) ( $preview['html'] ?? '' ),
			'source_scope' => $source_scope,
			'source_id'    => $source_id,
			'source_label' => $source_label,
		) );
	}

	/**
	 * Live compose preview built from the exact current Manual Issuance form.
	 * POST is intentional so Recipient/profile data never appears in preview URLs.
	 * The operation is mutation-free and final Review/Issue still revalidates it.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issuance_preview( \WP_REST_Request $request ) {
		$input = $request->get_body_params();
		$input = is_array( $input ) ? $input : array();
		$input = $this->normalize_issuance_preview_request( $input );
		$review = ( new \Certloom\Application\Issuance\ManualIssuanceService() )->draft_preview( $input );
		if ( is_wp_error( $review ) ) {
			$data = $review->get_error_data();
			if ( ! is_array( $data ) ) { $data = array(); }
			if ( empty( $data['status'] ) ) { $data['status'] = 400; }
			return new \WP_Error( $review->get_error_code(), $review->get_error_message(), $data );
		}

		$response = array(
			'presentation_mode' => (string) ( $review['request']['presentation_mode'] ?? 'details_only' ),
			'data_readiness'    => isset( $review['data_readiness'] ) && is_array( $review['data_readiness'] ) ? $review['data_readiness'] : array( 'ready' => true, 'fields' => array(), 'missing_count' => 0 ),
			'html'              => '',
			'name'              => '',
			'description'       => '',
			'source_label'      => '',
		);

		if ( 'design' === $response['presentation_mode'] ) {
			$preview = ( new \Certloom\Infrastructure\Rendering\ManualIssuancePreviewService() )->build( $review );
			if ( is_wp_error( $preview ) ) { return new \WP_Error( $preview->get_error_code(), $preview->get_error_message(), array( 'status' => 400 ) ); }
			try {
				$presented = ( new \Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview() )->present( $preview['render_plan'] );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'certloom_issuance_preview_failed', __( 'Certloom could not build the live credential preview.', 'certloom' ), array( 'status' => 400 ) );
			}
			$template = isset( $review['template'] ) && is_array( $review['template'] ) ? $review['template'] : array();
			$response['html'] = (string) ( $presented['html'] ?? '' );
			$response['name'] = (string) ( $template['name'] ?? '' );
			$response['description'] = (string) ( $template['description'] ?? '' );
			$response['source_label'] = $this->issuance_preview_source_label( $review );
		}

		return rest_ensure_response( $response );
	}

	/** @return array<string,mixed> */
	private function normalize_issuance_preview_request( array $input ) {
		$custom = isset( $input['custom_merge_fields'] ) && is_array( $input['custom_merge_fields'] ) ? $input['custom_merge_fields'] : array();
		$keys = isset( $input['custom_merge_key'] ) && is_array( $input['custom_merge_key'] ) ? array_values( $input['custom_merge_key'] ) : array();
		$values = isset( $input['custom_merge_value'] ) && is_array( $input['custom_merge_value'] ) ? array_values( $input['custom_merge_value'] ) : array();
		foreach ( array_slice( $keys, 0, 100 ) as $index => $raw_key ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key || array_key_exists( $key, $custom ) ) { continue; }
			$custom[ $key ] = isset( $values[ $index ] ) && ! is_array( $values[ $index ] ) ? (string) $values[ $index ] : '';
		}
		$input['custom_merge_fields'] = $custom;
		unset( $input['custom_merge_key'], $input['custom_merge_value'], $input['certloom_manual_issuance_step'], $input['_wpnonce'], $input['_wp_http_referer'] );
		return $input;
	}

	/** @return string */
	private function issuance_preview_source_label( array $review ) {
		$request = isset( $review['request'] ) && is_array( $review['request'] ) ? $review['request'] : array();
		if ( 'assigned' === (string) ( $request['design_source'] ?? '' ) && ! empty( $request['template_id'] ) ) {
			return __( 'Assigned for this credential only', 'certloom' );
		}
		$scope = sanitize_key( (string) ( $review['template_resolution'] ?? 'global' ) );
		$details = isset( $review['template_resolution_details'] ) && is_array( $review['template_resolution_details'] ) ? $review['template_resolution_details'] : array();
		$scope_id = absint( $details['scope_id'] ?? 0 );
		if ( 0 === strpos( $scope, 'program_locale' ) ) {
			$program = isset( $review['program'] ) && is_array( $review['program'] ) ? $review['program'] : array();
			$variant = isset( $review['template_locale_variant'] ) && is_array( $review['template_locale_variant'] ) ? $review['template_locale_variant'] : array();
			return sprintf( /* translators: %1$s: Program title, %2$s: locale. */ __( 'Inherited from Program “%1$s” language variant (%2$s)', 'certloom' ), (string) ( $program['title'] ?? '' ), (string) ( $variant['matched_locale'] ?? '' ) );
		}
		return $this->template_preview_source_label(
			$scope,
			$scope_id,
			isset( $review['program'] ) && is_array( $review['program'] ) ? $review['program'] : null,
			isset( $review['issuer'] ) && is_array( $review['issuer'] ) ? $review['issuer'] : null,
			isset( $review['batch'] ) && is_array( $review['batch'] ) ? $review['batch'] : null
		);
	}

	/** @return string */
	private function template_preview_source_label( $scope, $scope_id, $program, $issuer, $batch ) {
		$scope = sanitize_key( (string) $scope );
		if ( 'batch' === $scope && is_array( $batch ) ) { return sprintf( /* translators: %s: Batch name. */ __( 'Inherited from Batch “%s”', 'certloom' ), (string) ( $batch['name'] ?? $batch['code'] ?? '#' . absint( $scope_id ) ) ); }
		if ( 'program' === $scope && is_array( $program ) ) { return sprintf( /* translators: %s: Program title. */ __( 'Inherited from Program “%s”', 'certloom' ), (string) ( $program['title'] ?? '#' . absint( $scope_id ) ) ); }
		if ( 'issuer' === $scope && is_array( $issuer ) ) { return sprintf( /* translators: %s: Issuer name. */ __( 'Inherited from Issuer “%s”', 'certloom' ), (string) ( $issuer['name'] ?? '#' . absint( $scope_id ) ) ); }
		return __( 'Inherited from Global design', 'certloom' );
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
