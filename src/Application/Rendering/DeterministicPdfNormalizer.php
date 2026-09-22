<?php
namespace Certloom\Application\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use InvalidArgumentException;

/**
 * Length-preserving normalization of mPDF volatile metadata.
 *
 * PDF cross-reference offsets remain valid because every replacement preserves
 * byte length. Any unsupported/non-PDF payload fails closed.
 */
final class DeterministicPdfNormalizer {
	/** @return string */
	public function normalize( $bytes, $seed ) {
		$bytes = (string) $bytes;
		$seed = strtolower( trim( (string) $seed ) );
		if ( 0 !== strpos( $bytes, '%PDF-' ) || ! preg_match( '/^[0-9a-f]{64}$/', $seed ) ) {
			throw new InvalidArgumentException( 'Deterministic PDF normalization requires PDF bytes and a SHA-256 seed.' );
		}
		$uuid_hex = hash( 'sha256', $seed . ':xmp-uuid' );
		$uuid = substr( $uuid_hex, 0, 8 ) . '-' . substr( $uuid_hex, 8, 4 ) . '-4' . substr( $uuid_hex, 13, 3 ) . '-8' . substr( $uuid_hex, 17, 3 ) . '-' . substr( $uuid_hex, 20, 12 );
		$bytes = preg_replace_callback( '/(uuid:)([0-9a-fA-F-]{36})/i', static function ( $match ) use ( $uuid ) { return $match[1] . $uuid; }, $bytes );
		$bytes = preg_replace_callback( '~(<xmp:(?:CreateDate|ModifyDate|MetadataDate)>)([^<]+)(</xmp:(?:CreateDate|ModifyDate|MetadataDate)>)~', static function ( $match ) {
			$fixed = '2000-01-01T00:00:00+00:00';
			$payload = strlen( $match[2] ) <= strlen( $fixed ) ? substr( $fixed, 0, strlen( $match[2] ) ) : str_pad( $fixed, strlen( $match[2] ), '0' );
			return $match[1] . $payload . $match[3];
		}, $bytes );
		$bytes = preg_replace_callback( '~(/(?:CreationDate|ModDate)\s*\(D:)([^)]*)(\))~', static function ( $match ) {
			$fixed = "20000101000000+00'00'";
			$payload = strlen( $match[2] ) <= strlen( $fixed ) ? substr( $fixed, 0, strlen( $match[2] ) ) : str_pad( $fixed, strlen( $match[2] ), '0' );
			return $match[1] . $payload . $match[3];
		}, $bytes );
		$bytes = preg_replace_callback( '/(\/ID\s*\[\s*<)([0-9A-Fa-f]+)(>\s*<)([0-9A-Fa-f]+)(>\s*\])/', static function ( $match ) use ( $seed ) {
			$a = substr( str_repeat( hash( 'sha256', $seed . ':id:a' ), 4 ), 0, strlen( $match[2] ) );
			$b = substr( str_repeat( hash( 'sha256', $seed . ':id:b' ), 4 ), 0, strlen( $match[4] ) );
			return $match[1] . $a . $match[3] . $b . $match[5];
		}, $bytes );
		return $bytes;
	}
}
