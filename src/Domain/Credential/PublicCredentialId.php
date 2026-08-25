<?php
namespace Certloom\Domain\Credential;

use InvalidArgumentException;

/**
 * Human-friendly, non-enumerable, checksummed public credential identifier.
 *
 * Stored form is 12 Crockford-style Base32 characters. Display form is
 * XXXX-XXXX-XXXX. New identifiers use a GF(2^5) check symbol. The legacy v1
 * weighted checksum remains accepted so pre-authority rehearsal data, backups,
 * or externally persisted pre-v2 values are never mislabeled as corrupt.
 */
final class PublicCredentialId {
	const ALPHABET      = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const DATA_LENGTH   = 11;
	const TOTAL_LENGTH  = 12;
	const CHECKSUM_VERSION = 2;
	const FIELD_POLYNOMIAL = 0x25; // x^5 + x^2 + 1.
	const WEIGHTS = array( 2, 4, 8, 16, 5, 10, 20, 13, 26, 17, 7 );

	/** @return string Normalized 12-character ID using the current checksum. */
	public function generate() {
		$data = '';
		for ( $i = 0; $i < self::DATA_LENGTH; $i++ ) {
			$data .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
		}
		return $data . $this->checksum_character_v2( $data );
	}

	/** @return string */
	public function normalize( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		$value = str_replace( array( 'I', 'L', 'O' ), array( '1', '1', '0' ), $value );
		$value = preg_replace( '/[\s\-_.]+/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Accepts current v2 and legacy v1 IDs. Exact repository lookup remains the
	 * authority after this inexpensive syntax/check-symbol filter.
	 *
	 * @return bool
	 */
	public function is_valid( $value ) {
		return $this->is_valid_v2( $value ) || $this->is_valid_v1( $value );
	}

	/** @return bool */
	public function is_valid_v2( $value ) {
		$value = $this->normalize( $value );
		if ( ! $this->has_valid_shape( $value ) ) {
			return false;
		}
		$data = substr( $value, 0, self::DATA_LENGTH );
		return $this->checksum_character_v2( $data ) === substr( $value, -1 );
	}

	/** @return bool */
	public function is_valid_v1( $value ) {
		$value = $this->normalize( $value );
		if ( ! $this->has_valid_shape( $value ) ) {
			return false;
		}
		$data = substr( $value, 0, self::DATA_LENGTH );
		return $this->checksum_character_v1( $data ) === substr( $value, -1 );
	}

	/** @return int 2 for v2, 1 for legacy v1, or 0 for invalid. */
	public function checksum_version( $value ) {
		if ( $this->is_valid_v2( $value ) ) { return 2; }
		if ( $this->is_valid_v1( $value ) ) { return 1; }
		return 0;
	}

	/** @return string */
	public function format( $value ) {
		$value = $this->normalize( $value );
		if ( ! $this->is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Invalid public credential ID.' );
		}
		return implode( '-', str_split( $value, 4 ) );
	}

	/** @return bool */
	private function has_valid_shape( $value ) {
		return self::TOTAL_LENGTH === strlen( $value ) && strspn( $value, self::ALPHABET ) === self::TOTAL_LENGTH;
	}

	/** @return string */
	private function checksum_character_v2( $data ) {
		if ( self::DATA_LENGTH !== strlen( (string) $data ) ) {
			throw new InvalidArgumentException( 'Credential ID payload has the wrong length.' );
		}
		$accumulator = 0;
		for ( $i = 0; $i < self::DATA_LENGTH; $i++ ) {
			$value = strpos( self::ALPHABET, $data[ $i ] );
			if ( false === $value ) {
				throw new InvalidArgumentException( 'Credential ID contains an invalid character.' );
			}
			$accumulator ^= self::field_multiply( self::WEIGHTS[ $i ], $value );
		}
		return self::ALPHABET[ $accumulator ];
	}

	/** @return string */
	private function checksum_character_v1( $data ) {
		$sum = 0;
		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$value = strpos( self::ALPHABET, $data[ $i ] );
			if ( false === $value ) {
				throw new InvalidArgumentException( 'Credential ID contains an invalid character.' );
			}
			$sum += ( $i + 1 ) * $value;
		}
		return self::ALPHABET[ $sum % 32 ];
	}

	/** Carry-less multiplication in GF(2^5) modulo x^5 + x^2 + 1. @return int */
	private static function field_multiply( $a, $b ) {
		$a = (int) $a & 0x1F;
		$b = (int) $b & 0x1F;
		$product = 0;
		for ( $bit = 0; $bit < 5; $bit++ ) {
			if ( $b & 1 ) { $product ^= $a; }
			$b >>= 1;
			$a <<= 1;
			if ( $a & 0x20 ) { $a ^= self::FIELD_POLYNOMIAL; }
		}
		return $product & 0x1F;
	}
}
