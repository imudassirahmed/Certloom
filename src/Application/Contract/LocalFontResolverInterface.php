<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Resolves one exact content-addressed font face to a local file.
 *
 * Implementations must never download a font during rendering. The requested
 * SHA-256 + format are the portable identity; filesystem paths are runtime-only.
 */
interface LocalFontResolverInterface {
	/**
	 * @param string $sha256 Lowercase SHA-256 identity from the FontManifest.
	 * @param string $format ttf or otf.
	 * @return array{path:string,sha256:string,format:string,size:int}
	 */
	public function resolve( $sha256, $format );
}
