<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The one dynamic authoring namespace, not a general certloom_* recovery scope. */
final class WholeStateTemplateRecommendationInventory {
	const VERSION = 1;
	const PREFIX = 'certloom_template_recommendations_';
	const MAX_OPTIONS = 5000;

	public static function template_id( $name ) {
		if ( ! is_string( $name ) || 1 !== preg_match( '/^certloom_template_recommendations_([1-9][0-9]{0,18})$/D', $name, $match ) ) { return false; }
		$id = (int) $match[1];
		return $id > 0 && (string) $id === $match[1] ? $id : false;
	}

	/** Missing dynamic rows are absent from this set; a false presence flag cannot hide a captured row. */
	public function validate_records( $records ) {
		if ( ! is_array( $records ) || count( $records ) > self::MAX_OPTIONS ) { return $this->invalid(); }
		foreach ( $records as $name => $record ) {
			if ( false === self::template_id( $name ) || ! is_array( $record ) || true !== ( $record['exists'] ?? null ) ) { return $this->invalid(); }
		}
		return ( new WholeStateOptionRecord() )->validate_set( $records, array_keys( $records ) );
	}

	public function validate_snapshot( $snapshot ) {
		$keys = array( 'inventory_version', 'options', 'fingerprint' );
		if ( ! is_array( $snapshot ) || count( $snapshot ) !== count( $keys ) || array_diff( array_keys( $snapshot ), $keys ) || self::VERSION !== $snapshot['inventory_version'] || ! is_string( $snapshot['fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['fingerprint'] ) ) { return $this->invalid(); }
		$records = $this->validate_records( $snapshot['options'] );
		if ( is_wp_error( $records ) ) { return $records; }
		$fingerprint = ( new WholeStateOptionRecord() )->fingerprint_set( $records, array_keys( $records ) );
		return is_wp_error( $fingerprint ) || ! hash_equals( $snapshot['fingerprint'], $fingerprint ) ? $this->invalid() : $snapshot;
	}

	private function invalid() { return new \WP_Error( 'certloom_whole_recommendations_invalid', __( 'This recovery capture has invalid or unsupported certificate design profile metadata.', 'certloom' ) ); }
}
