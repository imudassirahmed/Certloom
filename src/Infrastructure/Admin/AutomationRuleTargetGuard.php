<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Validates optional Issuer / Program / Batch filters used by post-issuance automations. */
final class AutomationRuleTargetGuard {
	/** @return array{issuer_id:int,program_id:int,batch_id:int}|\WP_Error */
	public function validate( $issuer_id, $program_id, $batch_id ) {
		$issuer_id  = absint( $issuer_id );
		$program_id = absint( $program_id );
		$batch_id   = absint( $batch_id );

		$issuer = null;
		if ( $issuer_id ) {
			$issuer = ( new IssuerRepository() )->find( $issuer_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
			if ( ! is_array( $issuer ) ) {
				return new \WP_Error( 'certloom_automation_issuer_missing', __( 'Choose an Issuer that still exists.', 'certloom' ) );
			}
		}

		$program = null;
		if ( $program_id ) {
			$program = ( new ProgramRepository() )->find( $program_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) || empty( $program['active'] ) ) {
				return new \WP_Error( 'certloom_automation_program_missing', __( 'Choose an active Program that still exists.', 'certloom' ) );
			}
			if ( $issuer_id && absint( $program['issuer_id'] ?? 0 ) !== $issuer_id ) {
				return new \WP_Error( 'certloom_automation_issuer_program_mismatch', __( 'The selected Program does not belong to the selected Issuer.', 'certloom' ) );
			}
		}

		if ( $batch_id ) {
			if ( ! $program_id ) {
				return new \WP_Error( 'certloom_automation_batch_program_required', __( 'Choose a Program before narrowing an automation to a Batch.', 'certloom' ) );
			}
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) ) {
				return new \WP_Error( 'certloom_automation_batch_missing', __( 'Choose an active Batch that still exists.', 'certloom' ) );
			}
			if ( absint( $batch['program_id'] ?? 0 ) !== $program_id ) {
				return new \WP_Error( 'certloom_automation_batch_program_mismatch', __( 'The selected Batch does not belong to the selected Program.', 'certloom' ) );
			}
		}

		return array(
			'issuer_id'  => $issuer_id,
			'program_id' => $program_id,
			'batch_id'   => $batch_id,
		);
	}
}
