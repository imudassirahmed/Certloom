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
 */
final class Transaction implements TransactionInterface {
	/**
	 * @param callable $callback Callback returning a value or WP_Error.
	 * @return mixed|\WP_Error
	 */
	public function run( callable $callback ) {
		global $wpdb;
		$wpdb->last_error = '';
		$started = $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $started || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_transaction_start_failed', __( 'Certloom could not start the credential update safely.', 'certloom' ) );
		}

		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				return $result;
			}

			$wpdb->last_error = '';
			$committed = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( false === $committed || '' !== (string) $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				return new \WP_Error( 'certloom_transaction_commit_failed', __( 'Certloom could not finish the credential update safely.', 'certloom' ) );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			throw $error;
		}
	}
}
