<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Setup\ProfileStarterBundle;
use Certloom\Domain\Rendering\LayoutSchema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Additive profile artwork; old factory keys/versions and customer revisions remain unchanged. */
final class ProfilePresetCatalog {
	const PRESET_VERSION = 2;
	const KEY_PREFIX = 'profile-';

	public function all() {
		$out = array();
		foreach ( ( new ProfileStarterBundle() )->all() as $profile => $bundle ) {
			$out[] = $this->definition( $profile, $bundle, false );
			if ( $bundle['fields'] ) { $out[] = $this->definition( $profile, $bundle, true ); }
		}
		return $out;
	}
	public function essentials() {
		$out = array();
		foreach ( ( new ProfileStarterBundle() )->all() as $profile => $bundle ) { $out[] = $this->definition( $profile, $bundle, false ); }
		return $out;
	}
	public function get( $key, $version = self::PRESET_VERSION ) {
		if ( ! in_array( (int) $version, array( 1, 2 ), true ) ) { return null; }
		foreach ( ( new ProfileStarterBundle() )->all() as $profile => $bundle ) {
			if ( $key === self::KEY_PREFIX . $profile . '-essential' ) { return $this->definition( $profile, $bundle, false, (int) $version ); }
			if ( $bundle['fields'] && $key === self::KEY_PREFIX . $profile . '-details' ) { return $this->definition( $profile, $bundle, true, (int) $version ); }
		}
		return null;
	}
	/** Discovery never needs to build/validate/hash all layouts for each library card. */
	public static function metadata() {
		$out = array();
		foreach ( ( new ProfileStarterBundle() )->all() as $profile => $bundle ) {
			foreach ( array( false, true ) as $details ) {
				if ( $details && ! $bundle['fields'] ) { continue; }
				$key = self::KEY_PREFIX . $profile . ( $details ? '-details' : '-essential' );
				$out[ $key ] = array( 'family_label' => $bundle['name'], 'style' => $bundle['style'], 'color' => $bundle['color'], 'purposes' => array( $bundle['purpose'] ), 'profiles' => array( $profile ) );
			}
		}
		return $out;
	}

	private function definition( $profile, array $bundle, $details, $version = self::PRESET_VERSION ) {
		$key = self::KEY_PREFIX . $profile . ( $details ? '-details' : '-essential' );
		$layout = ( new LayoutValidator() )->normalize( $this->layout( $key, $bundle, $details, $version ) );
		return array( 'preset_key' => $key, 'preset_version' => $version, 'machine_key' => 'certloom-preset-' . $key, 'name' => $bundle['name'] . ( $details ? ' — With details' : ' — Essentials' ), 'description' => $details ? 'Profile-specific field layout. Review the field sources, Program requirements and public exposure before assigning this design.' : 'A simple profile-specific starting point using recipient, Program, issuer and native credential facts.', 'family_key' => $key, 'family_name' => $bundle['name'], 'page_size' => 'A4', 'orientation' => 'landscape', 'variant_key' => 'a4-landscape', 'style' => $bundle['style'], 'color' => $bundle['color'], 'purposes' => array( $bundle['purpose'] ), 'recommended_profiles' => array( $profile ), 'includes_profile_fields' => (bool) $details, 'layout' => $layout, 'layout_sha256' => ( new CanonicalJson() )->hash( $layout ) );
	}

	private function layout( $seed, array $bundle, $details, $version ) {
		$layout = TemplateDefaults::blank( LayoutSchema::PAGE_A4, LayoutSchema::ORIENTATION_LANDSCAPE );
		// Freeze the schema used by both factory versions; future default-schema
		// upgrades must not change the hashes of historical profile layouts.
		$layout['_meta']['schema_version'] = LayoutSchema::VERSION_V4;
		$elements = array(); $accent = $bundle['accent'];
		$add = function ( $key, $type, $x, $y, $w, $h, array $extra ) use ( &$elements, $seed ) {
			$hex = substr( hash( 'sha256', 'certloom-profile-v1:' . $seed . ':' . $key ), 0, 32 ); $hex[12] = '4'; $hex[16] = '8';
			$id = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
			$elements[] = array_merge( array( 'id' => $id, 'type' => $type, 'x_mm' => (float) $x, 'y_mm' => (float) $y, 'width_mm' => (float) $w, 'height_mm' => (float) $h, 'z' => count( $elements ), 'rotation_deg' => 0.0, 'opacity' => 1.0 ), $extra );
		};
		$text = function ( $key, $type, $value, $x, $y, $w, $h, $size, $weight = 400, $color = '#17233C' ) use ( $add ) {
			$style = array( 'font_family' => 'sans-serif', 'font_size_pt' => (float) $size, 'font_weight' => $weight, 'italic' => false, 'color' => $color, 'align' => 'left', 'vertical_align' => 'middle', 'direction' => 'auto', 'line_height' => 1.2, 'letter_spacing_pt' => 0.0, 'overflow' => 'shrink' );
			$add( $key, $type, $x, $y, $w, $h, array( 'merge' === $type ? 'token' : 'text' => $value, 'style' => $style ) );
		};
		$add( 'accent', 'shape', 18, 20, 3, 146, array( 'shape' => 'rectangle', 'fill' => $accent, 'stroke' => '#00000000', 'stroke_width_mm' => 0.0 ) );
		$text( 'issuer', 'merge', 'issuer.name', 30, 23, 226, 12, 10, 600, $accent );
		$text( 'title', 'text', $bundle['title'], 30, 44, 226, 23, 25, 600 );
		$text( 'award', 'text', 'Issued to', 30, 74, 226, 7, 9, 400, '#667085' );
		$text( 'recipient', 'merge', 'recipient.name', 30, 86, 226, 23, 28, 700 );
		$text( 'program', 'merge', 'program.name', 30, 117, 226, 17, 17, 600, $accent );
		if ( $details ) {
			$count = count( $bundle['fields'] ); $width = ( 226 - 10 * ( $count - 1 ) ) / $count;
			foreach ( $bundle['fields'] as $index => $field ) {
				$x = 30 + $index * ( $width + 10 );
				$text( 'label-' . $field['key'], 'text', $field['label'], $x, 143, $width, 7, 8, 600, '#667085' );
				$text( 'value-' . $field['key'], 'merge', $field['token'], $x, 152, $width, 19, 11, 500 );
				// v1 remains reproducible for immutable history. v2 keeps longer
				// field values readable by wrapping them within their existing box.
				if ( $version >= 2 ) { $elements[ count( $elements ) - 1 ]['style']['overflow'] = 'wrap'; }
			}
		}
		$text( 'number-label', 'text', 'CREDENTIAL NUMBER', 30, 179, 91, 5, 6, 600, '#667085' );
		$text( 'number', 'merge', 'credential.display_number', 30, 185, 91, 8, 8, 600 );
		$text( 'date-label', 'text', 'ISSUED', 134, 179, 89, 5, 6, 600, '#667085' );
		$text( 'date', 'merge', 'credential.issue_date', 134, 185, 89, 8, 8 );
		$add( 'qr', 'qr', 248, 175, 18, 18, array( 'source' => 'verification_url', 'error_correction' => 'H', 'quiet_zone_modules' => 4 ) );
		$layout['elements'] = $elements;
		return $layout;
	}
}
