<?php
namespace Certloom\Application\Restore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only current opt-in collection mapping shape; target graph checks are separate. */
final class WholeStatePendingMappingValue {
	public static function validate( $value, $prefix, $source_column, $maximum ) {
		if ( ! in_array( array( $prefix, $source_column ), array( array( 'tp', 'course_id' ), array( 'wp', 'product_id' ) ), true ) || ! is_array( $value ) || count( $value ) !== 2 || array_diff( array_keys( $value ), array( 'schema_version', 'mappings' ) ) || 1 !== ( $value['schema_version'] ?? null ) || ! is_array( $value['mappings'] ?? null ) || count( $value['mappings'] ) > $maximum || array_values( $value['mappings'] ) !== $value['mappings'] ) { return self::invalid(); }
		$keys = array(); $sources = array();
		foreach ( $value['mappings'] as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== 7 || array_diff( array_keys( $row ), array( 'key', $source_column, 'program_id', 'batch_id', 'active', 'revision', 'updated_at' ) ) || ! is_string( $row['key'] ?? null ) || ! preg_match( '/^' . $prefix . '-[a-f0-9]{12}$/D', $row['key'] ) || ! is_int( $row[$source_column] ?? null ) || $row[$source_column] < 1 || ! is_int( $row['program_id'] ?? null ) || $row['program_id'] < 1 || ! is_int( $row['batch_id'] ?? null ) || $row['batch_id'] < 0 || ! is_bool( $row['active'] ?? null ) || ! is_int( $row['revision'] ?? null ) || $row['revision'] < 1 || ! WholeStateOptionRows::date( $row['updated_at'] ?? null, 'Y-m-d H:i:s' ) || isset( $keys[$row['key']] ) || isset( $sources[$row[$source_column]] ) ) { return self::invalid(); }
			$keys[$row['key']] = true; $sources[$row[$source_column]] = true;
		}
		return $value;
	}
	private static function invalid() { return new \WP_Error( 'certloom_whole_pending_mapping_invalid', __( 'A saved recovery connection for collecting recipient details is invalid or duplicated. Review its current course or product and Program before restoring it.', 'certloom' ) ); }
}
