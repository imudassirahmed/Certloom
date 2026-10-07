<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Lossless storage transport only. Never unserializes, decrypts, activates or restores a value. */
final class WholeStateOptionRecord {
	public function from_storage( $storage ) {
		if ( null !== $storage && ( ! is_string( $storage ) || strlen( $storage ) > WholeStateOptionInventory::MAX_OPTION_BYTES ) ) { return $this->invalid(); }
		$bytes = null === $storage ? '' : $storage;
		return array( 'exists' => null !== $storage, 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'storage_base64' => base64_encode( $bytes ) );
	}

	public function validate( $record ) {
		$keys = array( 'exists', 'bytes', 'sha256', 'storage_base64' );
		if ( ! is_array( $record ) || count( $record ) !== count( $keys ) || array_diff( array_keys( $record ), $keys ) || ! is_bool( $record['exists'] ) || ! is_int( $record['bytes'] ) || $record['bytes'] < 0 || $record['bytes'] > WholeStateOptionInventory::MAX_OPTION_BYTES || ! is_string( $record['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) || ! is_string( $record['storage_base64'] ) || strlen( $record['storage_base64'] ) !== 4 * (int) ceil( $record['bytes'] / 3 ) ) { return $this->invalid(); }
		$bytes = base64_decode( $record['storage_base64'], true );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $record['bytes'] || base64_encode( $bytes ) !== $record['storage_base64'] || ! hash_equals( $record['sha256'], hash( 'sha256', $bytes ) ) || ( ! $record['exists'] && '' !== $bytes ) ) { return $this->invalid(); }
		return $record;
	}

	/** A closed, complete set includes absent options; absence cannot be confused with an empty value. */
	public function validate_set( $records, array $keys ) {
		if ( ! is_array( $records ) || count( $records ) !== count( $keys ) || array_diff( array_keys( $records ), $keys ) ) { return $this->invalid(); }
		$total = 0;
		foreach ( $records as $record ) {
			$valid = $this->validate( $record );
			if ( is_wp_error( $valid ) || $valid['bytes'] > WholeStateOptionInventory::MAX_TOTAL_OPTION_BYTES - $total ) { return $this->invalid(); }
			$total += $valid['bytes'];
		}
		return $records;
	}

	/** Includes names, presence, length and exact storage digest, without publishing storage bytes. */
	public function fingerprint( array $records ) {
		return $this->fingerprint_set( $records, WholeStateOptionInventory::keys() );
	}

	/** The caller must supply a locally validated inventory; this hash grants no import authority. */
	public function fingerprint_set( array $records, array $keys ) {
		$valid = $this->validate_set( $records, $keys );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$descriptors = array();
		foreach ( $valid as $key => $record ) { $descriptors[ $key ] = array_intersect_key( $record, array_flip( array( 'exists', 'bytes', 'sha256' ) ) ); }
		try { return ( new CanonicalJson() )->hash( $descriptors ); } catch ( \Throwable $error ) { return $this->invalid(); }
	}

	private function invalid() { return new \WP_Error( 'certloom_whole_option_record_invalid', __( 'Certloom could not verify the complete bounded configuration storage records.', 'certloom' ) ); }
}
