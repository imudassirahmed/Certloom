<?php
/** Stable new hashes and bounded historical float-hash compatibility. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/fake-wp/' ); }
require_once dirname( __DIR__ ) . '/src/Application/Rendering/CanonicalJson.php';

use Certloom\Application\Rendering\CanonicalJson;

$checks = 0;
$failures = 0;
function cj_check( $condition, $label ) {
	global $checks, $failures;
	++$checks;
	if ( $condition ) { echo "PASS  {$label}\n"; }
	else { ++$failures; echo "FAIL  {$label}\n"; }
}

$original = ini_get( 'serialize_precision' );
$value = array( 'z' => array( 'width' => 0.1, 'zero' => -0.0 ), 'a' => true );
$json = new CanonicalJson();
try {
	ini_set( 'serialize_precision', '-1' );
	$canonical = $json->encode( $value );
	$canonical_hash = $json->hash( $value );
	ini_set( 'serialize_precision', '17' );
	$legacy = json_encode( array( 'a' => true, 'z' => array( 'width' => 0.1, 'zero' => 0.0 ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	$legacy_hash = hash( 'sha256', $legacy );
	cj_check( $canonical === $json->encode( $value ) && $canonical_hash === $json->hash( $value ), 'new canonical bytes and hash are independent of host precision' );
	cj_check( '17' === ini_get( 'serialize_precision' ), 'encoder restores the caller precision setting' );
	cj_check( $canonical_hash !== $legacy_hash && $json->matches_hash( $value, $legacy_hash ), 'legacy 17-digit layout hash remains readable' );
	cj_check( $json->matches_hash( $value, $canonical_hash ), 'new canonical hash remains readable' );
	cj_check( ! $json->matches_hash( array( 'a' => true, 'z' => array( 'width' => 0.2, 'zero' => 0.0 ) ), $legacy_hash ), 'changed layout does not pass legacy compatibility' );
	cj_check( ! $json->matches_hash( $value, str_repeat( 'g', 64 ) ), 'malformed digest is rejected' );
} finally {
	ini_set( 'serialize_precision', $original );
}
echo "\n{$checks} canonical-precision checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
