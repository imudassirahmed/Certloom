<?php
namespace Certloom\Domain\Rendering;

use InvalidArgumentException;

/**
 * Closed contract for immutable repeating credential data used by Data Tables.
 *
 * This is deliberately not an arbitrary JSON/table escape hatch. Only the two
 * product-approved rowsets and their bounded scalar fields are accepted. The
 * normalized rows are safe to fingerprint into immutable issuance snapshots
 * and revalidate again at render-resolution time.
 */
final class TableDataContract {
	const SUBJECTS = 'credential.subjects';
	const RESULTS = 'credential.results';
	const MAX_ROWS = 50;
	const MAX_CELL_BYTES = 1000;
	const MAX_TOTAL_BYTES = 65536;

	/** @return array<string,string[]> */
	public static function sources() {
		return array(
			self::SUBJECTS => array( 'subject', 'code', 'credits' ),
			self::RESULTS => array( 'subject', 'code', 'score', 'max_score', 'percentage', 'grade', 'credits', 'status' ),
		);
	}

	/** @return string[] */
	public static function source_ids() { return array_keys( self::sources() ); }

	/** @return string[] */
	public static function fields_for_source( $source ) {
		$source = strtolower( trim( (string) $source ) );
		$all = self::sources();
		return isset( $all[ $source ] ) ? $all[ $source ] : array();
	}

	/** @return string */
	public static function normalize_source( $source ) {
		$source = strtolower( trim( (string) $source ) );
		if ( ! in_array( $source, self::source_ids(), true ) ) {
			throw new InvalidArgumentException( 'Unsupported immutable Data Table source.' );
		}
		return $source;
	}

	/**
	 * Normalize one immutable rowset without accepting arbitrary keys/objects.
	 * Missing approved fields become empty strings so every row has a stable
	 * canonical shape and hash regardless of caller array ordering.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function normalize_rows( $source, $rows ) {
		$source = self::normalize_source( $source );
		if ( null === $rows || '' === $rows ) { return array(); }
		if ( ! is_array( $rows ) || count( $rows ) > self::MAX_ROWS ) {
			throw new InvalidArgumentException( 'Repeating credential data must contain at most 50 rows.' );
		}
		$allowed = self::fields_for_source( $source );
		$out = array();
		$total = 0;
		foreach ( array_values( $rows ) as $row ) {
			if ( ! is_array( $row ) || array_diff( array_keys( $row ), $allowed ) ) {
				throw new InvalidArgumentException( 'Repeating credential rows may contain only approved scalar fields.' );
			}
			$normalized = array();
			foreach ( $allowed as $field ) {
				$value = array_key_exists( $field, $row ) ? $row[ $field ] : '';
				if ( is_array( $value ) || is_object( $value ) || is_resource( $value ) ) {
					throw new InvalidArgumentException( 'Repeating credential cell values must be scalar.' );
				}
				$value = null === $value ? '' : ( is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value );
				if ( false !== strpos( $value, "\0" ) || 1 !== preg_match( '//u', $value ) || strlen( $value ) > self::MAX_CELL_BYTES ) {
					throw new InvalidArgumentException( 'Repeating credential cells must be bounded valid UTF-8 without NUL bytes.' );
				}
				$total += strlen( $field ) + strlen( $value );
				if ( $total > self::MAX_TOTAL_BYTES ) {
					throw new InvalidArgumentException( 'Repeating credential data exceeds the immutable snapshot budget.' );
				}
				$normalized[ $field ] = $value;
			}
			$out[] = $normalized;
		}
		return $out;
	}
}
