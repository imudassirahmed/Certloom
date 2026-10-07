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
		return ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->change( $key, static function () use ( $profiles ) { return $profiles ? maybe_serialize( $profiles ) : null; } );
	}

	/** @return true|\WP_Error */
	public function delete( $template_id ) {
		$template_id = absint( $template_id );
		return $template_id > 0 ? ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->change( self::OPTION_PREFIX . $template_id, static function () { return null; } ) : true;
	}

	/** @return array<int,string> */
	public function normalize( array $profiles ) {
		return TemplateRecommendationProfiles::normalize( $profiles );
	}
}
