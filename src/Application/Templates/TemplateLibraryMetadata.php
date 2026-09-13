<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Program\ProgramProfile;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Presentation-only discovery metadata for TPLUX-01.
 *
 * This never becomes Template, Program, issuance or renderer authority. Factory
 * metadata is derived from stable preset identity; customer Templates are
 * described only from their current canonical layout. Purpose tags are browsing
 * guidance, never compliance/certification assertions.
 */
final class TemplateLibraryMetadata {
	const RTL_LAYOUT_READY = 'layout_ready';
	const RTL_NEEDS_REVIEW = 'needs_review';

	/** @return array<string,array<string,string>> */
	public static function filter_labels() {
		return array(
			'style' => array(
				'formal' => __( 'Formal', 'certloom' ),
				'modern' => __( 'Modern', 'certloom' ),
				'corporate' => __( 'Corporate', 'certloom' ),
				'academic' => __( 'Academic', 'certloom' ),
				'elegant' => __( 'Elegant', 'certloom' ),
				'minimal' => __( 'Minimal', 'certloom' ),
				'custom' => __( 'Custom', 'certloom' ),
			),
			'color' => array(
				'navy_gold' => __( 'Navy + gold', 'certloom' ),
				'blue' => __( 'Blue', 'certloom' ),
				'blue_teal' => __( 'Blue + teal', 'certloom' ),
				'burgundy_gold' => __( 'Burgundy + gold', 'certloom' ),
				'warm_neutral' => __( 'Warm neutral', 'certloom' ),
				'neutral_accent' => __( 'Neutral + accent', 'certloom' ),
				'custom' => __( 'Custom / mixed', 'certloom' ),
			),
			'language' => array(
				'en_base' => __( 'English base', 'certloom' ),
				'custom' => __( 'Custom / unknown', 'certloom' ),
			),
			'rtl' => array(
				self::RTL_LAYOUT_READY => __( 'RTL layout-ready', 'certloom' ),
				self::RTL_NEEDS_REVIEW => __( 'Needs RTL review', 'certloom' ),
			),
		);
	}

	/** @return array<string,mixed> */
	public function describe( array $template ) {
		$layout = isset( $template['layout'] ) && is_array( $template['layout'] ) ? $template['layout'] : array();
		$machine_key = (string) ( $template['machine_key'] ?? '' );
		$factory = 0 === strpos( $machine_key, 'certloom-preset-' );
		$family = $factory ? $this->factory_family( $machine_key ) : '';
		$factory_map = $this->factory_map();
		$known = $family && isset( $factory_map[ $family ] ) ? $factory_map[ $family ] : null;
		$page = isset( $layout['page'] ) && is_array( $layout['page'] ) ? $layout['page'] : array();
		$recommended_profiles = TemplateRecommendationProfiles::normalize( isset( $template['recommended_profiles'] ) && is_array( $template['recommended_profiles'] ) ? $template['recommended_profiles'] : array() );

		return array(
			'factory' => $factory,
			'family' => $known ? $family : 'custom',
			'family_label' => $known ? (string) $known['family_label'] : __( 'Custom', 'certloom' ),
			'style' => $known ? (string) $known['style'] : 'custom',
			'color' => $known ? (string) $known['color'] : 'custom',
			'purposes' => $known ? array_values( $known['purposes'] ) : array( 'custom_other' ),
			'profiles' => $recommended_profiles,
			'language' => $known ? 'en_base' : 'custom',
			'rtl' => $this->rtl_readiness( $layout ),
			'page_size' => (string) ( $page['size'] ?? '' ),
			'orientation' => (string) ( $page['orientation'] ?? '' ),
		);
	}

