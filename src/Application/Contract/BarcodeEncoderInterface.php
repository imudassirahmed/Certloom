<?php
namespace Certloom\Application\Contract;

/**
 * Renderer-independent one-dimensional barcode encoder boundary.
 *
 * Implementations return the exact dark/light module sequence for the payload;
 * presentation and future artifact renderers decide only how those modules are
 * drawn. The encoder does not own credential lookup or identifier semantics.
 */
interface BarcodeEncoderInterface {
	/** Stable machine identifier. @return string */
	public function id();

	/** Stable implementation version. @return string */
	public function version();

	/**
	 * Encodes one authoritative identifier into a binary module sequence.
	 *
	 * @param string $data Identifier to encode.
	 * @return array<int,int> 0/1 modules, excluding quiet zones.
	 */
	public function encode( $data );
}
