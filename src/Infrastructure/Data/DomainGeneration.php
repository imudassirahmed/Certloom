<?php
namespace Certloom\Infrastructure\Data;

/** Monotonic/randomized marker used to reject mixed-state domain backups/exports. */
final class DomainGeneration {
	const OPTION = 'certloom_domain_data_generation';

	/** @return string */
	public function current() {
		return (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Advances before a user-data mutation. A failed later mutation may cause a
	 * harmless extra generation change; the unsafe inverse (changed data with an
	 * unchanged marker) is prevented.
	 *
	 * @return true|\WP_Error
	 */
	public function mark_before_mutation() {
		$allowed = ( new DomainMaintenanceLock() )->assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		try {
			$random = bin2hex( random_bytes( 12 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_domain_generation_random_failed', __( 'Certloom could not prepare a safe data-change marker.', 'certloom' ) );
		}
		$value = sprintf( '%.6F|%s', microtime( true ), $random );
		$saved = update_option( self::OPTION, $value, false );
		return $saved || hash_equals( $value, (string) get_option( self::OPTION, '0' ) )
			? true
			: new \WP_Error( 'certloom_domain_generation_save_failed', __( 'Certloom could not save its data-change marker, so the requested change was not started.', 'certloom' ) );
	}
}
