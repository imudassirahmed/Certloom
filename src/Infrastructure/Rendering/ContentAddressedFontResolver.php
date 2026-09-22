<?php
namespace Certloom\Infrastructure\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\LocalFontResolverInterface;
use InvalidArgumentException;

/**
 * Local-only exact font resolver using <sha256>.<ttf|otf> filenames.
 *
 * The root is supplied by the owning font catalog/storage layer. Rendering is
 * never allowed to fetch or discover fonts from the network or system paths.
 */
final class ContentAddressedFontResolver implements LocalFontResolverInterface {
	private $root;

	public function __construct( $root ) {
		$root = rtrim( (string) $root, '/\\' );
		if ( '' === $root || false !== strpos( $root, "\0" ) ) {
			throw new InvalidArgumentException( 'Font storage root is invalid.' );
		}
		$this->root = $root;
	}

	/** @return array{path:string,sha256:string,format:string,size:int} */
	public function resolve( $sha256, $format ) {
		$sha256 = strtolower( trim( (string) $sha256 ) );
		$format = strtolower( trim( (string) $format ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) || ! in_array( $format, array( 'ttf', 'otf' ), true ) ) {
			throw new InvalidArgumentException( 'Font resolution requires a canonical SHA-256 and ttf/otf format.' );
		}

		$root = realpath( $this->root );
		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			throw new InvalidArgumentException( 'Exact local font storage is unavailable.' );
		}
		$path = realpath( $root . DIRECTORY_SEPARATOR . $sha256 . '.' . $format );
		if ( false === $path || ! is_file( $path ) || dirname( $path ) !== $root ) {
			throw new InvalidArgumentException( 'Requested exact local font face is unavailable.' );
		}
		$size = filesize( $path );
		if ( false === $size ) {
			throw new InvalidArgumentException( 'Requested exact local font face could not be measured.' );
		}
		return array( 'path' => $path, 'sha256' => $sha256, 'format' => $format, 'size' => (int) $size );
	}
}
