<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Renderer-independent QR encoder. Renderers receive the exact same module matrix. */
interface QrEncoderInterface {
	/**
	 * @return array<int,array<int,int>> Square QR module matrix containing only 0/1 values.
	 */
	public function encode( $data, $error_correction );

	/** @return string Stable encoder/library identifier. */
	public function id();

	/** @return string Encoder/library version. */
	public function version();
}
