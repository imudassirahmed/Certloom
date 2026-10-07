<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * FLD-02 structural compatibility for automatic integration issuance.
 *
 * Current first-party LMS adapters deliberately supply only trusted completion
 * evidence and an explicitly linked Recipient. They do not invent Grade or
 * arbitrary credential-custom values. A Program that requires such values is
 * therefore incompatible with automatic issuance until an explicit bounded
 * mapping/default feature exists.
 */
final class IntegrationFieldCompatibility {
	/** @return true|\WP_Error */
	public function validate_program( array $program, array $catalog ) {
		return $this->validate_context( $program, null, $catalog );
	}

	/**
	 * Automatic sources must satisfy the exact Program + optional Batch field
	 * contract because they do not get a second interactive form at issuance.
	 *
	 * @param array<string,mixed>|null $batch
	 * @return true|\WP_Error
	 */
	public function validate_context( array $program, ?array $batch, array $catalog, ?array $global_contract = null, ?array $issuer_contract = null ) {
		$contract = ( new ScopedFieldContract() )->resolve_hierarchy(
			$global_contract,
			$issuer_contract,
			is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program(),
			is_array( $batch ) && is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : null,
			$catalog
		);
		if ( is_wp_error( $contract ) ) { return $contract; }
		if ( empty( $contract['_meta']['configured'] ) && empty( $contract['_meta']['global_configured'] ) && empty( $contract['_meta']['issuer_configured'] ) && empty( $contract['_meta']['batch_configured'] ) ) { return true; }

		$unsupported = array();
		foreach ( (array) ( $contract['fields'] ?? array() ) as $row ) {
			if ( empty( $row['required'] ) ) { continue; }
			$source = (string) ( $row['value_source'] ?? '' );
			if ( 'recipient' === $source ) { continue; }
			$unsupported[] = (string) ( $row['label'] ?? $row['key'] ?? '' );
		}
		if ( empty( $unsupported ) ) { return true; }
		return new \WP_Error(
			'certloom_integration_required_fields_unmapped',
			sprintf(
				/* translators: %s: comma-separated credential field labels. */
				__( 'Automatic issuance needs a configured source for these required fields: %s. Configure where each value comes from, or use Manual/Bulk Issuance until the required source is available.', 'certloom' ),
				implode( ', ', $unsupported )
			)
		);
	}
}
