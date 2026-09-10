<?php
/** Phase 4 completion-candidate proof that historical certloom-layout v1-v3 authority remains identical to alpha.4.8. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutSchemaUpgrader;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Domain\Rendering\LayoutSchema;

$pass = 0;
$fail = 0;
function p4hist( $ok, $label ) {
	global $pass, $fail;
	if ( $ok ) {
		++$pass;
		echo "PASS  {$label}\n";
		return;
	}
	++$fail;
	echo "FAIL  {$label}\n";
}

$root = dirname( __DIR__ );
$manifest_path = __DIR__ . '/fixtures/phase4-historical-immutability-alpha4.8.json';
$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
p4hist( is_array( $manifest ), 'frozen alpha.4.8 historical baseline manifest is readable' );
p4hist( 'certloom-phase4-historical-immutability-baseline' === ( $manifest['schema'] ?? '' ) && 1 === ( $manifest['version'] ?? 0 ), 'historical baseline uses the explicit bounded manifest contract' );
p4hist( '1.7.0-alpha.4.8' === ( $manifest['baseline_plugin_version'] ?? '' ), 'historical baseline identifies the exact alpha.4.8 checkpoint' );
p4hist( 'c71d2a7a62f3301dcf9bebf42dbc38ab51768dd70a981c334beec71d1f1b4e2b' === ( $manifest['baseline_source_zip_sha256'] ?? '' ), 'historical baseline records the exact alpha.4.8 source ZIP SHA-256' );
p4hist( array( 1, 2, 3 ) === ( $manifest['historical_schema_versions'] ?? array() ), 'historical proof is explicitly limited to schemas 1 through 3' );

$expected_types = array(
	'1' => array( 'text', 'merge', 'image', 'logo', 'signature', 'shape', 'line', 'qr' ),
	'2' => array( 'text', 'merge', 'image', 'logo', 'signature', 'shape', 'line', 'qr', 'barcode' ),
	'3' => array( 'text', 'merge', 'image', 'logo', 'signature', 'shape', 'line', 'qr', 'barcode', 'list' ),
);
$current_types = array();
foreach ( array( 1, 2, 3 ) as $version ) {
	$current_types[ (string) $version ] = LayoutSchema::element_types( $version );
}
p4hist( $expected_types === ( $manifest['element_types'] ?? array() ), 'alpha.4.8 baseline freezes the complete historical type inventory' );
p4hist( $expected_types === $current_types, 'completion candidate preserves the exact schema 1-3 type inventory' );

$source_hashes = $manifest['authority_source_sha256'] ?? array();
$source_match = 7 === count( $source_hashes );
foreach ( $source_hashes as $relative => $expected_hash ) {
	$path = $root . '/' . $relative;
	$source_match = $source_match && is_file( $path ) && hash_equals( (string) $expected_hash, (string) hash_file( 'sha256', $path ) );
}
p4hist( $source_match, 'seven historical schema/validator/resolver/registry/preset authority sources are byte-identical to alpha.4.8' );

$canonical = new CanonicalJson();
$validator = new LayoutValidator();
$upgrader = new LayoutSchemaUpgrader( $validator );
$catalog = new PresetCatalog();
$expected_layout_hashes = $manifest['preset_layout_hashes'] ?? array();
$current_layout_hashes = array();
foreach ( array( 1, 2 ) as $preset_version ) {
	foreach ( $catalog->all() as $default_definition ) {
		$key = (string) $default_definition['preset_key'];
		$definition = $catalog->get( $key, $preset_version );
		if ( ! is_array( $definition ) ) {
			continue;
		}
		$v1 = $validator->normalize( $definition['layout'] );
		foreach ( array( 1, 2, 3 ) as $schema_version ) {
			$layout = 1 === $schema_version ? $v1 : $upgrader->upgrade( $v1, $schema_version );
			$id = sprintf( 'preset-v%d/%s/schema-%d', $preset_version, $key, $schema_version );
			$current_layout_hashes[ $id ] = $canonical->hash( $layout );
		}
	}
}
ksort( $current_layout_hashes, SORT_STRING );
ksort( $expected_layout_hashes, SORT_STRING );
p4hist( 144 === count( $expected_layout_hashes ), 'alpha.4.8 baseline freezes 144 canonical historical preset/schema artifacts' );
p4hist( 144 === count( $current_layout_hashes ), 'completion candidate reproduces the complete 144-artifact historical corpus' );
p4hist( $expected_layout_hashes === $current_layout_hashes, 'all 144 schema 1-3 canonical preset hashes are byte-for-byte unchanged from alpha.4.8' );

$stepwise_ok = true;
foreach ( $catalog->all() as $definition ) {
	$v1 = $validator->normalize( $definition['layout'] );
	$v2 = $upgrader->upgrade( $v1, 2 );
	$v3_step = $upgrader->upgrade( $v2, 3 );
	$v3_direct = $upgrader->upgrade( $v1, 3 );
	$stepwise_ok = $stepwise_ok && hash_equals( $canonical->hash( $v3_step ), $canonical->hash( $v3_direct ) );
}
p4hist( $stepwise_ok, 'every factory layout retains identical direct and stepwise v1→v3 canonical upgrade output' );

$reference_manifest = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/rendering/render-plan-manifest.json' ), true );
$reference_ok = is_array( $reference_manifest ) && isset( $reference_manifest['fixtures'] ) && 7 === count( $reference_manifest['fixtures'] );
foreach ( (array) ( $reference_manifest['fixtures'] ?? array() ) as $name => $fixture ) {
	$name = (string) $name;
	$path = __DIR__ . '/fixtures/rendering/' . $name . '.json';
	if ( '' === $name || ! is_file( $path ) ) {
		$reference_ok = false;
		continue;
	}
	$layout = json_decode( (string) file_get_contents( $path ), true );
	$reference_ok = $reference_ok && is_array( $layout ) && 1 === (int) ( $layout['_meta']['schema_version'] ?? 0 );
}
p4hist( $reference_ok, 'the seven permanent renderer reference layouts remain historical schema-1 fixtures' );

p4hist( ! file_exists( $root . '/resources/renderer/accepted.json' ) && ! file_exists( $root . '/resources/domain/accepted.json' ), 'historical proof does not manufacture renderer/domain acceptance authority' );

echo "\n{$pass} Phase 4 historical-immutability checks, {$fail} failures\n";
exit( $fail ? 1 : 0 );
