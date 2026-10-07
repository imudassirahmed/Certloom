<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\RecipientWorkflowStore;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reviewed exact account connections; no email claiming or implicit reassignment. */
final class RecipientAccountLinkService {
	const SYNC_HOLD_META = '_certloom_recipient_sync_hold';
	const MAX_OPEN = 100;
	public function update( $id, array $patch, $revision, $confirmed, $actor_id ) {
		if ( ! $actor_id || ! user_can( $actor_id, \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS ) ) { return $this->forbidden(); }
		if ( ! is_string( $revision ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision ) || ! is_bool( $confirmed ) || ! array_key_exists( 'wp_user_id', $patch ) || ( null !== $patch['wp_user_id'] && ( ! is_int( $patch['wp_user_id'] ) || $patch['wp_user_id'] < 1 ) ) ) { return $this->stale(); }
		$initial = ( new RecipientRepository() )->find( $id );
		if ( is_wp_error( $initial ) ) { return $initial; }
		if ( ! is_array( $initial ) ) { return $this->forbidden(); }
		return ( new Transaction() )->run( function () use ( $id, $patch, $revision, $confirmed, $actor_id, $initial ) {
			// Account sync and connection changes share account -> Recipient lock order.
			$users = array_unique( array_filter( array( (int) ( $initial['wp_user_id'] ?? 0 ), (int) ( $patch['wp_user_id'] ?? 0 ) ) ) ); sort( $users, SORT_NUMERIC );
			foreach ( $users as $user_id ) { $locked = self::lock_user( $user_id ); if ( is_wp_error( $locked ) ) { return $locked; } }
			$repo = new RecipientRepository(); $policy = new RecipientAccountLink();
			$recipient = $repo->find_for_update( $id );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->forbidden(); }
			if ( ! hash_equals( $policy->revision( $recipient ), $revision ) ) { return $this->stale(); }
			$old_user = (int) ( $recipient['wp_user_id'] ?? 0 ); $new_user = (int) ( $patch['wp_user_id'] ?? 0 );
			if ( $old_user !== $new_user && ! $confirmed ) { return new \WP_Error( 'certloom_recipient_account_review_required', __( 'Confirm that you reviewed who owns this account. Unlinking closes unissued requests and outstanding detail submissions.', 'certloom' ) ); }
			if ( $old_user && $new_user && $old_user !== $new_user ) { return new \WP_Error( 'certloom_recipient_account_unlink_first', __( 'Unlink the current account first. Review this recipient before connecting a different account.', 'certloom' ) ); }
			$custom = $patch['custom_fields'] ?? $recipient['custom_fields'];
			if ( ! is_array( $custom ) ) { return $this->forbidden(); }
			// Ordinary fields editors cannot replace the reviewed connection history.
			unset( $custom[ RecipientAccountLink::KEY ] );
			if ( isset( $recipient['custom_fields'][ RecipientAccountLink::KEY ] ) ) { $custom[ RecipientAccountLink::KEY ] = $recipient['custom_fields'][ RecipientAccountLink::KEY ]; }
			if ( $old_user !== $new_user ) {
				if ( $new_user ) {
					$valid = $this->available_user( $new_user, (int) $recipient['id'] );
					if ( is_wp_error( $valid ) ) { return $valid; }
				}
				if ( $old_user ) { $closed = $this->close_open_work( $recipient['id'], $actor_id ); if ( is_wp_error( $closed ) ) { return $closed; } }
				if ( $old_user ) { $held = $this->sync_hold( $old_user, true ); if ( is_wp_error( $held ) ) { return $held; } }
				if ( $new_user ) { $held = $this->sync_hold( $new_user, false ); if ( is_wp_error( $held ) ) { return $held; } }
				$custom = $policy->changed( $custom, $new_user ? 'linked' : 'unlinked', $actor_id );
				if ( is_wp_error( $custom ) ) { return $custom; }
			}
			$patch['custom_fields'] = $custom;
			return $repo->update( $recipient['id'], $patch );
		} );
	}
	public static function lock_user( $user_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,user_status FROM %i WHERE ID=%d FOR UPDATE', $wpdb->users, (int) $user_id ), ARRAY_A );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_recipient_account_lock_failed', __( 'The account connection could not be checked safely.', 'certloom' ) ) : $row;
	}
	/** Called after core deletion; a missing account cannot keep pending work alive. */
	public function account_deleted( $user_id ) {
		return ( new Transaction() )->run( function () use ( $user_id ) {
			$user = self::lock_user( $user_id );
			if ( is_wp_error( $user ) ) { return $user; }
			if ( is_array( $user ) ) { return $this->forbidden(); }
			$repo = new RecipientRepository(); $recipient = $repo->find_by_wp_user_id( $user_id );
			if ( is_wp_error( $recipient ) || ! is_array( $recipient ) ) { return is_wp_error( $recipient ) ? $recipient : true; }
			$recipient = $repo->find_for_update( $recipient['id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || (int) ( $recipient['wp_user_id'] ?? 0 ) !== (int) $user_id || ! empty( $recipient['privacy_anonymized_at'] ) ) { return true; }
			$closed = $this->close_open_work( $recipient['id'], 0 );
			if ( is_wp_error( $closed ) ) { return $closed; }
			$custom = ( new RecipientAccountLink() )->changed( $recipient['custom_fields'], 'account_deleted', 0 );
			return is_wp_error( $custom ) ? $custom : $repo->update( $recipient['id'], array( 'wp_user_id' => null, 'custom_fields' => $custom ) );
		} );
	}

	/** Fresh database read: a rolled-back command cannot leave an authoritative cache hint. */
	public static function sync_held( $user_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$hold = $wpdb->get_var( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE user_id=%d AND meta_key=%s LIMIT 1', $wpdb->usermeta, (int) $user_id, self::SYNC_HOLD_META ) );
		return '' !== (string) $wpdb->last_error || null !== $hold;
	}
	private function available_user( $user_id, $recipient_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$user = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,user_status FROM %i WHERE ID=%d', $wpdb->users, $user_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $user ) || 0 !== (int) $user['user_status'] || ! user_can( $user_id, 'read' ) || ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) ) { return new \WP_Error( 'certloom_recipient_account_unavailable', __( 'Choose an active WordPress account on this site.', 'certloom' ) ); }
		$other = ( new RecipientRepository() )->find_by_wp_user_id( $user_id );
		if ( is_wp_error( $other ) ) { return $other; }
		return is_array( $other ) && (int) $other['id'] !== $recipient_id ? new \WP_Error( 'certloom_recipient_wp_user_duplicate', __( 'This account already belongs to another recipient. Review and unlink that record first; Certloom will not merge identities automatically.', 'certloom' ) ) : true;
	}
	private function sync_hold( $user_id, $held ) {
		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id, 'meta_key' => self::SYNC_HOLD_META ) );
		if ( false === $result || '' !== (string) $wpdb->last_error ) { return $this->storage_error(); }
		if ( $held ) {
			$wpdb->last_error = '';
			$result = $wpdb->insert( $wpdb->usermeta, array( 'user_id' => $user_id, 'meta_key' => self::SYNC_HOLD_META, 'meta_value' => '1' ) );
			if ( false === $result || '' !== (string) $wpdb->last_error ) { return $this->storage_error(); }
		}
		wp_cache_delete( $user_id, 'user_meta' );
		return true;
	}
	private function close_open_work( $recipient_id, $actor_id ) {
		$store = new RecipientWorkflowStore(); $requests = new IssuanceRequestRepository();
		$rows = $requests->open_for_recipient( $recipient_id );
		if ( is_wp_error( $rows ) ) { return $rows; }
		// Bound the synchronous identity command. Large histories need a staged operation.
		if ( count( $rows ) > self::MAX_OPEN ) { return $this->too_many(); }
		$submissions = $store->select( 'field_submissions', array( 'recipient_id' => (int) $recipient_id, 'status' => 'pending' ), self::MAX_OPEN + 1 );
		if ( is_wp_error( $submissions ) ) { return $submissions; }
		if ( count( $submissions ) > self::MAX_OPEN ) { return $this->too_many(); }
		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], array( 'issued', 'cancelled' ), true ) ) { continue; }
			$receipt = ( new ManualIssuanceService() )->pending_receipt( $row['id'] );
			if ( is_wp_error( $receipt ) ) { return $receipt; }
			$patch = array( 'status' => 'cancelled', 'review_sha256' => null, 'next_attempt_at' => null );
			if ( is_array( $receipt ) ) { $patch = array_merge( $patch, array( 'status' => 'issued', 'series_id' => (int) $receipt['series_id'], 'version_id' => (int) $receipt['version_id'], 'blockers_json' => '[]' ) ); }
			$result = $requests->transition( $row['id'], $row['revision'], $patch, is_array( $receipt ) ? 'canonical_receipt_reconciled' : 'account_connection_changed', $actor_id );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		foreach ( $submissions as $row ) {
			$result = ( new FieldSubmissionRepository() )->decide( $row['id'], $row['revision'], 'cancelled', $actor_id );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}
	private function storage_error() { return new \WP_Error( 'certloom_recipient_account_save_failed', __( 'The account connection could not be saved safely. No connection change was applied.', 'certloom' ) ); }
	private function too_many() { return new \WP_Error( 'certloom_recipient_account_work_limit', __( 'Review and close this recipient’s outstanding requests before changing the account connection. No changes were saved.', 'certloom' ) ); }
	private function forbidden() { return new \WP_Error( 'certloom_recipient_account_forbidden', __( 'You cannot change this recipient’s account connection.', 'certloom' ), array( 'status' => 403 ) ); }
	private function stale() { return new \WP_Error( 'certloom_recipient_account_stale', __( 'This recipient changed since you opened it. Refresh the profile before saving.', 'certloom' ), array( 'status' => 409 ) ); }
}
