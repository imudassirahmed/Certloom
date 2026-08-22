<?php
namespace Certloom\Infrastructure\Security;

/** Shared operation-scoped public abuse limiter for verification, claims, PDF and future APIs. */
final class RateLimiter {
	const DEFAULT_LIMIT = 30;

	/** @return bool True when the caller must be rate-limited. */
	public function is_limited( $scope = 'verify', $fail_closed = false, $limit = null, $identifier = null ) {
		$scope = sanitize_key( (string) $scope );
		$scope = '' === $scope ? 'verify' : $scope;
		$limit = null === $limit ? self::DEFAULT_LIMIT : absint( $limit );
		$limit = max( 1, absint( apply_filters( 'certloom_rate_limit', $limit, $scope ) ) );
		if ( null === $identifier ) {
			$identifier = $this->default_identifier();
		}
		$identifier = (string) apply_filters( 'certloom_rate_limit_identifier', (string) $identifier, $scope );
		$bucket = (string) floor( time() / MINUTE_IN_SECONDS );
		$key = 'certloom_rl_' . $scope . '_' . hash_hmac( 'sha256', $identifier . '|' . $bucket, wp_salt( 'nonce' ) );

		if ( wp_using_ext_object_cache() ) {
			$group = 'certloom';
			if ( wp_cache_add( $key, 1, $group, 70 ) ) {
				return false;
			}
			$count = wp_cache_incr( $key, 1, $group );
			if ( false === $count ) {
				$reset = wp_cache_set( $key, 1, $group, 70 );
				return $fail_closed && false === $reset;
			}
			return (int) $count > $limit;
		}

		global $wpdb;
		$option_key = 'certloom_rl_' . $scope . '_' . $bucket . '_' . substr( hash_hmac( 'sha256', $identifier, wp_salt( 'nonce' ) ), 0, 40 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic internal counter avoids transient races.
		$increment = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				$wpdb->options,
				$option_key
			)
		);
		wp_cache_delete( $option_key, 'options' );
		if ( false === $increment ) {
			return (bool) $fail_closed;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the just-updated internal counter directly.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option_key ) );
		$this->probabilistic_cleanup( $scope, $bucket );
		return $count > $limit;
	}

	/** @return string */
	private function default_identifier() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip = preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip );
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ua = function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 200 ) : substr( $ua, 0, 200 );
		return (string) $ip . '|' . (string) $ua;
	}

	/** @return void */
	private function probabilistic_cleanup( $scope, $bucket ) {
		if ( 1 !== wp_rand( 1, 100 ) ) { return; }
		global $wpdb;
		$current_prefix = $wpdb->esc_like( 'certloom_rl_' . $scope . '_' . $bucket . '_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only expired Certloom-owned limiter buckets.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'certloom_rl_' . $scope . '_' ) . '%',
				$current_prefix
			)
		);
	}
}
