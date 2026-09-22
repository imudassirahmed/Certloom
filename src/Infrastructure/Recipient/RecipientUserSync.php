<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Keeps the optional WordPress-account -> Recipient bridge explicit and exact.
 *
 * No email/name matching is ever used to claim ownership. When automatic sync is
 * enabled, the WordPress user ID itself is the identity authority. Existing
 * issued credential snapshots remain immutable even if this mutable profile is
 * updated later.
 */
final class RecipientUserSync {
	const SETTING = 'recipient_user_sync_enabled';

	/** @var RecipientRepository */
	private $recipients;

	public function __construct( ?RecipientRepository $recipients = null ) {
		$this->recipients = $recipients ?: new RecipientRepository();
	}

	/** @return void */
	public function register() {
		add_action( 'user_register', array( $this, 'registered' ), 20, 1 );
		add_action( 'profile_update', array( $this, 'profile_updated' ), 20, 2 );
		add_action( 'added_user_meta', array( $this, 'profile_meta_updated' ), 30, 4 );
		add_action( 'updated_user_meta', array( $this, 'profile_meta_updated' ), 30, 4 );
	}

	/** @return bool */
	public static function enabled() {
		return ! class_exists( 'Ecapla_CertVerify_Settings' ) || (bool) \Ecapla_CertVerify_Settings::get( self::SETTING, true );
	}

	/** @return void */
	public function registered( $user_id ) {
		if ( ! self::enabled() ) { return; }
		$result = $this->ensure_for_user( $user_id );
		if ( is_wp_error( $result ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $result->get_error_message() );
		}
	}

	/** @return void */
	public function profile_updated( $user_id, $old_user_data = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! self::enabled() ) { return; }
		$result = $this->sync_linked_user( $user_id );
		if ( is_wp_error( $result ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $result->get_error_message() );
		}
	}

	/**
	 * Registration/profile plugins often save first/last-name metadata after the
	 * core user_register event. Re-sync those exact identity fields so the new
	 * Recipient reflects what the member actually entered without guessing by
	 * email or name.
	 *
	 * @return void
	 */
	public function profile_meta_updated( $meta_id, $user_id, $meta_key, $meta_value = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! self::enabled() || ! in_array( (string) $meta_key, array( 'first_name', 'last_name' ), true ) ) { return; }
		$this->profile_updated( $user_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function ensure_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! function_exists( 'get_userdata' ) ) {
			return new \WP_Error( 'certloom_recipient_user_invalid', __( 'A valid WordPress account is required to create a Recipient profile.', 'certloom' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'certloom_recipient_user_missing', __( 'The WordPress account no longer exists.', 'certloom' ) );
		}
		$existing = $this->recipients->find_by_wp_user_id( $user_id );
		if ( is_wp_error( $existing ) ) { return $existing; }
		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$updated = $this->update_from_user( $existing, $user );
			if ( is_wp_error( $updated ) ) { return $updated; }
			$refreshed = $this->recipients->find( absint( $existing['id'] ) );
			return is_array( $refreshed ) ? $refreshed : $existing;
		}

		$created = $this->recipients->create( $this->profile_from_user( $user ) );
		if ( is_wp_error( $created ) ) { return $created; }
		$recipient = $this->recipients->find( absint( $created ) );
		return is_array( $recipient ) ? $recipient : new \WP_Error( 'certloom_recipient_user_create_unreadable', __( 'The Recipient profile was created but could not be re-read safely.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function sync_linked_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! function_exists( 'get_userdata' ) ) { return true; }
		$recipient = $this->recipients->find_by_wp_user_id( $user_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || empty( $recipient['id'] ) ) {
			return $this->ensure_for_user( $user_id );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) { return true; }
		return $this->update_from_user( $recipient, $user );
	}

	/** @return true|\WP_Error */
	private function update_from_user( array $recipient, $user ) {
		$patch = $this->profile_from_user( $user );
		unset( $patch['wp_user_id'], $patch['middle_name'], $patch['external_id'], $patch['custom_fields'] );
		return $this->recipients->update( absint( $recipient['id'] ?? 0 ), $patch );
	}

	/** @return array<string,mixed> */
	private function profile_from_user( $user ) {
		$first = function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( absint( $user->ID ), 'first_name', true ) ) : '';
		$last  = function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( absint( $user->ID ), 'last_name', true ) ) : '';
		$display = trim( (string) ( $user->display_name ?? '' ) );
		$login = trim( (string) ( $user->user_login ?? '' ) );
		$full_name = trim( $first . ' ' . $last );
		if ( '' !== $full_name && ( '' === $display || $display === $login ) ) { $display = $full_name; }
		if ( '' === $display ) { $display = $login; }
		if ( '' === $display ) { $display = __( 'WordPress user', 'certloom' ) . ' #' . absint( $user->ID ?? 0 ); }
		return array(
			'display_name'  => $display,
			'first_name'    => '' === $first ? null : $first,
			'middle_name'   => null,
			'last_name'     => '' === $last ? null : $last,
			'email'         => ! empty( $user->user_email ) ? (string) $user->user_email : null,
			'wp_user_id'    => absint( $user->ID ?? 0 ),
			'external_id'   => null,
			'custom_fields' => array(),
		);
	}
}
