<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only destination suggestions; typed restore proofs recheck every selected ID. */
final class RecoveryConnectionRest {
	public function register() { add_action( 'rest_api_init', array( $this, 'routes' ) ); }
	public function routes() {
		register_rest_route( 'certloom/v1', '/admin/recovery-connections', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'search' ),
			'permission_callback' => function () { return current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) ? true : new \WP_Error( 'certloom_recovery_search_forbidden', __( 'You do not have permission to review recovery connections.', 'certloom' ), array( 'status' => 403 ) ); },
			'args' => array( 'kind' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ), 'type' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ), 'q' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
	}
	public function search( \WP_REST_Request $request ) {
		$kind = $request->get_param( 'kind' ); $type = $request->get_param( 'type' ); $query = trim( (string) $request->get_param( 'q' ) );
		if ( ! in_array( $kind, array( 'accounts', 'objects' ), true ) || strlen( $query ) > 200 || ( strlen( $query ) < 2 && ! ctype_digit( $query ) ) || ( 'objects' === $kind && ( ! $type || ! post_type_exists( $type ) ) ) ) { return new \WP_Error( 'certloom_recovery_search_invalid', __( 'Enter at least two characters to find an existing account or website item.', 'certloom' ), array( 'status' => 400 ) ); }
		$items = array();
		if ( 'accounts' === $kind ) {
			$args = array( 'blog_id' => get_current_blog_id(), 'number' => 25, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_login' ) );
			if ( ctype_digit( $query ) && (string) (int) $query === $query ) { $args['include'] = array( (int) $query ); }
			else { $args['search'] = '*' . $query . '*'; $args['search_columns'] = array( 'display_name', 'user_login' ); }
			foreach ( get_users( $args ) as $user ) { $items[] = array( 'id' => (int) $user->ID, 'label' => $user->display_name . ' (' . $user->user_login . ') #' . $user->ID ); }
		} else {
			$args = array( 'post_type' => $type, 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'inherit' ), 'posts_per_page' => 25, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true, 'ignore_sticky_posts' => true );
			if ( ctype_digit( $query ) && (string) (int) $query === $query ) { $args['post__in'] = array( (int) $query ); } else { $args['s'] = $query; }
			foreach ( get_posts( $args ) as $post ) { $items[] = array( 'id' => (int) $post->ID, 'label' => ( '' === $post->post_title ? __( 'Untitled', 'certloom' ) : wp_strip_all_tags( $post->post_title ) ) . ' #' . $post->ID ); }
		}
		$response = new \WP_REST_Response( array( 'items' => $items, 'limit' => 25 ) );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' ); $response->header( 'Vary', 'Cookie' );
		return $response;
	}
}
