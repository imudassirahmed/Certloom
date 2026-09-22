<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Templates\TemplateRecommendationProfiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presentation-only recommendation metadata for certificate Templates.
 *
 * These profile tags are discovery/setup guidance. They never gate Template
 * use, issuance, rendering or verification authority.
 */
final class TemplateRecommendationRepository {
	const OPTION_PREFIX = 'certloom_template_recommendations_';

	/** @return array<int,string> */
	public function get( $template_id ) {
		$template_id = absint( $template_id );
		if ( $template_id < 1 ) { return array(); }
		$raw = get_option( self::OPTION_PREFIX . $template_id, array() );
		return $this->normalize( is_array( $raw ) ? $raw : array() );
	}

	/** @return true|\WP_Error */
	public function save( $template_id, array $profiles ) {
		$template_id = absint( $template_id );
		if ( $template_id < 1 ) {
			return new \WP_Error( 'certloom_template_recommendation_id_required', __( 'A valid certificate design is required.', 'certloom' ) );
		}
		$profiles = $this->normalize( $profiles );
		$key = self::OPTION_PREFIX . $template_id;
		if ( empty( $profiles ) ) {
			delete_option( $key );
			return true;
		}
		$saved = update_option( $key, $profiles, false );
		return $saved || $profiles === get_option( $key, array() )
			? true
			: new \WP_Error( 'certloom_template_recommendation_save_failed', __( 'Certloom could not save the recommended profiles for this design.', 'certloom' ) );
	}

	/** @return true */
	public function delete( $template_id ) {
		$template_id = absint( $template_id );
		if ( $template_id > 0 ) { delete_option( self::OPTION_PREFIX . $template_id ); }
		return true;
	}

	/** @return array<int,string> */
	public function normalize( array $profiles ) {
		return TemplateRecommendationProfiles::normalize( $profiles );
	}
}
