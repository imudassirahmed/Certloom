<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read the actual Tutor completion record, never a synthetic current-time fallback. */
final class TutorCompletionEvidence {
	public function read( $course, $user, $comment_id = 0 ) {
		global $wpdb;
		if ( ! TutorLmsAdapter::is_available() || ! is_int( $course ) || $course < 1 || ! is_int( $user ) || $user < 1 || ! is_int( $comment_id ) || $comment_id < 0 ) { return $this->missing(); }
		$wpdb->last_error = '';
		$post = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,post_type,post_status FROM %i WHERE ID=%d FOR UPDATE', $wpdb->posts, $course ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $post ) || TutorLmsAdapter::course_post_type() !== $post['post_type'] || ! in_array( $post['post_status'], array( 'publish','private' ), true ) ) { return $this->missing(); }
		$args = array( $wpdb->comments, $course, $user );
		$where = ''; if ( $comment_id ) { $where = ' AND comment_ID=%d'; $args[] = $comment_id; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID,comment_post_ID,user_id,comment_date_gmt,comment_approved,comment_agent,comment_type,comment_content FROM %i WHERE comment_post_ID=%d AND user_id=%d AND comment_type='course_completed' AND comment_agent='TutorLMSPlugin' AND comment_approved IN ('approved','1')" . $where . ' ORDER BY comment_ID DESC LIMIT 1 FOR UPDATE', $args ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) !== 1 ) { return $this->missing(); }
		$row = $rows[0]; $stamp = strtotime( $row['comment_date_gmt'] . ' UTC' );
		if ( false === $stamp || gmdate( 'Y-m-d H:i:s', $stamp ) !== $row['comment_date_gmt'] || $stamp > time() ) { return $this->missing(); }
		// Tutor's completion hash stays private; only its digest enters request provenance.
		$row['comment_content'] = hash( 'sha256', $row['comment_content'] );
		return array( 'comment_id' => (int) $row['comment_ID'], 'completed_at' => $row['comment_date_gmt'], 'sha256' => ( new CanonicalJson() )->hash( $row ) );
	}
	private function missing() { return new \WP_Error( 'certloom_pending_missing_current_evidence', __( 'Tutor course completion is unavailable or changed. Your organization must review the original completion.', 'certloom' ), array( 'reason_code' => 'missing_current_evidence' ) ); }
}
