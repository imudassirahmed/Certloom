<?php
namespace Certloom\Application\Program;

use Certloom\Application\I18n\CredentialLocale;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical Program Profile configuration stored inside the Program metadata envelope.
 *
 * This is configuration only. Issued Credential Versions continue to freeze the exact
 * facts actually used at issuance time. The profile never becomes credential truth.
 */
final class ProgramProfile {
	const STORAGE_KEY = 'certloom_profile_v1';
	const VERSION = 3;
	const MAX_TEMPLATE_VARIANTS = 30;

	/** @return array<string,string> */
	public static function purpose_labels() {
		return array(
			'certification_standards' => __( 'Certification & Standards', 'certloom' ),
			'academic_credentials' => __( 'Academic Credentials', 'certloom' ),
			'training_courses' => __( 'Training & Courses', 'certloom' ),
			'professional_license_membership' => __( 'Professional License / Membership', 'certloom' ),
			'awards_recognition' => __( 'Awards & Recognition', 'certloom' ),
			'events_participation' => __( 'Events & Participation', 'certloom' ),
			'custom_other' => __( 'Custom / Other', 'certloom' ),
		);
	}

	/** @return array<string,array<string,string>> */
	public static function subcategory_labels() {
		return array(
			'certification_standards' => array(
				'management_systems' => __( 'Management systems', 'certloom' ),
				'personnel' => __( 'Personnel', 'certloom' ),
				'product_service' => __( 'Product / service', 'certloom' ),
				'inspection_assessment' => __( 'Inspection / assessment', 'certloom' ),
				'custom_scheme' => __( 'Custom scheme', 'certloom' ),
			),
			'academic_credentials' => array(
				'degree' => __( 'Degree', 'certloom' ),
				'diploma' => __( 'Diploma', 'certloom' ),
				'academic_certificate' => __( 'Academic certificate', 'certloom' ),
				'course_completion' => __( 'Course completion', 'certloom' ),
				'continuing_education' => __( 'Continuing education', 'certloom' ),
			),
			'training_courses' => array(
				'online_course' => __( 'Online course', 'certloom' ),
				'workshop' => __( 'Workshop', 'certloom' ),
				'corporate_training' => __( 'Corporate training', 'certloom' ),
				'safety_compliance' => __( 'Safety / compliance', 'certloom' ),
				'cpd' => __( 'CPD', 'certloom' ),
			),
			'professional_license_membership' => array(
				'professional_license' => __( 'Professional license', 'certloom' ),
				'membership' => __( 'Membership', 'certloom' ),
			),
			'awards_recognition' => array(
				'award' => __( 'Award', 'certloom' ),
				'recognition' => __( 'Recognition', 'certloom' ),
			),
			'events_participation' => array(
				'participation' => __( 'Participation', 'certloom' ),
				'attendance' => __( 'Attendance', 'certloom' ),
				'speaker' => __( 'Speaker', 'certloom' ),
			),
			'custom_other' => array( 'custom' => __( 'Custom', 'certloom' ) ),
		);
	}

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'schema_version' => self::VERSION,
			'purpose' => 'custom_other',
			'subcategory' => 'custom',
			'credential_type' => 'certificate',
			'eligibility_context' => '',
			'credential_locale' => CredentialLocale::SITE_DEFAULT,
			'template_variants' => array(),
			'template_variant_fallback' => 'program_default',
			'validity_mode' => ProgramLifecyclePolicy::VALIDITY_LEGACY,
			'validity_value' => 0,
			'validity_unit' => 'days',
			'renewal' => 'not_applicable',
			'repeat_policy' => ProgramLifecyclePolicy::REPEAT_LIFECYCLE,
			'verification_visibility' => 'inherit',
			'portal' => 'inherit',
			'sharing' => 'inherit',
			'email_delivery' => 'inherit',
			'integration_mapping_key' => '',
			'external_scheme_id' => '',
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize( array $input ) {
		$out = self::defaults();
		$purpose = sanitize_key( (string) ( $input['purpose'] ?? $out['purpose'] ) );
		$purposes = self::purpose_labels();
		if ( ! isset( $purposes[ $purpose ] ) ) {
			return new \WP_Error( 'certloom_program_profile_purpose_invalid', __( 'Choose a valid Program purpose.', 'certloom' ) );
		}
		$out['purpose'] = $purpose;

		$subcategories = self::subcategory_labels();
		$subcategory = sanitize_key( (string) ( $input['subcategory'] ?? '' ) );
		if ( '' === $subcategory ) { $subcategory = array_key_first( $subcategories[ $purpose ] ); }
		if ( ! isset( $subcategories[ $purpose ][ $subcategory ] ) ) {
			return new \WP_Error( 'certloom_program_profile_subcategory_invalid', __( 'Choose a subcategory that belongs to the selected Program purpose.', 'certloom' ) );
		}
		$out['subcategory'] = $subcategory;

		$locale = ( new CredentialLocale() )->normalize_configured( $input['credential_locale'] ?? CredentialLocale::SITE_DEFAULT );
		if ( is_wp_error( $locale ) ) { return new \WP_Error( 'certloom_program_profile_locale_invalid', __( 'Enter a valid credential locale such as en_US, ar, fr_FR or zh_Hant.', 'certloom' ) ); }
		$out['credential_locale'] = $locale;

		$variants = $this->template_variants( $input['template_variants'] ?? array() );
		if ( is_wp_error( $variants ) ) { return $variants; }
		$out['template_variants'] = $variants;
		$fallback = trim( (string) ( $input['template_variant_fallback'] ?? 'program_default' ) );
		if ( 'program_default' !== $fallback ) {
			$fallback = ( new CredentialLocale() )->normalize_locale( $fallback );
			if ( is_wp_error( $fallback ) || ! isset( $variants[ $fallback ] ) ) { return new \WP_Error( 'certloom_program_profile_template_fallback_invalid', __( 'Choose Program default or a locale that has a configured Template variant.', 'certloom' ) ); }
		}
		$out['template_variant_fallback'] = $fallback;

		$out['credential_type'] = $this->choice( $input['credential_type'] ?? 'certificate', array( 'certificate', 'certification', 'degree', 'diploma', 'license', 'membership', 'award', 'participation', 'custom' ), 'certloom_program_profile_credential_type_invalid', __( 'Choose a valid credential type.', 'certloom' ) );
		if ( is_wp_error( $out['credential_type'] ) ) { return $out['credential_type']; }
		$eligibility = $this->text( $input['eligibility_context'] ?? '' );
		if ( $this->length( $eligibility ) > 500 ) { return new \WP_Error( 'certloom_program_profile_eligibility_too_long', __( 'Eligibility context must be 500 characters or fewer.', 'certloom' ) ); }
		$out['eligibility_context'] = $eligibility;

		$out['validity_mode'] = $this->choice( $input['validity_mode'] ?? ProgramLifecyclePolicy::VALIDITY_LEGACY, array( ProgramLifecyclePolicy::VALIDITY_LEGACY, ProgramLifecyclePolicy::VALIDITY_PERMANENT, ProgramLifecyclePolicy::VALIDITY_DURATION, ProgramLifecyclePolicy::VALIDITY_ISSUANCE ), 'certloom_program_profile_validity_mode_invalid', __( 'Choose a valid credential validity policy.', 'certloom' ) );
		if ( is_wp_error( $out['validity_mode'] ) ) { return $out['validity_mode']; }
		$out['validity_unit'] = $this->choice( $input['validity_unit'] ?? 'days', array( 'days', 'months', 'years' ), 'certloom_program_profile_validity_unit_invalid', __( 'Choose days, months or years for the Program validity duration.', 'certloom' ) );
		if ( is_wp_error( $out['validity_unit'] ) ) { return $out['validity_unit']; }
		$out['validity_value'] = absint( $input['validity_value'] ?? 0 );
		if ( ProgramLifecyclePolicy::VALIDITY_DURATION === $out['validity_mode'] && $out['validity_value'] < 1 ) { return new \WP_Error( 'certloom_program_profile_validity_value_required', __( 'Enter a positive validity duration for this Program.', 'certloom' ) ); }
		if ( ProgramLifecyclePolicy::VALIDITY_DURATION !== $out['validity_mode'] ) { $out['validity_value'] = 0; }

		$out['renewal'] = $this->choice( $input['renewal'] ?? 'not_applicable', array( 'not_applicable', 'optional', 'required' ), 'certloom_program_profile_renewal_invalid', __( 'Choose a valid renewal policy.', 'certloom' ) );
		if ( is_wp_error( $out['renewal'] ) ) { return $out['renewal']; }
		if ( ProgramLifecyclePolicy::VALIDITY_PERMANENT === $out['validity_mode'] && 'not_applicable' !== $out['renewal'] ) { return new \WP_Error( 'certloom_program_profile_permanent_renewal_conflict', __( 'A credential that does not expire cannot use an after-expiry renewal workflow.', 'certloom' ) ); }
		$out['repeat_policy'] = $this->choice( $input['repeat_policy'] ?? ProgramLifecyclePolicy::REPEAT_LIFECYCLE, array( ProgramLifecyclePolicy::REPEAT_LIFECYCLE, ProgramLifecyclePolicy::REPEAT_BATCH, ProgramLifecyclePolicy::REPEAT_SEPARATE ), 'certloom_program_profile_repeat_policy_invalid', __( 'Choose a valid repeat-issuance policy.', 'certloom' ) );
		if ( is_wp_error( $out['repeat_policy'] ) ) { return $out['repeat_policy']; }
		$out['verification_visibility'] = $this->choice( $input['verification_visibility'] ?? 'inherit', array( 'inherit', 'verification_summary', 'public', 'unlisted', 'recipient_only', 'organization_member', 'private' ), 'certloom_program_profile_visibility_invalid', __( 'Choose a valid verification visibility default.', 'certloom' ) );
		if ( is_wp_error( $out['verification_visibility'] ) ) { return $out['verification_visibility']; }
		foreach ( array( 'portal', 'sharing', 'email_delivery' ) as $field ) {
			$out[ $field ] = $this->choice( $input[ $field ] ?? 'inherit', array( 'inherit', 'enabled', 'disabled' ), 'certloom_program_profile_default_invalid', __( 'Choose a valid Program experience default.', 'certloom' ) );
			if ( is_wp_error( $out[ $field ] ) ) { return $out[ $field ]; }
		}
		foreach ( array( 'integration_mapping_key', 'external_scheme_id' ) as $field ) {
			$value = $this->text( $input[ $field ] ?? '' );
			if ( $this->length( $value ) > 191 ) { return new \WP_Error( 'certloom_program_profile_identifier_too_long', __( 'Program Profile identifiers must be 191 characters or fewer.', 'certloom' ) ); }
			$out[ $field ] = $value;
		}
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function from_metadata( array $metadata ) {
		$stored = $metadata[ self::STORAGE_KEY ] ?? null;
		if ( null !== $stored && ! is_array( $stored ) ) {
			return new \WP_Error( 'certloom_program_profile_storage_invalid', __( 'Stored Program Profile configuration is structurally invalid.', 'certloom' ) );
		}
		$raw = is_array( $stored ) ? $stored : array();
		$stored_version = isset( $raw['schema_version'] ) ? absint( $raw['schema_version'] ) : 1;
		if ( ! in_array( $stored_version, array( 1, 2, self::VERSION ), true ) ) {
			return new \WP_Error( 'certloom_program_profile_version_unsupported', __( 'Stored Program Profile configuration uses an unsupported schema version.', 'certloom' ) );
		}
		// Older profiles upgrade in memory without mutating storage. Lifecycle is
		// intentionally marked legacy so ProgramLifecyclePolicy can continue using
		// the historical programs.validity_days column until the Program is saved.
		if ( 1 === $stored_version ) {
			$raw['template_variants'] = array();
			$raw['template_variant_fallback'] = 'program_default';
		}
		if ( $stored_version < self::VERSION ) {
			$raw['schema_version'] = self::VERSION;
			$raw['validity_mode'] = ProgramLifecyclePolicy::VALIDITY_LEGACY;
			$raw['validity_value'] = 0;
			$raw['validity_unit'] = 'days';
			$raw['repeat_policy'] = ProgramLifecyclePolicy::REPEAT_LIFECYCLE;
		}
		return $this->normalize( $raw );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function with_metadata( array $metadata, array $profile ) {
		$normalized = $this->normalize( $profile );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$metadata[ self::STORAGE_KEY ] = $normalized;
		return $metadata;
	}


	/** @return array<string,string>|\WP_Error */
	private function template_variants( $value ) {
		if ( null === $value || array() === $value || '' === $value ) { return array(); }
		if ( ! is_array( $value ) || count( $value ) > self::MAX_TEMPLATE_VARIANTS ) { return new \WP_Error( 'certloom_program_profile_template_variants_invalid', __( 'Program Template variants must be a bounded locale-to-Template map.', 'certloom' ) ); }
		$out = array(); $locales = new CredentialLocale();
		foreach ( $value as $locale => $template_key ) {
			$normalized = $locales->normalize_locale( $locale );
			if ( is_wp_error( $normalized ) ) { return new \WP_Error( 'certloom_program_profile_template_variant_locale_invalid', __( 'Each Program Template variant must use a valid locale.', 'certloom' ) ); }
			$template_key = sanitize_key( (string) $template_key );
			if ( '' === $template_key || strlen( $template_key ) > 191 ) { return new \WP_Error( 'certloom_program_profile_template_variant_template_invalid', __( 'Each Program Template variant must reference a stable Template machine key.', 'certloom' ) ); }
			if ( isset( $out[ $normalized ] ) ) { return new \WP_Error( 'certloom_program_profile_template_variant_duplicate', __( 'A Program cannot define the same locale Template variant more than once.', 'certloom' ) ); }
			$out[ $normalized ] = $template_key;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @return string|\WP_Error */
	private function choice( $value, array $allowed, $code, $message ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : new \WP_Error( $code, $message );
	}

	private function text( $value ) {
		if ( function_exists( 'sanitize_text_field' ) ) { return trim( sanitize_text_field( (string) $value ) ); }
		$value = strip_tags( (string) $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
		return trim( (string) $value );
	}

	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
