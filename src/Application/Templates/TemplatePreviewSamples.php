<?php
namespace Certloom\Application\Templates;

/**
 * Deterministic, non-production merge samples for Template Preview.
 *
 * Preview values are deliberately synthetic. They exist to stress the same
 * LayoutResolver/RenderPlan pipeline without reading or mutating credential
 * records while an administrator is designing a Template.
 */
final class TemplatePreviewSamples {
	const STANDARD = 'standard';
	const LONG = 'long';
	const VERY_LONG_NAME = 'very-long-name';
	const RTL = 'rtl';
	const ORGANIZATION = 'organization';
	const LONG_ORGANIZATION = 'long-organization';
	const LONG_ISO_SCOPE = 'long-iso-scope';
	const SCHOOL_RESULT = 'school-result';

	/** @return string[] */
	public static function profiles() {
		return array( self::STANDARD, self::LONG, self::VERY_LONG_NAME, self::RTL, self::ORGANIZATION, self::LONG_ORGANIZATION, self::LONG_ISO_SCOPE, self::SCHOOL_RESULT );
	}

	/** Browser-safe deterministic synthetic values for the live Designer sample selector. @return array<string,mixed> */
	public function browser_contract() {
		$out = array();
		foreach ( self::profiles() as $profile ) {
			$out[ $profile ] = array( 'values' => $this->known_values( $profile ), 'verification_url' => $this->verification_url( $profile ) );
		}
		return array( 'default' => self::STANDARD, 'profiles' => $out );
	}

	/** @return array<string,string> */
	public function snapshot_for_layout( array $layout, $profile = self::STANDARD ) {
		$profile = in_array( (string) $profile, self::profiles(), true ) ? (string) $profile : self::STANDARD;
		$known = $this->known_values( $profile );
		$snapshot = array();
		foreach ( isset( $layout['elements'] ) && is_array( $layout['elements'] ) ? $layout['elements'] : array() as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$type = (string) ( $element['type'] ?? '' );
			$token = '';
			if ( 'merge' === $type ) { $token = (string) ( $element['token'] ?? '' ); }
			elseif ( 'barcode' === $type ) { $token = (string) ( $element['source'] ?? '' ); }
			if ( '' === $token || array_key_exists( $token, $snapshot ) ) { continue; }
			$snapshot[ $token ] = array_key_exists( $token, $known ) ? $known[ $token ] : $this->placeholder( $token, $profile );
		}
		ksort( $snapshot, SORT_STRING );
		return $snapshot;
	}

	/** @return string */
	public function verification_url( $profile = self::STANDARD ) {
		$profile = in_array( (string) $profile, self::profiles(), true ) ? (string) $profile : self::STANDARD;
		return 'https://example.invalid/certloom/verify/CL-PREVIEW-' . strtoupper( $profile );
	}

	/** @return array<string,string> */
	private function known_values( $profile ) {
		$standard = array(
			'recipient.name' => 'Alexandria Noor Rahman',
			'recipient.first_name' => 'Alexandria',
			'recipient.last_name' => 'Rahman',
			'program.name' => 'Professional Credential Program',
			'program.title' => 'Professional Credential Program',
			'issuer.name' => 'Northern Learning Institute',
			'credential.display_number' => 'CERT-2026-001',
			'credential.public_id' => '7M4K-2Q8D-F3XP',
			'credential.issue_date' => '26 August 2026',
			'credential.expiry_date' => '26 August 2028',
			'credential.grade' => 'A',
			'credential.status' => 'Valid',
			'batch.name' => 'August 2026 Cohort',
		);
		if ( self::LONG === $profile ) {
			$standard['recipient.name'] = 'Alexandria Noor Fatima Montgomery-Washington';
			$standard['program.name'] = 'Advanced Professional Credentialing and Applied Leadership Programme';
			$standard['program.title'] = $standard['program.name'];
			$standard['issuer.name'] = 'Northern International Institute for Professional Learning and Certification';
			return $standard;
		}
		if ( self::VERY_LONG_NAME === $profile ) {
			$standard['recipient.name'] = 'Alexandria Noor Fatima Montgomery-Washington de la Cruz Al-Hassan';
			$standard['recipient.first_name'] = 'Alexandria Noor Fatima';
			$standard['recipient.last_name'] = 'Montgomery-Washington de la Cruz Al-Hassan';
			return $standard;
		}
		if ( self::RTL === $profile ) {
			$standard['recipient.name'] = 'محمد علی رحمان';
			$standard['recipient.first_name'] = 'محمد علی';
			$standard['recipient.last_name'] = 'رحمان';
			$standard['program.name'] = 'پیشہ ورانہ اسناد پروگرام';
			$standard['program.title'] = $standard['program.name'];
			$standard['issuer.name'] = 'شمالی تعلیمی ادارہ';
			$standard['credential.status'] = 'درست';
			return $standard;
		}
		if ( self::ORGANIZATION === $profile ) {
			$standard['recipient.name'] = 'Apex Manufacturing (Pvt.) Limited';
			$standard['recipient.first_name'] = 'Apex Manufacturing';
			$standard['recipient.last_name'] = 'Pvt. Limited';
			$standard['program.name'] = 'Organization Compliance Credential';
			$standard['program.title'] = $standard['program.name'];
			return $standard;
		}
		if ( self::LONG_ORGANIZATION === $profile ) {
			$standard['recipient.name'] = 'International Consortium for Sustainable Manufacturing, Quality Assurance and Applied Technology (Private) Limited';
			$standard['recipient.first_name'] = 'International Consortium';
			$standard['recipient.last_name'] = 'Sustainable Manufacturing, Quality Assurance and Applied Technology';
			$standard['program.name'] = 'Organization Compliance Credential';
			$standard['program.title'] = $standard['program.name'];
			return $standard;
		}
		if ( self::LONG_ISO_SCOPE === $profile ) {
			$standard['recipient.name'] = 'Apex Precision Systems';
			$standard['program.name'] = 'ISO/IEC 27001:2022 Information Security Management System — design, development, support and managed operation of cloud-hosted business applications and associated customer support services';
			$standard['program.title'] = $standard['program.name'];
			$standard['credential.grade'] = 'Scope: design, development, support and managed operation of cloud-hosted business applications and associated customer support services';
			$standard['credential.iso_scope'] = $standard['credential.grade'];
			return $standard;
		}
		if ( self::SCHOOL_RESULT === $profile ) {
			$standard['recipient.name'] = 'Sara Ahmed Khan';
			$standard['program.name'] = 'Higher Secondary School Certificate';
			$standard['program.title'] = $standard['program.name'];
			$standard['issuer.name'] = 'Northbridge School and College';
			$standard['credential.grade'] = 'A+ · 93.4% · Distinction';
			$standard['batch.name'] = 'Class of 2026';
			return $standard;
		}
		return $standard;
	}

	/** @return string */
	private function placeholder( $token, $profile ) {
		$label = preg_replace( '/[^a-z0-9]+/i', ' ', (string) $token );
		$label = trim( preg_replace( '/\s+/', ' ', (string) $label ) );
		if ( '' === $label ) { $label = 'Custom field'; }
		$label = ucwords( strtolower( $label ) );
		if ( self::RTL === $profile ) { return 'نمونہ · ' . substr( $label, 0, 70 ); }
		return 'Sample · ' . substr( $label, 0, 78 );
	}
}
