<?php
namespace Certloom\Infrastructure\Data;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Monotonic/randomized marker used to reject mixed-state domain backups/exports. */
final class DomainGeneration {
	const OPTION = 'certloom_domain_data_generation';

	/** Fresh convenience read for diagnostics; an uncertain marker never matches a saved baseline. @return string */
	public function current() {
		$value = $this->read_current();
		return is_wp_error( $value ) ? '' : $value;
	}

	/** A backup consistency decision must observe other requests and distinguish absence from a failed read. @return string|\WP_Error */
	public function read_current() {
		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		if ( '' !== (string) $wpdb->last_error || ( null !== $value && ( ! is_string( $value ) || '' === $value ) ) ) {
			return new \WP_Error( 'certloom_domain_generation_read_failed', __( 'Certloom could not read its data-change marker safely. Backup consistency could not be confirmed.', 'certloom' ) );
		}
		return null === $value ? '0' : $value;
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
		update_option( self::OPTION, $value, false );
		$current = $this->read_current();
		return ! is_wp_error( $current ) && hash_equals( $value, $current )
			? true
			: new \WP_Error( 'certloom_domain_generation_save_failed', __( 'Certloom could not save its data-change marker, so the requested change was not started.', 'certloom' ) );
	}
}
