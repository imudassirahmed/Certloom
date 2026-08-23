<?php
/** 1.6.21 cross-linked PDF-04 verifier report/environment import regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererAcceptanceImporter;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererVerifierEnvironment;

$checks = 0;
function rei_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rei_throws( callable $fn ) { try { $fn(); } catch ( InvalidArgumentException $e ) { return true; } throw new RuntimeException( 'Expected InvalidArgumentException.' ); }

$environment = array(
	'schema' => RendererVerifierEnvironment::SCHEMA,
	'version' => RendererVerifierEnvironment::VERSION,
	'plugin_version' => '1.6.21',
	'runtime' => array( 'python_implementation' => 'CPython', 'python_version' => '3.13.5', 'php_version' => '8.4.23', 'platform' => 'Linux-x86_64-test' ),
	'tools' => array( 'pdfinfo' => 'pdfinfo 25.06.0', 'pdftocairo' => 'pdftocairo 25.06.0' ),
	'packages' => array( 'numpy' => '2.3.5', 'opencv' => '4.13.0', 'pillow' => '12.3.0' ),
	'scripts' => array(
		'verify_bundle_sha256' => str_repeat( '1', 64 ), 'capture_environment_sha256' => str_repeat( '2', 64 ),
		'validate_candidate_sha256' => str_repeat( '3', 64 ), 'verify_receipt_sha256' => str_repeat( '4', 64 ),
		'validate_environment_sha256' => str_repeat( '5', 64 ), 'validate_report_sha256' => str_repeat( '6', 64 ),
	),
);
$env_contract = new RendererVerifierEnvironment();
$env_hash = $env_contract->hash( $environment );
rei_check( 1 === preg_match( '/^[0-9a-f]{64}$/', $env_hash ), 'Verifier environment must have a stable canonical SHA-256.' );
$bad_env = $environment; $bad_env['scripts']['verify_bundle_sha256'] = 'bad';
rei_check( rei_throws( function () use ( $env_contract, $bad_env ) { $env_contract->normalize( $bad_env ); } ), 'Verifier environment must reject unbound script provenance.' );

$fixtures = $parity = array();
foreach ( RendererAcceptanceContract::required_fixtures() as $index => $name ) {
	$fixtures[ $name ] = array( 'render_plan_sha256' => hash( 'sha256', 'plan-' . $name ), 'pdf_sha256' => hash( 'sha256', 'pdf-' . $name ), 'png_sha256' => hash( 'sha256', 'png-' . $name ) );
	$parity[ $name ] = array( 'mean_abs_error' => 0.1, 'fraction_channels_delta_gt_10' => 0.001 );
}
$report = array(
	'schema' => RendererVerificationReport::SCHEMA, 'version' => RendererVerificationReport::VERSION,
	'verified' => true, 'qualification' => 'production-candidate', 'qualifies_pdf04' => true,
	'bundle_manifest_sha256' => str_repeat( 'a', 64 ), 'fixture_manifest_sha256' => str_repeat( 'b', 64 ), 'render_plan_manifest_sha256' => str_repeat( 'c', 64 ), 'verifier_environment_sha256' => $env_hash,
	'candidate_provenance_rechecked' => true, 'artifact_receipts_rechecked' => true, 'deterministic_runs' => true, 'pdf_geometry' => true, 'png_geometry' => true, 'qr_raster_decode' => true, 'pdf_png_visual_parity' => true,
	'renderers' => array(
		'pdf' => array( 'id' => 'mpdf-pdf', 'version' => '8.3.1+certloom.1', 'candidate_manifest_sha256' => str_repeat( 'd', 64 ) ),
		'png' => array( 'id' => 'certloom-raster', 'version' => '1', 'candidate_manifest_sha256' => str_repeat( 'e', 64 ) ),
	),
	'fixtures' => $fixtures, 'parity' => $parity,
);
$report_contract = new RendererVerificationReport();
$report_hash = $report_contract->hash( $report );
rei_check( 1 === preg_match( '/^[0-9a-f]{64}$/', $report_hash ), 'Independent verification report must have a stable canonical SHA-256.' );
$bad_report = $report; $bad_report['qualifies_pdf04'] = false;
rei_check( rei_throws( function () use ( $report_contract, $bad_report ) { $report_contract->normalize( $bad_report ); } ), 'Production report must reject inconsistent qualification.' );
$bad_report = $report; $bad_report['candidate_provenance_rechecked'] = false;
rei_check( rei_throws( function () use ( $report_contract, $bad_report ) { $report_contract->normalize( $bad_report ); } ), 'Production report must require candidate provenance recheck.' );
$bad_report = $report; $bad_report['parity']['english']['mean_abs_error'] = 1.1;
rei_check( rei_throws( function () use ( $report_contract, $bad_report ) { $report_contract->normalize( $bad_report ); } ), 'Verification report must reject parity outside the accepted threshold.' );

$checks_map = array(); foreach ( RendererAcceptanceContract::required_checks() as $key ) { $checks_map[ $key ] = true; }
$evidence = array(
	'schema' => RendererAcceptanceContract::SCHEMA, 'version' => RendererAcceptanceContract::VERSION, 'qualification' => 'production-candidate', 'plugin_version' => '1.6.21',
	'fixture_manifest_sha256' => $report['fixture_manifest_sha256'], 'render_plan_manifest_sha256' => $report['render_plan_manifest_sha256'], 'bundle_manifest_sha256' => $report['bundle_manifest_sha256'],
	'verification_report_sha256' => $report_hash, 'verifier_environment_sha256' => $env_hash,
	'renderers' => array(
		'pdf' => array( 'id' => $report['renderers']['pdf']['id'], 'version' => $report['renderers']['pdf']['version'], 'context_hash' => str_repeat( '7', 64 ), 'candidate_manifest_sha256' => $report['renderers']['pdf']['candidate_manifest_sha256'] ),
		'png' => array( 'id' => $report['renderers']['png']['id'], 'version' => $report['renderers']['png']['version'], 'context_hash' => str_repeat( '8', 64 ), 'candidate_manifest_sha256' => $report['renderers']['png']['candidate_manifest_sha256'] ),
	),
	'checks' => $checks_map, 'fixtures' => $fixtures,
);
$importer = new RendererAcceptanceImporter();
$accepted = $importer->import( $evidence, $report, $environment );
rei_check( RendererAcceptanceContract::VERSION === $accepted['version'], 'Cross-linked evidence set must import successfully when every identity agrees.' );
rei_check( 4 === RendererAcceptanceContract::VERSION, 'Renderer acceptance must be schema v4.' );
rei_check( 2 === RendererVerificationReport::VERSION, 'Independent verification report must be schema v2.' );
rei_check( 1 === RendererVerifierEnvironment::VERSION, 'Verifier environment must be schema v1.' );

$bad = $evidence; $bad['bundle_manifest_sha256'] = str_repeat( 'f', 64 );
rei_check( rei_throws( function () use ( $importer, $bad, $report, $environment ) { $importer->import( $bad, $report, $environment ); } ), 'Importer must reject a bundle hash that disagrees with the independent report.' );
$bad = $evidence; $bad['verification_report_sha256'] = str_repeat( 'f', 64 );
rei_check( rei_throws( function () use ( $importer, $bad, $report, $environment ) { $importer->import( $bad, $report, $environment ); } ), 'Importer must reject a different verification report.' );
$bad = $evidence; $bad['verifier_environment_sha256'] = str_repeat( 'f', 64 );
rei_check( rei_throws( function () use ( $importer, $bad, $report, $environment ) { $importer->import( $bad, $report, $environment ); } ), 'Importer must reject a different verifier environment.' );
$bad = $report; $bad['renderers']['pdf']['candidate_manifest_sha256'] = str_repeat( 'f', 64 );
rei_check( rei_throws( function () use ( $importer, $evidence, $bad, $environment ) { $importer->import( $evidence, $bad, $environment ); } ), 'Importer must reject renderer provenance disagreement.' );
$bad = $report; $bad['fixtures']['rotation']['pdf_sha256'] = str_repeat( 'f', 64 );
rei_check( rei_throws( function () use ( $importer, $evidence, $bad, $environment ) { $importer->import( $evidence, $bad, $environment ); } ), 'Importer must reject fixture artifact disagreement.' );
$bad = $environment; $bad['plugin_version'] = '1.6.20';
$bad_hash = $env_contract->hash( $bad ); $bad_report = $report; $bad_report['verifier_environment_sha256'] = $bad_hash; $bad_evidence = $evidence; $bad_evidence['verifier_environment_sha256'] = $bad_hash; $bad_evidence['verification_report_sha256'] = $report_contract->hash( $bad_report );
rei_check( rei_throws( function () use ( $importer, $bad_evidence, $bad_report, $bad ) { $importer->import( $bad_evidence, $bad_report, $bad ); } ), 'Importer must reject verifier environments from a different Certloom version.' );

$dev = $report; $dev['qualification'] = 'development-reference'; $dev['qualifies_pdf04'] = false; $dev['candidate_provenance_rechecked'] = false; $dev['artifact_receipts_rechecked'] = false;
rei_check( 'development-reference' === $report_contract->normalize( $dev )['qualification'], 'Development reference reports remain valid verifier regression evidence.' );
$dev_evidence = $evidence; $dev_evidence['verification_report_sha256'] = $report_contract->hash( $dev );
rei_check( rei_throws( function () use ( $importer, $dev_evidence, $dev, $environment ) { $importer->import( $dev_evidence, $dev, $environment ); } ), 'Development reference reports can never be imported as PDF-04 production acceptance.' );

rei_check( is_file( __DIR__ . '/renderer-candidate/verify-production-bundle.sh' ), 'Standard production verification entrypoint must be source-controlled.' );
rei_check( is_file( __DIR__ . '/renderer-candidate/validate-verification-report.php' ), 'Verification report validator must be source-controlled.' );
rei_check( is_file( __DIR__ . '/renderer-candidate/validate-verifier-environment.php' ), 'Verifier environment validator must be source-controlled.' );

echo "PASS  PDF-04 import cross-links independent verifier report, toolchain provenance, candidates, fixtures and bundle identity\n";
echo "\n{$checks} checks, 0 failures\n";
