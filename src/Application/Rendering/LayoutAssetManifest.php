<?php
namespace Certloom\Application\Rendering;

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Domain\Rendering\ElementRegistry;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bind certificate media to reviewed bytes without storing host paths in history. */
final class LayoutAssetManifest {
	public static function reference( array $element, array $merge ) {
		$source = $element['source'] ?? '';
		if ( '' !== $source ) {
			$keys = array( 'issuer.logo' => 'issuer.logo_asset', 'issuer.seal' => 'issuer.seal_asset', 'signer.signature' => 'signer.signature_asset' );
			$key = $keys[ $source ] ?? '';
			$reference = $merge[ $key ] ?? null;
		} else { $reference = $element['asset'] ?? null; }
		if ( ! is_string( $reference ) || '' === trim( $reference ) ) {
			if ( 'signer.signature' === $source || 'signature' === ( $element['type'] ?? '' ) ) { throw new InvalidArgumentException( 'The immutable credential signer does not provide a usable signature asset.' ); }
			if ( in_array( $source, array( 'issuer.logo', 'issuer.seal' ), true ) ) { throw new InvalidArgumentException( 'The immutable credential issuer does not provide the media required by this template.' ); }
			throw new InvalidArgumentException( 'The credential does not provide the local media required by this design.' );
		}
		return trim( $reference );
	}

	public function capture( array $layout, array $merge, LocalAssetResolverInterface $resolver ) {
		$assets = array(); $inspector = new AssetInspector();
		foreach ( $layout['elements'] ?? array() as $element ) {
			$definition = ElementRegistry::require_type( $element['type'] ?? '' );
			if ( ! in_array( $definition['resolver'], array( 'asset', 'signature_asset' ), true ) ) { continue; }
			$reference = self::reference( $element, $merge );
			if ( isset( $assets[ $reference ] ) ) { continue; }
			$asset = $inspector->inspect( $resolver->resolve( $reference ) );
			unset( $asset['path'] );
			$assets[ $reference ] = $asset;
		}
		ksort( $assets, SORT_STRING );
		return array( 'version' => 1, 'assets' => $assets, 'sha256' => ( new CanonicalJson() )->hash( $assets ) );
	}

	/** Bind an uploaded certificate to the same immutable media contract as designs. */
	public function capture_image( $reference, LocalAssetResolverInterface $resolver ) {
		$asset = ( new AssetInspector() )->inspect( $resolver->resolve( $reference ) );
		unset( $asset['path'] );
		$assets = array( $reference => $asset );
		return array( 'version' => 1, 'assets' => $assets, 'sha256' => ( new CanonicalJson() )->hash( $assets ) );
	}
}
