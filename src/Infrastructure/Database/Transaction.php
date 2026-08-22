<?php
namespace Certloom\Infrastructure\Database;

use Certloom\Application\Contract\TransactionInterface;

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
	/**
	 * @param callable $callback Callback returning a value or WP_Error.
	 * @return mixed|\WP_Error
	 */
	public function run( callable $callback ) {
		global $wpdb;
		$started = $this->control( 'START TRANSACTION', 'certloom_transaction_start_failed', __( 'Certloom could not start the credential update safely.', 'certloom' ) );
		if ( is_wp_error( $started ) ) {
			return $started;
		}

		try {
			$result = $callback();
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
		}
	}

	/** @return true|\WP_Error */
	private function rollback() {
		return $this->control( 'ROLLBACK', 'certloom_transaction_rollback_failed', __( 'Certloom could not confirm transaction rollback. The database state is uncertain and requires administrator review.', 'certloom' ) );
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
