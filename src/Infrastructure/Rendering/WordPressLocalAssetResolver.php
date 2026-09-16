<?php
namespace Certloom\Infrastructure\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\LocalAssetResolverInterface;
use InvalidArgumentException;

/** Resolve local WordPress attachment assets without permitting remote URLs. */
final class WordPressLocalAssetResolver implements LocalAssetResolverInterface {
	/** @return array{path:string,sha256:string,mime:string,size:int} */
	public function resolve( $reference ) {
		$reference = trim( (string) $reference );
		if ( 1 !== preg_match( '/^attachment:([1-9][0-9]*)$/', $reference, $match ) ) {
			if ( 0 === strpos( $reference, 'asset:' ) ) {
				throw new InvalidArgumentException( 'Named Certloom asset keys are not yet backed by the controlled local asset catalog.' );
			}
			throw new InvalidArgumentException( 'Template Preview accepts only local WordPress attachment references.' );
		}
		$id = (int) $match[1];
		if ( ! function_exists( 'get_attached_file' ) || ! function_exists( 'get_post_mime_type' ) ) {
			throw new InvalidArgumentException( 'WordPress media APIs are unavailable for Template Preview.' );
		}
		$path = get_attached_file( $id, true );
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			throw new InvalidArgumentException( 'The selected WordPress attachment is unavailable as a readable local file.' );
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			throw new InvalidArgumentException( 'The selected WordPress attachment path cannot be resolved safely.' );
		}
		$size = filesize( $real );
		if ( false === $size || $size < 1 ) { throw new InvalidArgumentException( 'The selected WordPress attachment is empty.' ); }
		$mime = strtolower( trim( (string) get_post_mime_type( $id ) ) );
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
			throw new InvalidArgumentException( 'Template Preview supports only local PNG and JPEG WordPress attachments.' );
		}
		return array( 'path' => $real, 'sha256' => hash_file( 'sha256', $real ), 'mime' => $mime, 'size' => (int) $size );
	}
}
