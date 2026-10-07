<?php
namespace Certloom\Infrastructure\Verification;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A presentation layer over the existing verifier; never a new lookup path. */
final class ProVerificationStudio {
	public static function presets() {
		return array(
			'campus' => array( 'label' => __( 'Campus', 'certloom' ), 'description' => __( 'A welcoming split layout for courses, learning pathways and academic results.', 'certloom' ), 'profiles' => array( 'training_lms', 'school_results' ) ),
			'authority' => array( 'label' => __( 'Authority', 'certloom' ), 'description' => __( 'A precise registry layout for standards, licenses and product inspection.', 'certloom' ), 'profiles' => array( 'iso_management', 'government_licensing', 'product_inspection' ) ),
			'executive' => array( 'label' => __( 'Executive', 'certloom' ), 'description' => __( 'A calm, centered layout for compliance and professional membership.', 'certloom' ), 'profiles' => array( 'corporate_compliance', 'membership_association' ) ),
			'celebration' => array( 'label' => __( 'Celebration', 'certloom' ), 'description' => __( 'A generous editorial layout for awards, recognition and participation.', 'certloom' ), 'profiles' => array( 'events_awards' ) ),
			'essential' => array( 'label' => __( 'Essential', 'certloom' ), 'description' => __( 'A compact, versatile layout that fits almost any page.', 'certloom' ), 'profiles' => array( 'general_other' ) ),
		);
	}
	public static function choices() {
		$out = array();
		foreach ( self::presets() as $key => $preset ) { $out[ $key ] = $preset['label']; }
		$out['custom'] = __( 'Custom', 'certloom' );
		return $out;
	}
	public static function recommended() {
		$profile = (string) \Ecapla_CertVerify_Settings::get( 'usage_profile', 'general_other' );
		foreach ( self::presets() as $key => $preset ) { if ( in_array( $profile, $preset['profiles'], true ) ) { return $key; } }
		return 'essential';
	}
	public static function numeric_controls() {
		return array(
			'width' => array( __( 'Maximum width', 'certloom' ), 960, 320, 1600 ),
			'padding' => array( __( 'Panel padding', 'certloom' ), 32, 0, 120 ),
			'gap' => array( __( 'Content gap', 'certloom' ), 32, 8, 96 ),
			'radius' => array( __( 'Panel corners', 'certloom' ), 20, 0, 48 ),
			'border_width' => array( __( 'Panel border width', 'certloom' ), 1, 0, 8 ),
			'title_size' => array( __( 'Heading size', 'certloom' ), 36, 18, 72 ),
			'text_size' => array( __( 'Body size', 'certloom' ), 16, 14, 24 ),
			'input_height' => array( __( 'Input and button height', 'certloom' ), 52, 44, 88 ),
			'button_radius' => array( __( 'Button corners', 'certloom' ), 12, 0, 48 ),
			'intro_width' => array( __( 'Intro column width (%)', 'certloom' ), 48, 25, 75 ),
		);
	}
	public static function color_controls() {
		return array(
			'accent' => array( __( 'Accent and button', 'certloom' ), '#1450e0' ),
			'background' => array( __( 'Panel background', 'certloom' ), '#ffffff' ),
			'ink' => array( __( 'Heading and text', 'certloom' ), '#01133d' ),
			'muted' => array( __( 'Supporting text', 'certloom' ), '#56637f' ),
			'border' => array( __( 'Border', 'certloom' ), '#dce1ea' ),
			'input_surface' => array( __( 'Input background', 'certloom' ), '#ffffff' ),
			'button_text' => array( __( 'Button text color', 'certloom' ), '#ffffff' ),
		);
	}
	/** Only bounded numbers, fixed enums and hex colors reach generated CSS. */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$preset = is_string( $input['preset'] ?? null ) ? $input['preset'] : '';
		$out = array( 'preset' => isset( self::choices()[ $preset ] ) ? $preset : self::recommended() );
		if ( 'custom' !== $out['preset'] ) { return $out; }
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$values = is_array( $input[ $device ] ?? null ) ? $input[ $device ] : array();
			$out[ $device ] = array();
			foreach ( self::numeric_controls() as $key => $rule ) {
				if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) || ! is_numeric( $values[ $key ] ) ) { continue; }
				$out[ $device ][ $key ] = max( $rule[2], min( $rule[3], (int) $values[ $key ] ) );
			}
			foreach ( array( 'layout' => array( 'split', 'stack', 'inline' ), 'align' => array( 'left', 'center', 'right' ), 'form_layout' => array( 'row', 'stack' ) ) as $key => $choices ) {
				if ( isset( $values[ $key ] ) && in_array( $values[ $key ], $choices, true ) ) { $out[ $device ][ $key ] = $values[ $key ]; }
			}
		}
		foreach ( array( 'font_family' => array( 'system', 'serif', 'mono' ), 'heading_weight' => array( '400', '500', '600', '700' ), 'shadow' => array( 'none', 'soft', 'elevated' ) ) as $key => $choices ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $choices, true ) ) { $out[ $key ] = $input[ $key ]; }
		}
		$out['colors'] = array();
		$colors = is_array( $input['colors'] ?? null ) ? $input['colors'] : array();
		foreach ( self::color_controls() as $key => $rule ) {
			if ( is_string( $colors[ $key ] ?? null ) && preg_match( '/^#[a-fA-F0-9]{6}$/D', $colors[ $key ] ) ) { $out['colors'][ $key ] = strtolower( $colors[ $key ] ); }
		}
		return $out;
	}
	private static function declarations( $values ) {
		$css = '';
		$rules = self::numeric_controls();
		foreach ( $values as $key => $value ) {
			if ( isset( $rules[ $key ] ) ) { $css .= '--cls-' . str_replace( '_', '-', $key ) . ':' . $value . ( 'intro_width' === $key ? '%' : 'px' ) . ';'; }
			elseif ( 'align' === $key ) { $css .= '--cls-align:' . $value . ';'; }
			elseif ( 'layout' === $key ) {
				$css .= '--cls-columns:' . ( 'split' === $value ? 'minmax(0,var(--cls-intro-width)) minmax(0,1fr)' : ( 'inline' === $value ? 'minmax(0,1fr) minmax(0,1.3fr)' : 'minmax(0,1fr)' ) ) . ';';
			}
			elseif ( 'form_layout' === $key ) { $css .= '--cls-form-columns:' . ( 'stack' === $value ? 'minmax(0,1fr)' : 'minmax(0,1fr) auto' ) . ';--cls-button-row:' . ( 'stack' === $value ? '3' : '1' ) . ';--cls-button-column:' . ( 'stack' === $value ? '1 / -1' : '-2 / -1' ) . ';'; }
		}
		return $css;
	}
	public static function render( $config = array(), $content = array() ) {
		// Each block editor preview is a separate REST request. A request-local
		// counter alone would reuse CSS scopes and input/result IDs in the canvas.
		$id = wp_unique_id( 'certloom-studio-' ) . '-' . wp_generate_uuid4();
		// Downgrades keep public lookup operational without granting Pro styling.
		if ( ! ProVerificationAccess::enabled() ) { return \Ecapla_CertVerify_Render::scoped_markup_html( \Ecapla_CertVerify_Render::legacy_widget( $content ), $id ); }
		\Ecapla_CertVerify_Shortcode::enqueue_assets();
		wp_enqueue_style( 'certloom-pro-studio' );
		$config = self::sanitize( $config );
		$css = '';
		if ( 'custom' === $config['preset'] ) {
			$css = '#' . $id . ',#' . $id . '>.cv-widget{' . self::declarations( $config['desktop'] );
			$fonts = array( 'system' => 'var(--cl-sans,system-ui)', 'serif' => 'var(--cl-serif,Georgia,serif)', 'mono' => 'ui-monospace,monospace' );
			$shadows = array( 'none' => 'none', 'soft' => '0 8px 32px rgba(1,19,61,.045)', 'elevated' => '0 16px 48px rgba(1,19,61,.12)' );
			if ( isset( $config['font_family'] ) ) { $css .= '--cls-font:' . $fonts[ $config['font_family'] ] . ';'; }
			if ( isset( $config['heading_weight'] ) ) { $css .= '--cls-heading-weight:' . $config['heading_weight'] . ';'; }
			if ( isset( $config['shadow'] ) ) { $css .= '--cls-shadow:' . $shadows[ $config['shadow'] ] . ';'; }
			foreach ( $config['colors'] as $key => $value ) { $css .= '--cls-' . str_replace( '_', '-', $key ) . ':' . $value . ';'; }
			$css .= '}';
			foreach ( array( 'tablet' => 1024, 'mobile' => 767 ) as $device => $width ) {
				$values = self::declarations( $config[ $device ] );
				if ( '' !== $values ) {
					$css .= '@media(max-width:' . $width . 'px){#' . $id . ',#' . $id . '>.cv-widget{' . $values . '}}';
					// Device controls follow the editor/viewport breakpoints. Applying
					// tablet values to every narrow desktop column would silently
					// override a customer's explicitly selected desktop layout.
				}
			}
		}
		// Intro markup is shared by all widgets, while the studio alone gets its
		// own structural wrapper. Authoritative results retain the global layout.
		$content['text_align'] = 'left';
		$content['studio_intro'] = true;
		$html = \Ecapla_CertVerify_Render::scoped_markup_html( \Ecapla_CertVerify_Render::legacy_widget( $content ), $id );
		return ( '' !== $css ? '<style>' . $css . '</style>' : '' ) . '<div id="' . esc_attr( $id ) . '" class="certloom-pro-frame certloom-pro-' . esc_attr( $config['preset'] ) . '">' . $html . '</div>';
	}
}
