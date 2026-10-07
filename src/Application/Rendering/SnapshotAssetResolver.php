<?php
namespace Certloom\Application\Rendering;

use Certloom\Application\Contract\LocalAssetResolverInterface;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Historical media without byte provenance cannot authorize current replacement files. */
final class SnapshotAssetResolver implements LocalAssetResolverInterface {
	private $resolver;
	private $assets;
	private $historical;

	public function __construct( LocalAssetResolverInterface $resolver, $manifest, ?callable $historical = null ) {
		$this->resolver = $resolver;
		$this->historical = $historical;
		$this->assets = array();
		// Older media-free snapshots remain readable. An actual media lookup below
		// requires provenance; never infer it from today's attachment contents.
		if ( null === $manifest ) { return; }
		if ( ! is_array( $manifest ) || 1 !== ( $manifest['version'] ?? null ) || ! is_array( $manifest['assets'] ?? null ) || ! is_string( $manifest['sha256'] ?? null ) || ! ( new CanonicalJson() )->matches_hash( $manifest['assets'], $manifest['sha256'] ) ) {
			throw new InvalidArgumentException( 'Issued media provenance is invalid.' );
		}
		foreach ( $manifest['assets'] as $reference => $asset ) {
			if ( ! is_string( $reference ) || ! is_array( $asset ) ) { throw new InvalidArgumentException( 'Issued media provenance is malformed.' ); }
			$keys = array_keys( $asset ); sort( $keys, SORT_STRING );
			if ( array( 'height_px', 'mime', 'sha256', 'size', 'width_px' ) !== $keys || ! is_string( $asset['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $asset['sha256'] ) || ! in_array( $asset['mime'], array( 'image/png', 'image/jpeg' ), true ) ) { throw new InvalidArgumentException( 'Issued media provenance is malformed.' ); }
			foreach ( array( 'size', 'width_px', 'height_px' ) as $key ) { if ( ! is_int( $asset[ $key ] ) || $asset[ $key ] < 1 ) { throw new InvalidArgumentException( 'Issued media bounds are invalid.' ); } }
		}
		$this->assets = $manifest['assets'];
	}

	public function resolve( $reference ) {
		if ( ! is_string( $reference ) || ! isset( $this->assets[ $reference ] ) ) { throw new InvalidArgumentException( 'The issued certificate has no proven bytes for this media reference.' ); }
		try { return $this->inspect_exact( $this->resolver->resolve( $reference ), $this->assets[$reference] ); }
		catch ( \Throwable $error ) {
			if ( ! $this->historical ) { throw new InvalidArgumentException( 'Exact issued certificate media is unavailable.' ); }
			return $this->inspect_exact( call_user_func( $this->historical, $reference, $this->assets[$reference] ), $this->assets[$reference] );
		}
	}
	private function inspect_exact( $resolved, array $expected ) {
		if ( ! is_array( $resolved ) ) { throw new InvalidArgumentException( 'Exact issued certificate media is unavailable.' ); }
		$asset = ( new AssetInspector() )->inspect( $resolved ); $descriptor = $asset; unset( $descriptor['path'] );
		if ( ( new CanonicalJson() )->hash( $descriptor ) !== ( new CanonicalJson() )->hash( $expected ) ) { throw new InvalidArgumentException( 'Certificate media changed after issuance.' ); }
		return $asset;
	}
}
