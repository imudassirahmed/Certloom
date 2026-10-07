<?php
namespace Certloom\Application\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-administrator resumable draft workspace.
 *
 * Product/domain configuration must never read this store as authority. It is
 * intentionally limited to unfinished admin form intent and return context.
 */
final class DraftWorkspace {
	const META_KEY = 'certloom_admin_draft_workspaces_v1';
	const VERSION  = 1;

	/** @return array<string,mixed> */
	public static function get( $scope, $user_id = 0 ) {
		$scope   = self::scope( $scope );
		$user_id = self::user_id( $user_id );
		if ( '' === $scope || $user_id < 1 || ! function_exists( 'get_user_meta' ) ) {
			return array();
		}
		$all = get_user_meta( $user_id, self::meta_key(), true );
		$all = is_array( $all ) ? $all : array();
		$item = isset( $all[ $scope ] ) && is_array( $all[ $scope ] ) ? $all[ $scope ] : array();
		if ( empty( $item ) ) { return array(); }
		return self::normalize_item( $item, $scope );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function save( $scope, array $payload, array $context = array(), $user_id = 0 ) {
		$scope   = self::scope( $scope );
		$user_id = self::user_id( $user_id );
		if ( '' === $scope || $user_id < 1 || ! function_exists( 'update_user_meta' ) || ! function_exists( 'get_user_meta' ) ) {
			return new \WP_Error( 'certloom_draft_workspace_unavailable', __( 'Certloom could not identify the administrator draft workspace.', 'certloom' ) );
		}
		return self::with_lock( $user_id, static function () use ( $scope, $payload, $context, $user_id ) {
			return self::save_owned( $scope, $payload, $context, $user_id );
		} );
	}

	private static function save_owned( $scope, array $payload, array $context, $user_id ) {
		$all = get_user_meta( $user_id, self::meta_key(), true );
		$all = is_array( $all ) ? $all : array();
		$existing = isset( $all[ $scope ] ) && is_array( $all[ $scope ] ) ? self::normalize_item( $all[ $scope ], $scope ) : array();
		if ( array_key_exists( 'expected_revision', $context ) && (
			! is_int( $context['expected_revision'] ) || $context['expected_revision'] < 0
			|| $context['expected_revision'] !== (int) ( $existing['revision'] ?? 0 )
			|| self::run_id( $context['run_id'] ?? '' ) !== (string) ( $existing['run_id'] ?? '' )
		) ) {
			return new \WP_Error( 'certloom_draft_workspace_conflict', __( 'Setup changed in another tab. Your edits are still on this page. Copy any changes you want to keep, then reload to continue with the saved draft.', 'certloom' ) );
		}
		$run_id = isset( $context['run_id'] ) ? self::run_id( $context['run_id'] ) : (string) ( $existing['run_id'] ?? '' );
		if ( '' === $run_id ) { $run_id = self::new_run_id(); }
		$incoming_revision = isset( $context['client_revision'] ) ? max( 0, (int) $context['client_revision'] ) : 0;
		$same_run = $run_id === (string) ( $existing['run_id'] ?? '' );
		$existing_revision = $same_run ? max( 0, (int) ( $existing['client_revision'] ?? 0 ) ) : 0;
		if ( ! array_key_exists( 'expected_revision', $context ) && $incoming_revision > 0 && $existing_revision > $incoming_revision && $same_run ) { return $existing; }
		$now = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$item = array(
			'version'    => self::VERSION,
			'scope'      => $scope,
			'run_id'     => $run_id,
			'payload'    => $payload,
			'step'       => isset( $context['step'] ) ? sanitize_key( (string) $context['step'] ) : (string) ( $existing['step'] ?? '' ),
			'status'     => isset( $context['status'] ) ? sanitize_key( (string) $context['status'] ) : (string) ( $existing['status'] ?? 'active' ),
			'started_at' => $same_run ? (string) ( $existing['started_at'] ?? $now ) : $now,
			'updated_at' => $now,
			'client_revision' => $incoming_revision > 0 ? $incoming_revision : $existing_revision,
			'revision' => (int) ( $existing['revision'] ?? 0 ) + 1,
		);
		if ( ! in_array( $item['status'], array( 'active', 'complete', 'discarded' ), true ) ) { $item['status'] = 'active'; }
		$all[ $scope ] = $item;
		$result = update_user_meta( $user_id, self::meta_key(), $all );
		wp_cache_delete( $user_id, 'user_meta' );
		if ( false === $result && $all !== get_user_meta( $user_id, self::meta_key(), true ) ) {
			return new \WP_Error( 'certloom_draft_workspace_save_failed', __( 'Certloom could not autosave the administrator draft.', 'certloom' ) );
		}
		return $item;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function start_new( $scope, array $payload = array(), array $context = array(), $user_id = 0 ) {
		$context['run_id'] = self::new_run_id();
		$context['status'] = 'active';
		return self::save( $scope, $payload, $context, $user_id );
	}

	/** Keep final wizard actions inside the same revision boundary as autosaves. */
	public static function run_if_current( $scope, $run_id, $revision, callable $callback, $user_id = 0 ) {
		$scope = self::scope( $scope ); $user_id = self::user_id( $user_id );
		if ( '' === $scope || $user_id < 1 ) { return new \WP_Error( 'certloom_draft_workspace_unavailable', __( 'Certloom could not identify the administrator draft workspace.', 'certloom' ) ); }
		return self::with_lock( $user_id, static function () use ( $scope, $run_id, $revision, $callback, $user_id ) {
			$current = self::get( $scope, $user_id );
			if ( ! is_int( $revision ) || $revision < 0 || $revision !== (int) ( $current['revision'] ?? 0 ) || self::run_id( $run_id ) !== (string) ( $current['run_id'] ?? '' ) ) {
				return new \WP_Error( 'certloom_draft_workspace_conflict', __( 'Setup changed in another tab. Reload Setup to review the saved draft before continuing.', 'certloom' ) );
			}
			return $callback();
		} );
	}

	/** @return true|\WP_Error */
	public static function clear( $scope, $user_id = 0 ) {
		$scope   = self::scope( $scope );
		$user_id = self::user_id( $user_id );
		if ( '' === $scope || $user_id < 1 || ! function_exists( 'get_user_meta' ) || ! function_exists( 'update_user_meta' ) ) { return true; }
		return self::with_lock( $user_id, static function () use ( $scope, $user_id ) {
			return self::clear_owned( $scope, $user_id );
		} );
	}

	private static function clear_owned( $scope, $user_id ) {
		$all = get_user_meta( $user_id, self::meta_key(), true );
		$all = is_array( $all ) ? $all : array();
		if ( ! array_key_exists( $scope, $all ) ) { return true; }
		unset( $all[ $scope ] );
		if ( empty( $all ) && function_exists( 'delete_user_meta' ) ) {
			$deleted = delete_user_meta( $user_id, self::meta_key() );
			wp_cache_delete( $user_id, 'user_meta' );
			return false === $deleted && '' !== get_user_meta( $user_id, self::meta_key(), true ) ? new \WP_Error( 'certloom_draft_workspace_clear_failed', __( 'Certloom could not clear the administrator draft.', 'certloom' ) ) : true;
		}
		$result = update_user_meta( $user_id, self::meta_key(), $all );
		wp_cache_delete( $user_id, 'user_meta' );
		return false === $result && $all !== get_user_meta( $user_id, self::meta_key(), true ) ? new \WP_Error( 'certloom_draft_workspace_clear_failed', __( 'Certloom could not clear the administrator draft.', 'certloom' ) ) : true;
	}

	/** Preserve primary-site drafts; never import that intent into another network site. */
	private static function meta_key() {
		$site_id = function_exists( 'get_current_blog_id' ) ? absint( get_current_blog_id() ) : 1;
		return $site_id > 1 ? self::META_KEY . '_site_' . $site_id : self::META_KEY;
	}

	/** Serialize the entire per-user map, including saves to different task scopes. */
	private static function with_lock( $user_id, callable $callback ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->usermeta ) ) {
			return new \WP_Error( 'certloom_draft_workspace_unavailable', __( 'Certloom could not access the administrator draft workspace.', 'certloom' ) );
		}
		return \Certloom\Infrastructure\Database\DatabaseSession::run( static function () use ( $wpdb, $user_id, $callback ) {
			$name = 'certloom-draft-' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->usermeta . '|' . self::meta_key() . '|' . $user_id ), 0, 48 );
			$wpdb->last_error = '';
			$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
			if ( '1' !== (string) $held || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_draft_workspace_busy', __( 'Another tab is saving this draft. Keep this page open and try again shortly.', 'certloom' ) );
			}
			try {
				wp_cache_delete( $user_id, 'user_meta' );
				$result = $callback();
			} finally {
				$wpdb->last_error = '';
				$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
			return '1' === (string) $released && '' === (string) $wpdb->last_error
				? $result
				: new \WP_Error( 'certloom_draft_workspace_lock_release_failed', __( 'Certloom could not confirm the draft save safely. Keep this page open and check the saved draft in another tab before retrying.', 'certloom' ) );
		} );
	}

	/** @return string */
	private static function new_run_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return strtolower( (string) wp_generate_uuid4() );
		}
		return strtolower( str_replace( '.', '', uniqid( 'cl-', true ) ) );
	}

	private static function user_id( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 && function_exists( 'get_current_user_id' ) ) { $user_id = absint( get_current_user_id() ); }
		return $user_id;
	}

	private static function scope( $scope ) { return sanitize_key( (string) $scope ); }
	private static function run_id( $run_id ) { return preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $run_id ) ); }

	/** @return array<string,mixed> */
	private static function normalize_item( array $item, $scope ) {
		return array(
			'version'    => self::VERSION,
			'scope'      => $scope,
			'run_id'     => self::run_id( $item['run_id'] ?? '' ),
			'payload'    => isset( $item['payload'] ) && is_array( $item['payload'] ) ? $item['payload'] : array(),
			'step'       => sanitize_key( (string) ( $item['step'] ?? '' ) ),
			'status'     => sanitize_key( (string) ( $item['status'] ?? 'active' ) ),
			'started_at' => sanitize_text_field( (string) ( $item['started_at'] ?? '' ) ),
			'updated_at' => sanitize_text_field( (string) ( $item['updated_at'] ?? '' ) ),
			'client_revision' => max( 0, (int) ( $item['client_revision'] ?? 0 ) ),
			'revision' => max( 0, (int) ( $item['revision'] ?? 0 ) ),
		);
	}
}
