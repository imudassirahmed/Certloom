<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\LocalAssetResolverInterface;
use InvalidArgumentException;

/**
 * Resolve Template Preview-only assets without weakening the production
 * WordPress Media Library boundary used by real credential issuance.
 */
final class TemplatePreviewAssetResolver implements LocalAssetResolverInterface {
	const SIGNER_SIGNATURE = 'asset:certloom-preview-signer-signature';
	private $wordpress;

	public function __construct( ?LocalAssetResolverInterface $wordpress = null ) {
		$this->wordpress = $wordpress ?: new WordPressLocalAssetResolver();
	}

	/** @return array{path:string,sha256:string,mime:string,size:int} */
	public function resolve( $reference ) {
		$reference = trim( (string) $reference );
		if ( self::SIGNER_SIGNATURE !== $reference ) {
			return $this->wordpress->resolve( $reference );
		}

		$root = defined( 'CERTLOOM_PATH' ) ? rtrim( (string) CERTLOOM_PATH, '/\\' ) : dirname( __DIR__, 3 );
		$path = $root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'certloom-preview-signer-signature.png';
		$real = realpath( $path );
		$root_real = realpath( $root );
		if ( false === $real || false === $root_real || ! is_file( $real ) || ! is_readable( $real ) || is_link( $path ) ) {
			throw new InvalidArgumentException( 'The bundled Signer signature Preview asset is unavailable.' );
		}
		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real, $root_prefix ) ) {
			throw new InvalidArgumentException( 'The bundled Signer signature Preview asset resolved outside Certloom.' );
		}
		$size = filesize( $real );
		if ( false === $size || $size < 1 ) {
			throw new InvalidArgumentException( 'The bundled Signer signature Preview asset is empty.' );
		}
		return array(
			'path' => $real,
			'sha256' => hash_file( 'sha256', $real ),
			'mime' => 'image/png',
			'size' => (int) $size,
		);
	}
}
