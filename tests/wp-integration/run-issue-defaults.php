<?php
/** Run with WP-CLI against a disposable local WordPress site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 1 );
}

use Certloom\Infrastructure\Database\SchemaManager;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( empty( $admins ) ) { throw new RuntimeException( 'A test administrator is required.' ); }
$previous_user = get_current_user_id();
wp_set_current_user( absint( $admins[0] ) );
try {
	global $wpdb;
	$recipient_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', SchemaManager::table( 'recipients' ) ) );
	$active_program_total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active = 1', SchemaManager::table( 'programs' ) ) );
	$request = new WP_REST_Request( 'GET', '/certloom/v1/admin/issue/bootstrap' );
	$response = rest_do_request( $request );
	if ( 200 !== $response->get_status() ) { throw new RuntimeException( 'Issue bootstrap failed.' ); }
	$defaults = (array) ( $response->get_data()['defaults'] ?? array() );
	$expected_recipient = $recipient_total ? 'existing' : 'new';
	$expected_basis = $active_program_total ? 'program' : 'independent';
	if ( $expected_recipient !== ( $defaults['recipient_mode'] ?? '' ) || $expected_basis !== ( $defaults['credential_basis'] ?? '' ) ) {
		throw new RuntimeException( 'Issue defaults do not reflect available Recipients and active Programs.' );
	}
	echo "PASS  Issue bootstrap chooses usable defaults for the current local data\n";
} finally {
	wp_set_current_user( $previous_user );
}
