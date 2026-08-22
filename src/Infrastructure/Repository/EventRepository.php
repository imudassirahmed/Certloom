<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Append-only credential lifecycle/event persistence. */
final class EventRepository implements EventRepositoryInterface {
	/**
	 * @param array<string,mixed> $event Event data.
	 * @return int|\WP_Error
	 */
	public function append( array $event ) {
		global $wpdb;
		$series_id = absint( $event['series_id'] ?? 0 );
		$event_type = strtolower( trim( (string) ( $event['event_type'] ?? '' ) ) );
		$event_type = preg_replace( '/[^a-z0-9._:-]+/', '', $event_type );
		$event_type = is_string( $event_type ) ? substr( $event_type, 0, 64 ) : '';
		if ( ! $series_id || '' === $event_type ) {
			return new \WP_Error( 'certloom_event_required_fields', __( 'A credential and event type are required.', 'certloom' ) );
		}
		$row = array(
			'series_id' => $series_id,
			'version_id' => ! empty( $event['version_id'] ) ? absint( $event['version_id'] ) : null,
			'event_type' => $event_type,
			'actor_user_id' => ! empty( $event['actor_user_id'] ) ? absint( $event['actor_user_id'] ) : null,
			'old_state_json' => isset( $event['old_state'] ) ? wp_json_encode( $event['old_state'] ) : null,
			'new_state_json' => isset( $event['new_state'] ) ? wp_json_encode( $event['new_state'] ) : null,
			'reason_code' => self::nullable_string( $event['reason_code'] ?? null ),
			'public_reason' => self::nullable_string( $event['public_reason'] ?? null ),
			'internal_note' => self::nullable_string( $event['internal_note'] ?? null ),
			'meta_json' => isset( $event['meta'] ) ? wp_json_encode( $event['meta'] ) : null,
			'created_at' => current_time( 'mysql', true ),
		);
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'credential_events' ), $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_event_create_failed', __( 'Certloom could not record the credential event.', 'certloom' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Loads a bounded newest-first lifecycle history for one Credential Series.
	 *
	 * @param int $series_id Credential Series ID.
	 * @param int $limit Maximum events.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function list_for_series( $series_id, $limit = 100 ) {
		global $wpdb;
		$series_id = absint( $series_id );
		$limit = max( 1, min( 250, absint( $limit ) ) );
		if ( ! $series_id ) {
			return array();
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, series_id, version_id, event_type, actor_user_id, old_state_json, new_state_json, reason_code, public_reason, internal_note, meta_json, created_at FROM %i WHERE series_id = %d ORDER BY id DESC LIMIT %d',
				SchemaManager::table( 'credential_events' ),
				$series_id,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_event_list_failed', __( 'Certloom could not load credential history.', 'certloom' ) );
		}
		foreach ( $rows as &$row ) {
			foreach ( array( 'id', 'series_id', 'version_id', 'actor_user_id' ) as $key ) {
				$row[ $key ] = ! empty( $row[ $key ] ) ? absint( $row[ $key ] ) : null;
			}
			foreach ( array( 'old_state_json' => 'old_state', 'new_state_json' => 'new_state', 'meta_json' => 'meta' ) as $json_key => $target ) {
				$decoded = ! empty( $row[ $json_key ] ) ? json_decode( (string) $row[ $json_key ], true ) : array();
				$row[ $target ] = is_array( $decoded ) ? $decoded : array();
				unset( $row[ $json_key ] );
			}
		}
		unset( $row );
		return $rows;
	}

	/** @return string|null */
	private static function nullable_string( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
