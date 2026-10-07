<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\TutorLmsAdapter;
use Certloom\Infrastructure\Integration\TutorLmsBridge;
use Certloom\Infrastructure\Integration\TutorLmsMappingStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin configuration boundary for Certloom-owned Tutor LMS mappings. */
final class TutorLmsIntegrationAdmin {
	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_tutor_lms_mapping_save', array( $this, 'save_mapping' ) );
		add_action( 'admin_post_certloom_tutor_lms_mapping_delete', array( $this, 'delete_mapping' ) );
		add_action( 'admin_post_certloom_tutor_pending_create', array( $this, 'create_pending' ) );
		add_action( 'admin_post_certloom_tutor_pending_change', array( $this, 'change_pending' ) );
	}

	/** @return array<string,mixed> */
	public function screen_data() {
		$status = TutorLmsBridge::status();
		$data = array(
			'status' => $status,
			'mappings' => ( new TutorLmsMappingStore() )->all(),
			'courses' => array(),
			'programs' => array(),
			'configuration_ready' => false,
			'error' => null,
			'pending_mappings' => array(),
			'pending_error' => null,
		);
		$pending_repo = new \Certloom\Infrastructure\Integration\TutorPendingMappingRepository();
		$pending = $pending_repo->read();
		if ( is_wp_error( $pending ) ) { $data['pending_error'] = $pending; }
		else { foreach ( $pending as $row ) { $row['fingerprint'] = $pending_repo->revision( $row ); $data['pending_mappings'][] = $row; } }
		if ( empty( $status['available'] ) ) { return $data; }
		$course_ids = array_values( array_unique( array_filter( array_map( static function ( $mapping ) { return absint( is_array( $mapping ) ? ( $mapping['course_id'] ?? 0 ) : 0 ); }, (array) $data['mappings'] ) ) ) );
		$course_ids = array_values( array_unique( array_merge( $course_ids, array_column( $data['pending_mappings'], 'course_id' ) ) ) );
		$data['courses'] = $this->courses( $course_ids );
		if ( is_wp_error( $data['courses'] ) ) { $data['error'] = $data['courses']; $data['courses'] = array(); return $data; }
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { return $data; }
		$programs = ( new ScopedContextChoices() )->programs();
		if ( is_wp_error( $programs ) ) { $data['error'] = $programs; return $data; }
		$data['programs'] = (array) $programs;
		$labels = ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->labels_by_ids( array_column( $data['pending_mappings'], 'program_id' ) );
		$data['pending_program_labels'] = is_wp_error( $labels ) ? array() : $labels;
		$data['configuration_ready'] = true;
		return $data;
	}

	/** @return void */
	public function save_mapping() {
		$this->require_access();
		check_admin_referer( 'certloom_tutor_lms_mapping_save' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$this->redirect( new \WP_Error( 'certloom_tutor_mapping_preview_closed', __( 'Tutor LMS mapping setup is unavailable until the Certloom Program/Batch workflow is enabled.', 'certloom' ) ) );
		}
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$course = $course_id ? get_post( $course_id ) : null;
		if ( ! $course || TutorLmsAdapter::course_post_type() !== (string) $course->post_type ) { $this->redirect( new \WP_Error( 'certloom_tutor_course_invalid', __( 'Choose a Tutor LMS course that still exists.', 'certloom' ) ) ); }
		$result = ( new \Certloom\Infrastructure\Integration\TutorCompletionMappingCommand() )->save( array(
			'course_id' => $course_id,
			'program_id' => $program_id,
			'batch_id' => $batch_id,
			'template_id' => 0,
			'certificate_threshold' => 100,
			'expiry_mode' => isset( $_POST['expiry_mode'] ) ? sanitize_key( wp_unslash( $_POST['expiry_mode'] ) ) : 'program',
			'expires_at' => isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '',
			'active' => ! empty( $_POST['active'] ),
		), get_current_user_id() );
		$this->redirect( $result, 'mapping_saved' );
	}

	public function create_pending() {
		$this->require_access(); check_admin_referer( 'certloom_tutor_pending_create' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { $this->redirect( new \WP_Error( 'certloom_tutor_mapping_preview_closed', __( 'Enable the Program workflow before connecting Tutor customer details.', 'certloom' ) ) ); }
		$result = ( new \Certloom\Infrastructure\Integration\TutorPendingMappingRepository() )->create( $this->posted_id( 'course_id' ), $this->posted_id( 'program_id' ), $this->posted_id( 'batch_id', '0' ) );
		$this->redirect( $result, 'tutor_pending_saved' );
	}
	public function change_pending() {
		$this->require_access();
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { $this->redirect( new \WP_Error( 'certloom_tutor_mapping_preview_closed', __( 'Enable the Program workflow before changing Tutor customer details.', 'certloom' ) ) ); }
		$key = isset( $_POST['source_key'] ) && is_string( $_POST['source_key'] ) ? wp_unslash( $_POST['source_key'] ) : '';
		check_admin_referer( 'certloom_tutor_pending_change_' . $key );
		$fingerprint = isset( $_POST['fingerprint'] ) && is_string( $_POST['fingerprint'] ) ? wp_unslash( $_POST['fingerprint'] ) : '';
		$command = isset( $_POST['command'] ) && is_string( $_POST['command'] ) ? wp_unslash( $_POST['command'] ) : '';
		$this->redirect( ( new \Certloom\Infrastructure\Integration\TutorPendingMappingRepository() )->change( $key, $fingerprint, $command ), 'tutor_pending_saved' );
	}
	private function posted_id( $key, $default = null ) {
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
		return is_string( $value ) && preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $value ) && strlen( $value ) <= strlen( (string) PHP_INT_MAX ) && ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ? (int) $value : null;
	}

	/** @return void */
	public function delete_mapping() {
		$this->require_access();
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		check_admin_referer( 'certloom_tutor_lms_mapping_delete_' . $course_id );
		$this->redirect( ( new \Certloom\Infrastructure\Integration\TutorCompletionMappingCommand() )->delete( $course_id, get_current_user_id() ), 'mapping_deleted' );
	}

	/** @return array<int,array{id:int,title:string}>|\WP_Error */
	private function courses( array $preserve_ids = array() ) {
		if ( ! TutorLmsAdapter::is_available() ) { return array(); }
		$post_type = TutorLmsAdapter::course_post_type();
		$posts = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => ScopedContextChoices::INITIAL_LIMIT, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false ) );
		if ( ! is_array( $posts ) ) { return new \WP_Error( 'certloom_tutor_courses_unavailable', __( 'Certloom could not load Tutor LMS courses.', 'certloom' ) ); }
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
		wp_safe_redirect( $url . ( 'tutor_pending_saved' === $notice ? '#certloom-tutor-details' : '' ) ); exit;
	}
}
