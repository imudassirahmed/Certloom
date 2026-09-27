<?php
/** Run with WP-CLI against a disposable local WordPress site with an issued credential. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 1 );
}

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobRuntime;

global $wpdb;
$version_id = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE issuance_state = %s ORDER BY id DESC LIMIT 1', SchemaManager::table( 'credential_versions' ), 'issued' ) ) );
if ( ! $version_id ) { throw new RuntimeException( 'Issue a local test credential before running the email/public-link integration check.' ); }
$preview = ( new JobRuntime() )->email_delivery()->preview( $version_id, 'qa@example.invalid' );
if ( is_wp_error( $preview ) ) { throw new RuntimeException( $preview->get_error_message() ); }
$composed = (array) ( $preview['composed'] ?? array() );
$url = (string) ( $composed['verification_url'] ?? '' );
if ( empty( $preview['verification_authoritative'] ) || '' === $url || 0 !== strpos( $url, home_url( '/certloom/credential/id/' ) ) ) {
	throw new RuntimeException( 'The reviewed email did not use the current self-hosted credential profile URL.' );
}
if ( false === strpos( (string) ( $composed['html_body'] ?? '' ), $url ) || false !== strpos( (string) ( $composed['html_body'] ?? '' ), '@media only screen' ) ) {
	throw new RuntimeException( 'The email body is missing the public link or exposes raw responsive CSS.' );
}
echo "PASS  Issued credential email includes its live public profile link without raw CSS\n";
