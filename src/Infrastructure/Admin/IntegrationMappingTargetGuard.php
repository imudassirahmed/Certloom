<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\I18n\CredentialLocale;
use Certloom\Application\I18n\ProgramTemplateVariantResolver;
use Certloom\Application\Integration\IntegrationFieldCompatibility;
use Certloom\Application\Templates\TemplateFieldCompatibility;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared validation for automatic source → Program/Batch mappings.
 *
 * Integrations choose qualification context, not a parallel certificate design.
 * The actual design is always resolved through the same hierarchy as manual
 * issuance: Global → Issuer → Program → Batch, with the most-specific selected
 * design winning. A Program locale variant is Program-level and therefore never
 * outranks an explicit Batch design.
 */
final class IntegrationMappingTargetGuard {
	/** @var ProgramRepository */ private $programs;
	/** @var BatchRepository */ private $batches;
	/** @var TemplateRepository */ private $templates;
	/** @var IntegrationFieldCompatibility */ private $fields;
	/** @var TemplateFieldCompatibility */ private $template_fields;

	public function __construct(
		?ProgramRepository $programs = null,
		?BatchRepository $batches = null,
		?TemplateRepository $templates = null,
		?IntegrationFieldCompatibility $fields = null,
		?TemplateFieldCompatibility $template_fields = null
	) {
		$this->programs = $programs ?: new ProgramRepository();
		$this->batches = $batches ?: new BatchRepository();
		$this->templates = $templates ?: new TemplateRepository();
		$this->fields = $fields ?: new IntegrationFieldCompatibility();
		$this->template_fields = $template_fields ?: new TemplateFieldCompatibility();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function validate( $program_id, $batch_id = 0 ) {
		$program_id = absint( $program_id );
		$batch_id = absint( $batch_id );
		$program = $this->programs->find( $program_id );
		if ( is_wp_error( $program ) ) { return $program; }
		$batch = null;
		if ( $batch_id ) {
			$batch = $this->batches->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
		}
		return $this->validate_snapshot( is_array( $program ) ? $program : array(), is_array( $batch ) ? $batch : null );
	}

	/**
	 * Validate a proposed Program/Batch configuration before it is persisted.
	 * This is used by dependency guards so changing an Issuer, Program profile,
	 * field contract, active state, or Batch context cannot strand an automatic
	 * source that was valid before the edit.
	 *
	 * @param array<string,mixed>      $program Proposed Program snapshot.
	 * @param array<string,mixed>|null $batch   Existing/proposed Batch snapshot.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_snapshot( array $program, ?array $batch = null, ?array $excluded_assignment = null, ?array $proposed_assignment = null ) {
		$program_id = absint( $program['id'] ?? 0 );
		if ( ! $program_id || empty( $program['active'] ) ) {
			return new \WP_Error( 'certloom_integration_program_invalid', __( 'Choose an active Certloom Program.', 'certloom' ) );
		}

		if ( is_array( $batch ) ) {
			$batch_id = absint( $batch['id'] ?? 0 );
			if ( ! $batch_id || empty( $batch['active'] ) ) {
				return new \WP_Error( 'certloom_integration_batch_invalid', __( 'Choose an active Batch, or leave Batch empty to use the Program context.', 'certloom' ) );
			}
			if ( $program_id !== absint( $batch['program_id'] ?? 0 ) ) {
				return new \WP_Error( 'certloom_integration_batch_program_mismatch', __( 'The selected Batch belongs to a different Program.', 'certloom' ) );
			}
		}

		$catalog = $this->catalog();
		$field_compatibility = $this->fields->validate_context( $program, $batch, $catalog );
		if ( is_wp_error( $field_compatibility ) ) { return $field_compatibility; }

		$design = $this->resolve_design( $program, $batch, $excluded_assignment, $proposed_assignment );
		if ( is_wp_error( $design ) ) { return $design; }
		$program_contract = is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program();
		$batch_contract = is_array( $batch ) && is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : null;
		$template_compatibility = $this->template_fields->validate( (array) $design['layout'], $program_contract, $batch_contract, $catalog );
		if ( is_wp_error( $template_compatibility ) ) { return $template_compatibility; }

		return array(
			'program' => $program,
			'batch' => $batch,
			'template_id' => absint( $design['template_id'] ),
			'template_name' => (string) $design['template_name'],
			'resolution_scope' => (string) $design['resolution_scope'],
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function resolve_design( array $program, ?array $batch, ?array $excluded_assignment = null, ?array $proposed_assignment = null ) {
		$program_id = absint( $program['id'] ?? 0 );
		$batch_id = is_array( $batch ) ? absint( $batch['id'] ?? 0 ) : 0;
		$issuer_id = absint( $program['issuer_id'] ?? 0 );
		$context = array(
			'issuer_id' => $issuer_id,
			'program_id' => $program_id,
			'batch_id' => $batch_id,
		);

		$proposed = $this->normalize_proposed_assignment( $proposed_assignment, $context );
		if ( is_wp_error( $proposed ) ) { return $proposed; }
		$effective_exclusion = is_array( $proposed )
			? array( 'scope_type' => $proposed['scope_type'], 'scope_id' => $proposed['scope_id'] )
			: $excluded_assignment;
		$resolved = is_array( $effective_exclusion )
			? $this->templates->resolve_published_assignment_details_excluding( $context, (string) ( $effective_exclusion['scope_type'] ?? '' ), absint( $effective_exclusion['scope_id'] ?? 0 ) )
			: $this->templates->resolve_published_assignment_details( $context );
		if ( is_wp_error( $resolved ) ) { return $resolved; }

		// A direct Batch assignment is the most-specific durable level and wins
		// even over Program locale variants. A submitted Batch design must be
		// evaluated before persistence for the same reason.
		if ( is_array( $proposed ) && 'batch' === $proposed['scope_type'] && $proposed['template_id'] ) {
			return $this->proposed_design( $proposed, 'batch' );
		}
		if ( is_array( $resolved ) && 'batch' === (string) ( $resolved['scope_type'] ?? '' ) && ! empty( $resolved['template_id'] ) ) {
			return array(
				'template_id' => absint( $resolved['template_id'] ),
				'template_name' => (string) ( $resolved['template_name'] ?? __( 'Batch design', 'certloom' ) ),
				'layout' => (array) ( $resolved['published_layout'] ?? array() ),
				'resolution_scope' => 'batch',
			);
		}

		$locale = ( new CredentialLocale() )->resolve( $program['profile']['credential_locale'] ?? CredentialLocale::SITE_DEFAULT );
		if ( is_wp_error( $locale ) ) { return $locale; }
		$variant = ( new ProgramTemplateVariantResolver() )->resolve( is_array( $program['profile'] ?? null ) ? $program['profile'] : array(), $locale['effective'] );
		if ( is_wp_error( $variant ) ) { return $variant; }
		if ( is_array( $variant ) && ! empty( $variant['template_key'] ) ) {
			$template = $this->templates->find_published_by_machine_key( (string) $variant['template_key'] );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || empty( $template['published_revision']['layout'] ) ) {
				return new \WP_Error( 'certloom_integration_locale_design_unavailable', __( 'The Program language variant does not resolve to a published certificate design. Review the Program design settings before enabling automatic issuance.', 'certloom' ) );
			}
			return array(
				'template_id' => absint( $template['id'] ?? 0 ),
				'template_name' => (string) ( $template['name'] ?? __( 'Program language design', 'certloom' ) ),
				'layout' => (array) $template['published_revision']['layout'],
				'resolution_scope' => (string) ( $variant['resolution_scope'] ?? 'program_locale' ),
			);
		}

		// For the remaining durable levels, compare the submitted assignment with
		// the currently effective assignment after excluding the scope being edited.
		// This lets one form safely change fields/context and its design together:
		// validation evaluates the proposed hierarchy instead of incorrectly using
		// the old assignment that the same save is about to replace.
		if ( is_array( $proposed ) && $proposed['template_id'] ) {
			$resolved_rank = is_array( $resolved ) ? $this->scope_rank( (string) ( $resolved['scope_type'] ?? '' ) ) : 0;
			if ( $this->scope_rank( $proposed['scope_type'] ) >= $resolved_rank ) {
				return $this->proposed_design( $proposed, $proposed['scope_type'] );
			}
		}

		if ( ! is_array( $resolved ) || empty( $resolved['template_id'] ) || empty( $resolved['published_layout'] ) ) {
			return new \WP_Error( 'certloom_integration_design_missing', __( 'This mapping does not resolve to a published certificate design. Configure Global → Issuer → Program → Batch first; the most specific selected design wins.', 'certloom' ) );
		}
		return array(
			'template_id' => absint( $resolved['template_id'] ),
			'template_name' => (string) ( $resolved['template_name'] ?? __( 'Inherited design', 'certloom' ) ),
			'layout' => (array) $resolved['published_layout'],
			'resolution_scope' => (string) ( $resolved['scope_type'] ?? 'global' ),
		);
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function normalize_proposed_assignment( ?array $assignment, array $context ) {
		if ( ! is_array( $assignment ) ) { return null; }
		$scope_type = sanitize_key( (string) ( $assignment['scope_type'] ?? '' ) );
		$scope_id = absint( $assignment['scope_id'] ?? 0 );
		$template_id = absint( $assignment['template_id'] ?? 0 );
		if ( ! in_array( $scope_type, array( 'global', 'issuer', 'program', 'batch' ), true ) ) {
			return new \WP_Error( 'certloom_integration_design_scope_invalid', __( 'Choose a valid certificate-design scope.', 'certloom' ) );
		}
		$expected_id = 'global' === $scope_type ? 0 : absint( $context[ $scope_type . '_id' ] ?? 0 );
		if ( $scope_id !== $expected_id ) { return null; }
		return array( 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'template_id' => $template_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function proposed_design( array $assignment, $resolution_scope ) {
		$template = $this->templates->find_published( absint( $assignment['template_id'] ?? 0 ) );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['published_revision']['layout'] ) ) {
			return new \WP_Error( 'certloom_integration_design_unavailable', __( 'The proposed certificate design is no longer published.', 'certloom' ) );
		}
		return array(
			'template_id' => absint( $template['id'] ?? 0 ),
			'template_name' => (string) ( $template['name'] ?? __( 'Proposed design', 'certloom' ) ),
			'layout' => (array) $template['published_revision']['layout'],
			'resolution_scope' => sanitize_key( (string) $resolution_scope ),
		);
	}

	/** @return int */
	private function scope_rank( $scope_type ) {
		switch ( sanitize_key( (string) $scope_type ) ) {
			case 'batch': return 4;
			case 'program': return 3;
			case 'issuer': return 2;
			case 'global': return 1;
			default: return 0;
		}
	}

	/** @return array<string,array<string,mixed>> */
	private function catalog() {
		return class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
	}
}
