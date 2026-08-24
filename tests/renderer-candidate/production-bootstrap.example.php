<?php
/**
 * COPY this file outside the Certloom source tree and configure exact frozen
 * candidate evidence. Never turn this example into live WordPress bootstrap.
 */
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\FontManifest;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Infrastructure\Rendering\GdRasterRenderer;
use Certloom\Infrastructure\Rendering\MpdfPdfRenderer;
require_once __DIR__ . '/frozen-candidate-lib.php';

throw new RuntimeException(
	'Example only. Copy outside the Certloom tree, remove this guard, load the dependency-prefixed candidate autoloader, and configure exact candidate evidence before production evaluation.'
);

/*
$freezer = new CertloomFrozenCandidateEvidence( CERTLOOM_PATH );
$pdf_frozen = $freezer->validate( '/absolute/frozen/pdf-candidate' );
$raster_frozen = $freezer->validate( '/absolute/frozen/gd-candidate' );
$prefixed_autoload = $pdf_frozen['runtime_root'] . '/vendor/autoload.php';
if ( ! is_file( $prefixed_autoload ) ) { throw new RuntimeException( 'Frozen prefixed candidate autoloader is unavailable.' ); }
require_once $prefixed_autoload;

$pdf_manifest_path = $pdf_frozen['manifest_path'];
$raster_manifest_path = $raster_frozen['manifest_path'];
$pdf_manifest = $pdf_frozen['manifest'];
$raster_manifest = $raster_frozen['manifest'];
$pdf_hash = $candidate_contract->hash( $pdf_manifest );
$raster_hash = $candidate_contract->hash( $raster_manifest );

$golden = $read_json( CERTLOOM_PATH . 'tests/fixtures/rendering/reference/manifest.json' );
$font_hash = strtolower( (string) $golden['font']['sha256'] );
$font_manifest = array(
	'schema' => 'certloom-font-manifest',
	'version' => 2,
	'families' => array(
		'dejavu' => array(
			'400-normal' => array( 'faces' => array( array( 'sha256' => $font_hash, 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ) ),
			'600-normal' => array( 'faces' => array( array( 'sha256' => $font_hash, 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ) ),
		),
	),
);
$font_manifest = ( new FontManifest() )->normalize( $font_manifest );

$pdf_renderer = new MpdfPdfRenderer( $pdf_manifest['renderer_version'], $pdf_hash, $font_manifest, '/absolute/local/mpdf-temp' );
$raster_renderer = new GdRasterRenderer( $raster_manifest['renderer_version'], $raster_hash, $font_manifest );

return array(
	'production_candidate_ack' => 'CERTLOOM_PDF04_PRODUCTION_CANDIDATE',
	'font_root' => '/absolute/local/content-addressed-font-root',
	'formats' => array(
		'pdf' => array(
			'renderer' => $pdf_renderer,
			'candidate_manifest' => $pdf_manifest_path,
			'source_archive' => $pdf_frozen['source_archive'],
			'dependency_lock' => $pdf_frozen['dependency_lock'],
		),
		'png' => array(
			'renderer' => $raster_renderer,
			'candidate_manifest' => $raster_manifest_path,
			'source_archive' => $raster_frozen['source_archive'],
			'dependency_lock' => $raster_frozen['dependency_lock'],
		),
		'jpeg' => array(
			'renderer' => $raster_renderer,
			'candidate_manifest' => $raster_manifest_path,
			'source_archive' => $raster_frozen['source_archive'],
			'dependency_lock' => $raster_frozen['dependency_lock'],
		),
	),
);
*/
