<?php
namespace Certloom\Infrastructure\Issuance;

use Certloom\Application\Contract\BulkRecipientResolverInterface;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact, fail-closed Recipient identity resolver for Bulk Issuance CSV rows. */
final class BulkRecipientResolver implements BulkRecipientResolverInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function resolve( array $request ) {
		if ( 'new' !== (string) ( $request['recipient_mode'] ?? '' ) ) { return $request; }
		$new = isset( $request['new_recipient'] ) && is_array( $request['new_recipient'] ) ? $request['new_recipient'] : array();
		$email = strtolower( trim( (string) ( $new['email'] ?? '' ) ) );
		$external = trim( (string) ( $new['external_id'] ?? '' ) );
		if ( '' === $email && '' === $external ) {
			return new \WP_Error( 'certloom_bulk_recipient_identity_required', __( 'Each new Bulk Issuance recipient requires an email address or external ID so the row can be resolved deterministically.', 'certloom' ) );
		}

		global $wpdb;
		$table = SchemaManager::table( 'recipients' );
		$clauses = array(); $args = array( $table );
		if ( '' !== $email ) { $clauses[] = 'LOWER(email) = %s'; $args[] = $email; }
		if ( '' !== $external ) { $clauses[] = 'external_id = %s'; $args[] = $external; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, external_id FROM %i WHERE ' . implode( ' OR ', $clauses ) . ' ORDER BY id ASC LIMIT 3', $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_bulk_recipient_lookup_failed', __( 'Certloom could not safely resolve a Bulk Issuance recipient.', 'certloom' ) );
		}
		if ( empty( $rows ) ) { return $request; }
		$ids = array_values( array_unique( array_map( static function ( $row ) { return absint( $row['id'] ?? 0 ); }, $rows ) ) );
		$ids = array_values( array_filter( $ids ) );
		if ( 1 !== count( $ids ) ) {
			return new \WP_Error( 'certloom_bulk_recipient_identity_ambiguous', __( 'A Bulk Issuance row matches more than one existing Recipient. Resolve the duplicate Recipient identity before issuing.', 'certloom' ) );
		}
		$row = $rows[0];
		$stored_email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
		$stored_external = trim( (string) ( $row['external_id'] ?? '' ) );
		if ( '' !== $email && ! hash_equals( $email, $stored_email ) ) {
			return new \WP_Error( 'certloom_bulk_recipient_identity_conflict', __( 'The supplied email and external ID do not identify the same existing Recipient.', 'certloom' ) );
		}
		if ( '' !== $external && ! hash_equals( $external, $stored_external ) ) {
			return new \WP_Error( 'certloom_bulk_recipient_identity_conflict', __( 'The supplied email and external ID do not identify the same existing Recipient.', 'certloom' ) );
		}
		$request['recipient_mode'] = 'existing';
		$request['recipient_id'] = $ids[0];
		$request['new_recipient'] = array();
		return $request;
	}
}
