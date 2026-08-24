<?php
/**
 * PDF-04 production-candidate bootstrap template.
 *
 * COPY this file outside the Certloom source tree. Then:
 * 1. replace the five /absolute/... placeholders below;
 * 2. remove the guard;
 * 3. run produce-production-bundle.php against the copied bootstrap.
 *
 * The copied file deliberately loads its helper library from the configured
 * Certloom source root rather than from __DIR__, because __DIR__ is the external
 * operator workspace after the copy.
 */
throw new RuntimeException(
	'Example only. Copy outside the Certloom tree, configure absolute paths, remove this guard, and then use it only for source-side PDF-04 evaluation.'
);

/*
$certloom_source_root = '/absolute/path/to/certloom-source/certloom';
require_once $certloom_source_root . '/tests/renderer-candidate/production-bootstrap-lib.php';

$factory = new CertloomProductionCandidateBootstrapFactory( $certloom_source_root );
return $factory->build( array(
	'pdf_freeze_dir' => '/absolute/frozen/pdf-candidate',
	'raster_freeze_dir' => '/absolute/frozen/gd-candidate',
	'font_root' => '/absolute/local/content-addressed-font-root',
	'mpdf_temp_dir' => '/absolute/local/mpdf-temp',
) );
*/
