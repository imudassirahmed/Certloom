<?php
namespace Certloom\Application\Privacy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Portable one-way identity digests used only for private privacy-request matching. */
final class IdentityDigest {
	const PREFIX = 'certloom-email-v1:';
	const MAX_HASHES = 64;

	/** @return string|null */
	public function email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) { return null; }
		return hash( 'sha256', self::PREFIX . $email );
	}

	/** @return array<int,string> */
	/** @return bool */
	public function is_valid_history( array $hashes ) {
		if ( count( $hashes ) > self::MAX_HASHES ) { return false; }
		foreach ( $hashes as $hash ) {
			if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( $hash ) ) ) ) { return false; }
		}
		return count( array_unique( array_map( 'strtolower', $hashes ) ) ) === count( $hashes );
	}

	public function merge_email( array $hashes, $email ) {
		$clean = array();
		foreach ( $hashes as $hash ) {
			$hash = strtolower( trim( (string) $hash ) );
			if ( preg_match( '/^[a-f0-9]{64}$/', $hash ) ) { $clean[ $hash ] = true; }
		}
		$new = $this->email( $email );
		if ( null !== $new ) { $clean[ $new ] = true; }
		return array_keys( $clean );
	}
}
