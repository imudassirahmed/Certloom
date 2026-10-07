<?php
namespace Certloom\Application\Restore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Restoring private data never authorizes an account, an approval or source replay. */
final class RecipientWorkflowRestorePolicy {
	public static function prepare( $entity, array $row ) {
		if ( 'issuance_requests' === $entity ) {
			$row['restore_state'] = 'paused';
			$row['review_sha256'] = null;
			$row['mapping_sha256'] = null;
			$row['next_attempt_at'] = null;
			if ( ! in_array( $row['status'], array( 'issued', 'cancelled', 'not_eligible' ), true ) ) {
				$row['status'] = 'blocked';
				$row['blockers_json'] = wp_json_encode( array( array( 'code' => 'restore_review_required' ) ) );
			}
		}
		if ( 'field_submissions' === $entity ) {
			$row['submitted_by'] = null;
			$row['decided_by'] = null;
		}
		if ( 'issuance_request_events' === $entity ) { $row['actor_user_id'] = null; }
		return $row;
	}
}
