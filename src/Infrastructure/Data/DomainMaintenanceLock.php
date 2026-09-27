<?php
namespace Certloom\Infrastructure\Data;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
		return MaintenanceMutex::run( function () use ( $owner_token, $purpose, $ttl ) {
			return $this->acquire_locked( $owner_token, $purpose, $ttl );
		} );
	}

	private function acquire_locked( $owner_token, $purpose = 'maintenance', $ttl = self::DEFAULT_TTL ) {
		global $wpdb;

		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		foreach ( array( 'certloom_domain_restore_journal', 'certloom_domain_restore_build' ) as $key ) {
			$pending = get_option( $key, null );
			if ( null !== $pending && ( ! is_array( $pending ) || ( $pending['owner_token'] ?? '' ) !== $owner_token ) ) {
				return new \WP_Error( 'certloom_domain_maintenance_recovery_required', __( 'An interrupted Certloom restore must finish recovery before another maintenance operation can start.', 'certloom' ) );
			}
		}

		$purpose = $this->purpose( $purpose );
		$ttl     = $this->ttl( $ttl );
		$now     = time();
		$value   = $this->payload_value( $owner_token, $now, $ttl, $purpose );

		if ( add_option( self::OPTION, $value, '', false ) ) {
			return true;
		}

		$stored = $this->stored_value();
		if ( is_wp_error( $stored ) ) { return $stored; }
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
		return MaintenanceMutex::run( function () use ( $owner_token, $purpose, $ttl ) {
			return $this->touch_locked( $owner_token, $purpose, $ttl );
		} );
	}

	private function touch_locked( $owner_token, $purpose = 'maintenance', $ttl = self::DEFAULT_TTL ) {
		global $wpdb;

		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$stored = $this->stored_value();
		if ( is_wp_error( $stored ) ) { return $stored; }
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
		// MySQL reports zero affected rows for an UPDATE that writes the exact
		// same value. A second job slice in the same second still owns this lease.
		if ( hash_equals( $stored, $value ) ) {
			return true;
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
				'certloom_domain_maintenance_owner_mismatch',
				__( 'The Certloom maintenance lock changed before it could be refreshed.', 'certloom' )
			);
	}

	/** @return true|\WP_Error */
	public function release( $owner_token ) {
		return MaintenanceMutex::run( function () use ( $owner_token ) {
			return $this->release_locked( $owner_token );
		} );
	}

	private function release_locked( $owner_token ) {
		global $wpdb;

		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$stored = $this->stored_value();
		if ( is_wp_error( $stored ) ) { return $stored; }
		if ( '' === $stored ) {
			return true;
		}

		if ( is_wp_error( $stored ) ) { return $stored; }
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

		return 1 === (int) $deleted || '' === $this->stored_value()
			? true
			: new \WP_Error(
				'certloom_domain_maintenance_release_failed',
				__( 'Certloom could not release its maintenance lock safely. Credential writes remain paused until the lock expires or a maintenance worker repairs it.', 'certloom' )
			);
	}

	/** Returns whether this exact owner still holds a non-expired maintenance lease. */
	public function owned_active( $owner_token ) {
		return $this->owned_by( $owner_token, true );
	}

	/** Owner-matched cleanup may also release the expired lease of a dead worker. */
	public function owned_by( $owner_token, $require_active = false ) {
		$owner_token = $this->token( $owner_token );
		if ( is_wp_error( $owner_token ) ) { return false; }
		$stored = $this->stored_value();
		if ( is_wp_error( $stored ) ) { return false; }
		$lock = $this->parse_payload( $stored );
		return $lock && ( ! $require_active || $lock['expires_at'] > time() ) && hash_equals( $lock['owner'], $owner_token );
	}

	/** Called under MaintenanceMutex: uncertain ownership must never authorize recovery DDL. */
	public function recovery_allowed( $owner_token ) {
		$stored = $this->stored_value();
		if ( is_wp_error( $stored ) ) { return $stored; }
		if ( '' === $stored ) { return true; }
		$lock = $this->parse_payload( $stored );
		if ( ! $lock || ! hash_equals( $lock['owner'], (string) $owner_token ) ) {
			return new \WP_Error( 'certloom_domain_maintenance_owner_mismatch', __( 'Certloom could not confirm the interrupted restore owns its maintenance state. Recovery is paused for review.', 'certloom' ) );
		}
		return $lock['expires_at'] <= time();
	}

	/**
	 * Returns true for an unexpired valid lock and also for a non-empty corrupt
	 * payload. Normal writes must fail closed on lock corruption; a subsequent
	 * maintenance acquire may still replace the corrupt value atomically by CAS.
	 *
	 * @return bool
	 */
	public function active() {
		global $wpdb;
		$stored = $this->stored_value();
		if ( is_wp_error( $stored ) ) { return true; }
		$lock = $this->parse_payload( $stored );
		if ( '' !== $stored && ( ! $lock || $lock['expires_at'] > time() ) ) { return true; }
		// Expiry permits recovery, not user writes against an unresolved swap.
		$pending = $wpdb->get_var( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name IN (%s, %s) LIMIT 1',
			$wpdb->options, 'certloom_domain_restore_journal', 'certloom_domain_restore_build'
		) );
		return null !== $pending || '' !== (string) $wpdb->last_error;
	}

	/** Read coordination state from MySQL, never a request or persistent option cache. */
	private function stored_value() {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		return '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_maintenance_read_failed', __( 'Certloom could not check database maintenance ownership. Changes remain paused until the database responds.', 'certloom' ) )
			: (string) $value;
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
