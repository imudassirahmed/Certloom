<?php
namespace Certloom\Application\I18n;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical credential-locale normalization and direction policy.
 *
 * Administrator language remains owned by WordPress user locale. This class
 * only resolves the credential presentation locale frozen at issuance time.
 */
final class CredentialLocale {
	const SITE_DEFAULT = 'site_default';
	const DIRECTION_LTR = 'ltr';
	const DIRECTION_RTL = 'rtl';

	/** @return string|\WP_Error */
	public function normalize_configured( $locale ) {
		$locale = trim( (string) $locale );
		if ( '' === $locale || self::SITE_DEFAULT === strtolower( $locale ) ) { return self::SITE_DEFAULT; }
		return $this->normalize_locale( $locale );
	}

	/** @return string|\WP_Error */
	public function normalize_locale( $locale ) {
		$locale = trim( str_replace( '-', '_', (string) $locale ) );
		if ( '' === $locale || strlen( $locale ) > 32 || ! preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}$/', $locale ) ) {
			return new \WP_Error( 'certloom_credential_locale_invalid', __( 'Enter a valid credential locale such as en_US, ar, fr_FR or zh_Hant.', 'certloom' ) );
		}
		$parts = explode( '_', $locale );
		$parts[0] = strtolower( $parts[0] );
		for ( $i = 1; $i < count( $parts ); $i++ ) {
			$part = $parts[ $i ];
			if ( 4 === strlen( $part ) && ctype_alpha( $part ) ) { $parts[ $i ] = ucfirst( strtolower( $part ) ); }
			elseif ( ( 2 === strlen( $part ) && ctype_alpha( $part ) ) || ( 3 === strlen( $part ) && ctype_digit( $part ) ) ) { $parts[ $i ] = strtoupper( $part ); }
			else { $parts[ $i ] = $part; }
		}
		return implode( '_', $parts );
	}

	/** @return array<string,string>|\WP_Error */
	public function resolve( $configured, $site_locale = null ) {
		$configured = $this->normalize_configured( $configured );
		if ( is_wp_error( $configured ) ) { return $configured; }
		if ( self::SITE_DEFAULT === $configured ) {
			if ( null === $site_locale ) { $site_locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US'; }
			$effective = $this->normalize_locale( $site_locale ?: 'en_US' );
			if ( is_wp_error( $effective ) ) { return new \WP_Error( 'certloom_site_locale_invalid', __( 'The current WordPress site locale cannot be used as a credential locale.', 'certloom' ) ); }
		} else { $effective = $configured; }
		return array(
			'configured' => $configured,
			'effective' => $effective,
			'language' => $this->language( $effective ),
			'direction' => $this->direction( $effective ),
		);
	}

	/** @return string */
	public function language( $locale ) {
		$parts = explode( '_', str_replace( '-', '_', strtolower( (string) $locale ) ) );
		return (string) ( $parts[0] ?? '' );
	}

	/** @return string */
	public function direction( $locale ) {
		$locale = str_replace( '-', '_', (string) $locale );
		$parts = explode( '_', $locale );
		foreach ( $parts as $part ) {
			if ( in_array( strtolower( $part ), array( 'arab', 'hebr', 'thaa', 'nkoo', 'adlm', 'syrc', 'mand', 'rohg', 'samr' ), true ) ) { return self::DIRECTION_RTL; }
		}
		return in_array( $this->language( $locale ), array( 'ar', 'fa', 'he', 'ur', 'yi', 'ps', 'dv' ), true ) ? self::DIRECTION_RTL : self::DIRECTION_LTR;
	}

	/** @return array<int,string>|\WP_Error */
	public function fallback_chain( $effective_locale, $declared_fallback = 'program_default' ) {
		$effective = $this->normalize_locale( $effective_locale );
		if ( is_wp_error( $effective ) ) { return $effective; }
		$out = array( $effective );
		$language = $this->language( $effective );
		if ( $language && $language !== $effective ) { $out[] = $language; }
		if ( 'program_default' !== (string) $declared_fallback ) {
			$fallback = $this->normalize_locale( $declared_fallback );
			if ( is_wp_error( $fallback ) ) { return $fallback; }
			if ( ! in_array( $fallback, $out, true ) ) { $out[] = $fallback; }
			$fallback_language = $this->language( $fallback );
			if ( $fallback_language && ! in_array( $fallback_language, $out, true ) ) { $out[] = $fallback_language; }
		}
		return $out;
	}
}
