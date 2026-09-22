<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Email\EmailAutomationRuleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Mutations for the task-oriented Automations workspace. */
final class AutomationAdmin {
	const PAGE = 'certloom-automations';

	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_automation_template_save', array( $this, 'save_template' ) );
		add_action( 'admin_post_certloom_automation_template_delete', array( $this, 'delete_template' ) );
		add_action( 'admin_post_certloom_automation_rule_save', array( $this, 'save_rule' ) );
		add_action( 'admin_post_certloom_automation_rule_delete', array( $this, 'delete_rule' ) );
	}

	/** Legacy endpoint retained so cached forms fail safely without creating a second email-content authority. */
	public function save_template() {
		$this->require_capability();
		check_admin_referer( 'certloom_automation_template_save' );
		wp_safe_redirect( add_query_arg( array( 'page' => EmailDesignAdmin::PAGE, 'certloom_notice' => __( 'Email content is now managed in Design → Email designs. No changes were made from the retired Automation editor.', 'certloom' ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Legacy endpoint retained so cached forms cannot delete Email Designs from Automations. */
	public function delete_template() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		check_admin_referer( 'certloom_automation_template_delete_' . $id );
		wp_safe_redirect( add_query_arg( array( 'page' => EmailDesignAdmin::PAGE, 'certloom_notice' => __( 'Email designs are managed in Design. No design was deleted from the retired Automation endpoint.', 'certloom' ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	public function save_rule() {
		$this->require_capability();
		check_admin_referer( 'certloom_automation_rule_save' );
		$issuer_id  = isset( $_POST['issuer_id'] ) ? absint( $_POST['issuer_id'] ) : 0;
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$batch_id   = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$target = ( new AutomationRuleTargetGuard() )->validate( $issuer_id, $program_id, $batch_id );
		if ( is_wp_error( $target ) ) {
			$this->redirect( $target, '', 'rules' );
		}
		$result = ( new EmailAutomationRuleRepository() )->save(
			array(
				'id'          => isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '',
				'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'active'      => ! empty( $_POST['active'] ),
				'issuer_id'   => $target['issuer_id'],
				'program_id'  => $target['program_id'],
				'batch_id'    => $target['batch_id'],
				'source_type' => isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'any',
			)
		);
		$this->redirect( $result, __( 'Automation saved.', 'certloom' ), 'rules' );
	}

	/** @return void */
	public function delete_rule() {
		$this->require_capability();
		$id = isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '';
		check_admin_referer( 'certloom_automation_rule_delete_' . $id );
		$result = ( new EmailAutomationRuleRepository() )->delete( $id );
		$this->redirect( $result, __( 'Automation removed.', 'certloom' ), 'rules' );
	}

	/** @return void */
	private function require_capability() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Certloom automations.', 'certloom' ) );
		}
	}

	/** @param mixed $result @return void */
	private function redirect( $result, $success, $section ) {
		$args = array(
			'page'             => self::PAGE,
			'certloom_section' => sanitize_key( (string) $section ),
		);
		if ( is_wp_error( $result ) ) {
			$args['certloom_error'] = $result->get_error_message();
		} elseif ( '' !== (string) $success ) {
			$args['certloom_notice'] = (string) $success;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
