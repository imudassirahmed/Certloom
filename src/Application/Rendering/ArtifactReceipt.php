<?php
namespace Certloom\Application\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\RendererInterface;
use InvalidArgumentException;

/** Deterministic integrity receipt for generated credential artifacts. */
final class ArtifactReceipt {
	const SCHEMA = 'certloom-artifact-receipt';
	const VERSION = 4;
	private $canonical;
	private $artifacts;

	public function __construct( ?CanonicalJson $canonical = null, ?ArtifactInspector $artifacts = null ) {
		$this->canonical = $canonical ?: new CanonicalJson();
		$this->artifacts = $artifacts ?: new ArtifactInspector();
	}

	/** @return array<string,mixed> */
	public function create( $format, $bytes, $layout_hash, $request_hash, $render_plan_hash, RendererInterface $renderer ) {
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			throw new InvalidArgumentException( 'Artifact bytes must be a non-empty string.' );
		}
		$format = strtolower( trim( (string) $format ) );
		$formats = array_map( function ( $value ) { return strtolower( trim( (string) $value ) ); }, (array) $renderer->formats() );
		if ( ! in_array( $format, $formats, true ) ) {
			throw new InvalidArgumentException( 'Renderer does not advertise the requested artifact format.' );
		}
		$artifact = $this->artifacts->inspect( $format, $bytes );
		$layout_hash = strtolower( trim( (string) $layout_hash ) );
		$request_hash = strtolower( trim( (string) $request_hash ) );
		$render_plan_hash = strtolower( trim( (string) $render_plan_hash ) );
		if ( ! $this->is_sha256( $layout_hash ) || ! $this->is_sha256( $request_hash ) || ! $this->is_sha256( $render_plan_hash ) ) {
			throw new InvalidArgumentException( 'Artifact receipt hashes must be SHA-256 values.' );
		}
		$renderer_id = trim( (string) $renderer->id() );
		$renderer_version = trim( (string) $renderer->version() );
		$context = array( 'renderer_id' => $renderer_id, 'renderer_version' => $renderer_version, 'context' => (array) $renderer->context() );
		$receipt = array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'format' => $format,
			'artifact' => $artifact,
			'bytes' => strlen( $bytes ),
			'sha256' => hash( 'sha256', $bytes ),
			'layout_hash' => $layout_hash,
			'request_hash' => $request_hash,
			'render_plan_hash' => $render_plan_hash,
			'renderer_id' => $renderer_id,
			'renderer_version' => $renderer_version,
			'renderer_context_hash' => $this->canonical->hash( $context ),
		);
		$receipt['receipt_hash'] = $this->canonical->hash( $receipt );
		return $receipt;
	}

	/** @return bool */
	public function verify( array $receipt, $bytes, ?RendererInterface $renderer = null ) {
		$required = array( 'schema', 'version', 'format', 'artifact', 'bytes', 'sha256', 'layout_hash', 'request_hash', 'render_plan_hash', 'renderer_id', 'renderer_version', 'renderer_context_hash', 'receipt_hash' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $receipt ) ) { return false; }
		}
		if ( self::SCHEMA !== (string) $receipt['schema'] || ! is_int( $receipt['version'] ) || self::VERSION !== $receipt['version'] ) { return false; }
		$format = strtolower( (string) $receipt['format'] );
		if ( ! in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ) || ! is_array( $receipt['artifact'] ) ) { return false; }
		foreach ( array( 'sha256', 'layout_hash', 'request_hash', 'render_plan_hash', 'renderer_context_hash', 'receipt_hash' ) as $hash_key ) {
			if ( ! $this->is_sha256( strtolower( (string) $receipt[ $hash_key ] ) ) ) { return false; }
		}
		$metadata = $receipt;
		$receipt_hash = strtolower( (string) $metadata['receipt_hash'] );
		unset( $metadata['receipt_hash'] );
		if ( ! hash_equals( $receipt_hash, $this->canonical->hash( $metadata ) ) ) { return false; }
		if ( ! is_int( $receipt['bytes'] ) || $receipt['bytes'] < 1 || ! is_string( $bytes ) || $receipt['bytes'] !== strlen( $bytes ) || ! hash_equals( strtolower( (string) $receipt['sha256'] ), hash( 'sha256', $bytes ) ) ) { return false; }
		try {
			$artifact = $this->artifacts->inspect( $format, $bytes );
		} catch ( \Throwable $error ) {
			return false;
		}
		if ( ! hash_equals( $this->canonical->hash( $artifact ), $this->canonical->hash( $receipt['artifact'] ) ) ) { return false; }

		if ( null !== $renderer ) {
			$renderer_id = trim( (string) $renderer->id() );
			$renderer_version = trim( (string) $renderer->version() );
			if ( ! hash_equals( (string) $receipt['renderer_id'], $renderer_id ) || ! hash_equals( (string) $receipt['renderer_version'], $renderer_version ) ) { return false; }
			$formats = array_map( function ( $value ) { return strtolower( trim( (string) $value ) ); }, (array) $renderer->formats() );
			if ( ! in_array( $format, $formats, true ) ) { return false; }
			$context = array( 'renderer_id' => $renderer_id, 'renderer_version' => $renderer_version, 'context' => (array) $renderer->context() );
			$expected = $this->canonical->hash( $context );
			if ( ! hash_equals( strtolower( (string) $receipt['renderer_context_hash'] ), $expected ) ) { return false; }
		}
		return true;
	}

	/** @return bool */
	private function is_sha256( $value ) { return 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $value ); }
}
