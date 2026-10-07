<?php
namespace Certloom\Application\Recipient;

use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private read model: no intake, result values or source diagnostics leave this boundary. */
final class RecipientIssuanceRequests {
	public function read_for_user( $user_id, $after_id = 0, $view = 'active' ) {
		if ( ! in_array( $view, array( 'active', 'closed' ), true ) ) { return new \WP_Error( 'certloom_account_request_view_invalid', __( 'Choose requests in progress or closed requests.', 'certloom' ), array( 'status' => 400 ) ); }
		$recipient = ( new RecipientRepository() )->find_by_wp_user_id( $user_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return array( 'linked' => false, 'items' => array(), 'has_more' => false, 'next_id' => null ); }
		$rows = ( new IssuanceRequestRepository() )->account_summaries( $recipient['id'], $after_id, $view );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$items = array(); $forms = array();
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			$key = (int) ( $row['registration_id'] ?? 0 ); $form_key = $row['program_id'] . ':' . ( $row['batch_id'] ?? 0 ) . ':' . $key . ':' . ( \Certloom\Application\Issuance\PendingRequestContext::supports_source( $row ) ? 'accepted' : 'unsupported' );
			if ( 'waiting' === $row['status'] && 'local' === $row['restore_state'] && ! array_key_exists( $form_key, $forms ) ) {
				$form = ( new RecipientCollectionService() )->read_for_user( $user_id, $key, 0, (int) $row['id'] );
				$forms[ $form_key ] = is_wp_error( $form ) ? array() : $form['fields'];
			}
			$kind = 'issuer_review'; $can_complete = false;
			$blockers = json_decode( (string) $row['blockers_json'], true );
			if ( 'waiting' === $row['status'] && 'local' === $row['restore_state'] && is_array( $blockers ) ) {
				$has_approval = false; $has_source = false;
				// Customers can complete details while qualification is still being earned.
				foreach ( $forms[ $form_key ] as $field ) {
					if ( $field['required'] && in_array( $field['status'], array( 'missing', 'rejected' ), true ) ) { $can_complete = true; }
					$has_approval = $has_approval || ( $field['required'] && 'awaiting_approval' === $field['status'] );
				}
				foreach ( $blockers as $blocker ) {
					$code = $blocker['code'] ?? '';
					$has_approval = $has_approval || 'awaiting_approval' === $code;
					$has_source = $has_source || in_array( $code, array( 'missing_current_evidence', 'missing_source_evidence', 'source_evidence_not_current' ), true );
				}
				$kind = $can_complete ? 'details_needed' : ( $has_approval ? 'approval_pending' : ( $has_source ? 'source_pending' : 'issuer_review' ) );
			} elseif ( in_array( $row['status'], array( 'received', 'ready', 'processing' ), true ) && 'local' === $row['restore_state'] ) { $kind = 'preparing'; }
			elseif ( 'not_eligible' === $row['status'] ) { $kind = 'qualification_pending'; }
			elseif ( 'cancelled' === $row['status'] ) { $kind = false !== strpos( (string) $row['blockers_json'], '"request_expired"' ) ? 'expired' : 'cancelled'; }
			elseif ( 'issued' === $row['status'] ) { $kind = 'issued'; }
			$items[] = array( 'id' => (int) $row['id'], 'title' => $row['title'], 'registration_id' => $key, 'notice' => $kind, 'can_complete' => $can_complete );
		}
		return array( 'linked' => true, 'items' => $items, 'has_more' => count( $rows ) > 50, 'next_id' => count( $rows ) > 50 ? (int) end( $items )['id'] : null );
	}
}
