<?php
/**
 * Source-only factory for the real PDF-04 production-candidate bootstrap.
 *
 * This file is intentionally excluded from the installable plugin. It turns
 * two already-frozen renderer candidates plus the exact content-addressed font
 * store into the strict config consumed by produce-production-bundle.php.
 */
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';
require_once __DIR__ . '/frozen-candidate-lib.php';
require_once dirname( __DIR__ ) . '/render-plan-reference/reference_lib.php';

use Certloom\Application\Rendering\FontManifest;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Infrastructure\Rendering\ContentAddressedFontResolver;
use Certloom\Infrastructure\Rendering\GdRasterRenderer;
use Certloom\Infrastructure\Rendering\MpdfPdfRenderer;

final class CertloomProductionCandidateBootstrapFactory {
	private $root;
	private $candidate_contract;
	private $font_contract;

	public function __construct( $source_root ) {
		$root = realpath( (string) $source_root );
		if ( false === $root || ! is_dir( $root ) || ! is_file( $root . '/certloom.php' ) ) {
			throw new InvalidArgumentException( 'Certloom source root is invalid for the production-candidate bootstrap.' );
		}
		if ( defined( 'CERTLOOM_PATH' ) && realpath( CERTLOOM_PATH ) !== $root ) {
			throw new InvalidArgumentException( 'Production bootstrap source root must match the loaded Certloom source tree.' );
		}
		$this->root = $root;
		$this->candidate_contract = new RendererCandidateManifest();
		$this->font_contract = new FontManifest();
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function build( array $input ) {
		$allowed = array( 'pdf_freeze_dir', 'raster_freeze_dir', 'font_root', 'mpdf_temp_dir' );
		if ( array_diff( array_keys( $input ), $allowed ) || array_diff( $allowed, array_keys( $input ) ) ) {
			throw new InvalidArgumentException( 'Production bootstrap requires exactly pdf_freeze_dir, raster_freeze_dir, font_root and mpdf_temp_dir.' );
		}

		$freezer = new CertloomFrozenCandidateEvidence( $this->root );
		$pdf_frozen = $freezer->validate( $input['pdf_freeze_dir'] );
		$raster_frozen = $freezer->validate( $input['raster_freeze_dir'] );
		$pdf_manifest = $pdf_frozen['manifest'];
		$raster_manifest = $raster_frozen['manifest'];

		$this->assert_candidate_identity( $pdf_manifest, 'certloom-mpdf-pdf', array( 'pdf' ), 'PDF' );
		$this->assert_candidate_identity( $raster_manifest, 'certloom-gd-raster', array( 'jpeg', 'png' ), 'raster' );

		$autoload = $this->regular_file_inside( $pdf_frozen['runtime_root'] . '/vendor/autoload.php', $pdf_frozen['runtime_root'], 'Frozen prefixed mPDF autoloader' );
		require_once $autoload;
		if ( ! class_exists( 'CertloomVendor\\Mpdf\\Mpdf', true ) ) {
			throw new RuntimeException( 'Frozen PDF candidate autoloader does not expose CertloomVendor\\Mpdf\\Mpdf.' );
		}

		$font_root = $this->real_directory( $input['font_root'], 'Exact font root', false );
		$mpdf_temp = $this->real_directory( $input['mpdf_temp_dir'], 'mPDF temporary directory', true );
		$setup = certloom_render_plan_reference_setup();
		if ( realpath( (string) $setup['root'] ) !== $this->root ) {
			throw new RuntimeException( 'Frozen Render Plan fixture setup belongs to a different Certloom source tree.' );
		}
		$font_manifest = $this->font_contract->normalize( (array) $setup['font_manifest'] );
		$resolver = new ContentAddressedFontResolver( $font_root );
		$resolver->resolve( (string) $setup['font_face_sha256'], 'ttf' );

		$pdf_hash = $this->candidate_contract->hash( $pdf_manifest );
		$raster_hash = $this->candidate_contract->hash( $raster_manifest );
		$pdf_renderer = new MpdfPdfRenderer( $pdf_manifest['renderer_version'], $pdf_hash, $font_manifest, $mpdf_temp );
		$raster_renderer = new GdRasterRenderer( $raster_manifest['renderer_version'], $raster_hash, $font_manifest );

		return array(
			'production_candidate_ack' => 'CERTLOOM_PDF04_PRODUCTION_CANDIDATE',
			'font_root' => $font_root,
			'formats' => array(
				'pdf' => array(
					'renderer' => $pdf_renderer,
					'candidate_manifest' => $pdf_frozen['manifest_path'],
					'source_archive' => $pdf_frozen['source_archive'],
					'dependency_lock' => $pdf_frozen['dependency_lock'],
				),
				'png' => array(
					'renderer' => $raster_renderer,
					'candidate_manifest' => $raster_frozen['manifest_path'],
					'source_archive' => $raster_frozen['source_archive'],
					'dependency_lock' => $raster_frozen['dependency_lock'],
				),
				'jpeg' => array(
					'renderer' => $raster_renderer,
					'candidate_manifest' => $raster_frozen['manifest_path'],
					'source_archive' => $raster_frozen['source_archive'],
					'dependency_lock' => $raster_frozen['dependency_lock'],
				),
			),
		);
	}

	private function assert_candidate_identity( array $manifest, $id, array $formats, $label ) {
		$actual_formats = array_values( (array) ( $manifest['formats'] ?? array() ) );
		sort( $actual_formats, SORT_STRING );
		sort( $formats, SORT_STRING );
		if ( ! hash_equals( (string) $id, (string) ( $manifest['renderer_id'] ?? '' ) ) || $formats !== $actual_formats ) {
			throw new InvalidArgumentException( $label . ' frozen candidate identity/formats do not match the canonical PDF-04 adapter.' );
		}
	}

	private function real_directory( $path, $label, $writable ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || false !== strpos( $path, "\0" ) || is_link( $path ) ) {
			throw new InvalidArgumentException( $label . ' path is invalid or symbolic.' );
		}
		$real = realpath( $path );
		if ( false === $real || ! is_dir( $real ) || ! is_readable( $real ) || ( $writable && ! is_writable( $real ) ) ) {
			throw new InvalidArgumentException( $label . ' is unavailable with the required permissions.' );
		}
		return $real;
	}

	private function regular_file_inside( $path, $root, $label ) {
		if ( is_link( $path ) ) { throw new InvalidArgumentException( $label . ' may not be symbolic.' ); }
		$file = realpath( $path );
		$base = realpath( $root );
		if ( false === $file || false === $base || ! is_file( $file ) || ! is_readable( $file ) || 0 !== strpos( $file, rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			throw new InvalidArgumentException( $label . ' must be a readable regular file inside the frozen runtime tree.' );
		}
		return $file;
	}
}
