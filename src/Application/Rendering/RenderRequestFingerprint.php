<?php
namespace Certloom\Application\Rendering;

/** Hashes the canonical layout plus resolved immutable values and asset bytes. */
final class RenderRequestFingerprint {
	private $canonical;
	public function __construct( CanonicalJson $canonical = null ) { $this->canonical = $canonical ?: new CanonicalJson(); }

	/** @return string */
	public function hash( $layout_hash, array $resolved_document ) {
		return $this->canonical->hash(
			array(
				'schema' => 'certloom-render-request',
				'version' => 1,
				'layout_hash' => strtolower( (string) $layout_hash ),
				'document' => $this->portable_document( $resolved_document ),
			)
		);
	}

	/** @return mixed */
	private function portable_document( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( 'path' === $key && isset( $value['sha256'], $value['mime'], $value['size'] ) ) {
				continue;
			}
			$out[ $key ] = $this->portable_document( $item );
		}
		return $out;
	}
}
