<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only, Program-scoped data for the one-page batch editor. */
final class ProgramWorkspaceRest {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'private_headers' ), 10, 3 );
	}
	public function routes() {
		register_rest_route( 'certloom/v1', '/admin/programs/(?P<program_id>\d+)/batches', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'read' ),
			'permission_callback' => array( $this, 'permission' ),
			'args' => array(
				'batch_id' => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
				'page' => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
				'search' => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'members' => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
			),
		) );
	}
	public function permission() {
		return is_user_logged_in() && current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) && current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
	}
	public function private_headers( $response, $server, $request ) {
		if ( preg_match( '#^/certloom/v1/admin/programs/\d+/batches$#D', $request->get_route() ) && $response instanceof \WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Cookie', false );
			$response->header( 'X-Robots-Tag', 'noindex' );
		}
		return $response;
	}
	public function read( \WP_REST_Request $request ) {
		$id = absint( $request['program_id'] );
		$program = ( new ProgramRepository() )->find( $id );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_program_missing', __( 'The Program no longer exists.', 'certloom' ), array( 'status' => 404 ) ); }
		$repo = new BatchRepository();
		$batch_id = absint( $request['batch_id'] );
		if ( $batch_id ) {
			$batch = $repo->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || (int) $batch['program_id'] !== $id ) { return new \WP_Error( 'certloom_batch_program_context_mismatch', __( 'This batch does not belong to the selected Program.', 'certloom' ), array( 'status' => 404 ) ); }
			if ( $request['members'] ) {
				ob_start();
				( new DomainStructureAdmin() )->render_workspace_members( $program, $batch );
				return rest_ensure_response( array( 'html' => ob_get_clean() ) );
			}
			$assignment = ( new TemplateRepository() )->get_assignment( 'batch', $batch_id );
			if ( is_wp_error( $assignment ) ) { return $assignment; }
			// Technical metadata remains server-owned; date/design edits never round-trip it.
			$patch = array_intersect_key( $batch, array_flip( array( 'name', 'code', 'start_date', 'end_date', 'active', 'instructor_signer_id' ) ) );
			$patch['batch_template_id'] = is_array( $assignment ) && ! empty( $assignment['active'] ) ? absint( $assignment['template_id'] ) : 0;
			$patch['batch_field_requirement'] = (array) ( $batch['field_contract']['fields'] ?? array() );
			return rest_ensure_response( array( 'id' => $batch_id, 'revision' => ProgramBatchChanges::revision( $batch, $assignment ), 'patch' => $patch ) );
		}
		$page = max( 1, absint( $request['page'] ) );
		$result = $repo->list_page( (string) $request['search'], $page, 25, 'all', $id );
		if ( is_wp_error( $result ) ) { return $result; }
		$choices = $repo->configuration_choices( $id, array_column( $result['items'], 'id' ) );
		if ( is_wp_error( $choices ) ) { return $choices; }
		$email_overrides = array();
		foreach ( ( new \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository() )->all() as $rule ) {
			if ( 'batch' === $rule['scope_type'] && \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED === $rule['purpose'] ) { $email_overrides[ absint( $rule['scope_id'] ) ] = true; }
		}
		$items = array();
		foreach ( $result['items'] as $batch ) {
			$item = array_intersect_key( $batch, array_flip( array( 'id', 'name', 'code', 'active', 'start_date', 'end_date', 'recipient_count', 'credential_count', 'instructor_signer_id' ) ) );
			$choice = $choices[ $batch['id'] ] ?? array();
			$item['batch_template_id'] = absint( $choice['batch_template_id'] ?? 0 );
			$item['batch_field_requirement'] = (array) ( $batch['field_contract']['fields'] ?? array() );
			$item['settings'] = array( 'certificate' => $item['batch_template_id'] > 0, 'email' => ! empty( $email_overrides[ $batch['id'] ] ), 'signing' => ! empty( $choice['signing'] ) );
			$items[] = $item;
		}
		return rest_ensure_response( array( 'items' => $items, 'total' => (int) $result['total'], 'page' => $page, 'per_page' => 25 ) );
	}
}
