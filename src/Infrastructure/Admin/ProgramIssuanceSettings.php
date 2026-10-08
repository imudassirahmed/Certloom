<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\I18n\CredentialLocale;
use Certloom\Application\I18n\ProgramTemplateVariantResolver;
use Certloom\Application\Program\ProgramLifecyclePolicy;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read current configuration for the short issuance form. Never creates domain records. */
final class ProgramIssuanceSettings {

	/** @return array<string,mixed>|\WP_Error */
	public function resolve( $program_id, $batch_id = 0 ) {
		$program = ( new ProgramRepository() )->find( absint( $program_id ) );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) || empty( $program['active'] ) ) { return new \WP_Error( 'certloom_issue_program_missing', __( 'Choose an active Program before issuing.', 'certloom' ) ); }
		$batch = null;
		if ( $batch_id ) {
			$batch = ( new BatchRepository() )->find( absint( $batch_id ) );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) || (int) $batch['program_id'] !== (int) $program['id'] ) { return new \WP_Error( 'certloom_issue_batch_program_mismatch', __( 'Choose an active Batch belonging to this Program.', 'certloom' ) ); }
		}
		$policy = ( new ProgramLifecyclePolicy() )->resolve( $program );
		if ( is_wp_error( $policy ) ) { return $policy; }
		$batch_choices = $batch ? array( $batch ) : ( new BatchRepository() )->search_active_for_program( $program['id'], '', 1 );
		if ( is_wp_error( $batch_choices ) ) { return $batch_choices; }
		$templates = new TemplateRepository();
		$assignment = $templates->resolve_published_assignment_details( array( 'issuer_id' => absint( $program['issuer_id'] ?? 0 ), 'program_id' => (int) $program['id'], 'batch_id' => absint( $batch_id ) ) );
		if ( is_wp_error( $assignment ) ) { return $assignment; }
		$has_design = is_array( $assignment );
		// A locale variant also represents a configured design. Canonical issuance
		// resolves the exact design/revision and checks its availability at Review.
		if ( ! $has_design ) {
			$locale = ( new CredentialLocale() )->resolve( $program['profile']['credential_locale'] ?? CredentialLocale::SITE_DEFAULT );
			if ( is_wp_error( $locale ) ) { return $locale; }
			$variant = ( new ProgramTemplateVariantResolver() )->resolve( (array) ( $program['profile'] ?? array() ), $locale['effective'] );
			if ( is_wp_error( $variant ) ) { return $variant; }
			$has_design = is_array( $variant );
		}
		return array(
			'program' => array( 'id' => (int) $program['id'], 'label' => (string) $program['title'] ),
			'batch' => $batch ? array( 'id' => (int) $batch['id'], 'label' => (string) $batch['name'] ) : null,
			'valid_from' => ! empty( $batch['end_date'] ) ? (string) $batch['end_date'] : current_time( 'Y-m-d' ),
			'has_batch_date' => ! empty( $batch['end_date'] ),
			'validity' => (string) ( $program['validity_summary'] ?? '' ),
			'expiry_at_issuance' => ProgramLifecyclePolicy::VALIDITY_ISSUANCE === $policy['validity_mode'],
			'expiry_required' => 'required' === $policy['renewal'],
			'batch_required' => ProgramLifecyclePolicy::REPEAT_BATCH === $policy['repeat_policy'],
			'has_batches' => ! empty( $batch_choices ),
			'presentation_mode' => $has_design ? 'design' : 'details_only',
			'program_url' => add_query_arg( array( 'page' => 'certloom-domain-programs', 'program_id' => (int) $program['id'] ), admin_url( 'admin.php' ) ),
		);
	}

	/** Keep configurable overrides out of the short form, including forged overrides. */
	public function request( array $input ) {
		$settings = $this->resolve( $input['program_id'] ?? 0, $input['batch_id'] ?? 0 );
		if ( is_wp_error( $settings ) ) { return $settings; }
		$expiry = ! empty( $settings['expiry_at_issuance'] ) && ! empty( $input['expires_at'] ) ? 'date' : 'program';
		// Programs with "Decide at issuance" can explicitly choose no expiry.
		if ( $settings['expiry_at_issuance'] && 'program' === $expiry ) { $expiry = 'permanent'; }
		return array(
			'recipient_mode' => $input['recipient_mode'] ?? 'existing',
			'recipient_id' => $input['recipient_id'] ?? 0,
			'new_recipient' => array_intersect_key( (array) ( $input['new_recipient'] ?? array() ), array_flip( array( 'display_name', 'email' ) ) ),
			'program_id' => $settings['program']['id'], 'batch_id' => $settings['batch']['id'] ?? 0,
			'registration_id' => $input['registration_id'] ?? 0,
			'credential_basis' => 'program', 'title_source' => 'program',
			'presentation_mode' => $settings['presentation_mode'], 'design_source' => 'inherited',
			// Freeze today's effective date across review/retry when no Batch date is
			// configured. The signed canonical fingerprint still verifies this intent.
			'valid_from' => $settings['has_batch_date'] ? $settings['valid_from'] : ( $input['valid_from'] ?? $settings['valid_from'] ),
			'expiry_mode' => $expiry, 'expires_at' => 'date' === $expiry ? $input['expires_at'] : '',
			'display_number' => $input['display_number'] ?? '',
		);
	}
}
