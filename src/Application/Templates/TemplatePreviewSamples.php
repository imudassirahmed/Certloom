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
	const RTL = 'rtl';

	/** @return string[] */
	public static function profiles() {
		return array( self::STANDARD, self::LONG, self::RTL );
	}

	/** @return array<string,string> */
	public function snapshot_for_layout( array $layout, $profile = self::STANDARD ) {
		$profile = in_array( (string) $profile, self::profiles(), true ) ? (string) $profile : self::STANDARD;
		$known = $this->known_values( $profile );
		$snapshot = array();
		foreach ( isset( $layout['elements'] ) && is_array( $layout['elements'] ) ? $layout['elements'] : array() as $element ) {
			if ( ! is_array( $element ) || 'merge' !== (string) ( $element['type'] ?? '' ) ) { continue; }
			$token = (string) ( $element['token'] ?? '' );
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
		if ( self::RTL === $profile ) {
			$standard['recipient.name'] = 'محمد علی رحمان';
			$standard['program.name'] = 'پیشہ ورانہ اسناد پروگرام';
			$standard['program.title'] = $standard['program.name'];
			$standard['issuer.name'] = 'شمالی تعلیمی ادارہ';
			$standard['credential.status'] = 'درست';
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
