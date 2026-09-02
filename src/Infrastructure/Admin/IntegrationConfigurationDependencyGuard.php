<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Integration\IntegrationFieldCompatibility;
use Certloom\Infrastructure\Integration\CourseraMappingStore;
use Certloom\Infrastructure\Integration\LearnDashMappingStore;
use Certloom\Infrastructure\Integration\TutorLmsMappingStore;
use Certloom\Infrastructure\Integration\WebhookSourceRepository;
use Certloom\Infrastructure\Integration\WooCommerceMappingStore;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Prevents mutable field rules from silently breaking existing automatic-source mappings. */
final class IntegrationConfigurationDependencyGuard {
	/** @var IntegrationFieldCompatibility */
	private $fields;
	/** @var BatchRepository */
	private $batches;
	/** @var IntegrationMappingTargetGuard */
	private $targets;

	public function __construct( ?IntegrationFieldCompatibility $fields = null, ?BatchRepository $batches = null, ?IntegrationMappingTargetGuard $targets = null ) {
		$this->fields  = $fields ?: new IntegrationFieldCompatibility();
		$this->batches = $batches ?: new BatchRepository();
		$this->targets = $targets ?: new IntegrationMappingTargetGuard();
	}

	/**
	 * Validate every active automatic-source mapping against a proposed Program
	 * snapshot. This catches Issuer moves, deactivation, locale-design changes,
	 * field changes, and loss of an effective published design before save.
	 *
	 * @param array<string,mixed> $program
	 * @return true|\WP_Error
	 */
	public function validate_program_context_change( array $program, ?array $proposed_assignment = null ) {
		$program_id = absint( $program['id'] ?? 0 );
		if ( ! $program_id ) { return true; }
		foreach ( $this->mapping_sets() as $set ) {
			foreach ( $set['rows'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['active'] ) || absint( $row['program_id'] ?? 0 ) !== $program_id ) { continue; }
				$batch = null;
				$batch_id = absint( $row['batch_id'] ?? 0 );
				if ( $batch_id ) {
					$batch = $this->batches->find( $batch_id );
					if ( is_wp_error( $batch ) ) { return $batch; }
					if ( ! is_array( $batch ) || absint( $batch['program_id'] ?? 0 ) !== $program_id ) {
						return $this->broken_mapping( $set['label'], __( 'its selected Batch no longer belongs to this Program', 'certloom' ) );
					}
				}
				$valid = $this->targets->validate_snapshot( $program, is_array( $batch ) ? $batch : null, null, $proposed_assignment );
				if ( is_wp_error( $valid ) ) { return $this->wrap_context( $set['label'], $valid ); }
			}
		}
		return true;
	}

	/**
	 * Validate a proposed Batch snapshot against every active automatic source
	 * that targets that Batch. This catches deactivation and relationship/field
	 * changes before a learner qualifies against a now-impossible mapping.
	 *
	 * @param array<string,mixed> $program
	 * @param array<string,mixed> $batch
	 * @return true|\WP_Error
	 */
	public function validate_batch_context_change( array $program, array $batch, ?array $proposed_assignment = null ) {
		$batch_id = absint( $batch['id'] ?? 0 );
		if ( ! $batch_id ) { return true; }
		foreach ( $this->mapping_sets() as $set ) {
			foreach ( $set['rows'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['active'] ) || absint( $row['batch_id'] ?? 0 ) !== $batch_id ) { continue; }
				if ( absint( $row['program_id'] ?? 0 ) !== absint( $program['id'] ?? 0 ) ) {
					return $this->broken_mapping( $set['label'], __( 'it points this Batch at a different Program', 'certloom' ) );
				}
				$valid = $this->targets->validate_snapshot( $program, $batch, null, $proposed_assignment );
				if ( is_wp_error( $valid ) ) { return $this->wrap_context( $set['label'], $valid ); }
			}
		}
		return true;
	}

	/**
	 * Before a direct certificate-design assignment is cleared, prove that every
	 * affected active automatic-source mapping still resolves through the
	 * remaining hierarchy. This prevents an apparently harmless “inherit” change
	 * from stranding LMS/WooCommerce/Coursera/Webhook issuance later.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_design_inheritance_change( $scope_type, $scope_id = 0 ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = absint( $scope_id );
		if ( ! in_array( $scope_type, array( 'global', 'issuer', 'program', 'batch' ), true ) ) {
			return new \WP_Error( 'certloom_integration_design_scope_invalid', __( 'Choose a valid certificate-design inheritance scope.', 'certloom' ) );
		}
		if ( 'global' !== $scope_type && ! $scope_id ) { return true; }

		$programs = new ProgramRepository();
		foreach ( $this->mapping_sets() as $set ) {
			foreach ( $set['rows'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['active'] ) ) { continue; }
				$program_id = absint( $row['program_id'] ?? 0 );
				$batch_id = absint( $row['batch_id'] ?? 0 );
				if ( ! $program_id ) { continue; }

				$program = $programs->find( $program_id );
				if ( is_wp_error( $program ) ) { return $program; }
				if ( ! is_array( $program ) ) { return $this->broken_mapping( $set['label'], __( 'its selected Program no longer exists', 'certloom' ) ); }
				$batch = null;
				if ( $batch_id ) {
					$batch = $this->batches->find( $batch_id );
					if ( is_wp_error( $batch ) ) { return $batch; }
					if ( ! is_array( $batch ) ) { return $this->broken_mapping( $set['label'], __( 'its selected Batch no longer exists', 'certloom' ) ); }
				}

				$affected = 'global' === $scope_type
					|| ( 'issuer' === $scope_type && absint( $program['issuer_id'] ?? 0 ) === $scope_id )
					|| ( 'program' === $scope_type && $program_id === $scope_id )
					|| ( 'batch' === $scope_type && $batch_id === $scope_id );
				if ( ! $affected ) { continue; }

				$valid = $this->targets->validate_snapshot(
					$program,
					is_array( $batch ) ? $batch : null,
					array( 'scope_type' => $scope_type, 'scope_id' => $scope_id )
				);
				if ( is_wp_error( $valid ) ) {
					return new \WP_Error(
						'certloom_integration_design_inheritance_dependency',
						sprintf(
							/* translators: 1: integration source name, 2: validation error. */
							__( 'This design cannot switch to inheritance yet because an active %1$s mapping would become invalid: %2$s Configure a valid Global → Issuer → Program → Batch fallback or update the integration mapping first.', 'certloom' ),
							(string) $set['label'],
							$valid->get_error_message()
						),
						array( 'source' => (string) $set['label'], 'reason' => $valid->get_error_code() )
					);
				}
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_program_field_change( $program_id, array $program_contract ) {
		$program_id = absint( $program_id );
		if ( ! $program_id ) { return true; }
		$program = array( 'id' => $program_id, 'field_contract' => $program_contract );
		$catalog = $this->catalog();
		foreach ( $this->mapping_sets() as $set ) {
			foreach ( $set['rows'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['active'] ) || absint( $row['program_id'] ?? 0 ) !== $program_id ) { continue; }
				$batch = null;
				$batch_id = absint( $row['batch_id'] ?? 0 );
				if ( $batch_id ) {
					$batch = $this->batches->find( $batch_id );
					if ( is_wp_error( $batch ) ) { return $batch; }
					if ( ! is_array( $batch ) || absint( $batch['program_id'] ?? 0 ) !== $program_id ) {
						return $this->broken_mapping( $set['label'], __( 'its selected Batch no longer belongs to this Program', 'certloom' ) );
					}
				}
				$valid = $this->fields->validate_context( $program, $batch, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->wrap( $set['label'], $valid ); }
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_batch_field_change( array $program, $batch_id, array $batch_contract ) {
		$batch_id = absint( $batch_id );
		if ( ! $batch_id ) { return true; }
		$program_id = absint( $program['id'] ?? 0 );
		$batch = array( 'id' => $batch_id, 'program_id' => $program_id, 'field_contract' => $batch_contract );
		$catalog = $this->catalog();
		foreach ( $this->mapping_sets() as $set ) {
			foreach ( $set['rows'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['active'] ) || absint( $row['batch_id'] ?? 0 ) !== $batch_id ) { continue; }
				if ( absint( $row['program_id'] ?? 0 ) !== $program_id ) {
					return $this->broken_mapping( $set['label'], __( 'it points this Batch at a different Program', 'certloom' ) );
				}
				$valid = $this->fields->validate_context( $program, $batch, $catalog );
				if ( is_wp_error( $valid ) ) { return $this->wrap( $set['label'], $valid ); }
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function validate_batch_program_change( $batch_id, $new_program_id ) {
		$batch_id = absint( $batch_id );
		$new_program_id = absint( $new_program_id );
		if ( ! $batch_id || ! $new_program_id ) { return true; }
		foreach ( $this->mapping_sets() as $set ) {
			foreach ( $set['rows'] as $row ) {
				if ( ! is_array( $row ) || absint( $row['batch_id'] ?? 0 ) !== $batch_id ) { continue; }
				if ( absint( $row['program_id'] ?? 0 ) !== $new_program_id ) {
					return new \WP_Error(
						'certloom_integration_batch_move_blocked',
						sprintf(
							/* translators: %s: integration source name. */
							__( 'This Batch is used by a %s mapping tied to another Program. Update or remove that integration mapping before moving the Batch.', 'certloom' ),
							(string) $set['label']
						)
					);
				}
			}
		}
		return true;
	}

	/** @return array<int,array{label:string,rows:array}> */
	private function mapping_sets() {
		return array(
			array( 'label' => __( 'Tutor LMS', 'certloom' ), 'rows' => array_values( ( new TutorLmsMappingStore() )->all() ) ),
			array( 'label' => __( 'LearnDash', 'certloom' ), 'rows' => array_values( ( new LearnDashMappingStore() )->all() ) ),
			array( 'label' => __( 'WooCommerce', 'certloom' ), 'rows' => array_values( ( new WooCommerceMappingStore() )->all() ) ),
			array( 'label' => __( 'Coursera', 'certloom' ), 'rows' => array_values( ( new CourseraMappingStore() )->all() ) ),
			array( 'label' => __( 'Webhook / API', 'certloom' ), 'rows' => array_values( ( new WebhookSourceRepository() )->all() ) ),
		);
	}

	/** @return \WP_Error */
	private function wrap( $label, \WP_Error $error ) {
		return new \WP_Error(
			'certloom_integration_field_dependency',
			sprintf(
				/* translators: 1: integration source name, 2: compatibility error. */
				__( 'This field change would break an active %1$s mapping: %2$s', 'certloom' ),
				(string) $label,
				$error->get_error_message()
			),
			array( 'source' => (string) $label, 'reason' => $error->get_error_code() )
		);
	}

	/** @return \WP_Error */
	private function wrap_context( $label, \WP_Error $error ) {
		return new \WP_Error(
			'certloom_integration_context_dependency',
			sprintf(
				/* translators: 1: integration source name, 2: compatibility/design error. */
				__( 'This Program/Batch change would break an active %1$s mapping: %2$s', 'certloom' ),
				(string) $label,
				$error->get_error_message()
			),
			array( 'source' => (string) $label, 'reason' => $error->get_error_code() )
		);
	}

	/** @return \WP_Error */
	private function broken_mapping( $label, $reason ) {
		return new \WP_Error(
			'certloom_integration_mapping_dependency_invalid',
			sprintf(
				/* translators: 1: integration source name, 2: reason. */
				__( 'An active %1$s mapping must be corrected before this change can be saved because %2$s.', 'certloom' ),
				(string) $label,
				(string) $reason
			)
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function catalog() {
		return class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
	}
}
