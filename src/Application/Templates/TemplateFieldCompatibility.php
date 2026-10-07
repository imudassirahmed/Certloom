<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\FieldSnapshotProjection;
use Certloom\Application\Issuance\MergeSnapshotBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * FLD-02 Template/Program/Batch compatibility guard.
 *
 * Templates are presentation consumers, never field authorities. When a Program
 * has explicitly adopted a scoped field contract, every catalog-backed merge
 * token used by a directly assigned Program/Batch Template must be satisfiable
 * by that effective contract. Legacy Programs with no FLD-02 contract retain
 * their historical free custom-token behavior until they opt in.
 */
final class TemplateFieldCompatibility {
	/** @var MergeSnapshotBuilder */
	private $merges;
	/** @var ScopedFieldContract */
	private $contracts;

	public function __construct( ?MergeSnapshotBuilder $merges = null, ?ScopedFieldContract $contracts = null ) {
		$this->merges = $merges ?: new MergeSnapshotBuilder();
		$this->contracts = $contracts ?: new ScopedFieldContract();
	}

	/**
	 * @param array<string,mixed>      $layout
	 * @param array<string,mixed>      $program_contract
	 * @param array<string,mixed>|null $batch_contract
	 * @param array<string,array<string,mixed>> $catalog
	 * @return true|\WP_Error
	 */
	public function validate( array $layout, array $program_contract, ?array $batch_contract, array $catalog, ?array $global_contract = null, ?array $issuer_contract = null ) {
		$resolved = $this->contracts->resolve_hierarchy( $global_contract, $issuer_contract, $program_contract, $batch_contract, $catalog );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		$collection = $this->validate_collection( $layout, $resolved, $catalog );
		if ( is_wp_error( $collection ) ) { return $collection; }

		// A Program that has never adopted FLD-02 remains in legacy free-token mode.
		if ( empty( $resolved['_meta']['configured'] ) && empty( $resolved['_meta']['issuer_configured'] ) && empty( $resolved['_meta']['global_configured'] ) ) { return true; }

		$available = array();
		foreach ( (array) ( $resolved['fields'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' !== $key ) { $available[ $key ] = true; }
		}

		$missing = array();
		foreach ( $this->merges->required_tokens( $layout ) as $token ) {
			$key = ScopedFieldContract::field_key_from_merge_token( $token );
			if ( null === $key ) { continue; }
			if ( ! isset( $catalog[ $key ] ) || ! ScopedFieldContract::is_scopable_definition( $catalog[ $key ] ) || ! isset( $available[ $key ] ) ) {
				$missing[ $key ] = array(
					'key' => $key,
					'token' => (string) $token,
					'label' => isset( $catalog[ $key ]['label'] ) ? (string) $catalog[ $key ]['label'] : $key,
				);
			}
		}
		if ( empty( $missing ) ) { return true; }

		$labels = array_values( array_map( static function ( $row ) { return (string) $row['label']; }, $missing ) );
		$preview = array_slice( $labels, 0, 3 );
		$message = count( $preview ) === 1
			? sprintf( /* translators: %s: field label. */ __( 'This design uses credential field “%s”, but that field is not available in the selected Global/Issuer/Program/Batch contract. Add the field to Credential fields or remove it from the design before assigning this Template.', 'certloom' ), $preview[0] )
			: sprintf( /* translators: %s: comma-separated field labels. */ __( 'This design uses credential fields that are not available in the selected Global/Issuer/Program/Batch contract: %s. Add them to Credential fields or remove them from the design before assigning this Template.', 'certloom' ), implode( ', ', $preview ) );
		if ( count( $labels ) > count( $preview ) ) {
			$message .= ' ' . sprintf( /* translators: %d: additional incompatible fields. */ _n( '%d additional field is also incompatible.', '%d additional fields are also incompatible.', count( $labels ) - count( $preview ), 'certloom' ), count( $labels ) - count( $preview ) );
		}
		return new \WP_Error( 'certloom_template_field_contract_incompatible', $message, array( 'fields' => array_values( $missing ) ) );
	}

	/** Collection opt-in never permits a legacy design or alias to expose a private account detail. */
	public function validate_collection( array $layout, array $contract, array $catalog, array $aliases = array() ) {
		$available = array();
		foreach ( $contract['fields'] ?? array() as $row ) { $available[ $row['key'] ] = true; }
		foreach ( $this->merges->required_tokens( $layout ) as $token ) {
			$key = $this->collection_key( $token, $catalog );
			if ( null === $key ) { continue; }
			$keys = array_unique( array( $key, $aliases[ $key ] ?? $key ) );
			foreach ( $keys as $candidate ) {
				$definition = $catalog[ $candidate ] ?? null;
				if ( ! is_array( $definition ) || ! array_key_exists( FieldCollectionPolicy::KEY, $definition ) ) { continue; }
				$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
				if ( is_wp_error( $policy ) ) { return $policy; }
				$label = (string) ( $definition['label'] ?? $candidate );
				if ( ! ( new FieldSnapshotProjection() )->artwork_allowed( $definition ) ) {
					return new \WP_Error( 'certloom_template_collected_field_private', sprintf( /* translators: %s: field label. */ __( 'This design uses the private field “%s”. Keep it off certificate artwork or explicitly review its public exposure in Field manager.', 'certloom' ), $label ) );
				}
				if ( ! isset( $available[ $candidate ] ) ) {
					return new \WP_Error( 'certloom_template_collected_field_unassigned', sprintf( /* translators: %s: field label. */ __( 'This design uses the collected field “%s”, but it is not assigned to this credential. Add it to the Program’s fields or remove it from the design.', 'certloom' ), $label ) );
				}
			}
		}
		return true;
	}

	/** Mirror supported historical namespaces only for catalog-backed collection privacy checks. */
	private function collection_key( $token, array $catalog ) {
		$key = ScopedFieldContract::field_key_from_merge_token( $token );
		if ( null !== $key ) { return $key; }
		if ( isset( $catalog[ $token ] ) ) { return $token; }
		foreach ( array( 'recipient.', 'credential.', 'custom.', 'credential:' ) as $prefix ) {
			if ( 0 !== strpos( $token, $prefix ) ) { continue; }
			$key = substr( $token, strlen( $prefix ) );
			if ( isset( $catalog[ $key ] ) ) { return $key; }
		}
		return null;
	}
}
