<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\FontManifest;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Application\Contract\ArtifactAuthorityGateInterface;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local, namespaced production engines. Registration never grants authority. */
final class ProductionRendererRuntime {
	private $root;
	private $authority;
	private $components;

	public function __construct( ?ArtifactAuthorityGateInterface $authority = null ) {
		$this->root = rtrim( defined( 'CERTLOOM_PATH' ) ? CERTLOOM_PATH : dirname( __DIR__, 3 ), '/\\' );
		$this->authority = $authority ?: new RendererAuthorityGate();
	}

	/** @return array<string,mixed> */
	public function status( $format ) {
		// Avoid dependency loading or cache creation in an unaccepted release.
		if ( $this->authority instanceof RendererAuthorityGate ) {
			$status = $this->authority->manifest_status();
			if ( empty( $status['accepted'] ) ) { return $status; }
		}
		try { return $this->authority->artifact_status( $format, $this->components()['registry'] ); }
		catch ( \Throwable $error ) { return array( 'accepted' => false, 'reason' => 'renderer_runtime_unavailable' ); }
	}

	/** @return array{registry:RendererRegistry,coordinator:ArtifactRenderCoordinator,fonts:array} */
	public function components() {
		if ( is_array( $this->components ) ) { return $this->components; }
		$path = $this->root . '/resources/renderer/runtime.json';
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) { throw new RuntimeException( 'Certloom renderer runtime configuration is unavailable.' ); }
		$config = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $config ) || 'certloom-renderer-runtime' !== ( $config['schema'] ?? '' ) || 1 !== ( $config['version'] ?? null ) ) {
			throw new RuntimeException( 'Certloom renderer runtime configuration is unavailable.' );
		}
		$fonts = ( new FontManifest() )->normalize( (array) ( $config['font_manifest'] ?? array() ) );
		BundledRendererDependencies::load( $this->root . '/resources/renderer/vendor' );
		if ( ! class_exists( 'CertloomVendor\\Mpdf\\Mpdf' ) ) { throw new RuntimeException( 'Certloom prefixed PDF dependencies are unavailable.' ); }
		$private = ( new PrivateJobFileStore() )->ensure_directory();
		if ( is_wp_error( $private ) ) { throw new RuntimeException( 'Certloom private renderer storage is unavailable.' ); }
		$temp = $private . '/renderer-mpdf';
		if ( is_link( $temp ) || ( ! is_dir( $temp ) && ! wp_mkdir_p( $temp ) ) || ! is_writable( $temp ) ) {
			throw new RuntimeException( 'Certloom private font cache is unavailable.' );
		}
		@chmod( $temp, 0700 );
		$registry = new RendererRegistry();
		$pdf = (array) ( $config['renderers']['pdf'] ?? array() );
		$raster = (array) ( $config['renderers']['png'] ?? array() );
		$registry->register( new MpdfPdfRenderer( $pdf['version'] ?? '', $pdf['candidate_manifest_sha256'] ?? '', $fonts, $temp ) );
		$registry->register( new GdRasterRenderer( $raster['version'] ?? '', $raster['candidate_manifest_sha256'] ?? '', $fonts ) );
		$this->components = array(
			'registry' => $registry,
			'fonts' => $fonts,
			'coordinator' => new ArtifactRenderCoordinator( $registry, null, null, null, null, null, new ContentAddressedFontResolver( $this->root . '/resources/renderer/fonts' ), null, null, $this->authority ),
		);
		return $this->components;
	}

	public function assert_supported( array $document ) {
		$components = $this->components();
		( new FontManifest() )->assert_local_glyphs( $document, $components['fonts'], new ContentAddressedFontResolver( $this->root . '/resources/renderer/fonts' ) );
	}
}
