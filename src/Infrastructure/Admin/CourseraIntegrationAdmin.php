<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\CourseraMappingStore;
use Certloom\Infrastructure\Integration\CourseraXapiBridge;
use Certloom\Infrastructure\Integration\CourseraXapiCredentials;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin boundary for the site-local Coursera Standard xAPI completion source. */
final class CourseraIntegrationAdmin {
	const SECRET_TRANSIENT_PREFIX = 'certloom_coursera_setup_secret_';

	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_coursera_rotate', array( $this, 'rotate' ) );
		add_action( 'admin_post_certloom_coursera_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_certloom_coursera_mapping_save', array( $this, 'save_mapping' ) );
		add_action( 'admin_post_certloom_coursera_mapping_delete', array( $this, 'delete_mapping' ) );
	}

	/** @return array<string,mixed> */
	public function screen_data() {
		$status = CourseraXapiCredentials::status();
		$data = array(
			'credentials'         => $status,
			'status'              => CourseraXapiBridge::status(),
			'mappings'            => ( new CourseraMappingStore() )->all(),
			'programs'            => array(),
			'configuration_ready' => false,
			'one_time_secret'     => $this->pull_one_time_secret(),
			'error'               => null,
		);
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { return $data; }
		$programs  = ( new ScopedContextChoices() )->programs();
		if ( is_wp_error( $programs ) ) { $data['error'] = $programs; return $data; }
		$data['programs']            = (array) $programs;
		$data['configuration_ready'] = true;
		return $data;
	}

	/** @return void */
	public function rotate() {
		$this->require_access();
		check_admin_referer( 'certloom_coursera_rotate' );
		$result = CourseraXapiCredentials::rotate();
		if ( is_wp_error( $result ) ) { $this->redirect( $result ); }
		$key = self::SECRET_TRANSIENT_PREFIX . get_current_user_id();
		$stored = set_transient( $key, $result, 10 * MINUTE_IN_SECONDS );
		if ( ! $stored ) {
			CourseraXapiCredentials::disconnect();
			$this->redirect( new \WP_Error( 'certloom_coursera_secret_display_failed', __( 'Certloom generated the Coursera connection, but could not keep the one-time secret long enough to show it safely. Nothing was connected; please try again.', 'certloom' ) ) );
		}
		$this->redirect( true, 'coursera_connection_rotated' );
	}

	/** @return void */
	public function disconnect() {
		$this->require_access();
		check_admin_referer( 'certloom_coursera_disconnect' );
		delete_transient( self::SECRET_TRANSIENT_PREFIX . get_current_user_id() );
		$this->redirect( CourseraXapiCredentials::disconnect(), 'coursera_disconnected' );
	}

	/** @return void */
	public function save_mapping() {
		$this->require_access();
		check_admin_referer( 'certloom_coursera_mapping_save' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$this->redirect( new \WP_Error( 'certloom_coursera_mapping_preview_closed', __( 'Coursera mapping setup is unavailable until the Certloom Program/Batch workflow is available.', 'certloom' ) ) );
		}
		$course_id   = isset( $_POST['course_id'] ) ? sanitize_text_field( wp_unslash( $_POST['course_id'] ) ) : '';
		$program_id  = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$batch_id    = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		if ( '' === $course_id || strlen( $course_id ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9._:@\/-]+$/', $course_id ) ) {
			$this->redirect( new \WP_Error( 'certloom_coursera_course_invalid', __( 'Enter the Coursera course ID exactly as Coursera sends it in xAPI.', 'certloom' ) ) );
		}
		$target = ( new IntegrationMappingTargetGuard() )->validate( $program_id, $batch_id );
		if ( is_wp_error( $target ) ) { $this->redirect( $target ); }
		$result = ( new CourseraMappingStore() )->save( array(
			'course_id'   => $course_id,
			'program_id'  => $program_id,
			'batch_id'    => $batch_id,
			'template_id' => 0,
			'expiry_mode' => 'program',
			'active'      => ! empty( $_POST['active'] ),
		) );
		$this->redirect( $result, 'coursera_mapping_saved' );
	}

	/** @return void */
	public function delete_mapping() {
		$this->require_access();
		$course_id = isset( $_POST['course_id'] ) ? sanitize_text_field( wp_unslash( $_POST['course_id'] ) ) : '';
		check_admin_referer( 'certloom_coursera_mapping_delete_' . $course_id );
		$this->redirect( ( new CourseraMappingStore() )->delete( $course_id ), 'coursera_mapping_deleted' );
	}

	/** @return array<string,string> */
	private function pull_one_time_secret() {
		if ( ! function_exists( 'get_current_user_id' ) ) { return array(); }
		$key = self::SECRET_TRANSIENT_PREFIX . get_current_user_id();
		$value = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $value ) || empty( $value['client_id'] ) || empty( $value['client_secret'] ) ) { return array(); }
		return array(
			'client_id'     => (string) $value['client_id'],
			'client_secret' => (string) $value['client_secret'],
		);
	}

	/** @return void */
	private function require_access() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Certloom integrations.', 'certloom' ) );
		}
	}

	/** @return void */
	private function redirect( $result, $notice = '' ) {
		$url = admin_url( 'admin.php?page=certloom-integrations#certloom-coursera-setup' );
		if ( is_wp_error( $result ) ) { $url = add_query_arg( 'certloom_error', $result->get_error_message(), $url ); }
		elseif ( '' !== $notice ) { $url = add_query_arg( 'certloom_notice', sanitize_key( $notice ), $url ); }
		wp_safe_redirect( $url );
		exit;
	}
}
