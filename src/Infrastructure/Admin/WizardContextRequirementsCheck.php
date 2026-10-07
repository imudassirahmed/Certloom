<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared read-only relationship/design checks for wizard apply, first issue and setup preview. */
final class WizardContextRequirementsCheck {
	public function validate( array $draft, array $plan, $require_issuance_values = true ) {
		$terms = \Certloom\Infrastructure\Admin\UsageProfilePresentation::terms( (string) ( $draft['usage_profile'] ?? 'general_other' ) );
		$context = is_array( $plan['context_required'] ?? null ) ? $plan['context_required'] : array();
		$context_fields = is_array( $plan['context_fields'] ?? null ) ? $plan['context_fields'] : array();
		$program_id = absint( $draft['setup_program_id'] ?? 0 );
		$new_program = trim( (string) ( $draft['setup_new_program_title'] ?? '' ) );
		$issuer_id = absint( $draft['setup_issuer_id'] ?? 0 );
		$new_issuer = trim( (string) ( $draft['setup_new_issuer_name'] ?? '' ) );
		$batch_id = absint( $draft['setup_batch_id'] ?? 0 );
		$new_batch = trim( (string) ( $draft['setup_new_batch_name'] ?? '' ) );
		$signer_id = absint( $draft['setup_signer_id'] ?? 0 );
		$new_signer = trim( (string) ( $draft['setup_new_signer_name'] ?? '' ) );

		$program_repo = new \Certloom\Infrastructure\Repository\ProgramRepository();
		$issuer_repo = new \Certloom\Infrastructure\Repository\IssuerRepository();
		$batch_repo = new \Certloom\Infrastructure\Repository\BatchRepository();
		$signer_repo = new \Certloom\Infrastructure\Repository\SignerRepository();

		$program = null;
		if ( $program_id ) {
			$program = $program_repo->find( $program_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_setup_program_missing', __( 'The selected Program is no longer available.', 'certloom' ) ); }
		}
		if ( ! empty( $context['program'] ) && ! $program_id && '' === $new_program ) {
			return new \WP_Error( 'certloom_setup_design_program_required', sprintf( /* translators: %s: profile-specific Program-equivalent relationship label. */ __( 'The selected design needs %s information. Choose an existing one or add it here.', 'certloom' ), (string) ( $terms['program']['singular'] ?? __( 'Program', 'certloom' ) ) ) );
		}

		$program_issuer_id = is_array( $program ) ? absint( $program['issuer_id'] ?? 0 ) : 0;
		$effective_issuer_id = $program_issuer_id ?: $issuer_id;
		$issuer = null;
		if ( $effective_issuer_id ) {
			$issuer = $issuer_repo->find( $effective_issuer_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
			if ( ! is_array( $issuer ) ) { return new \WP_Error( 'certloom_setup_issuer_missing', __( 'The selected Issuer is no longer available.', 'certloom' ) ); }
		}
		if ( ! empty( $context['issuer'] ) && ! $effective_issuer_id && '' === $new_issuer ) {
			return new \WP_Error( 'certloom_setup_design_issuer_required', sprintf( /* translators: %s: profile-specific Program-equivalent relationship label. */ __( 'The selected design needs %s information. Choose an existing one or add it here.', 'certloom' ), (string) ( $terms['issuer']['singular'] ?? __( 'Issuer', 'certloom' ) ) ) );
		}

		$batch = null;
		if ( $batch_id ) {
			$batch = $batch_repo->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) ) { return new \WP_Error( 'certloom_setup_batch_missing', __( 'The selected Batch or cycle is no longer available.', 'certloom' ) ); }
			if ( $program_id && absint( $batch['program_id'] ?? 0 ) !== $program_id ) { return new \WP_Error( 'certloom_setup_batch_program_mismatch', __( 'Choose a Batch or cycle that belongs to the selected Program context.', 'certloom' ) ); }
		}
		if ( ! empty( $context['batch'] ) && ! $batch_id && '' === $new_batch ) {
			return new \WP_Error( 'certloom_setup_design_batch_required', sprintf( /* translators: %s: profile-specific Program-equivalent relationship label. */ __( 'The selected design needs %s information. Choose an existing one or add it here.', 'certloom' ), (string) ( $terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) ) ) );
		}

		$signer = null;
		if ( $signer_id ) {
			$signer = $signer_repo->find( $signer_id );
			if ( is_wp_error( $signer ) ) { return $signer; }
			if ( ! is_array( $signer ) ) { return new \WP_Error( 'certloom_setup_signer_missing', __( 'The selected Signer is no longer available.', 'certloom' ) ); }
		}
		if ( ! empty( $context['signer'] ) && ! $signer_id && '' === $new_signer ) {
			return new \WP_Error( 'certloom_setup_design_signer_required', sprintf( /* translators: %s: profile-specific Program-equivalent relationship label. */ __( 'The selected design needs %s information. Choose an existing one or add it here.', 'certloom' ), (string) ( $terms['signer']['singular'] ?? __( 'Signer', 'certloom' ) ) ) );
		}

