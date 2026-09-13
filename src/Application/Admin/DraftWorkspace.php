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
		$all = get_user_meta( $user_id, self::META_KEY, true );
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
		$all = get_user_meta( $user_id, self::META_KEY, true );
		$all = is_array( $all ) ? $all : array();
		$existing = isset( $all[ $scope ] ) && is_array( $all[ $scope ] ) ? self::normalize_item( $all[ $scope ], $scope ) : array();
		$run_id = isset( $context['run_id'] ) ? self::run_id( $context['run_id'] ) : (string) ( $existing['run_id'] ?? '' );
		if ( '' === $run_id ) { $run_id = self::new_run_id(); }
		$incoming_revision = isset( $context['client_revision'] ) ? max( 0, (int) $context['client_revision'] ) : 0;
		$same_run = $run_id === (string) ( $existing['run_id'] ?? '' );
		$existing_revision = $same_run ? max( 0, (int) ( $existing['client_revision'] ?? 0 ) ) : 0;
		if ( $incoming_revision > 0 && $existing_revision > $incoming_revision && $same_run ) { return $existing; }
		$now = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$item = array(
			'version'    => self::VERSION,
			'scope'      => $scope,
			'run_id'     => $run_id,
			'payload'    => $payload,
			'step'       => isset( $context['step'] ) ? sanitize_key( (string) $context['step'] ) : (string) ( $existing['step'] ?? '' ),
			'status'     => isset( $context['status'] ) ? sanitize_key( (string) $context['status'] ) : (string) ( $existing['status'] ?? 'active' ),
			'started_at' => (string) ( $existing['started_at'] ?? $now ),
			'updated_at' => $now,
			'client_revision' => $incoming_revision > 0 ? $incoming_revision : $existing_revision,
		);
		if ( ! in_array( $item['status'], array( 'active', 'complete', 'discarded' ), true ) ) { $item['status'] = 'active'; }
		$all[ $scope ] = $item;
		$result = update_user_meta( $user_id, self::META_KEY, $all );
		if ( false === $result && $all !== get_user_meta( $user_id, self::META_KEY, true ) ) {
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

	/** @return true|\WP_Error */
	public static function clear( $scope, $user_id = 0 ) {
		$scope   = self::scope( $scope );
		$user_id = self::user_id( $user_id );
		if ( '' === $scope || $user_id < 1 || ! function_exists( 'get_user_meta' ) || ! function_exists( 'update_user_meta' ) ) { return true; }
		$all = get_user_meta( $user_id, self::META_KEY, true );
		$all = is_array( $all ) ? $all : array();
		if ( ! array_key_exists( $scope, $all ) ) { return true; }
		unset( $all[ $scope ] );
		if ( empty( $all ) && function_exists( 'delete_user_meta' ) ) {
			$deleted = delete_user_meta( $user_id, self::META_KEY );
			return false === $deleted && '' !== get_user_meta( $user_id, self::META_KEY, true ) ? new \WP_Error( 'certloom_draft_workspace_clear_failed', __( 'Certloom could not clear the administrator draft.', 'certloom' ) ) : true;
		}
		$result = update_user_meta( $user_id, self::META_KEY, $all );
		return false === $result && $all !== get_user_meta( $user_id, self::META_KEY, true ) ? new \WP_Error( 'certloom_draft_workspace_clear_failed', __( 'Certloom could not clear the administrator draft.', 'certloom' ) ) : true;
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
		);
	}
}
