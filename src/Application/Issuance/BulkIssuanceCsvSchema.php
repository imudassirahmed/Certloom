<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** CSV supplies per-achievement staff facts; account and connection authorities remain intact. */
final class BulkIssuanceCsvSchema {
	public function describe( array $contract, array $catalog ) {
		$out = array( 'configured' => ! empty( $contract['_meta']['configured'] ), 'custom' => array(), 'managed' => array(), 'grade' => null );
		foreach ( (array) ( $contract['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $catalog[ $field['key'] ?? '' ] ) ) { return $this->invalid(); }
			$key = $field['key']; $definition = $catalog[ $key ];
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( ! empty( $policy['_meta']['configured'] ) && ( 'achievement' !== $policy['lifetime'] || 'staff' !== $policy['writer'] ) ) {
				$out['managed'][ $key ] = array( 'label' => $field['label'] ?? $key, 'writer' => $policy['writer'], 'lifetime' => $policy['lifetime'] );
				continue;
			}
			$source = $field['value_source'] ?? ScopedFieldContract::value_source( $key );
			if ( 'credential_custom' === $source ) { $out['custom'][ $key ] = $field; }
			if ( 'credential' === $source && 'grade' === $key ) { $out['grade'] = $field; }
		}
		return $out;
	}
	public function validate( array $header, array $shared, array $schema ) {
		if ( ! $schema['configured'] ) { return true; }
		$set = array_fill_keys( $header, true );
		foreach ( $header as $column ) {
			if ( 0 !== strpos( $column, 'custom:' ) ) { continue; }
			$key = substr( $column, 7 );
			if ( isset( $schema['managed'][ $key ] ) ) { return $this->managed_error( $schema['managed'][ $key ] ); }
			if ( ! isset( $schema['custom'][ $key ] ) ) { return new \WP_Error( 'certloom_bulk_csv_unassigned_field', sprintf( /* translators: %s: field key. */ __( 'CSV column “custom:%s” is not assigned to the selected Program/Batch credential-field contract.', 'certloom' ), $key ) ); }
		}
		foreach ( $schema['custom'] as $key => $field ) {
			if ( ! empty( $field['required'] ) && ! isset( $set[ 'custom:' . $key ] ) ) { return new \WP_Error( 'certloom_bulk_csv_required_field_header_missing', sprintf( /* translators: %1$s: label; %2$s: field key. */ __( 'Required credential field “%1$s” needs a custom:%2$s column in this Bulk Issuance CSV.', 'certloom' ), $field['label'] ?? $key, $key ) ); }
		}
		$has_grade = isset( $set['grade'] ) || '' !== trim( (string) ( $shared['grade'] ?? '' ) );
		if ( isset( $schema['managed']['grade'] ) && $has_grade ) { return $this->managed_error( $schema['managed']['grade'] ); }
		if ( null === $schema['grade'] && $has_grade ) { return new \WP_Error( 'certloom_bulk_csv_unassigned_grade', __( 'Grade / result is not assigned to the selected Program/Batch credential-field contract.', 'certloom' ) ); }
		if ( is_array( $schema['grade'] ) && ! empty( $schema['grade']['required'] ) && ! $has_grade ) { return new \WP_Error( 'certloom_bulk_csv_required_grade_header_missing', __( 'Grade / result is required for this Program/Batch. Add a grade column or provide one shared Grade value.', 'certloom' ) ); }
		return true;
	}
	public function columns( array $schema, $automatic_numbering = false ) {
		$columns = array( 'recipient_id' );
		foreach ( $schema['managed'] as $field ) { if ( 'registration' === $field['lifetime'] ) { $columns[] = 'registration_id'; break; } }
		if ( ! $automatic_numbering ) { $columns[] = 'certificate_number'; }
		if ( is_array( $schema['grade'] ) ) { $columns[] = 'grade'; }
		foreach ( $schema['custom'] as $key => $field ) { $columns[] = 'custom:' . $key; }
		if ( count( $columns ) > \Certloom\Infrastructure\Issuance\BulkIssuanceCsvReader::MAX_COLUMNS ) { return new \WP_Error( 'certloom_bulk_csv_columns_invalid', __( 'This Program needs more columns than one bulk CSV supports. Use Program defaults for shared values or reduce the per-achievement fields before downloading its template.', 'certloom' ) ); }
		return $columns;
	}
	private function managed_error( array $field ) { return new \WP_Error( 'certloom_bulk_csv_managed_field', sprintf( /* translators: %s: field label. */ __( '“%s” comes from customer account details, a Program default or a connected source. Complete that source; a bulk CSV cannot replace it.', 'certloom' ), $field['label'] ) ); }
	private function invalid() { return new \WP_Error( 'certloom_bulk_csv_contract_invalid', __( 'Review the selected Program’s field configuration before preparing its CSV.', 'certloom' ) ); }
}
