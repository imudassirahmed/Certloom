<?php
namespace Certloom\Application\Rendering;

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Contract\QrEncoderInterface;
use InvalidArgumentException;

/** Resolves immutable merge/asset/QR inputs once before any renderer sees them. */
final class LayoutResolver {
	private $validator;
	private $directions;
	private $assets;
	private $qr_encoder;

	public function __construct( ?LayoutValidator $validator = null, ?TextDirectionResolver $directions = null, ?AssetInspector $assets = null, ?QrEncoderInterface $qr_encoder = null ) {
		$this->validator = $validator ?: new LayoutValidator();
		$this->directions = $directions ?: new TextDirectionResolver();
		$this->assets = $assets ?: new AssetInspector();
		$this->qr_encoder = $qr_encoder;
	}

	/** @return array<string,mixed> */
	public function resolve( array $layout, array $merge_snapshot, $verification_url, LocalAssetResolverInterface $assets ) {
		$layout = $this->validator->normalize( $layout );
		$verification_url = $this->verification_url( $verification_url );

		$resolved = $layout;
		foreach ( $resolved['elements'] as $index => $element ) {
			switch ( $element['type'] ) {
				case 'merge':
					$token = $element['token'];
					if ( ! array_key_exists( $token, $merge_snapshot ) ) {
						throw new InvalidArgumentException( 'Missing immutable merge value: ' . $token );
					}
					$value = $merge_snapshot[ $token ];
					if ( is_array( $value ) || is_object( $value ) || is_resource( $value ) ) {
						throw new InvalidArgumentException( 'Merge values must resolve to scalar text.' );
					}
					$element['type'] = 'text';
					$element['text'] = $this->resolved_text( null === $value ? '' : (string) $value );
					unset( $element['token'] );
					$element['style']['direction'] = $this->directions->resolve( $element['text'], $element['style']['direction'] );
					break;
				case 'text':
					$element['style']['direction'] = $this->directions->resolve( $element['text'], $element['style']['direction'] );
					break;
				case 'image':
				case 'logo':
				case 'signature':
					$reference = $element['asset'];
					$verified_asset = $this->assets->inspect( $assets->resolve( $reference ) );
					$verified_asset['reference'] = $reference;
					$element['asset'] = $verified_asset;
					break;
				case 'qr':
					if ( null === $this->qr_encoder ) {
						throw new InvalidArgumentException( 'A Certloom QR encoder is required before QR-bearing layouts can render.' );
					}
					$encoder_id = strtolower( trim( (string) $this->qr_encoder->id() ) );
					$encoder_version = trim( (string) $this->qr_encoder->version() );
					if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $encoder_id ) || '' === $encoder_version || strlen( $encoder_version ) > 64 ) {
						throw new InvalidArgumentException( 'QR encoder ID/version does not satisfy the stable rendering contract.' );
					}
					$matrix = $this->qr_encoder->encode( $verification_url, $element['error_correction'] );
					$element['data'] = $verification_url;
					$element['matrix'] = $this->qr_matrix( $matrix );
					$element['encoder'] = array( 'id' => $encoder_id, 'version' => $encoder_version );
					unset( $element['source'] );
					break;
			}
			$resolved['elements'][ $index ] = $element;
		}
		$resolved['_resolved'] = array( 'verification_url' => $verification_url );
		return $resolved;
	}


	/** @return array<int,array<int,int>> */
	private function qr_matrix( $matrix ) {
		if ( ! is_array( $matrix ) ) {
			throw new InvalidArgumentException( 'QR encoder returned an invalid module matrix.' );
		}
		$size = count( $matrix );
		if ( $size < 21 || $size > 177 || 0 !== ( $size - 21 ) % 4 ) {
			throw new InvalidArgumentException( 'QR module matrix size is not a valid QR Model 2 version.' );
		}
		$out = array();
		foreach ( array_values( $matrix ) as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $size ) {
				throw new InvalidArgumentException( 'QR module matrix must be square.' );
			}
			$normalized = array();
			foreach ( array_values( $row ) as $module ) {
				if ( 0 !== $module && 1 !== $module && false !== $module && true !== $module ) {
					throw new InvalidArgumentException( 'QR module matrix may contain only binary modules.' );
				}
				$normalized[] = $module ? 1 : 0;
			}
			$out[] = $normalized;
		}
		return $out;
	}

	/** @return string */
	private function verification_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 2048 || false === filter_var( $value, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $value ) ) {
			throw new InvalidArgumentException( 'A valid HTTP(S) verification URL is required for rendering.' );
		}
		$parts = parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			throw new InvalidArgumentException( 'Verification URLs may not contain credentials or fragments.' );
		}
		return $value;
	}

	/** @return string */
	private function resolved_text( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) > LayoutValidator::MAX_TEXT_BYTES || false !== strpos( $value, "\0" ) || 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'Resolved merge text is invalid UTF-8, contains NUL bytes, or is too large.' );
		}
		return $value;
	}
}
