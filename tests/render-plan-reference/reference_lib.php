<?php
/** Development-only deterministic render-plan reference builder. */
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Rendering\RenderRequestFingerprint;
use Certloom\Infrastructure\Rendering\LocalQrEncoder;

final class CertloomRenderPlanNullAssetResolver implements LocalAssetResolverInterface {
	public function resolve( $reference ) {
		throw new InvalidArgumentException( 'Permanent render-plan fixtures do not use raster assets: ' . (string) $reference );
	}
}

/** @return array<string,mixed> */
function certloom_render_plan_reference_manifest() {
	$root = dirname( __DIR__, 2 );
	$fixtures_root = $root . '/tests/fixtures/rendering';
	$golden_manifest_path = $fixtures_root . '/reference/manifest.json';
	$golden = json_decode( (string) file_get_contents( $golden_manifest_path ), true );
	if ( ! is_array( $golden ) || empty( $golden['font']['sha256'] ) ) {
		throw new RuntimeException( 'Golden rendering manifest is unavailable or missing its exact font identity.' );
	}
	$font_sha = strtolower( trim( (string) $golden['font']['sha256'] ) );
	if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $font_sha ) ) {
		throw new RuntimeException( 'Golden rendering font SHA-256 is invalid.' );
	}
	$font_manifest = array(
		'schema' => 'certloom-font-manifest',
		'version' => 2,
		'families' => array(
			'sans-serif' => array(
				'400-normal' => array( 'faces' => array( array( 'sha256' => $font_sha, 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ) ),
				'600-normal' => array( 'faces' => array( array( 'sha256' => $font_sha, 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ) ),
			),
		),
	);
	$merge_values = array(
		'english' => array( 'recipient.name' => 'Amina Rahman' ),
		'urdu-rtl' => array( 'recipient.name' => 'محمد علی' ),
		'long-name-shrink' => array( 'recipient.name' => 'Alexandria Noor Fatima Montgomery-Washington' ),
	);
	$verification_url = 'https://example.test/credential/7M4K-2Q8D-F3XP';
	$names = array( 'english', 'urdu-rtl', 'mixed-script', 'long-name-shrink', 'rotation', 'qr' );
	$validator = new LayoutValidator();
	$resolver = new LayoutResolver( null, null, null, new LocalQrEncoder() );
	$plans = new RenderPlanBuilder();
	$fingerprints = new RenderRequestFingerprint();
	$assets = new CertloomRenderPlanNullAssetResolver();
	$results = array();

	foreach ( $names as $name ) {
		$layout_path = $fixtures_root . '/' . $name . '.json';
		$layout = json_decode( (string) file_get_contents( $layout_path ), true );
		if ( ! is_array( $layout ) ) { throw new RuntimeException( 'Invalid render fixture: ' . $name ); }
		$layout_hash = $validator->hash( $layout );
		$resolved = $resolver->resolve( $layout, isset( $merge_values[ $name ] ) ? $merge_values[ $name ] : array(), $verification_url, $assets );
		$request_hash = $fingerprints->hash( $layout_hash, $resolved );
		$plan = $plans->build( $resolved, array( 'font_manifest' => $font_manifest ) );
		$plan_hash = $plans->hash( $plan );
		$results[ $name ] = array(
			'layout_sha256' => $layout_hash,
			'render_request_sha256' => $request_hash,
			'render_plan_sha256' => $plan_hash,
			'operations' => count( $plan['operations'] ),
			'page' => $plan['page'],
		);
	}

	return array(
		'schema' => 'certloom-render-plan-reference',
		'version' => 2,
		'layout_schema' => array( 'name' => 'certloom-layout', 'version' => 1 ),
		'render_plan_schema' => array( 'name' => RenderPlanBuilder::SCHEMA, 'version' => RenderPlanBuilder::VERSION ),
		'font_face_sha256' => $font_sha,
		'verification_url' => $verification_url,
		'fixtures' => $results,
	);
}

function certloom_render_plan_reference_json() {
	return ( new CanonicalJson() )->encode( certloom_render_plan_reference_manifest() ) . "\n";
}
