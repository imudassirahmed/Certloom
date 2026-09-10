<?php
/** 1.6.28 permanent renderer primitive-capability acceptance regression. */
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/render-plan-reference/reference_lib.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererVerificationReport;

$checks = 0;
function rpc_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }

$root = __DIR__;
$golden_path = $root . '/fixtures/rendering/reference/manifest.json';
$plan_path = $root . '/fixtures/rendering/render-plan-manifest.json';
$fixture_path = $root . '/fixtures/rendering/primitives.json';
$asset_path = $root . '/fixtures/rendering/assets/primitives.png';

$golden_bytes = file_get_contents( $golden_path );
$plan_bytes = file_get_contents( $plan_path );
$golden = json_decode( $golden_bytes, true );
$plans = json_decode( $plan_bytes, true );
$fixture = json_decode( file_get_contents( $fixture_path ), true );

rpc_check( 8 === RendererAcceptanceContract::VERSION, 'Renderer acceptance must require v8 while preserving legacy primitive evidence.' );
rpc_check( 6 === RendererVerificationReport::VERSION, 'Renderer acceptance must require verification-report v6.' );
rpc_check( in_array( 'primitives', RendererAcceptanceContract::required_fixtures(), true ), 'Permanent acceptance fixtures must include primitives.' );
rpc_check( in_array( 'primitive_capability_coverage', RendererAcceptanceContract::required_checks(), true ), 'Acceptance checks must include primitive-capability coverage.' );
rpc_check( 4 === (int) $golden['version'], 'Golden reference manifest must be v4.' );
rpc_check( 7 === count( $golden['fixtures'] ), 'Golden reference manifest must contain seven permanent fixtures.' );
rpc_check( 7 === count( $plans['fixtures'] ), 'Render Plan manifest must contain seven permanent fixtures.' );
rpc_check( 'fb5a3db32dbf24b1f7554bfab6f12a3a274f314226f5bb6501733f51adc4cb6f' === hash( 'sha256', $golden_bytes ), 'Seven-fixture golden manifest SHA-256 must remain frozen.' );
rpc_check( '99a1e13fc48bf877699f22a76645be3e31f3762c8436ab6c57b6bb8a2e9da98a' === hash( 'sha256', $plan_bytes ), 'Seven-fixture Render Plan manifest SHA-256 must remain frozen.' );
rpc_check( '09af86c2160d00f830ae4609e8b8fbe9e8e4d837ff692ea4aea656f7cfc8c0ba' === hash_file( 'sha256', $asset_path ), 'Primitive fixture local raster asset must remain content-addressed by exact bytes.' );

$types = array();
$fits = array();
$rotations = array();
foreach ( $fixture['elements'] as $element ) {
    $types[] = $element['type'];
    if ( isset( $element['fit'] ) ) { $fits[] = $element['fit']; }
    if ( isset( $element['rotation_deg'] ) ) { $rotations[] = (float) $element['rotation_deg']; }
}
sort( $types ); sort( $fits ); sort( $rotations );
rpc_check( array( 'image', 'line', 'logo', 'shape', 'shape', 'signature' ) === $types, 'Primitive fixture must cover rectangle/ellipse shapes, line, image, logo and signature.' );
rpc_check( array( 'contain', 'cover', 'fill' ) === $fits, 'Primitive fixture must cover contain, cover and fill raster semantics.' );
rpc_check( in_array( 8.0, $rotations, true ) && in_array( -6.0, $rotations, true ) && in_array( 5.0, $rotations, true ) && in_array( -4.0, $rotations, true ), 'Primitive fixture must cover non-text arbitrary rotations.' );

$primitive = $golden['fixtures']['primitives'];
rpc_check( 6 === count( $primitive['elements'] ), 'Primitive golden reference must contain six non-text operations.' );
rpc_check( 'd229abc5724586fa861c068defc2ddecab557336eb6d4f0275d50e8cc6bf22aa' === $plans['fixtures']['primitives']['render_plan_sha256'], 'Primitive Render Plan hash must remain frozen.' );
rpc_check( 6 === (int) $plans['fixtures']['primitives']['operations'], 'Primitive Render Plan must contain six operations.' );

