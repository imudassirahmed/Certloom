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

throw new RuntimeException(
	'Example only. Copy outside the Certloom tree, remove this guard, load the dependency-prefixed candidate autoloader, and configure exact candidate evidence before production evaluation.'
);

/*
$prefixed_autoload = '/absolute/candidate/vendor/autoload.php';
if ( ! is_file( $prefixed_autoload ) ) { throw new RuntimeException( 'Prefixed candidate autoloader is unavailable.' ); }
require_once $prefixed_autoload;

$read_json = static function ( $path ) {
	$bytes = file_get_contents( $path );
	$data = json_decode( $bytes, true );
	if ( ! is_array( $data ) ) { throw new RuntimeException( 'Candidate JSON is invalid: ' . $path ); }
	return $data;
};
$candidate_contract = new RendererCandidateManifest();
$pdf_manifest_path = '/absolute/pdf-candidate.json';
$raster_manifest_path = '/absolute/raster-candidate.json';
$pdf_manifest = $candidate_contract->normalize( $read_json( $pdf_manifest_path ) );
$raster_manifest = $candidate_contract->normalize( $read_json( $raster_manifest_path ) );
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
			'source_archive' => '/absolute/pdf-candidate-source.zip',
			'dependency_lock' => '/absolute/pdf-composer.lock',
		),
		'png' => array(
			'renderer' => $raster_renderer,
			'candidate_manifest' => $raster_manifest_path,
			'source_archive' => '/absolute/raster-candidate-source.zip',
			'dependency_lock' => '/absolute/raster-dependencies.lock',
		),
		'jpeg' => array(
			'renderer' => $raster_renderer,
			'candidate_manifest' => $raster_manifest_path,
			'source_archive' => '/absolute/raster-candidate-source.zip',
			'dependency_lock' => '/absolute/raster-dependencies.lock',
		),
	),
);
*/
