<?php
/** Permanent six-fixture shared render-plan manifest regression. */
require_once __DIR__ . '/render-plan-reference/reference_lib.php';
$path = __DIR__ . '/fixtures/rendering/render-plan-manifest.json';
$stored = (string) file_get_contents( $path );
$generated = certloom_render_plan_reference_json();
if ( '' === $stored || ! hash_equals( hash( 'sha256', $stored ), hash( 'sha256', $generated ) ) || $stored !== $generated ) {
	throw new RuntimeException( 'Stored render-plan manifest does not match the current shared composition contract.' );
}
$manifest = json_decode( $stored, true );
if ( ! is_array( $manifest ) || 'certloom-render-plan-reference' !== (string) ( $manifest['schema'] ?? '' ) || 1 !== ( $manifest['version'] ?? null ) || 6 !== count( (array) ( $manifest['fixtures'] ?? array() ) ) ) {
	throw new RuntimeException( 'Stored render-plan manifest has an invalid shape.' );
}
foreach ( array( 'english', 'urdu-rtl', 'mixed-script', 'long-name-shrink', 'rotation', 'qr' ) as $name ) {
	$item = $manifest['fixtures'][ $name ] ?? null;
	if ( ! is_array( $item ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) ( $item['render_plan_sha256'] ?? '' ) ) ) {
		throw new RuntimeException( 'Render-plan fixture is missing its portable SHA-256: ' . $name );
	}
}
echo "PASS  Six permanent fixtures bind canonical layout/request state to one shared render plan\n";
echo "\n6 fixtures, 0 failures\n";
