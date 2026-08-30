<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Templates\TemplateFieldCompatibility;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * FLD-02 configuration dependency guard.
 *
 * Program/Batch field rules are mutable configuration, so edits must be checked
 * against the mutable consumers that would become impossible to satisfy:
 * dependent Batch overrides, Program locale Template variants and the currently
 * resolved Template assignment hierarchy. Issuance still revalidates everything
 * again; this guard exists to reject bad configuration earlier with useful UX.
 */
final class ScopedFieldConfigurationGuard {
	const BATCH_CHUNK = 100;
	/** @var TemplateRepository */ private $templates;
	/** @var BatchRepository */ private $batches;
	/** @var TemplateFieldCompatibility */ private $compatibility;

	public function __construct( ?TemplateRepository $templates = null, ?BatchRepository $batches = null, ?TemplateFieldCompatibility $compatibility = null ) {
		$this->templates = $templates ?: new TemplateRepository();
		$this->batches = $batches ?: new BatchRepository();
		$this->compatibility = $compatibility ?: new TemplateFieldCompatibility();
	}

	/** @return true|\WP_Error */
	public function validate_program_change( $program_id, array $program_contract, array $profile, $issuer_id = 0 ) {
		$catalog = $this->catalog();
		$base = ( new ScopedFieldContract() )->resolve( $program_contract, null, $catalog );
		if ( is_wp_error( $base ) ) { return $base; }
		$variants = $this->variant_templates( $profile );
		if ( is_wp_error( $variants ) ) { return $variants; }
		$valid = $this->validate_templates( $variants, $program_contract, null, $catalog );
		if ( is_wp_error( $valid ) ) { return $valid; }

		$program_id = absint( $program_id );
		// New Programs already inherit Issuer/Global design assignments, so validate
		// that fallback chain before a durable Program id exists. Existing Programs
		// additionally validate their direct assignment and every dependent Batch.
		$valid = $this->validate_resolved_assignment( $program_id, 0, absint( $issuer_id ), $program_contract, null, $catalog );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( ! $program_id ) { return true; }

		$after = 0;
		do {
			$rows = $this->batches->list_for_program_after( $program_id, $after, self::BATCH_CHUNK );
			if ( is_wp_error( $rows ) ) { return $rows; }
			foreach ( $rows as $batch ) {
				$after = max( $after, absint( $batch['id'] ?? 0 ) );
				$batch_contract = is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : ScopedFieldContract::empty_batch();
				$effective = ( new ScopedFieldContract() )->resolve( $program_contract, $batch_contract, $catalog );
				if ( is_wp_error( $effective ) ) {
					return new \WP_Error( 'certloom_program_field_contract_breaks_batch', sprintf( /* translators: %s: Batch name. */ __( 'This Program field change conflicts with existing Batch “%s”. Update that Batch override first.', 'certloom' ), (string) ( $batch['name'] ?? ( '#' . absint( $batch['id'] ?? 0 ) ) ) ), array( 'batch_id' => absint( $batch['id'] ?? 0 ), 'reason' => $effective->get_error_code() ) );
				}
				$valid = $this->validate_templates( $variants, $program_contract, $batch_contract, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->with_batch_context( $valid, $batch ); }
				$valid = $this->validate_resolved_assignment( $program_id, absint( $batch['id'] ?? 0 ), absint( $issuer_id ), $program_contract, $batch_contract, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->with_batch_context( $valid, $batch ); }
			}
		} while ( count( $rows ) === self::BATCH_CHUNK );
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_batch_change( array $program, $batch_id, array $batch_contract ) {
		$catalog = $this->catalog();
		$program_contract = is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program();
		$effective = ( new ScopedFieldContract() )->resolve( $program_contract, $batch_contract, $catalog );
		if ( is_wp_error( $effective ) ) { return $effective; }
		$variants = $this->variant_templates( is_array( $program['profile'] ?? null ) ? $program['profile'] : array() );
		if ( is_wp_error( $variants ) ) { return $variants; }
		$valid = $this->validate_templates( $variants, $program_contract, $batch_contract, $catalog );
		if ( is_wp_error( $valid ) ) { return $valid; }
		return $this->validate_resolved_assignment( absint( $program['id'] ?? 0 ), absint( $batch_id ), absint( $program['issuer_id'] ?? 0 ), $program_contract, $batch_contract, $catalog );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function variant_templates( array $profile ) {
		$out = array();
		foreach ( (array) ( $profile['template_variants'] ?? array() ) as $locale => $machine_key ) {
			$machine_key = sanitize_key( (string) $machine_key );
			if ( '' === $machine_key ) { continue; }
			$template = $this->templates->find_published_by_machine_key( $machine_key );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || empty( $template['published_revision']['layout'] ) ) {
				return new \WP_Error( 'certloom_scoped_field_variant_template_unavailable', sprintf( /* translators: %s: locale code. */ __( 'The Program language variant for %s no longer resolves to a published Template.', 'certloom' ), (string) $locale ) );
			}
			$out[] = $template;
		}
		return $out;
	}

	/** @return true|\WP_Error */
	private function validate_templates( array $templates, array $program_contract, ?array $batch_contract, array $catalog ) {
		foreach ( $templates as $template ) {
			$valid = $this->compatibility->validate( (array) ( $template['published_revision']['layout'] ?? array() ), $program_contract, $batch_contract, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function validate_resolved_assignment( $program_id, $batch_id, $issuer_id, array $program_contract, ?array $batch_contract, array $catalog ) {
		$resolved = $this->templates->resolve_published_assignment_details( array( 'issuer_id' => absint( $issuer_id ), 'program_id' => absint( $program_id ), 'batch_id' => absint( $batch_id ) ) );
		if ( is_wp_error( $resolved ) ) {
			// A draft design must not block Program/Batch structure changes. Publication and
			// issuance remain fail-closed until that exact design has a published revision.
			if ( 'certloom_template_not_published' === $resolved->get_error_code() ) { return true; }
			return $resolved;
		}
		if ( ! is_array( $resolved ) || empty( $resolved['template_id'] ) ) { return true; }
		if ( empty( $resolved['published_layout'] ) || ! is_array( $resolved['published_layout'] ) ) {
			return new \WP_Error( 'certloom_scoped_field_template_unavailable', __( 'The currently assigned certificate design is no longer published.', 'certloom' ) );
		}
		return $this->compatibility->validate( (array) $resolved['published_layout'], $program_contract, $batch_contract, $catalog );
	}

	/** @return array<string,array<string,mixed>> */
	private function catalog() { return class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array(); }

	/** @return \WP_Error */
	private function with_batch_context( \WP_Error $error, array $batch ) {
		return new \WP_Error( $error->get_error_code(), sprintf( /* translators: 1: Batch name, 2: validation message. */ __( 'Batch “%1$s” would become incompatible: %2$s', 'certloom' ), (string) ( $batch['name'] ?? ( '#' . absint( $batch['id'] ?? 0 ) ) ), $error->get_error_message() ), array( 'batch_id' => absint( $batch['id'] ?? 0 ), 'reason' => $error->get_error_code() ) );
	}
}
