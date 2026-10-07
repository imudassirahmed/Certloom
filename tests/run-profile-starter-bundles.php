<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
function __( $text ) { return $text; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Setup\ProfileStarterBundle;
use Certloom\Application\Templates\ProfilePresetCatalog;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Application\Templates\TemplateLibraryMetadata;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\CanonicalJson;
$checks = 0;
function bundle_check( $ok, $label ) { global $checks; if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; }
$bundles = new ProfileStarterBundle(); $catalog = new ProfilePresetCatalog(); $definitions = $catalog->all(); $keys = array(); $hashes = array();
bundle_check( 9 === count( $bundles->all() ) && 17 === count( $definitions ), 'all nine profiles have native artwork; eight have a corresponding detail layout' );
bundle_check( 9 === count( $catalog->essentials() ), 'only native essentials enter starter publication' );
bundle_check( null === $catalog->get( 'profile-school_results-details', 3 ) && null === $bundles->get( 'unknown' ), 'unknown versions/profiles are not silently substituted' );
$v1_hashes = json_decode( file_get_contents( __DIR__ . '/support/profile-preset-v1-hashes.json' ), true );
foreach ( $v1_hashes as $key => $hash ) { bundle_check( $hash === $catalog->get( $key, 1 )['layout_sha256'], 'immutable original profile v1 layout is reproducible: ' . $key ); }
foreach ( $definitions as $definition ) {
	$key = $definition['preset_key']; $layout = $definition['layout'];
	bundle_check( ! isset( $keys[ $key ] ) && ! isset( $hashes[ $definition['layout_sha256'] ] ), 'new identity/hash is unique: ' . $key ); $keys[ $key ] = true; $hashes[ $definition['layout_sha256'] ] = true;
	bundle_check( $definition['layout_sha256'] === ( new CanonicalJson() )->hash( ( new LayoutValidator() )->normalize( $layout ) ), 'layout is canonical: ' . $key );
	bundle_check( $definition['layout_sha256'] === $catalog->get( $key, $catalog::PRESET_VERSION )['layout_sha256'], 'current factory layout is reproducible: ' . $key );
	$boxes = array(); $ids = array(); $tokens = array();
	foreach ( $layout['elements'] as $element ) {
		bundle_check( ! isset( $ids[ $element['id'] ] ), 'no duplicate element ID: ' . $key ); $ids[ $element['id'] ] = true;
		bundle_check( $element['x_mm'] >= 0 && $element['y_mm'] >= 0 && $element['x_mm'] + $element['width_mm'] <= 297 && $element['y_mm'] + $element['height_mm'] <= 210, 'element stays on the page: ' . $key );
		if ( in_array( $element['type'], array( 'text', 'merge', 'qr' ), true ) ) { $boxes[] = $element; }
		if ( 'merge' === $element['type'] ) { $tokens[] = $element['token']; }
	}
	foreach ( $boxes as $i => $a ) { foreach ( array_slice( $boxes, $i + 1 ) as $b ) { bundle_check( min( $a['x_mm'] + $a['width_mm'], $b['x_mm'] + $b['width_mm'] ) <= max( $a['x_mm'], $b['x_mm'] ) || min( $a['y_mm'] + $a['height_mm'], $b['y_mm'] + $b['height_mm'] ) <= max( $a['y_mm'], $b['y_mm'] ), 'content boxes do not collide: ' . $key ); } }
	$profile = $definition['recommended_profiles'][0]; $bundle = $bundles->get( $profile );
	foreach ( $bundle['fields'] as $field ) { if ( 'date' === $field['type'] ) { bundle_check( strtotime( $field['sample'] ) <= strtotime( $bundles->sample_values( $profile )['credential.issue_date'] ), 'example issuance follows the completion/event/inspection date: ' . $key ); } }
	$values = ( new TemplatePreviewSamples() )->snapshot_for_layout( $layout, TemplatePreviewSamples::STANDARD, $profile );
	bundle_check( $bundle['example']['recipient'] === $values['recipient.name'] && $bundle['example']['program'] === $values['program.name'], 'preview uses profile-specific synthetic subjects: ' . $key );
	foreach ( $bundle['fields'] as $field ) {
		bundle_check( false === $field['required'] && false === $field['show_on_certificate'], 'a starter does not require sensitive information or grant exposure: ' . $field['key'] );
		bundle_check( in_array( $field['token'], $tokens, true ) === $definition['includes_profile_fields'], 'detail tokens align with profile fields: ' . $key );
		if ( $definition['includes_profile_fields'] ) { bundle_check( $field['sample'] === $values[ $field['token'] ], 'sample detail matches its own field: ' . $key ); }
	}
	$metadata = ( new TemplateLibraryMetadata() )->describe( array( 'machine_key' => $definition['machine_key'], 'layout' => $layout ) );
	bundle_check( array( $profile ) === $metadata['profiles'] && $bundle['style'] === $metadata['style'], 'library can discover the actual factory profile: ' . $key );
}
$school = $bundles->get( 'school_results' );
bundle_check( 'recipient' === $school['fields'][0]['writer'] && 'registration' === $school['fields'][0]['lifetime'] && '000142' === $school['fields'][0]['sample'], 'student-owned enrollment number keeps leading zeros' );
bundle_check( 'staff' === $school['fields'][2]['writer'] && 'achievement' === $school['fields'][2]['lifetime'], 'changing academic results are organization-owned, not customer answers' );
echo "$checks checks, 0 failures\n";
