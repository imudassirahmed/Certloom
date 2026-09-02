<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Email\EmailAutomationRuleRepository;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;

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

	/** @return void */
	public function save_template() {
		$this->require_capability();
		check_admin_referer( 'certloom_automation_template_save' );
		$id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		$result = ( new EmailTemplateCatalogRepository() )->save(
			array(
				'id'        => $id,
				'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'active'    => ! empty( $_POST['active'] ),
				'subject'   => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'html_body' => isset( $_POST['html_body'] ) ? wp_kses_post( wp_unslash( $_POST['html_body'] ) ) : '',
				'text_body' => isset( $_POST['text_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text_body'] ) ) : '',
			)
		);
		$this->redirect( $result, __( 'Email template saved.', 'certloom' ), 'templates' );
	}

	/** @return void */
	public function delete_template() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		check_admin_referer( 'certloom_automation_template_delete_' . $id );
		foreach ( ( new EmailAutomationRuleRepository() )->all() as $rule ) {
			if ( $id === (string) ( $rule['template_id'] ?? '' ) ) {
				$this->redirect( new \WP_Error( 'certloom_email_template_in_use', __( 'This email template is used by an automation. Change or remove that automation first.', 'certloom' ) ), '', 'templates' );
			}
		}
		$result = ( new EmailTemplateCatalogRepository() )->delete( $id );
		$this->redirect( $result, __( 'Email template deleted.', 'certloom' ), 'templates' );
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
				'template_id' => isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : EmailTemplateCatalogRepository::DEFAULT_ID,
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