// Freeze the legacy 1.6.27 artifact + plan identities so adding primitive coverage cannot rewrite old proof.
$legacy = array(
    'english' => array('2d26e8e5cb99bcb2e23883b04fbc10960681fda5b82d9d1a995e79a11bfae1af','8491ccf0ce78f0e29649215cf0580dbfbd42d65634a35aa234f88eb391a87303','72da2fd5f6cea4e56c64e2a456c09dcac45a7828db9f7724f253d12719e04109','5ea0689597e7bff2e19e4a628d014de6d812d2d5d2f93883086e371ba19cf524','17280f247c518d265e869467df193ca16288fb0860b66cc9fbea55cfc84491cf'),
    'long-name-shrink' => array('fda779aa3b583734eec534982d2ffd62963041621d612117b76907e350da9c3d','4bb421cfc715859e0f1b445f81253fc4a24fb3b60e0bb808edda04b010f10cc9','493d725d9245294996e5413a7a39330db191e15f512874d6a2c73679312efbdb','421935d3097122986f91e7a487ee8e25a347943a176cd948fcfe7f4e3fe662b1','15041c4f589e1d254f79951846fc284e51d7a16afe86550980f30e4074a11443'),
    'mixed-script' => array('98e606bb408d98f1a25a8f40c3afaed6e25ba235e79f690fe7bddc27241ae142','a1b47f927982d79750d3ce098ce414e8e53b22264745cd8dee3dfc06b12f7678','e39e0c4c051b3b26dbaa0d77b976805a7fad6426a03e66b35024a32471178bcd','724c67566f8fb3c75496024b2c393e6370ab09bacc57a7726d1c4e7e025d1cd6','86d38eac2e6ee87850c1940c63f10ab8cf83795e0965f45ca585b66790a12cc0'),
    'qr' => array('fb66612f85797e661d027a4cfb3845fa1d2cb36248fce1de418e46ec6803d281','d696a3a873eb74a4c466a46f52a1f3c3e7b2adb00032bfcbcded612f36f651de','9c35ea7f267ac5095e3ae7a2802d638e2f35f0ea5952eaef06570c77f55c4cba','61a9f69c9a4cf0c88ce283e99e4fb44825d9468cd2cc4e73b9c476ae7e39d23a','a12b18dfb3c6b83a8997095e8d58ca5d7f0e83f44991db1e6a47148374cf41f6'),
    'rotation' => array('fd0dac4adbe67238723a9a72c89d9f0941af83a0c0a80b0b8374bfb98c715a99','a91846b6928af6b8e83abb5412e409c9e601780f4bb65dc75dea75eecfe0b53b','bf52debece53b1d02deac3b6c9630b6bbdbd0afe2acdea184f66db7c3e15f710','d4efae068cbcafaf16d823a2ca600db0e2a59a93810338632e4015e4cde5ed05','4578dc4fbdf231c2b7f4da3caae1952a306d4b115bcce00078954e4baf7ff3a8'),
    'urdu-rtl' => array('fc5c9971b90bc721cd00043579e63e4defea6069eef6a251151c016a57a715da','893d34c6562d6f8764360de56abdd157b5ddd4f17c951fd681726f96054ac636','f07e21259accd9ca084a0773c81a2e6384c0e65a2cf71b7042fa6934e949eda7','ecc1853b044377c8f7ab028fa28d36e5b30a33ba18a5e5268b2ec4ca1a15c78e','e22b7326cc0dd8b41262866aaf91fe53b90059b7e23979cf04dba86345c5e268'),
);
foreach ( $legacy as $name => $expected ) {
    $row = $golden['fixtures'][ $name ];
    rpc_check( $expected[0] === $row['svg_sha256'] && $expected[1] === $row['png_sha256'] && $expected[2] === $row['jpeg_sha256'] && $expected[3] === $row['pdf_sha256'], 'Legacy fixture ' . $name . ' must retain its frozen 1.6.27 artifact hashes.' );
    rpc_check( $expected[4] === $plans['fixtures'][ $name ]['render_plan_sha256'], 'Legacy fixture ' . $name . ' must retain its frozen 1.6.27 Render Plan hash.' );
}

$mpdf = file_get_contents( $root . '/../src/Infrastructure/Rendering/MpdfPdfRenderer.php' );
$gd = file_get_contents( $root . '/../src/Infrastructure/Rendering/GdRasterRenderer.php' );
rpc_check( false !== strpos( $mpdf, 'preserveAspectRatio="xMidYMid slice"' ) || false !== strpos( $mpdf, 'xMidYMid slice' ), 'mPDF candidate must implement local cover image placement.' );
rpc_check( false !== strpos( $mpdf, 'assert_exact_image_file' ) && false !== strpos( $gd, 'assert_exact_image_file' ), 'Both production candidates must re-prove exact local raster identity at render time.' );

$verifier = file_get_contents( $root . '/renderer-candidate/verify-bundle.py' );
rpc_check( false !== strpos( $verifier, "'primitives'" ) && false !== strpos( $verifier, "'primitive_capability_coverage':True" ), 'Independent verifier must bind the permanent primitive fixture to explicit capability evidence.' );

echo "PASS  Renderer acceptance covers the full current non-text primitive surface\n";
echo "\n{$checks} checks, 0 failures\n";
