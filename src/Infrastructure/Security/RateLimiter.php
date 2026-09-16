<?php
namespace Certloom\Infrastructure\Security;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Shared operation-scoped public abuse limiter for verification, claims, PDF and future APIs. */
final class RateLimiter {
	const DEFAULT_LIMIT = 30;
	const NETWORK_MULTIPLIER = 4;

	/** @return bool True when the caller must be rate-limited. */
	public function is_limited( $scope = 'verify', $fail_closed = false, $limit = null, $identifier = null ) {
		$scope = sanitize_key( (string) $scope );
		$scope = '' === $scope ? 'verify' : $scope;
		$limit = null === $limit ? self::DEFAULT_LIMIT : absint( $limit );
		$limit = max( 1, absint( apply_filters( 'certloom_rate_limit', $limit, $scope ) ) );
		$bucket = $this->bucket();

		/*
		 * Explicit identifiers remain a single caller-owned bucket for internal
		 * integrations/tests. Public callers use two independent buckets:
		 * a normal client fingerprint plus a wider network-only ceiling. A
		 * spoofed User-Agent can therefore no longer create unlimited fresh
		 * capacity, while shared IPv4/NAT and IPv6 /64 networks retain reasonable
		 * burst headroom.
		 */
		if ( null !== $identifier ) {
			$identifier = (string) apply_filters( 'certloom_rate_limit_identifier', (string) $identifier, $scope );
			return $this->bucket_is_limited( $scope, 'explicit:' . $identifier, $limit, $fail_closed, $bucket, true );
		}

		$network = $this->network_identity();
		$network = (string) apply_filters( 'certloom_rate_limit_network_identifier', $network, $scope );
		$network_limit = max(
			$limit,
			absint( apply_filters( 'certloom_rate_limit_network_limit', $limit * self::NETWORK_MULTIPLIER, $scope, $limit ) )
		);
		if ( $this->bucket_is_limited( $scope, 'network:' . $network, $network_limit, $fail_closed, $bucket, false ) ) {
			return true;
		}

		$fingerprint = $network . '|ua:' . $this->user_agent_fingerprint();
		$fingerprint = (string) apply_filters( 'certloom_rate_limit_identifier', $fingerprint, $scope );
		return $this->bucket_is_limited( $scope, 'client:' . $fingerprint, $limit, $fail_closed, $bucket, true );
	}

	/** @return bool */
	private function bucket_is_limited( $scope, $identifier, $limit, $fail_closed, $bucket, $cleanup ) {
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
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option_key ) );
		if ( $cleanup ) {
			$this->probabilistic_cleanup( $bucket );
		}
		if ( null === $count ) {
			return (bool) $fail_closed;
		}
		return (int) $count > $limit;
	}

	/** @return string */
	private function bucket() {
		return (string) intdiv( time(), MINUTE_IN_SECONDS );
	}

	/** @return string */
	private function network_identity() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'certloom_rate_limit_client_ip', $ip );
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return 'raw:' . substr( hash( 'sha256', $ip ), 0, 24 );
		}

		if ( 16 === strlen( $packed ) ) {
			/* Normalize IPv4-mapped IPv6 so one address cannot receive two identities. */
			if ( substr( $packed, 0, 12 ) === str_repeat( "\x00", 10 ) . "\xff\xff" ) {
				return 'v4:' . bin2hex( substr( $packed, 12, 4 ) );
			}
			/* IPv6 clients are network-bounded at /64 to resist address rotation. */
			return 'v6:' . bin2hex( substr( $packed, 0, 8 ) );
		}
		return 'v4:' . bin2hex( $packed );
	}

	/** @return string */
	private function user_agent_fingerprint() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ua = function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 200 ) : substr( $ua, 0, 200 );
		return substr( hash( 'sha256', (string) $ua ), 0, 16 );
	}

	/** @return void */
	private function probabilistic_cleanup( $bucket ) {
		if ( 1 !== wp_rand( 1, 50 ) ) { return; }
		global $wpdb;
		$current = '%' . $wpdb->esc_like( '_' . $bucket . '_' ) . '%';
		$previous = '%' . $wpdb->esc_like( '_' . (string) ( (int) $bucket - 1 ) . '_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded deletion of expired Certloom-owned limiter rows across all scopes.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT 500',
				$wpdb->options,
				$wpdb->esc_like( 'certloom_rl_' ) . '%',
				$current,
				$previous
			)
		);
	}
}
