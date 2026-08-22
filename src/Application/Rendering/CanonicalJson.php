<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/** Deterministic JSON serialization for hashes, snapshots and renderer receipts. */
final class CanonicalJson {
	/** @return string */
	public function encode( $value ) {
		$value = $this->normalize( $value );
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json ) {
			throw new InvalidArgumentException( 'Value could not be encoded as canonical JSON.' );
		}
		return $json;
	}

	/** @return string */
	public function hash( $value ) {
		return hash( 'sha256', $this->encode( $value ) );
	}

	/** @return mixed */
	private function normalize( $value ) {
		if ( is_object( $value ) || is_resource( $value ) ) {
			throw new InvalidArgumentException( 'Canonical JSON accepts only arrays and scalar/null values.' );
		}
		if ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				throw new InvalidArgumentException( 'Non-finite numbers are not allowed in canonical JSON.' );
			}
			// Collapse negative zero so semantically identical geometry has one hash.
			return 0.0 == $value ? 0.0 : $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( $this->is_list( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out[] = $this->normalize( $item );
			}
			return $out;
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Canonical JSON object keys must be strings or integers.' );
			}
			$value[ $key ] = $this->normalize( $item );
		}
		return $value;
	}

	/** @return bool */
	private function is_list( array $value ) {
		$index = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $index ) {
				return false;
			}
			$index++;
		}
		return true;
	}
}
