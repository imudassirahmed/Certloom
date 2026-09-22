<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Email\EmailAutomationRuleRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Prevents Issuer/Program/Batch relationship edits from making saved automation scopes impossible. */
final class AutomationConfigurationDependencyGuard {
	/** @return true|\WP_Error */
	public function validate_program_issuer_change( $program_id, $new_issuer_id ) {
		$program_id = absint( $program_id );
		$new_issuer_id = absint( $new_issuer_id );
		if ( ! $program_id ) { return true; }
		foreach ( ( new EmailAutomationRuleRepository() )->all() as $rule ) {
			if ( absint( $rule['program_id'] ?? 0 ) !== $program_id || ! absint( $rule['issuer_id'] ?? 0 ) ) { continue; }
			if ( absint( $rule['issuer_id'] ) !== $new_issuer_id ) {
				return new \WP_Error(
					'certloom_automation_program_issuer_move_blocked',
					sprintf(
						/* translators: %s: automation name. */
						__( 'Automation “%s” narrows this Program to its current Issuer. Update or remove that automation before changing the Program’s Issuer.', 'certloom' ),
						(string) ( $rule['name'] ?? __( 'Unnamed automation', 'certloom' ) )
					)
				);
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_batch_program_change( $batch_id, $new_program_id ) {
		$batch_id = absint( $batch_id );
		$new_program_id = absint( $new_program_id );
		if ( ! $batch_id || ! $new_program_id ) { return true; }
		foreach ( ( new EmailAutomationRuleRepository() )->all() as $rule ) {
			if ( absint( $rule['batch_id'] ?? 0 ) !== $batch_id || ! absint( $rule['program_id'] ?? 0 ) ) { continue; }
			if ( absint( $rule['program_id'] ) !== $new_program_id ) {
				return new \WP_Error(
					'certloom_automation_batch_program_move_blocked',
					sprintf(
						/* translators: %s: automation name. */
						__( 'Automation “%s” narrows this Batch to its current Program. Update or remove that automation before moving the Batch.', 'certloom' ),
						(string) ( $rule['name'] ?? __( 'Unnamed automation', 'certloom' ) )
					)
				);
			}
		}
		return true;
	}
}