		// Validate the exact sub-fields required by the published design, rather
		// than treating an entity selection as proof that all of its merge data exists.
		$issuer_fields = is_array( $context_fields['issuer'] ?? null ) ? $context_fields['issuer'] : array();
		if ( ! empty( $issuer_fields['title'] ) ) {
			$issuer_title = is_array( $issuer ) ? trim( (string) ( $issuer['settings']['title'] ?? '' ) ) : trim( (string) ( $draft['setup_new_issuer_title'] ?? '' ) );
			if ( '' === $issuer_title ) { return new \WP_Error( 'certloom_setup_design_issuer_title_required', sprintf( /* translators: %s: profile-specific Issuer-equivalent relationship label. */ __( 'The selected design uses the %s title. Add that title to the selected record or create a new one with a title.', 'certloom' ), (string) ( $terms['issuer']['singular'] ?? __( 'Issuer', 'certloom' ) ) ) ); }
		}
		$program_fields = is_array( $context_fields['program'] ?? null ) ? $context_fields['program'] : array();
		if ( ! empty( $program_fields['code'] ) ) {
			$program_code = is_array( $program ) ? trim( (string) ( $program['code'] ?? '' ) ) : trim( (string) ( $draft['setup_new_program_code'] ?? '' ) );
			if ( '' === $program_code ) { return new \WP_Error( 'certloom_setup_design_program_code_required', sprintf( /* translators: %s: profile-specific Program- or Batch-equivalent relationship label. */ __( 'The selected design uses the %s code. Enter a code before continuing.', 'certloom' ), (string) ( $terms['program']['singular'] ?? __( 'Program', 'certloom' ) ) ) ); }
		}
		$batch_fields = is_array( $context_fields['batch'] ?? null ) ? $context_fields['batch'] : array();
		if ( ! empty( $batch_fields['code'] ) ) {
			$batch_code = is_array( $batch ) ? trim( (string) ( $batch['code'] ?? '' ) ) : trim( (string) ( $draft['setup_new_batch_code'] ?? '' ) );
			if ( '' === $batch_code ) { return new \WP_Error( 'certloom_setup_design_batch_code_required', sprintf( /* translators: %s: profile-specific Program- or Batch-equivalent relationship label. */ __( 'The selected design uses the %s code. Enter a code before continuing.', 'certloom' ), (string) ( $terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) ) ) ); }
		}
		$signer_fields = is_array( $context_fields['signer'] ?? null ) ? $context_fields['signer'] : array();
		$signer_checks = array(
			'title' => array( is_array( $signer ) ? trim( (string) ( $signer['title'] ?? '' ) ) : trim( (string) ( $draft['setup_new_signer_title'] ?? '' ) ), __( 'position / designation', 'certloom' ) ),
			'email' => array( is_array( $signer ) ? trim( (string) ( $signer['email'] ?? '' ) ) : trim( (string) ( $draft['setup_new_signer_email'] ?? '' ) ), __( 'email', 'certloom' ) ),
			'signature' => array( is_array( $signer ) ? absint( $signer['signature_attachment_id'] ?? 0 ) : absint( $draft['setup_new_signer_signature_id'] ?? 0 ), __( 'signature image', 'certloom' ) ),
		);
		foreach ( $signer_checks as $field_key => $check ) {
			if ( empty( $signer_fields[ $field_key ] ) || ! empty( $check[0] ) ) { continue; }
			return new \WP_Error( 'certloom_setup_design_signer_field_required', sprintf( /* translators: 1: profile-specific relationship label; 2: required field label. */ __( 'The selected design uses the %1$s %2$s. Add that value before continuing.', 'certloom' ), (string) ( $terms['signer']['singular'] ?? __( 'Signer', 'certloom' ) ), (string) $check[1] ) );
		}

		// Program setup selects reusable context. Individual facts are supplied on
		// the first-issuance screen and remain required at final issuance validation.
		if ( ! $require_issuance_values ) { return true; }
		$values = is_array( $draft['setup_credential_values'] ?? null ) ? $draft['setup_credential_values'] : array();
		foreach ( (array) ( $plan['credential_fields'] ?? array() ) as $field ) {
			if ( empty( $field['required_by_design'] ) ) { continue; }
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' !== $key && '' === trim( (string) ( $values[ $key ] ?? '' ) ) ) {
				return new \WP_Error( 'certloom_setup_design_field_required', sprintf( /* translators: %s: required credential field label. */ __( 'Enter %s because the selected design uses this field.', 'certloom' ), (string) ( $field['label'] ?? $key ) ) );
			}
		}
		if ( ! empty( $plan['grade_required'] ) && '' === trim( (string) ( $draft['first_grade'] ?? '' ) ) ) {
			return new \WP_Error( 'certloom_setup_design_grade_required', __( 'Enter the grade because the selected design uses it.', 'certloom' ) );
		}
		$repeat = is_array( $draft['setup_repeating_data'] ?? null ) ? $draft['setup_repeating_data'] : array();
		foreach ( array( 'subjects', 'results' ) as $rowset ) {
			if ( ! empty( $plan['repeating'][ $rowset ] ) && empty( $repeat[ $rowset ] ) ) {
				return new \WP_Error( 'certloom_setup_design_repeating_required', sprintf( /* translators: %s: repeating Data Table label, such as Subjects or Results. */ __( 'Add at least one %s row because the selected design uses that Data Table.', 'certloom' ), 'results' === $rowset ? __( 'result', 'certloom' ) : __( 'subject', 'certloom' ) ) );
			}
		}
		return true;
	}
}
