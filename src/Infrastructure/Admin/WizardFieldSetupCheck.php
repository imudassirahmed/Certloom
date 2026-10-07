<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Setup\WizardFieldSetup;
use Certloom\Application\Templates\TemplateFieldCompatibility;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A configuration preflight, not a simulation of eligibility or issuance. No writers or jobs. */
final class WizardFieldSetupCheck {
	public function inspect( array $draft ) {
		$catalog = \Ecapla_CertVerify_Fields::get_field_map();
		$id = absint( $draft['setup_program_id'] ?? 0 );
		$program = $id ? ( new ProgramRepository() )->find( $id ) : null;
		if ( is_wp_error( $program ) ) { return $program; }
		if ( $id && ( ! is_array( $program ) || empty( $program['active'] ) ) ) { return $this->invalid(); }
		if ( ! empty( $program['issuer_id'] ) && ! empty( $draft['setup_issuer_id'] ) && (int) $program['issuer_id'] !== (int) $draft['setup_issuer_id'] ) { return $this->invalid(); }
		$contract = $program['field_contract'] ?? ScopedFieldContract::empty_program();
		$metadata = $program['metadata'] ?? array();
		if ( isset( $draft['setup_field_plan'] ) ) {
			if ( ! $id && '' === trim( (string) ( $draft['setup_new_program_title'] ?? '' ) ) ) { return $this->invalid(); }
			$plan = ( new WizardFieldSetup() )->build( $draft['setup_field_plan'], $catalog, \Ecapla_CertVerify_Fields::get_retired_fields(), $program );
			if ( is_wp_error( $plan ) ) { return $plan; }
			$catalog = $plan['catalog']; $contract = $plan['contract']; $metadata = $plan['metadata'];
		}
		$global = ( new ScopedFieldGlobalRepository() )->get( $catalog );
		if ( is_wp_error( $global ) ) { return $global; }
		$issuer_id = absint( $program['issuer_id'] ?? $draft['setup_issuer_id'] ?? 0 );
		$issuer = $issuer_id ? ( new IssuerRepository() )->find( $issuer_id ) : null;
		if ( is_wp_error( $issuer ) ) { return $issuer; }
		if ( $issuer_id && ! is_array( $issuer ) ) { return $this->invalid(); }
		$batch_id = absint( $draft['setup_batch_id'] ?? 0 );
		$batch = $batch_id ? ( new BatchRepository() )->find( $batch_id ) : null;
		if ( is_wp_error( $batch ) ) { return $batch; }
		if ( $batch_id && ( ! is_array( $batch ) || empty( $batch['active'] ) || $id !== (int) $batch['program_id'] ) ) { return $this->invalid(); }
		$resolved = ( new ScopedFieldContract() )->resolve_hierarchy( $global, $issuer['field_contract'] ?? null, $contract, $batch['field_contract'] ?? null, $catalog );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( 'design' === ( $draft['presentation_mode'] ?? '' ) ) {
			$template = ( new TemplateRepository() )->find_published( absint( $draft['design_template_id'] ?? 0 ) );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) ) { return new \WP_Error( 'certloom_setup_check_design_required', __( 'Choose a published design or switch to details only before checking artwork.', 'certloom' ) ); }
			$valid = ( new TemplateFieldCompatibility() )->validate( $template['published_revision']['layout'] ?? array(), $contract, $batch['field_contract'] ?? null, $catalog, $global, $issuer['field_contract'] ?? null );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		$defaults = ( new ProgramFieldDefaults() )->from_program( array( 'metadata' => $metadata ), $catalog );
		if ( is_wp_error( $defaults ) ) { return $defaults; }
		$warnings = array();
		foreach ( $resolved['fields'] as $row ) {
			$field = $catalog[ $row['key'] ]; $policy = ( new FieldCollectionPolicy() )->for_definition( $field );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( empty( $policy['_meta']['configured'] ) ) { continue; }
			$message = '';
			if ( 'recipient' === $policy['writer'] ) { $message = __( 'The customer supplies this in their account or enrollment. Any required approval must finish before issuance.', 'certloom' ); }
			elseif ( 'source' === $policy['writer'] ) { $message = __( 'A supported connection must supply verified results. Choosing a source here does not enable automatic issuance.', 'certloom' ); }
			elseif ( 'program' === $policy['lifetime'] && ! empty( $row['required'] ) && ! isset( $defaults['values'][ $row['key'] ] ) ) { $message = __( 'Set the shared Program value before issuing a certificate that requires it.', 'certloom' ); }
			if ( '' !== $message ) { $warnings[] = array( 'label' => $field['label'], 'message' => $message ); }
		}
		return array( 'configuration_only' => true, 'checks' => array( __( 'Field definitions and sources are valid.', 'certloom' ), __( 'Program requirements respect the organization and site rules.', 'certloom' ), 'design' === ( $draft['presentation_mode'] ?? '' ) ? __( 'The selected artwork is compatible with the field and privacy rules.', 'certloom' ) : __( 'Artwork is optional for this presentation.', 'certloom' ) ), 'next_steps' => $warnings, 'message' => __( 'Field and design checks passed. Final issuance still checks the recipient, results, approvals and current settings.', 'certloom' ) );
	}
	private function invalid() { return new \WP_Error( 'certloom_setup_check_context_invalid', __( 'Choose an active Program and matching organization / Batch, or add a Program name in setup.', 'certloom' ) ); }
}
