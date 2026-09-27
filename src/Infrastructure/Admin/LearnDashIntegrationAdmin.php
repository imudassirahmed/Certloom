<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\LearnDashAdapter;
use Certloom\Infrastructure\Integration\LearnDashBridge;
use Certloom\Infrastructure\Integration\LearnDashMappingStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin configuration boundary for Certloom-owned LearnDash mappings. */
final class LearnDashIntegrationAdmin {
	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_learndash_mapping_save', array( $this, 'save_mapping' ) );
		add_action( 'admin_post_certloom_learndash_mapping_delete', array( $this, 'delete_mapping' ) );
	}

	/** @return array<string,mixed> */
	public function screen_data() {
		$status = LearnDashBridge::status();
		$data = array(
			'status' => $status,
			'mappings' => ( new LearnDashMappingStore() )->all(),
			'courses' => array(),
			'programs' => array(),
			'configuration_ready' => false,
			'error' => null,
		);
		if ( empty( $status['available'] ) ) { return $data; }
		$course_ids = array_values( array_unique( array_filter( array_map( static function ( $mapping ) { return absint( is_array( $mapping ) ? ( $mapping['course_id'] ?? 0 ) : 0 ); }, (array) $data['mappings'] ) ) ) );
		$data['courses'] = $this->courses( $course_ids );
		if ( is_wp_error( $data['courses'] ) ) { $data['error'] = $data['courses']; $data['courses'] = array(); return $data; }
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { return $data; }
		$programs = ( new ScopedContextChoices() )->programs();
		if ( is_wp_error( $programs ) ) { $data['error'] = $programs; return $data; }
		$data['programs'] = (array) $programs;
		$data['configuration_ready'] = true;
		return $data;
	}

	/** @return void */
	public function save_mapping() {
		$this->require_access();
		check_admin_referer( 'certloom_learndash_mapping_save' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$this->redirect( new \WP_Error( 'certloom_learndash_mapping_preview_closed', __( 'LearnDash mapping setup is unavailable until the Certloom Program/Batch workflow is enabled.', 'certloom' ) ) );
		}
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$course = $course_id ? get_post( $course_id ) : null;
		if ( ! $course || LearnDashAdapter::course_post_type() !== (string) $course->post_type ) { $this->redirect( new \WP_Error( 'certloom_learndash_course_invalid', __( 'Choose a LearnDash course that still exists.', 'certloom' ) ) ); }
		$target = ( new IntegrationMappingTargetGuard() )->validate( $program_id, $batch_id );
		if ( is_wp_error( $target ) ) { $this->redirect( $target ); }
		$result = ( new LearnDashMappingStore() )->save( array(
			'course_id' => $course_id,
			'program_id' => $program_id,
			'batch_id' => $batch_id,
			'template_id' => 0,
			'certificate_threshold' => 100,
			'expiry_mode' => isset( $_POST['expiry_mode'] ) ? sanitize_key( wp_unslash( $_POST['expiry_mode'] ) ) : 'program',
			'expires_at' => isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '',
			'active' => ! empty( $_POST['active'] ),
		) );
		$this->redirect( $result, 'learndash_mapping_saved' );
	}

	/** @return void */
	public function delete_mapping() {
		$this->require_access();
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		check_admin_referer( 'certloom_learndash_mapping_delete_' . $course_id );
		$this->redirect( ( new LearnDashMappingStore() )->delete( $course_id ), 'learndash_mapping_deleted' );
	}

	/** @return array<int,array{id:int,title:string}>|\WP_Error */
	private function courses( array $preserve_ids = array() ) {
		if ( ! LearnDashAdapter::is_available() ) { return array(); }
		$post_type = LearnDashAdapter::course_post_type();
		$posts = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => ScopedContextChoices::INITIAL_LIMIT, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false ) );
		if ( ! is_array( $posts ) ) { return new \WP_Error( 'certloom_learndash_courses_unavailable', __( 'Certloom could not load LearnDash courses.', 'certloom' ) ); }
		$by_id = array();
		foreach ( $posts as $post ) { if ( is_object( $post ) && absint( $post->ID ?? 0 ) ) { $by_id[ absint( $post->ID ) ] = array( 'id' => absint( $post->ID ), 'title' => (string) get_the_title( $post ) ); } }
		foreach ( $preserve_ids as $course_id ) {
			$course_id = absint( $course_id ); if ( ! $course_id || isset( $by_id[ $course_id ] ) ) { continue; }
			$post = get_post( $course_id ); if ( $post && $post_type === (string) $post->post_type ) { $by_id[ $course_id ] = array( 'id' => $course_id, 'title' => (string) get_the_title( $post ) ); }
		}
		return array_values( $by_id );
	}

	private function require_access() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) ) { wp_die( esc_html__( 'You do not have permission to manage Certloom integrations.', 'certloom' ), '', array( 'response' => 403 ) ); }
	}

	private function redirect( $result, $notice = '' ) {
		$url = admin_url( 'admin.php?page=certloom-integrations' );
		if ( is_wp_error( $result ) ) { $url = add_query_arg( 'certloom_error', $result->get_error_message(), $url ); }
		elseif ( '' !== $notice ) { $url = add_query_arg( 'certloom_notice', sanitize_key( $notice ), $url ); }
		wp_safe_redirect( $url ); exit;
	}
}
