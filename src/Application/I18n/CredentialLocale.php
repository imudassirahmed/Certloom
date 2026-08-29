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


	/**
	 * Curated administrator-facing locale choices for certificate content.
	 *
	 * This is intentionally a convenience catalog, not a validation allow-list.
	 * normalize_locale() remains permissive for valid WordPress/BCP47-style
	 * locale identifiers so existing/API-managed Program data is never lost.
	 * These labels describe certificate locale/direction only; they do not claim
	 * that a reviewed Certloom interface translation is installed.
	 *
	 * @param bool $include_site_default Whether to include the site-default sentinel.
	 * @return array<string,string>
	 */
	public function selection_options( $include_site_default = true ) {
		$options = array();
		if ( $include_site_default ) { $options[ self::SITE_DEFAULT ] = __( 'Site default', 'certloom' ); }
		return $options + array(
			'en_US' => __( 'English (United States)', 'certloom' ),
			'en_GB' => __( 'English (United Kingdom)', 'certloom' ),
			'ar'    => __( 'العربية — Arabic', 'certloom' ),
			'ur'    => __( 'اردو — Urdu', 'certloom' ),
			'fa_IR' => __( 'فارسی — Persian', 'certloom' ),
			'he_IL' => __( 'עברית — Hebrew', 'certloom' ),
			'es_ES' => __( 'Español — Spanish', 'certloom' ),
			'fr_FR' => __( 'Français — French', 'certloom' ),
			'de_DE' => __( 'Deutsch — German', 'certloom' ),
			'pt_BR' => __( 'Português (Brasil)', 'certloom' ),
			'it_IT' => __( 'Italiano — Italian', 'certloom' ),
			'nl_NL' => __( 'Nederlands — Dutch', 'certloom' ),
			'tr_TR' => __( 'Türkçe — Turkish', 'certloom' ),
			'ru_RU' => __( 'Русский — Russian', 'certloom' ),
			'hi_IN' => __( 'हिन्दी — Hindi', 'certloom' ),
			'bn_BD' => __( 'বাংলা — Bengali', 'certloom' ),
			'id_ID' => __( 'Bahasa Indonesia — Indonesian', 'certloom' ),
			'ms_MY' => __( 'Bahasa Melayu — Malay', 'certloom' ),
			'zh_CN' => __( '简体中文 — Chinese (Simplified)', 'certloom' ),
			'zh_TW' => __( '繁體中文 — Chinese (Traditional)', 'certloom' ),
			'ja'    => __( '日本語 — Japanese', 'certloom' ),
			'ko_KR' => __( '한국어 — Korean', 'certloom' ),
		);
	}

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
