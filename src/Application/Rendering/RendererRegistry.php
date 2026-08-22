<?php
namespace Certloom\Application\Rendering;

use Certloom\Application\Contract\RendererInterface;
use InvalidArgumentException;
use RuntimeException;

/** Explicit format-to-renderer registry; presentation code never depends on a library class. */
final class RendererRegistry {
	private $renderers = array();

	/** @return void */
	public function register( RendererInterface $renderer ) {
		$id = trim( (string) $renderer->id() );
		$version = trim( (string) $renderer->version() );
		if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $id ) || '' === $version || strlen( $version ) > 64 ) {
			throw new InvalidArgumentException( 'Renderer ID/version does not satisfy the stable renderer contract.' );
		}
		$formats = array_values( (array) $renderer->formats() );
		if ( empty( $formats ) ) {
			throw new InvalidArgumentException( 'Renderer must own at least one artifact format.' );
		}
		$seen = array();
		foreach ( $formats as $format ) {
			$format = strtolower( trim( (string) $format ) );
			if ( ! in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ) ) {
				throw new InvalidArgumentException( 'Renderer registered an unsupported artifact format.' );
			}
			if ( isset( $seen[ $format ] ) ) {
				throw new InvalidArgumentException( 'Renderer registered the same artifact format more than once.' );
			}
			$seen[ $format ] = true;
			if ( isset( $this->renderers[ $format ] ) ) {
				throw new InvalidArgumentException( 'A renderer is already registered for format: ' . $format );
			}
			$this->renderers[ $format ] = $renderer;
		}
	}

	/** @return bool */
	public function has_format( $format ) {
		$format = strtolower( trim( (string) $format ) );
		return isset( $this->renderers[ $format ] );
	}

	/** @return RendererInterface */
	public function for_format( $format ) {
		$format = strtolower( trim( (string) $format ) );
		if ( ! isset( $this->renderers[ $format ] ) ) {
			throw new RuntimeException( 'No Certloom renderer is available for format: ' . $format );
		}
		return $this->renderers[ $format ];
	}
}
