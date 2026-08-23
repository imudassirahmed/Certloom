<?php
/**
 * COPY this file outside the Certloom source tree and replace the placeholders.
 * Never edit this example into a working candidate inside a shipped plugin.
 *
 * The external candidate package must provide concrete RendererInterface
 * implementations. Candidate manifests/source archives/dependency locks must be
 * frozen before execution and their hashes must agree exactly.
 */
use Certloom\Application\Rendering\RendererCandidateManifest;

throw new RuntimeException(
	'Example only. Copy outside the Certloom tree and configure the exact dependency-prefixed PDF and raster RendererInterface candidates before running produce-production-bundle.php.'
);

/*
return array(
	'production_candidate_ack' => 'CERTLOOM_PDF04_PRODUCTION_CANDIDATE',
	'font_root' => '/absolute/local/content-addressed-font-root',
	'formats' => array(
		'pdf' => array(
			'renderer' => $pdf_renderer,
			'candidate_manifest' => '/absolute/pdf-candidate.json',
			'source_archive' => '/absolute/pdf-candidate-source.zip',
			'dependency_lock' => '/absolute/pdf-composer.lock',
		),
		'png' => array(
			'renderer' => $raster_renderer,
			'candidate_manifest' => '/absolute/raster-candidate.json',
			'source_archive' => '/absolute/raster-candidate-source.zip',
			'dependency_lock' => '/absolute/raster-composer.lock',
		),
		'jpeg' => array(
			'renderer' => $raster_renderer,
			'candidate_manifest' => '/absolute/raster-candidate.json',
			'source_archive' => '/absolute/raster-candidate-source.zip',
			'dependency_lock' => '/absolute/raster-composer.lock',
		),
	),
);
*/
