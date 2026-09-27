<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Templates\TemplateFieldCompatibility;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pre-save validation for the canonical certificate-design hierarchy.
 *
 * Global → Issuer → Program → Batch is the durable hierarchy; the most-specific
 * explicit assignment wins. These checks run before Program/Batch/Issuer data is
 * mutated so a failed design change cannot leave an entity half-updated.
 */
final class DesignHierarchyConfigurationGuard {
	/** @var TemplateRepository */ private $templates;
	/** @var BatchRepository */ private $batches;
	/** @var TemplateFieldCompatibility */ private $compatibility;
	/** @var IntegrationConfigurationDependencyGuard */ private $integrations;

	public function __construct(
		?TemplateRepository $templates = null,
		?BatchRepository $batches = null,
		?TemplateFieldCompatibility $compatibility = null,
		?IntegrationConfigurationDependencyGuard $integrations = null
	) {
		$this->templates = $templates ?: new TemplateRepository();
		$this->batches = $batches ?: new BatchRepository();
		$this->compatibility = $compatibility ?: new TemplateFieldCompatibility();
		$this->integrations = $integrations ?: new IntegrationConfigurationDependencyGuard();
	}


	/** @return true|\WP_Error */
	public function validate_global( $template_id ) {
		$template_id = absint( $template_id );
		if ( $template_id ) {
			$template = $this->published_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			return $this->compatibility->validate(
				(array) $template['published_revision']['layout'],
				ScopedFieldContract::empty_program( true ),
				null,
				$this->catalog()
			);
		}

		if ( $this->has_direct_assignment( 'global', 0 ) ) {
			return $this->integrations->validate_design_inheritance_change( 'global', 0 );
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_issuer( array $issuer, $template_id ) {
		$template_id = absint( $template_id );
		if ( $template_id ) {
			$template = $this->published_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			return $this->compatibility->validate(
				(array) $template['published_revision']['layout'],
				ScopedFieldContract::empty_program( true ),
				null,
				$this->catalog()
			);
		}

		$issuer_id = absint( $issuer['id'] ?? 0 );
		if ( $issuer_id && $this->has_direct_assignment( 'issuer', $issuer_id ) ) {
			return $this->integrations->validate_design_inheritance_change( 'issuer', $issuer_id );
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_program( array $program, $template_id ) {
		$template_id = absint( $template_id );
		$program_id = absint( $program['id'] ?? 0 );
		$program_contract = is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program();
		$catalog = $this->catalog();
		$variant_compatibility = $this->validate_program_variants( $program, $program_contract, $catalog );
		if ( is_wp_error( $variant_compatibility ) ) { return $variant_compatibility; }

		if ( $template_id ) {
			$template = $this->published_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			$layout = (array) $template['published_revision']['layout'];
			$valid = $this->compatibility->validate( $layout, $program_contract, null, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }
			if ( $program_id ) {
				$valid = $this->validate_inheriting_batches_against_layout( $program_id, $program_contract, $layout, $catalog );
				if ( is_wp_error( $valid ) ) { return $valid; }
			}
			return true;
		}

		if ( $program_id && $this->has_direct_assignment( 'program', $program_id ) ) {
			$dependency = $this->integrations->validate_design_inheritance_change( 'program', $program_id );
			if ( is_wp_error( $dependency ) ) { return $dependency; }
		}

		$context = array(
			'issuer_id' => absint( $program['issuer_id'] ?? 0 ),
			'program_id' => $program_id,
			'batch_id' => 0,
		);
		$resolved = $program_id
			? $this->templates->resolve_published_assignment_details_excluding( $context, 'program', $program_id )
			: $this->templates->resolve_published_assignment_details( $context );
		if ( is_wp_error( $resolved ) ) { return $this->allow_staged_draft( $resolved ); }
		if ( is_array( $resolved ) && ! empty( $resolved['published_layout'] ) ) {
			$valid = $this->compatibility->validate( (array) $resolved['published_layout'], $program_contract, null, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		if ( $program_id ) {
			$valid = $this->validate_inheriting_batches_after_program_clear( $program, $program_contract, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_batch( array $program, array $batch, $template_id ) {
		$template_id = absint( $template_id );
		$batch_id = absint( $batch['id'] ?? 0 );
		$program_contract = is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program();
		$batch_contract = is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : ScopedFieldContract::empty_batch();
		$catalog = $this->catalog();

		if ( $template_id ) {
			$template = $this->published_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			return $this->compatibility->validate( (array) $template['published_revision']['layout'], $program_contract, $batch_contract, $catalog );
		}

		if ( $batch_id && $this->has_direct_assignment( 'batch', $batch_id ) ) {
			$dependency = $this->integrations->validate_design_inheritance_change( 'batch', $batch_id );
			if ( is_wp_error( $dependency ) ) { return $dependency; }
		}

		// Program language designs are Program-level and become eligible whenever
		// the Batch has no direct design. Every published variant must satisfy this
		// Batch because issuance may select one from the Program locale policy.
		foreach ( (array) ( $program['profile']['template_variants'] ?? array() ) as $locale => $machine_key ) {
			$template = $this->templates->find_published_by_machine_key( sanitize_key( (string) $machine_key ) );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || empty( $template['published_revision']['layout'] ) ) {
				return new \WP_Error(
					'certloom_design_batch_variant_unavailable',
					sprintf(
						/* translators: %s: credential locale code. */
						__( 'This Batch cannot inherit yet because the Program language design for %s is not published.', 'certloom' ),
						(string) $locale
					)
				);
			}
			$valid = $this->compatibility->validate( (array) $template['published_revision']['layout'], $program_contract, $batch_contract, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}

		$context = array(
			'issuer_id' => absint( $program['issuer_id'] ?? 0 ),
			'program_id' => absint( $program['id'] ?? 0 ),
			'batch_id' => $batch_id,
		);
		$resolved = $batch_id
			? $this->templates->resolve_published_assignment_details_excluding( $context, 'batch', $batch_id )
			: $this->templates->resolve_published_assignment_details( $context );
		if ( is_wp_error( $resolved ) ) { return $this->allow_staged_draft( $resolved ); }
		if ( is_array( $resolved ) && ! empty( $resolved['published_layout'] ) ) {
			$valid = $this->compatibility->validate( (array) $resolved['published_layout'], $program_contract, $batch_contract, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function published_template( $template_id ) {
		$template = $this->templates->find_published( absint( $template_id ) );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['published_revision']['layout'] ) ) {
			return new \WP_Error( 'certloom_design_assignment_unavailable', __( 'Choose an active published certificate design.', 'certloom' ) );
		}
		return $template;
	}

	/**
	 * Program locale variants are Program-level designs. Validate them against
	 * the Program and every Batch that would inherit the Program level so a
	 * language change cannot introduce a design that fails only at issuance.
	 *
	 * @return true|\WP_Error
	 */
	private function validate_program_variants( array $program, array $program_contract, array $catalog ) {
		$program_id = absint( $program['id'] ?? 0 );
		foreach ( (array) ( $program['profile']['template_variants'] ?? array() ) as $locale => $machine_key ) {
			$machine_key = sanitize_key( (string) $machine_key );
			if ( '' === $machine_key ) { continue; }
			$template = $this->templates->find_published_by_machine_key( $machine_key );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['published_revision']['layout'] ) ) {
				return new \WP_Error(
					'certloom_design_program_variant_unavailable',
					sprintf(
						/* translators: %s: credential locale code. */
						__( 'The Program language design for %s is not published. Choose another design before saving the Program.', 'certloom' ),
						(string) $locale
					)
				);
			}
			$layout = (array) $template['published_revision']['layout'];
			$valid = $this->compatibility->validate( $layout, $program_contract, null, $catalog );
			if ( is_wp_error( $valid ) ) { return $this->with_variant( $locale, $valid ); }
			if ( $program_id ) {
				$valid = $this->validate_inheriting_batches_against_layout( $program_id, $program_contract, $layout, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->with_variant( $locale, $valid ); }
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function validate_inheriting_batches_against_layout( $program_id, array $program_contract, array $layout, array $catalog ) {
		$after = 0;
		do {
			$rows = $this->batches->list_for_program_after( absint( $program_id ), $after, 100 );
			if ( is_wp_error( $rows ) ) { return $rows; }
			foreach ( $rows as $batch ) {
				$batch_id = absint( $batch['id'] ?? 0 );
				if ( ! $batch_id ) { continue; }
				$after = max( $after, $batch_id );
				if ( $this->has_direct_assignment( 'batch', $batch_id ) ) { continue; }
				$batch_contract = is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : ScopedFieldContract::empty_batch();
				$valid = $this->compatibility->validate( $layout, $program_contract, $batch_contract, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->with_batch( $batch, $valid ); }
			}
		} while ( count( $rows ) === 100 );
		return true;
	}

	/** @return true|\WP_Error */
	private function validate_inheriting_batches_after_program_clear( array $program, array $program_contract, array $catalog ) {
		$program_id = absint( $program['id'] ?? 0 );
		$after = 0;
		do {
			$rows = $this->batches->list_for_program_after( $program_id, $after, 100 );
			if ( is_wp_error( $rows ) ) { return $rows; }
			foreach ( $rows as $batch ) {
				$batch_id = absint( $batch['id'] ?? 0 );
				if ( ! $batch_id ) { continue; }
				$after = max( $after, $batch_id );
				if ( $this->has_direct_assignment( 'batch', $batch_id ) ) { continue; }
				$batch_contract = is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : ScopedFieldContract::empty_batch();
				$resolved = $this->templates->resolve_published_assignment_details_excluding(
					array(
						'issuer_id' => absint( $program['issuer_id'] ?? 0 ),
						'program_id' => $program_id,
						'batch_id' => $batch_id,
					),
					'program',
					$program_id
				);
				if ( is_wp_error( $resolved ) ) {
					$allowed = $this->allow_staged_draft( $resolved );
					if ( is_wp_error( $allowed ) ) { return $this->with_batch( $batch, $allowed ); }
					continue;
				}
				if ( ! is_array( $resolved ) || empty( $resolved['published_layout'] ) ) { continue; }
				$valid = $this->compatibility->validate( (array) $resolved['published_layout'], $program_contract, $batch_contract, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->with_batch( $batch, $valid ); }
			}
		} while ( count( $rows ) === 100 );
		return true;
	}

	/** @return bool|\WP_Error */
	private function has_direct_assignment( $scope_type, $scope_id ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = absint( $scope_id );
		if ( 'global' !== $scope_type && ! $scope_id ) { return false; }
		$assignment = $this->templates->get_assignment( $scope_type, $scope_id );
		if ( is_wp_error( $assignment ) ) { return $assignment; }
		return is_array( $assignment ) && ! empty( $assignment['active'] );
	}

	/** @return true|\WP_Error */
	private function allow_staged_draft( \WP_Error $error ) {
		return 'certloom_template_not_published' === $error->get_error_code() ? true : $error;
	}

	/** @return \WP_Error */
	private function with_variant( $locale, \WP_Error $error ) {
		return new \WP_Error(
			'certloom_design_program_variant_incompatible',
			sprintf(
				/* translators: 1: credential locale code, 2: design compatibility error. */
				__( 'Program language design “%1$s” is incompatible with this Program or an inheriting Batch: %2$s', 'certloom' ),
				(string) $locale,
				$error->get_error_message()
			),
			array( 'locale' => (string) $locale, 'reason' => $error->get_error_code() )
		);
	}

	/** @return \WP_Error */
	private function with_batch( array $batch, \WP_Error $error ) {
		return new \WP_Error(
			'certloom_design_program_batch_incompatible',
			sprintf(
				/* translators: 1: Batch name, 2: design compatibility error. */
				__( 'Batch “%1$s” would become incompatible with this design hierarchy: %2$s', 'certloom' ),
				(string) ( $batch['name'] ?? ( '#' . absint( $batch['id'] ?? 0 ) ) ),
				$error->get_error_message()
			),
			array( 'batch_id' => absint( $batch['id'] ?? 0 ), 'reason' => $error->get_error_code() )
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function catalog() {
		return class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
	}
}
