<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Recipient\RecipientNoticeRecord;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\RecipientWorkflowStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Caller owns a checked transaction and the Recipient/request locks. Never sends transport. */
final class RecipientNoticeLedger {
	public function find( $request_id, $event_key ) {
		$rows = ( new RecipientWorkflowStore() )->select( 'issuance_request_events', array( 'request_id' => $request_id, 'event_key' => $event_key ), 1, 0, \Certloom\Infrastructure\Database\Transaction::owns_current() );
		if ( is_wp_error( $rows ) || ! $rows ) { return is_wp_error( $rows ) ? $rows : null; }
		$meta = json_decode( (string) $rows[0]['meta_json'], true );
		$valid = ( new RecipientNoticeRecord() )->validate( $meta );
		return is_wp_error( $valid ) || RecipientNoticeRecord::TYPE !== $rows[0]['event_type'] ? $this->failed() : $meta;
	}
	public function append( array $request, array $meta, $event_key = null ) {
		$valid = ( new RecipientNoticeRecord() )->validate( $meta ); if ( is_wp_error( $valid ) ) { return $valid; }
		$key = $event_key ?: RecipientNoticeRecord::event_key( $meta['notice_key'], $meta['state'] );
		$previous = $this->find( $request['id'], $key );
		if ( is_wp_error( $previous ) ) { return $previous; }
		if ( null !== $previous ) { return $meta === $previous ? true : $this->failed(); }
		$insert = ( new RecipientWorkflowStore() )->insert( 'issuance_request_events', array( 'public_ref' => bin2hex( random_bytes( 16 ) ), 'request_id' => $request['id'], 'event_type' => RecipientNoticeRecord::TYPE, 'event_key' => $key, 'actor_user_id' => null, 'reason_code' => 'notice_' . $meta['state'], 'request_revision' => $request['revision'], 'meta_json' => ( new \Certloom\Application\Rendering\CanonicalJson() )->encode( $meta ), 'created_at' => current_time( 'mysql', true ) ) );
		return is_wp_error( $insert ) ? $insert : true;
	}
	/** A bounded exact-key throttle: high-volume accounts are held instead of scanning their history. */
	public function used_today( $recipient_id ) {
		global $wpdb;
		$ids = array();
		foreach ( \Certloom\Domain\Issuance\IssuanceRequestState::all() as $state ) {
			$wpdb->last_error = '';
			$part = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i FORCE INDEX (recipient_status) WHERE recipient_id=%d AND status=%s ORDER BY id LIMIT 101' . ( \Certloom\Infrastructure\Database\Transaction::owns_current() ? ' FOR UPDATE' : '' ), SchemaManager::table( 'issuance_requests' ), $recipient_id, $state ) );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $part ) ) { return $this->failed(); }
			$ids = array_merge( $ids, $part ); if ( count( $ids ) > 100 ) { return true; }
		}
		if ( ! $ids ) { return false; }
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i FORCE INDEX (source_event) WHERE request_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND event_key=%s LIMIT 1' . ( \Certloom\Infrastructure\Database\Transaction::owns_current() ? ' FOR UPDATE' : '' ), array_merge( array( SchemaManager::table( 'issuance_request_events' ) ), $ids, array( RecipientNoticeRecord::day_key( $recipient_id, gmdate( 'Y-m-d' ) ) ) ) ) );
		return '' !== (string) $wpdb->last_error ? $this->failed() : null !== $found;
	}
	private function failed() { return new \WP_Error( 'certloom_recipient_notice_storage_failed', __( 'Certloom could not safely confirm recipient notice history.', 'certloom' ) ); }
}
