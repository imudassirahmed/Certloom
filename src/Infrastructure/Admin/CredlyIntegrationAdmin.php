<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\CredlyConnectionRepository;
use Certloom\Infrastructure\Integration\CredlyMappingStore;
use Certloom\Infrastructure\Repository\DestinationDeliveryRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin boundary for optional post-issuance publishing to Credly. */
final class CredlyIntegrationAdmin {
	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_credly_connection_save', array( $this, 'save_connection' ) );
		add_action( 'admin_post_certloom_credly_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_certloom_credly_mapping_save', array( $this, 'save_mapping' ) );
		add_action( 'admin_post_certloom_credly_mapping_delete', array( $this, 'delete_mapping' ) );
	}

	/** @return array<string,mixed> */
	public function screen_data() {
		$programs = array();
		$error = null;
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$result = ( new ScopedContextChoices() )->programs();
			if ( is_wp_error( $result ) ) { $error = $result; }
			else { $programs = (array) $result; }
		}
		$recent = array();
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$r = ( new DestinationDeliveryRepository() )->recent( 'credly', 20 );
			if ( is_wp_error( $r ) ) { if ( ! $error ) { $error = $r; } }
			else { $recent = $r; }
		}
		return array(
			'connection' => ( new CredlyConnectionRepository() )->status(),
			'mappings' => ( new CredlyMappingStore() )->all(),
			'programs' => $programs,
			'recent' => $recent,
			'error' => $error,
		);
	}

	/** @return void */
	public function save_connection() {
		$this->require_access();
		check_admin_referer( 'certloom_credly_connection_save' );
		$result = ( new CredlyConnectionRepository() )->save(
			isset( $_POST['organization_id'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_id'] ) ) : '',
			isset( $_POST['authorization_token'] ) ? sanitize_text_field( wp_unslash( $_POST['authorization_token'] ) ) : '',
			isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : 'production'
		);
		$this->redirect( $result, 'credly_connected' );
	}

	/** @return void */
	public function disconnect() {
		$this->require_access();
		check_admin_referer( 'certloom_credly_disconnect' );
		$this->redirect( ( new CredlyConnectionRepository() )->disconnect(), 'credly_disconnected' );
	}

	/** @return void */
	public function save_mapping() {
		$this->require_access();
		check_admin_referer( 'certloom_credly_mapping_save' );
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) || ! is_array( $program ) || empty( $program['active'] ) ) { $this->redirect( is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_credly_program_invalid', __( 'Choose an active Certloom Program.', 'certloom' ) ) ); }
		$result = ( new CredlyMappingStore() )->save( array(
			'program_id' => $program_id,
			'badge_template_id' => isset( $_POST['badge_template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['badge_template_id'] ) ) : '',
			'suppress_badge_notification_email' => ! empty( $_POST['suppress_badge_notification_email'] ),
			'active' => ! empty( $_POST['active'] ),
		) );
		$this->redirect( $result, 'credly_mapping_saved' );
	}

	/** @return void */
	public function delete_mapping() {
		$this->require_access();
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		check_admin_referer( 'certloom_credly_mapping_delete_' . $program_id );
		$this->redirect( ( new CredlyMappingStore() )->delete( $program_id ), 'credly_mapping_deleted' );
	}

	private function require_access() { if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) ) { wp_die( esc_html__( 'You do not have permission to manage Certloom integrations.', 'certloom' ), '', array( 'response' => 403 ) ); } }
	private function redirect( $result, $notice = '' ) {
		$url = admin_url( 'admin.php?page=certloom-integrations#certloom-credly-setup' );
		if ( is_wp_error( $result ) ) { $url = add_query_arg( 'certloom_error', $result->get_error_message(), $url ); }
		elseif ( '' !== $notice ) { $url = add_query_arg( 'certloom_notice', sanitize_key( $notice ), $url ); }
		wp_safe_redirect( $url ); exit;
	}
}
