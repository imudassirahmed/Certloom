<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\TutorLmsAdapter;
use Certloom\Infrastructure\Integration\TutorLmsBridge;
use Certloom\Infrastructure\Integration\TutorLmsMappingStore;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin configuration boundary for Certloom-owned Tutor LMS mappings. */
final class TutorLmsIntegrationAdmin {
	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_tutor_lms_mapping_save', array( $this, 'save_mapping' ) );
		add_action( 'admin_post_certloom_tutor_lms_mapping_delete', array( $this, 'delete_mapping' ) );
	}

	/** @return array<string,mixed> */
	public function screen_data() {
		$status = TutorLmsBridge::status();
		$data = array(
			'status' => $status,
			'mappings' => ( new TutorLmsMappingStore() )->all(),
			'courses' => array(),
			'programs' => array(),
			'templates' => array(),
			'configuration_ready' => false,
			'error' => null,
		);
		if ( empty( $status['available'] ) ) { return $data; }
		$data['courses'] = $this->courses();
		if ( is_wp_error( $data['courses'] ) ) { $data['error'] = $data['courses']; $data['courses'] = array(); return $data; }
		if ( ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) ) { return $data; }
		$programs = ( new ProgramRepository() )->list_page( '', 1, 100, 'active' );
		$templates = ( new TemplateRepository() )->list_published_candidates( 100 );
		if ( is_wp_error( $programs ) ) { $data['error'] = $programs; return $data; }
		if ( is_wp_error( $templates ) ) { $data['error'] = $templates; return $data; }
		$data['programs'] = (array) ( $programs['items'] ?? array() );
		$data['templates'] = $templates;
		$data['configuration_ready'] = true;
		return $data;
	}

	/** @return void */
	public function save_mapping() {
		$this->require_access();
		check_admin_referer( 'certloom_tutor_lms_mapping_save' );
		if ( ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) ) {
			$this->redirect( new \WP_Error( 'certloom_tutor_mapping_preview_closed', __( 'Tutor LMS mapping setup is unavailable until the Certloom Program/Template preview domain is enabled.', 'certloom' ) ) );
		}
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$course = $course_id ? get_post( $course_id ) : null;
		if ( ! $course || TutorLmsAdapter::course_post_type() !== (string) $course->post_type ) { $this->redirect( new \WP_Error( 'certloom_tutor_course_invalid', __( 'Choose a Tutor LMS course that still exists.', 'certloom' ) ) ); }
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) || ! is_array( $program ) || empty( $program['active'] ) ) { $this->redirect( is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_tutor_program_invalid', __( 'Choose an active Certloom Program.', 'certloom' ) ) ); }
		$template = ( new TemplateRepository() )->find_published( $template_id );
		if ( is_wp_error( $template ) || ! is_array( $template ) ) { $this->redirect( is_wp_error( $template ) ? $template : new \WP_Error( 'certloom_tutor_template_invalid', __( 'Choose a published Certloom Template.', 'certloom' ) ) ); }
		$result = ( new TutorLmsMappingStore() )->save( array(
			'course_id' => $course_id,
			'program_id' => $program_id,
			'template_id' => $template_id,
			'certificate_threshold' => 100,
			'expiry_mode' => isset( $_POST['expiry_mode'] ) ? sanitize_key( wp_unslash( $_POST['expiry_mode'] ) ) : 'program',
			'expires_at' => isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '',
			'active' => ! empty( $_POST['active'] ),
		) );
		$this->redirect( $result, 'mapping_saved' );
	}

	/** @return void */
	public function delete_mapping() {
		$this->require_access();
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		check_admin_referer( 'certloom_tutor_lms_mapping_delete_' . $course_id );
		$this->redirect( ( new TutorLmsMappingStore() )->delete( $course_id ), 'mapping_deleted' );
	}

	/** @return array<int,array{id:int,title:string}>|\WP_Error */
	private function courses() {
		if ( ! TutorLmsAdapter::is_available() ) { return array(); }
		$posts = get_posts( array( 'post_type' => TutorLmsAdapter::course_post_type(), 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => 250, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false ) );
		if ( ! is_array( $posts ) ) { return new \WP_Error( 'certloom_tutor_courses_unavailable', __( 'Certloom could not load Tutor LMS courses.', 'certloom' ) ); }
		$out = array();
		foreach ( $posts as $post ) { if ( is_object( $post ) && absint( $post->ID ?? 0 ) ) { $out[] = array( 'id' => absint( $post->ID ), 'title' => (string) get_the_title( $post ) ); } }
		return $out;
	}

	private function require_access() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) ) { wp_die( esc_html__( 'You do not have permission to manage Certloom integrations.', 'certloom' ) ); }
	}

	private function redirect( $result, $notice = '' ) {
		$url = admin_url( 'admin.php?page=certloom-integrations' );
		if ( is_wp_error( $result ) ) { $url = add_query_arg( 'certloom_error', $result->get_error_message(), $url ); }
		elseif ( '' !== $notice ) { $url = add_query_arg( 'certloom_notice', sanitize_key( $notice ), $url ); }
		wp_safe_redirect( $url ); exit;
	}
}
