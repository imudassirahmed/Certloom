<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\ArtifactInspector;
use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Contract\LocalFontResolverInterface;
use Certloom\Application\Rendering\FontAssetInspector;
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
	private $artifacts;
	private $font_resolver;
	private $font_assets;

	public function __construct( RendererRegistry $registry, RendererCapabilityDetector $capabilities = null, RenderRequestFingerprint $fingerprints = null, ArtifactReceipt $receipts = null, FontManifest $fonts = null, ArtifactInspector $artifacts = null, LocalFontResolverInterface $font_resolver = null, FontAssetInspector $font_assets = null ) {
		$this->registry = $registry;
		$this->capabilities = $capabilities ?: new RendererCapabilityDetector();
		$this->fingerprints = $fingerprints ?: new RenderRequestFingerprint();
		$this->receipts = $receipts ?: new ArtifactReceipt();
		$this->fonts = $fonts ?: new FontManifest();
		$this->artifacts = $artifacts ?: new ArtifactInspector();
		$this->font_resolver = $font_resolver;
		$this->font_assets = $font_assets ?: new FontAssetInspector();
	}

	/** @return array<string,mixed> */
	public function render( $format, $layout_hash, array $resolved_document ) {
		$renderer = $this->registry->for_format( $format );
		$report = $this->capabilities->inspect( $renderer );
		if ( empty( $report['available'] ) ) {
			throw new RuntimeException( 'Certloom renderer requirements are unavailable: ' . implode( ', ', (array) $report['missing'] ) );
		}
		$runtime_document = $resolved_document;
		if ( array_key_exists( '_runtime', $runtime_document ) ) {
			throw new RuntimeException( 'Resolved render documents may not supply Certloom runtime-only state.' );
		}
		if ( $this->fonts->document_uses_text( $resolved_document ) ) {
			$context = (array) $renderer->context();
			if ( empty( $context['font_manifest'] ) || ! is_array( $context['font_manifest'] ) ) {
				throw new RuntimeException( 'Certloom renderer does not declare a content-addressed font manifest.' );
			}
			if ( null === $this->font_resolver ) {
				throw new RuntimeException( 'Certloom text rendering requires an exact local font resolver.' );
			}
			try {
				$faces = $this->fonts->required_faces( $resolved_document, $context['font_manifest'] );
				$resolved_fonts = array();
				foreach ( $faces as $face ) {
					$asset = $this->font_resolver->resolve( $face['sha256'], $face['format'] );
					$verified = $this->font_assets->inspect( $asset );
					if ( ! hash_equals( (string) $face['sha256'], (string) $verified['sha256'] ) || (string) $face['format'] !== (string) $verified['format'] ) {
						throw new RuntimeException( 'Resolved local font does not match the exact manifest face identity.' );
					}
					$resolved_fonts[ $verified['sha256'] ] = $verified;
				}
				$runtime_document['_runtime'] = array( 'fonts' => $resolved_fonts );
			} catch ( \Throwable $error ) {
				throw new RuntimeException( 'Certloom renderer font manifest/local font storage cannot satisfy the resolved document.', 0, $error );
			}
		}
		$request_hash = $this->fingerprints->hash( $layout_hash, $resolved_document );
		try {
			$bytes = $renderer->render( $runtime_document, strtolower( trim( (string) $format ) ) );
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Certloom renderer failed without changing credential state.', 0, $error );
		}
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_ARTIFACT_BYTES ) {
			throw new RuntimeException( 'Certloom renderer returned an invalid or oversized artifact.' );
		}
		try {
			$artifact = $this->artifacts->inspect( $format, $bytes );
		} catch ( \Throwable $error ) {
			throw new RuntimeException( 'Certloom renderer returned bytes that do not match the requested artifact format.', 0, $error );
		}
		$receipt = $this->receipts->create( $format, $bytes, $layout_hash, $request_hash, $renderer );
		return array( 'bytes' => $bytes, 'artifact' => $artifact, 'request_hash' => $request_hash, 'receipt' => $receipt );
	}
}
