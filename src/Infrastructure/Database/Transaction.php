<?php
namespace Certloom\Infrastructure\Database;

use Certloom\Application\Contract\TransactionInterface;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\DomainWriteFence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small WordPress/MySQL transaction boundary for short domain commands.
 *
 * Long-running imports/jobs must never hold database transactions open. This
 * wrapper is only for compact state + event changes that should be atomic.
 * Every transaction-control statement is checked explicitly: a failed rollback
 * is an integrity-uncertain condition and must never be hidden behind the
 * original application error.
 */
final class Transaction implements TransactionInterface {
	/** Only transactions started by this adapter may be joined by repository commands. */
	private static $connection = null;
	private static $savepoint = 0;
	private static $uncertain = null;
	/** Current reads must remain inside the command's checked owning transaction. */
	public static function owns_current() { global $wpdb; return null !== self::$connection && self::$connection === $wpdb && null === self::$uncertain; }
	/**
	 * @param callable $callback Callback returning a value or WP_Error.
	 * @return mixed|\WP_Error
	 */
	public function run( callable $callback ) {
		return DatabaseSession::run( function () use ( $callback ) { return $this->run_owned( $callback ); } );
	}

	private function run_owned( callable $callback ) {
		global $wpdb;
		if ( null !== self::$connection ) {
			if ( self::$connection !== $wpdb ) { self::$uncertain = new \WP_Error( 'certloom_transaction_connection_changed', __( 'The database connection changed during a credential update. The update could not be confirmed safely.', 'certloom' ) ); return self::$uncertain; }
			return $this->nested( $callback );
		}
		$prepared = DomainWriteFence::prepare();
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$started = $this->control( 'START TRANSACTION', 'certloom_transaction_start_failed', __( 'Certloom could not start the credential update safely.', 'certloom' ) );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		self::$connection = $wpdb;
		self::$uncertain = null;

		try {
			$fenced = DomainWriteFence::shared();
			$allowed = is_wp_error( $fenced ) ? $fenced : ( new DomainMaintenanceLock() )->assert_writes_allowed();
			if ( ! is_wp_error( $allowed ) && class_exists( 'Ecapla_CertVerify_Backup', false ) && method_exists( 'Ecapla_CertVerify_Backup', 'assert_writes_allowed' ) ) { $allowed = \Ecapla_CertVerify_Backup::assert_writes_allowed(); }
			$result = is_wp_error( $allowed ) ? $allowed : $callback();
			if ( is_wp_error( self::$uncertain ) ) { $result = self::$uncertain; }
			if ( is_wp_error( $result ) ) {
				$rolled_back = $this->rollback();
				return is_wp_error( $rolled_back ) ? $rolled_back : $result;
			}

			$committed = $this->control( 'COMMIT', 'certloom_transaction_commit_failed', __( 'Certloom could not finish the credential update safely.', 'certloom' ) );
			if ( is_wp_error( $committed ) ) {
				$rolled_back = $this->rollback();
				return is_wp_error( $rolled_back )
					? new \WP_Error( 'certloom_transaction_commit_rollback_failed', __( 'Certloom could not confirm either commit or rollback for the credential update. The database state is uncertain and requires administrator review.', 'certloom' ) )
					: $committed;
			}
			return $result;
		} catch ( \Throwable $error ) {
			$rolled_back = $this->rollback();
			if ( is_wp_error( $rolled_back ) ) {
				return new \WP_Error( 'certloom_transaction_callback_rollback_failed', __( 'Certloom encountered an unexpected credential-update error and could not confirm rollback. The database state is uncertain and requires administrator review.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_transaction_callback_failed', __( 'Certloom encountered an unexpected credential-update error. The database changes were rolled back safely.', 'certloom' ) );
		} finally {
			self::$connection = null;
			self::$uncertain = null;
		}
	}

	/** Savepoints keep a repository command atomic without implicitly committing its caller. */
	private function nested( callable $callback ) {
		if ( is_wp_error( self::$uncertain ) ) { return self::$uncertain; }
		$name = 'certloom_command_' . ++self::$savepoint;
		$started = $this->control( 'SAVEPOINT ' . $name, 'certloom_transaction_savepoint_failed', __( 'Certloom could not start a nested credential update safely.', 'certloom' ) );
		if ( is_wp_error( $started ) ) { self::$uncertain = $started; return $started; }
		try {
			$result = $callback();
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'certloom_transaction_callback_failed', __( 'Certloom encountered an unexpected credential-update error. The database changes were rolled back safely.', 'certloom' ) );
		}
		if ( is_wp_error( $result ) ) {
			$rolled_back = $this->control( 'ROLLBACK TO SAVEPOINT ' . $name, 'certloom_transaction_savepoint_rollback_failed', __( 'Certloom could not confirm rollback of a nested credential update. The entire update must be rolled back.', 'certloom' ) );
			if ( function_exists( 'wp_cache_delete' ) ) { \Certloom\Infrastructure\Data\LegacyWriteGuard::invalidate_cache(); }
			if ( is_wp_error( $rolled_back ) ) { self::$uncertain = $rolled_back; return $rolled_back; }
		}
		$released = $this->control( 'RELEASE SAVEPOINT ' . $name, 'certloom_transaction_savepoint_release_failed', __( 'Certloom could not finish a nested credential update safely. The entire update must be rolled back.', 'certloom' ) );
		if ( is_wp_error( $released ) ) { self::$uncertain = $released; return $released; }
		return $result;
	}

	/** @return true|\WP_Error */
	private function rollback() {
		$result = $this->control( 'ROLLBACK', 'certloom_transaction_rollback_failed', __( 'Certloom could not confirm transaction rollback. The database state is uncertain and requires administrator review.', 'certloom' ) );
		if ( function_exists( 'wp_cache_delete' ) ) { \Certloom\Infrastructure\Data\LegacyWriteGuard::invalidate_cache(); \Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( \Certloom\Infrastructure\Data\DomainGeneration::OPTION, DomainMaintenanceLock::OPTION ) ); }
		return $result;
	}

	/** @return true|\WP_Error */
	private function control( $sql, $error_code, $error_message ) {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->query( (string) $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Explicit transaction-control boundary.
		return false === $result || '' !== (string) $wpdb->last_error
			? new \WP_Error( (string) $error_code, (string) $error_message )
			: true;
	}
}
