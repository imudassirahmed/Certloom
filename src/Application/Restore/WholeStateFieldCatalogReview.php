<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Editable scope references must exist in this snapshot, including retired definitions. */
final class WholeStateFieldCatalogReview {
	public function validate( $entity, array $row, array $catalog ) {
		$columns = array( 'issuers' => array( 'settings_json', 'issuer' ), 'programs' => array( 'metadata_json', 'program' ), 'batches' => array( 'metadata_json', 'batch' ) );
		if ( ! isset( $columns[$entity] ) ) { return true; }
		list( $column, $scope ) = $columns[$entity]; $raw = $row[$column] ?? null;
		if ( null === $raw || '' === $raw ) { return true; }
		if ( ! is_string( $raw ) || strlen( $raw ) > 1048576 ) { return $this->invalid( $entity ); }
		$metadata = json_decode( $raw, true, 64 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $metadata ) ) { return $this->invalid( $entity ); }
		if ( 'programs' === $entity && array_key_exists( \Certloom\Application\Fields\ProgramFieldDefaults::KEY, $metadata ) ) {
			$defaults = WholeStateOptionRows::guarded( function () use ( $metadata, $catalog ) { return ( new \Certloom\Application\Fields\ProgramFieldDefaults() )->normalize( $metadata[\Certloom\Application\Fields\ProgramFieldDefaults::KEY], $catalog ); } );
			if ( is_wp_error( $defaults ) || ! WholeStateOptionRows::same( $defaults, $metadata[\Certloom\Application\Fields\ProgramFieldDefaults::KEY] ) ) { return $this->invalid( $entity ); }
		}
		if ( ! array_key_exists( ScopedFieldContract::STORAGE_KEY, $metadata ) ) { return true; }
		$stored = ( new ScopedFieldContract() )->validate_stored_envelope( $metadata[ScopedFieldContract::STORAGE_KEY], $scope );
		if ( is_wp_error( $stored ) ) { return $this->invalid( $entity ); }
		foreach ( $stored['fields'] as $key => $mode ) { if ( ! array_key_exists( $key, $catalog ) || ! is_array( $catalog[$key] ) ) { return $this->invalid( $entity ); } }
		// A disabled/retired definition can be intentional historic configuration.
		// Preserve its original mode; ordinary issuance still enforces active fields.
		return true;
	}
	private function invalid( $entity ) { return new \WP_Error( 'certloom_whole_field_catalog_incomplete', __( 'A recovered field requirement refers to a definition missing from this snapshot. Repair its source Fields configuration before recovery.', 'certloom' ), array( 'entity' => $entity ) ); }
}
