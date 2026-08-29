<?php
namespace Certloom\Application\Setup;

use Certloom\Application\Program\ProgramProfile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WIZ-02 purpose presets.
 *
 * Presets are recommendations only. They never grant authority, assert
 * compliance, create Programs/Templates, or become runtime settings by
 * themselves.
 */
final class PurposePresetEngine {
	/** @return array<string,string> */
	public function purposes() {
		return ProgramProfile::purpose_labels();
	}

	/** @return array<string,array<string,string>> */
	public function subcategories() {
		return ProgramProfile::subcategory_labels();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function recommend( $purpose, $subcategory ) {
		$purpose       = sanitize_key( (string) $purpose );
		$subcategory   = sanitize_key( (string) $subcategory );
		$purposes      = $this->purposes();
		$subcategories = $this->subcategories();
		if ( ! isset( $purposes[ $purpose ] ) ) {
			return new \WP_Error( 'certloom_setup_purpose_invalid', __( 'Choose what you will use Certloom for.', 'certloom' ) );
		}
		if ( ! isset( $subcategories[ $purpose ][ $subcategory ] ) ) {
			return new \WP_Error( 'certloom_setup_subcategory_invalid', __( 'Choose an option that belongs to the selected purpose.', 'certloom' ) );
		}

		$base = array(
			'purpose'                 => $purpose,
			'subcategory'             => $subcategory,
			'purpose_label'           => $purposes[ $purpose ],
			'subcategory_label'       => $subcategories[ $purpose ][ $subcategory ],
			'credential_type'         => 'certificate',
			'validity'                => 'administrator_choice',
			'renewal'                 => 'not_applicable',
			'verification_visibility' => 'inherit',
			'portal'                  => 'inherit',
			'sharing'                 => 'inherit',
			'email_delivery'          => 'inherit',
			'numbering_mode'          => 'manual',
			'backup_schedule'         => 'weekly',
			'terminology'             => __( 'Credential', 'certloom' ),
			'design_tone'             => __( 'Clear and professional', 'certloom' ),
			'integration_focus'       => false,
			'lifecycle_emphasis'      => false,
			'qualification_emphasis'  => false,
			'sharing_emphasis'        => false,
			'recommendation_only'     => true,
			'notice'                  => __( 'These are editable starting points, not legal, accreditation, certification, or compliance advice.', 'certloom' ),
		);

		switch ( $purpose ) {
			case 'certification_standards':
				$base['credential_type']          = 'certification';
				$base['validity']                 = 'review_validity';
				$base['renewal']                  = 'optional';
				$base['verification_visibility']  = 'verification_summary';
				$base['numbering_mode']           = 'sequential';
				$base['terminology']              = __( 'Certification', 'certloom' );
				$base['design_tone']              = __( 'Formal certification', 'certloom' );
				$base['lifecycle_emphasis']       = true;
				break;
			case 'academic_credentials':
				$base['credential_type']          = in_array( $subcategory, array( 'degree', 'diploma' ), true ) ? $subcategory : 'certificate';
				$base['validity']                 = 'permanent';
				$base['verification_visibility']  = 'verification_summary';
				$base['numbering_mode']           = 'sequential';
				$base['terminology']              = __( 'Academic credential', 'certloom' );
				$base['design_tone']              = __( 'Academic and institution-led', 'certloom' );
				$base['qualification_emphasis']   = true;
				break;
			case 'training_courses':
				$base['validity']                 = 'optional';
				$base['portal']                   = 'enabled';
				$base['email_delivery']           = 'enabled';
				$base['terminology']              = __( 'Course', 'certloom' );
				$base['design_tone']              = __( 'Course completion', 'certloom' );
				$base['integration_focus']        = true;
				break;
			case 'professional_license_membership':
				$base['credential_type']          = 'professional_license' === $subcategory ? 'license' : 'membership';
				$base['validity']                 = 'review_validity';
				$base['renewal']                  = 'optional';
				$base['verification_visibility']  = 'verification_summary';
				$base['numbering_mode']           = 'sequential';
				$base['terminology']              = 'professional_license' === $subcategory ? __( 'License', 'certloom' ) : __( 'Membership', 'certloom' );
				$base['lifecycle_emphasis']       = true;
				break;
			case 'awards_recognition':
				$base['credential_type']          = 'award';
				$base['validity']                 = 'permanent';
				$base['sharing']                  = 'enabled';
				$base['terminology']              = __( 'Award', 'certloom' );
				$base['design_tone']              = __( 'Recognition and achievement', 'certloom' );
				$base['sharing_emphasis']         = true;
				break;
			case 'events_participation':
				$base['credential_type']          = 'participation';
				$base['validity']                 = 'permanent';
				$base['sharing']                  = 'enabled';
				$base['terminology']              = __( 'Participation credential', 'certloom' );
				$base['design_tone']              = __( 'Event and participation', 'certloom' );
				$base['sharing_emphasis']         = true;
				break;
			case 'custom_other':
			default:
				$base['validity']                 = 'administrator_choice';
				$base['terminology']              = __( 'Credential', 'certloom' );
				$base['design_tone']              = __( 'Choose your own style', 'certloom' );
				break;
		}
		return $base;
	}

	/** @return array<string,string> */
	public function reference_examples( $purpose ) {
		if ( 'certification_standards' !== sanitize_key( (string) $purpose ) ) {
			return array();
		}
		return array(
			'iso_9001'  => 'ISO 9001',
			'iso_14001' => 'ISO 14001',
			'iso_27001' => 'ISO/IEC 27001',
			'iso_45001' => 'ISO 45001',
			'custom'     => __( 'Another scheme or reference', 'certloom' ),
		);
	}
}