	/** @return bool */
	public function matches( array $metadata, array $filters ) {
		foreach ( array( 'style', 'color', 'language', 'rtl', 'orientation' ) as $key ) {
			$value = sanitize_key( (string) ( $filters[ $key ] ?? '' ) );
			if ( '' !== $value && 'all' !== $value && $value !== sanitize_key( (string) ( $metadata[ $key ] ?? '' ) ) ) { return false; }
		}
		$purpose = sanitize_key( (string) ( $filters['purpose'] ?? '' ) );
		if ( '' !== $purpose && 'all' !== $purpose && ! in_array( $purpose, (array) ( $metadata['purposes'] ?? array() ), true ) ) { return false; }
		return true;
	}

	/** @return bool */
	public function fits_program( array $metadata, array $program ) {
		$purpose = sanitize_key( (string) ( $program['profile']['purpose'] ?? $program['purpose'] ?? '' ) );
		return '' !== $purpose && in_array( $purpose, (array) ( $metadata['purposes'] ?? array() ), true );
	}

	/** @return array<string,string> */
	public static function purpose_labels() { return ProgramProfile::purpose_labels(); }

	/** @return array<string,string> */
	public static function profile_labels() { return TemplateRecommendationProfiles::labels(); }

	/** @return string */
	private function factory_family( $machine_key ) {
		$key = preg_replace( '/^certloom-preset-/', '', sanitize_key( (string) $machine_key ) );
		foreach ( array_keys( $this->factory_map() ) as $family ) {
			if ( $key === $family || 0 === strpos( $key, $family . '-' ) ) { return $family; }
		}
		return '';
	}

	/** @return array<string,array<string,mixed>> */
	private function factory_map() {
		$map = array(
			'classic' => array(
				'family_label' => __( 'Classic', 'certloom' ), 'style' => 'formal', 'color' => 'navy_gold',
				'purposes' => array( 'certification_standards', 'professional_license_membership', 'awards_recognition' ),
			),
			'modern' => array(
				'family_label' => __( 'Modern', 'certloom' ), 'style' => 'modern', 'color' => 'blue',
				'purposes' => array( 'training_courses', 'events_participation', 'awards_recognition' ),
			),
			'corporate' => array(
				'family_label' => __( 'Corporate', 'certloom' ), 'style' => 'corporate', 'color' => 'blue_teal',
				'purposes' => array( 'certification_standards', 'training_courses', 'professional_license_membership' ),
			),
			'academic' => array(
				'family_label' => __( 'Academic', 'certloom' ), 'style' => 'academic', 'color' => 'burgundy_gold',
				'purposes' => array( 'academic_credentials', 'training_courses' ),
			),
			'elegant' => array(
				'family_label' => __( 'Elegant', 'certloom' ), 'style' => 'elegant', 'color' => 'warm_neutral',
				'purposes' => array( 'awards_recognition', 'events_participation', 'professional_license_membership' ),
			),
			'minimal' => array(
				'family_label' => __( 'Minimal', 'certloom' ), 'style' => 'minimal', 'color' => 'neutral_accent',
				'purposes' => array( 'training_courses', 'academic_credentials', 'custom_other' ),
			),
		);
		foreach ( CuratedPresetCatalog::concepts() as $key => $concept ) {
			$map[ CuratedPresetCatalog::KEY_PREFIX . $key ] = array(
				'family_label' => CuratedPresetCatalog::display_name( $key ),
				'style' => (string) $concept['style'],
				'color' => (string) $concept['color'],
				'purposes' => array_values( (array) $concept['purposes'] ),
			);
		}
		return $map;
	}

	/** @return string */
	private function rtl_readiness( array $layout ) {
		foreach ( (array) ( $layout['elements'] ?? array() ) as $element ) {
			if ( ! is_array( $element ) || ! in_array( (string) ( $element['type'] ?? '' ), array( 'text', 'merge', 'list' ), true ) ) { continue; }
			$direction = strtolower( (string) ( $element['style']['direction'] ?? 'auto' ) );
			if ( 'ltr' === $direction ) { return self::RTL_NEEDS_REVIEW; }
		}
		return self::RTL_LAYOUT_READY;
	}
}
