<?php
/** Run with WP-CLI against a disposable WordPress test site. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 1 );
}

use Certloom\Infrastructure\Database\SchemaManager;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( empty( $admins ) ) {
	throw new RuntimeException( 'A test administrator is required.' );
}
$original_user = get_current_user_id();
wp_set_current_user( absint( $admins[0] ) );
if ( ! current_user_can( Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) ) {
	throw new RuntimeException( 'The test administrator cannot issue credentials.' );
}

global $wpdb;
$programs = SchemaManager::table( 'programs' );
$batches  = SchemaManager::table( 'batches' );
$before   = array(
	'programs' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $programs ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SchemaManager returns the trusted plugin table name.
	'batches'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $batches ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SchemaManager returns the trusted plugin table name.
);
$deny_management = static function ( $caps ) {
	$caps[ Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ] = false;
	$caps[ Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ]  = false;
	return $caps;
};
add_filter( 'user_has_cap', $deny_management, 10, 4 );
try {
	if ( ! current_user_can( Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) || current_user_can( Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) || current_user_can( Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ) {
		throw new RuntimeException( 'The capability mask is invalid.' );
	}
	foreach ( array(
		'quick-program' => array( 'title' => 'Denied program', 'code' => 'DENIED-QUICK-PROGRAM' ),
		'quick-batch'   => array( 'program_id' => 1, 'name' => 'Denied batch', 'code' => 'DENIED-QUICK-BATCH' ),
	) as $path => $body ) {
		$request = new WP_REST_Request( 'POST', '/certloom/v1/admin/issue/' . $path );
		$request->set_body_params( $body );
		$response = rest_do_request( $request );
		if ( 403 !== $response->get_status() || 'rest_forbidden' !== ( $response->get_data()['code'] ?? '' ) ) {
			throw new RuntimeException( $path . ' permitted an issuer without entity-management permission.' );
		}
	}
} finally {
	remove_filter( 'user_has_cap', $deny_management, 10 );
	wp_set_current_user( $original_user );
}
$after = array(
	'programs' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $programs ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted table name.
	'batches'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $batches ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted table name.
);
if ( $before !== $after ) {
	throw new RuntimeException( 'A denied quick-create request changed entity counts.' );
}
echo "PASS  quick Program and Batch routes deny issue-only capability without changing data\n";
