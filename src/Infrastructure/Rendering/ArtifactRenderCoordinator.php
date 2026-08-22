<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Rendering\FontManifest;
use Certloom\Application\Rendering\RenderRequestFingerprint;
use Certloom\Application\Rendering\RendererRegistry;
use RuntimeException;

/**
 * Single production choke point for renderer selection, availability, execution
 * and artifact-integrity receipts. Presentation code must not call engines directly.
 */
final class ArtifactRenderCoordinator {
	const MAX_ARTIFACT_BYTES = 52428800; // 50 MiB.
	private $registry;
	private $capabilities;
	private $fingerprints;
	private $receipts;
	private $fonts;

	public function __construct( RendererRegistry $registry, RendererCapabilityDetector $capabilities = null, RenderRequestFingerprint $fingerprints = null, ArtifactReceipt $receipts = null, FontManifest $fonts = null ) {
		$this->registry = $registry;
		$this->capabilities = $capabilities ?: new RendererCapabilityDetector();
		$this->fingerprints = $fingerprints ?: new RenderRequestFingerprint();
		$this->receipts = $receipts ?: new ArtifactReceipt();
		$this->fonts = $fonts ?: new FontManifest();
	}

	/** @return array<string,mixed> */
	public function render( $format, $layout_hash, array $resolved_document ) {
		$renderer = $this->registry->for_format( $format );
		$report = $this->capabilities->inspect( $renderer );
		if ( empty( $report['available'] ) ) {
			throw new RuntimeException( 'Certloom renderer requirements are unavailable: ' . implode( ', ', (array) $report['missing'] ) );
		}
		if ( $this->fonts->document_uses_text( $resolved_document ) ) {
			$context = (array) $renderer->context();
			if ( empty( $context['font_manifest'] ) || ! is_array( $context['font_manifest'] ) ) {
				throw new RuntimeException( 'Certloom renderer does not declare a content-addressed font manifest.' );
			}
			try {
				$this->fonts->assert_document_supported( $resolved_document, $context['font_manifest'] );
			} catch ( \Throwable $error ) {
				throw new RuntimeException( 'Certloom renderer font manifest cannot satisfy the resolved document.', 0, $error );
			}
		}
		$request_hash = $this->fingerprints->hash( $layout_hash, $resolved_document );
		try {
			$bytes = $renderer->render( $resolved_document, strtolower( trim( (string) $format ) ) );
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Certloom renderer failed without changing credential state.', 0, $error );
		}
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_ARTIFACT_BYTES ) {
			throw new RuntimeException( 'Certloom renderer returned an invalid or oversized artifact.' );
		}
		$receipt = $this->receipts->create( $format, $bytes, $layout_hash, $request_hash, $renderer );
		return array( 'bytes' => $bytes, 'request_hash' => $request_hash, 'receipt' => $receipt );
	}
}
