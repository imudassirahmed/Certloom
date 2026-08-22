<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\Contract\TransactionInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one lifecycle callback inside an already-owned outer transaction.
 *
 * This adapter exists only for the administrator-triggered domain acceptance
 * proof. Production lifecycle calls continue to use the normal Transaction
 * implementation. The proof uses one outer rollback-only transaction so every
 * synthetic repository/lifecycle write disappears together after verification.
 */
final class DirectTransaction implements TransactionInterface {
	/** @return mixed|\WP_Error */
	public function run( callable $callback ) {
		try {
			return $callback();
		} catch ( \Throwable $error ) {
			return new \WP_Error(
				'certloom_domain_acceptance_callback_failed',
				__( 'The gated-domain acceptance proof encountered an unexpected lifecycle error.', 'certloom' )
			);
		}
	}
}
