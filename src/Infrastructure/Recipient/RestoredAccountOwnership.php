<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Recipient\RecipientAccountLink;
use Certloom\Infrastructure\Backup\WholeStateReferenceRepository;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** An account ID reused after restore cannot inherit the old Recipient's private records. */
final class RestoredAccountOwnership {
	public function requires_identity( array $recipient ) {
		$custom = $recipient['custom_fields'] ?? array(); if ( is_string( $custom ) ) { $custom = json_decode( $custom, true ); }
		return is_array( $custom ) && is_array( $custom[RecipientAccountLink::KEY] ?? null ) && array_key_exists( 'restored_account_identity_sha256', $custom[RecipientAccountLink::KEY] );
	}
	public function assert_current( array $recipient, $lock = false ) {
		$custom = $recipient['custom_fields'] ?? array();
		if ( is_string( $custom ) ) { $custom = json_decode( $custom, true ); }
		if ( ! is_array( $custom ) ) { return new \WP_Error( 'certloom_recipient_account_missing', __( 'The restored account connection needs organization review.', 'certloom' ) ); }
		if ( ! array_key_exists( RecipientAccountLink::KEY, $custom ) ) { return true; }
		$metadata = ( new RecipientAccountLink() )->normalize( $custom[RecipientAccountLink::KEY] );
		if ( is_wp_error( $metadata ) ) { return $metadata; }
		if ( ! array_key_exists( 'restored_account_identity_sha256', $metadata ) ) { return true; }
		$user = (int) ( $recipient['wp_user_id'] ?? 0 );
		$digest = $user > 0 ? ( new WholeStateReferenceRepository() )->account_creation_identity( $user, $lock && Transaction::owns_current() ) : null;
		if ( is_wp_error( $digest ) ) { return $digest; }
		return is_string( $digest ) && hash_equals( $metadata['restored_account_identity_sha256'], $digest ) ? true : new \WP_Error( 'certloom_recipient_account_missing', __( 'This restored account connection needs organization review before certificate access or issuance.', 'certloom' ) );
	}
}
