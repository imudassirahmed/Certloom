<?php
namespace Certloom\Infrastructure\Data;

/**
 * Owner-matched maintenance lock shared by domain restore/cutover and legacy write guards.
 *
 * Lock payloads are stored as:
 *   owner|locked_at|expires_at|purpose
 *
 * The previous three-part payload (owner|locked_at|purpose) remains readable and is
 * interpreted using DEFAULT_TTL so upgrades cannot strand an in-flight maintenance job.
 */
final class DomainMaintenanceLock {
	const OPTION      = 'certloom_domain_maintenance_lock';
	const DEFAULT_TTL = 2100; // 35 minutes; bounded maintenance jobs refresh each slice.
	const MAX_TTL     = 3600;

	/** @var int Allows only the maintenance worker's own protected writes. */
	private static $internal_depth = 0;

	/** @return true|\WP_Error */
	public function assert_writes_allowed() {
		if ( self::$internal_depth > 0 ) {
			return true;
		}

		return $this->active()
			? new \WP_Error(
				'certloom_domain_maintenance_write_locked',
				__( 'Certloom maintenance is in progress. Credential and configuration changes are temporarily paused.', 'certloom' )
			)
			: true;
	}

	/** @return true|\WP_Error */
	public function acquire( $owner_token, $purpose = 'maintenance', $ttl = self::DEFAULT_TTL ) {
		global $wpdb;

		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$purpose = $this->purpose( $purpose );
		$ttl     = $this->ttl( $ttl );
		$now     = time();
		$value   = $this->payload_value( $owner_token, $now, $ttl, $purpose );

		if ( add_option( self::OPTION, $value, '', false ) ) {
			return true;
		}

		$stored = (string) get_option( self::OPTION, '' );
		$lock   = $this->parse_payload( $stored );

		if ( $lock && hash_equals( $lock['owner'], $owner_token ) ) {
			return $this->touch( $owner_token, $purpose, $ttl );
		}

		if ( $lock && $lock['expires_at'] > $now ) {
			return new \WP_Error(
				'certloom_domain_maintenance_locked',
				__( 'Another Certloom maintenance operation is already in progress.', 'certloom' )
			);
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$value,
				self::OPTION,
				$stored
			)
		);
		$this->clear_option_cache();

		return 1 === (int) $updated
			? true
			: new \WP_Error(
				'certloom_domain_maintenance_locked',
				__( 'Another Certloom maintenance operation acquired the lock first.', 'certloom' )
			);
	}

	/** @return true|\WP_Error */
	public function touch( $owner_token, $purpose = 'maintenance', $ttl = self::DEFAULT_TTL ) {
		global $wpdb;

		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$stored = (string) get_option( self::OPTION, '' );
		$lock   = $this->parse_payload( $stored );
		if ( ! $lock || ! hash_equals( $lock['owner'], $owner_token ) ) {
			return new \WP_Error(
				'certloom_domain_maintenance_owner_mismatch',
				__( 'This worker no longer owns the Certloom maintenance lock.', 'certloom' )
			);
		}

		$purpose = '' === trim( (string) $purpose ) ? $lock['purpose'] : $this->purpose( $purpose );
		$ttl     = $this->ttl( $ttl );
		$now     = time();
		$value   = $this->payload_value( $owner_token, $now, $ttl, $purpose );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$value,
				self::OPTION,
				$stored
			)
		);
		$this->clear_option_cache();

		return 1 === (int) $updated
			? true
			: new \WP_Error(
				'certloom_domain_maintenance_owner_mismatch',
				__( 'The Certloom maintenance lock changed before it could be refreshed.', 'certloom' )
			);
	}

	/** @return bool|\WP_Error */
	public function release( $owner_token ) {
		global $wpdb;

		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return true;
		}

		$lock = $this->parse_payload( $stored );
		if ( ! $lock || ! hash_equals( $lock['owner'], $owner_token ) ) {
			return new \WP_Error(
				'certloom_domain_maintenance_owner_mismatch',
				__( 'Certloom refused to release a maintenance lock owned by another worker.', 'certloom' )
			);
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::OPTION,
				$stored
			)
		);
		$this->clear_option_cache();

		return 1 === (int) $deleted || '' === (string) get_option( self::OPTION, '' );
	}

	/** Returns whether this exact owner still holds a non-expired maintenance lease. */
	public function owned_active( $owner_token ) {
		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) { return false; }
		$lock = $this->parse_payload( (string) get_option( self::OPTION, '' ) );
		return $lock && $lock['expires_at'] > time() && hash_equals( $lock['owner'], $owner_token );
	}

	/** @return bool */
	public function active() {
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return false;
		}

		$lock = $this->parse_payload( $stored );
		return $lock && $lock['expires_at'] > time();
	}

	/** Runs only the maintenance worker's own protected option/data writes under the lock. */
	public static function run_internal( callable $callback ) {
		self::$internal_depth++;
		try {
			return $callback();
		} finally {
			self::$internal_depth = max( 0, self::$internal_depth - 1 );
		}
	}

	/** @return array<string,mixed>|null */
	private function parse_payload( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return null;
		}

		$parts = explode( '|', $stored, 4 );
		if ( count( $parts ) >= 4 ) {
			$owner      = (string) $parts[0];
			$locked_at  = absint( $parts[1] );
			$expires_at = absint( $parts[2] );
			$purpose    = $this->purpose( $parts[3] );
		} else {
			// Backward compatibility with the 1.5.4 development payload:
			// owner|locked_at|purpose.
			$owner      = isset( $parts[0] ) ? (string) $parts[0] : '';
			$locked_at  = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
			$expires_at = $locked_at ? $locked_at + self::DEFAULT_TTL : 0;
			$purpose    = $this->purpose( isset( $parts[2] ) ? $parts[2] : 'maintenance' );
		}

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $owner ) || ! $locked_at || $expires_at <= $locked_at ) {
			return null;
		}

		return array(
			'owner'      => $owner,
			'locked_at'  => $locked_at,
			'expires_at' => $expires_at,
			'purpose'    => $purpose,
		);
	}

	private function payload_value( $owner_token, $locked_at, $ttl, $purpose ) {
		return implode(
			'|',
			array(
				$owner_token,
				(int) $locked_at,
				(int) $locked_at + (int) $ttl,
				$purpose,
			)
		);
	}

	private function purpose( $purpose ) {
		$purpose = sanitize_key( (string) $purpose );
		return '' === $purpose ? 'maintenance' : $purpose;
	}

	private function ttl( $ttl ) {
		return max( 60, min( self::MAX_TTL, absint( $ttl ) ) );
	}

	private function clear_option_cache() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
	}

	/** @return string|\WP_Error */
	private function token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token )
			? $token
			: new \WP_Error(
				'certloom_domain_maintenance_token_invalid',
				__( 'The Certloom maintenance owner token is invalid.', 'certloom' )
			);
	}
}
