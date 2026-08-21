<?php
namespace Certloom\Domain\Credential;

use InvalidArgumentException;

/**
 * Human-friendly, non-enumerable, checksummed public credential identifier.
 *
 * Stored form is 12 Crockford-style Base32 characters. Display form is
 * XXXX-XXXX-XXXX. The final character is a weighted mod-32 checksum, allowing
 * malformed IDs to fail before any database query.
 */
final class PublicCredentialId {
	const ALPHABET    = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const DATA_LENGTH = 11;
	const TOTAL_LENGTH = 12;

	/** @return string Normalized 12-character ID. */
	public function generate() {
		$data = '';
		for ( $i = 0; $i < self::DATA_LENGTH; $i++ ) {
			$data .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
		}
		return $data . $this->checksum_character( $data );
	}

	/** @return string */
	public function normalize( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		$value = str_replace( array( 'I', 'L', 'O' ), array( '1', '1', '0' ), $value );
		$value = preg_replace( '/[\s\-_.]+/', '', $value );
		return is_string( $value ) ? $value : '';
	}

	/** @return bool */
	public function is_valid( $value ) {
		$value = $this->normalize( $value );
		if ( self::TOTAL_LENGTH !== strlen( $value ) || strspn( $value, self::ALPHABET ) !== self::TOTAL_LENGTH ) {
			return false;
		}
		$data = substr( $value, 0, self::DATA_LENGTH );
		return $this->checksum_character( $data ) === substr( $value, -1 );
	}

	/** @return string */
	public function format( $value ) {
		$value = $this->normalize( $value );
		if ( ! $this->is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Invalid public credential ID.' );
		}
		return implode( '-', str_split( $value, 4 ) );
	}

	/** @return string */
	private function checksum_character( $data ) {
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
}
