<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Short, fenced delivery commands. No external transport runs inside this boundary. */
final class DeliveryWriteBoundary {
	private $type;
	private $prefix;

	public function __construct( $type ) {
		if ( ! in_array( $type, array( 'email_deliveries', 'destination_deliveries' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported delivery store.' );
		}
		$this->type = $type;
		$this->prefix = 'email_deliveries' === $type ? 'certloom_email_delivery_' : 'certloom_destination_delivery_';
	}

	/** @return string|\WP_Error */
	public function insert( array $row ) {
		return ( new Transaction() )->run( function () use ( $row ) {
			global $wpdb;
			$marked = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $marked ) ) { return $marked; }
			$wpdb->last_error = '';
			$inserted = $wpdb->insert( SchemaManager::table( $this->type ), $row );
			return 1 === $inserted && '' === (string) $wpdb->last_error
				? $row['delivery_key'] : $this->error( 'create_failed' );
		} );
	}

	/**
	 * Decide from the locked, current row, including evidence and attempt identity.
	 * true means an already confirmed no-op; an array is a narrow state mutation.
	 * @return true|\WP_Error
	 */
	public function change( $key, callable $decide ) {
		return ( new Transaction() )->run( function () use ( $key, $decide ) {
			global $wpdb;
			$wpdb->last_error = '';
			$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key = %s FOR UPDATE', SchemaManager::table( $this->type ), $key ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) { return $this->error( 'read_failed' ); }
			if ( ! is_array( $current ) ) { return $this->error( 'missing' ); }
			$changes = $decide( $current );
			if ( true === $changes || is_wp_error( $changes ) ) { return $changes; }
			$allowed = array( 'job_key', 'status', 'attempt_token', 'attempted_at', 'accepted_at', 'failed_at', 'failure_code', 'evidence_json', 'updated_at' );
			if ( 'destination_deliveries' === $this->type ) { $allowed[] = 'external_reference'; }
			if ( ! is_array( $changes ) || ! $changes || array_diff( array_keys( $changes ), $allowed ) ) { return $this->error( 'update_failed' ); }
			$marked = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $marked ) ) { return $marked; }
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( $this->type ), $changes, array( 'delivery_key' => $key, 'status' => $current['status'], 'attempt_token' => $current['attempt_token'] ) );
			// Every accepted mutation changes status. Zero affected rows are never success.
			return 1 === $updated && '' === (string) $wpdb->last_error ? true : $this->error( 'update_failed' );
		} );
	}

	/** The transport result/recovery must belong to the persisted attempt. */
	public static function attempt_matches( array $row, array $evidence ) {
		$proof = $evidence['attempt_token_sha256'] ?? null;
		if ( null === $proof ) { return 'sending' !== (string) $row['status']; }
		return is_string( $proof ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $proof )
			&& is_string( $row['attempt_token'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $row['attempt_token'] )
			&& hash_equals( hash( 'sha256', $row['attempt_token'] ), $proof );
	}

	private function error( $reason ) {
		return new \WP_Error( $this->prefix . $reason, __( 'Certloom could not confirm the delivery update safely. Refresh its status before trying again.', 'certloom' ) );
	}
}
