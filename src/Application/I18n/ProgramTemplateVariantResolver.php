<?php
namespace Certloom\Application\I18n;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pure Program locale → Template identity selection. Persistence stays elsewhere. */
final class ProgramTemplateVariantResolver {
	/** @var CredentialLocale */ private $locales;
	public function __construct( ?CredentialLocale $locales = null ) { $this->locales = $locales ?: new CredentialLocale(); }

	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve( array $profile, $effective_locale ) {
		$variants = isset( $profile['template_variants'] ) && is_array( $profile['template_variants'] ) ? $profile['template_variants'] : array();
		$fallback = (string) ( $profile['template_variant_fallback'] ?? 'program_default' );
		$chain = $this->locales->fallback_chain( $effective_locale, $fallback );
		if ( is_wp_error( $chain ) ) { return $chain; }
		foreach ( $chain as $index => $locale ) {
			if ( empty( $variants[ $locale ] ) ) { continue; }
			$scope = 0 === $index ? 'program_locale_exact' : ( $locale === $this->locales->language( $effective_locale ) ? 'program_locale_language' : 'program_locale_fallback' );
			return array(
				'template_key' => (string) $variants[ $locale ],
				'matched_locale' => (string) $locale,
				'resolution_scope' => $scope,
				'fallback_chain' => $chain,
			);
		}
		return null;
	}
}
