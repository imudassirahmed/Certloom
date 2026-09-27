<?php
/** WP-CLI eval-file: disposable local/development site, real WP comment query. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { exit( 1 ); }
global $wpdb;
$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Certloom completion timestamp regression' ), true );
if ( is_wp_error( $post_id ) ) { throw new RuntimeException( $post_id->get_error_message() ); }
$comment_id = 0;
try {
	// Match Tutor's actual persistence, which bypasses WP's status normalization.
	$wpdb->insert( $wpdb->comments, array( 'comment_post_ID' => $post_id, 'user_id' => 1, 'comment_agent' => 'TutorLMSPlugin', 'comment_type' => 'course_completed', 'comment_approved' => 'approved', 'comment_date' => '2025-01-02 03:04:05', 'comment_date_gmt' => '2025-01-02 03:04:05' ) );
	$comment_id = (int) $wpdb->insert_id;
	if ( ! $comment_id ) { throw new RuntimeException( 'Could not create completion fixture.' ); }
	$actual = Certloom\Infrastructure\Integration\TutorLmsAdapter::completion_time( $post_id, 1 );
	if ( '2025-01-02T03:04:05Z' !== $actual ) { throw new RuntimeException( 'Tutor completion timestamp was replaced: ' . $actual ); }
	echo "PASS  Tutor custom approved status preserves the original UTC completion time\n";
} finally {
	if ( $comment_id ) { wp_delete_comment( $comment_id, true ); }
	wp_delete_post( $post_id, true );
}
