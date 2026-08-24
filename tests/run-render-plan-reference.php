<?php
/** Permanent seven-fixture shared render-plan manifest regression. */
require_once __DIR__ . '/render-plan-reference/reference_lib.php';
$path = __DIR__ . '/fixtures/rendering/render-plan-manifest.json';
$stored = (string) file_get_contents( $path );
$generated = certloom_render_plan_reference_json();
if ( '' === $stored || ! hash_equals( hash( 'sha256', $stored ), hash( 'sha256', $generated ) ) || $stored !== $generated ) {
	throw new RuntimeException( 'Stored render-plan manifest does not match the current shared composition contract.' );
}
$manifest = json_decode( $stored, true );
if ( ! is_array( $manifest ) || 'certloom-render-plan-reference' !== (string) ( $manifest['schema'] ?? '' ) || 2 !== ( $manifest['version'] ?? null ) || 7 !== count( (array) ( $manifest['fixtures'] ?? array() ) ) ) {
	throw new RuntimeException( 'Stored render-plan manifest has an invalid shape.' );
}

$expected_plans = array(
    'english' => '17280f247c518d265e869467df193ca16288fb0860b66cc9fbea55cfc84491cf',
    'urdu-rtl' => 'e22b7326cc0dd8b41262866aaf91fe53b90059b7e23979cf04dba86345c5e268',
    'mixed-script' => '86d38eac2e6ee87850c1940c63f10ab8cf83795e0965f45ca585b66790a12cc0',
    'long-name-shrink' => '15041c4f589e1d254f79951846fc284e51d7a16afe86550980f30e4074a11443',
    'rotation' => '4578dc4fbdf231c2b7f4da3caae1952a306d4b115bcce00078954e4baf7ff3a8',
    'qr' => 'a12b18dfb3c6b83a8997095e8d58ca5d7f0e83f44991db1e6a47148374cf41f6',
    'primitives' => 'd229abc5724586fa861c068defc2ddecab557336eb6d4f0275d50e8cc6bf22aa',
);

foreach ( array( 'english', 'urdu-rtl', 'mixed-script', 'long-name-shrink', 'rotation', 'qr', 'primitives' ) as $name ) {
	$item = $manifest['fixtures'][ $name ] ?? null;
	if ( ! is_array( $item ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) ( $item['render_plan_sha256'] ?? '' ) ) || ! hash_equals( $expected_plans[ $name ], (string) $item['render_plan_sha256'] ) ) {
		throw new RuntimeException( 'Render-plan fixture changed from its frozen portable SHA-256: ' . $name );
	}
}
echo "PASS  Seven permanent fixtures bind canonical layout/request state to one shared render plan\n";
echo "\n7 fixtures, 0 failures\n";
