<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\CollectedFieldValueProvider;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Infrastructure\Repository\ProgramRegistrationRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checked ownership and bounded reads before any value provider is enabled. */
final class CollectedFieldValueProviderFactory {
	public function create( array $contract, array $catalog, array $recipient, array $program, $batch, $registration_id = 0, $request_record = null, $lock = false ) {
		$lock = $lock || RecipientCommandReadScope::active();
		$needs = array();
		foreach ( $contract['fields'] ?? array() as $row ) {
			$definition = $catalog[ $row['key'] ] ?? null;
			if ( ! is_array( $definition ) || ! array_key_exists( FieldCollectionPolicy::KEY, $definition ) ) { continue; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( 'achievement' === $policy['lifetime'] && 'staff' === $policy['writer'] ) { continue; }
			$needs[ $policy['lifetime'] ] = true;
		}
		if ( ! $needs && ! $registration_id ) { return null; }
		$registrations = new ProgramRegistrationRepository(); $registration = null;
		if ( ! empty( $recipient['id'] ) && ( isset( $needs['registration'] ) || $registration_id ) ) {
			$registration = $registrations->for_issuance( $recipient['id'], $program['id'] ?? 0, $batch['id'] ?? 0, $registration_id, $lock );
			if ( is_wp_error( $registration ) ) { return $registration; }
		}
		if ( ! $needs ) { return null; }
		$submissions = array(); $repository = new FieldSubmissionRepository();
		foreach ( array( 'recipient' => $recipient['id'] ?? 0, 'registration' => $registration['id'] ?? 0 ) as $kind => $id ) {
			if ( ! $id || ! isset( $needs[ $kind ] ) ) { continue; }
			$submissions[ $kind ] = $repository->latest_map( $recipient['id'], $kind, $id, $lock );
			if ( is_wp_error( $submissions[ $kind ] ) ) { return $submissions[ $kind ]; }
		}
		$defaults = isset( $needs['program'] ) ? ( new ProgramFieldDefaults() )->from_program( $program, $catalog ) : array( 'values' => array() );
		if ( is_wp_error( $defaults ) ) { return $defaults; }
		return new CollectedFieldValueProvider( $recipient, $program, $registration, $submissions, $defaults, $request_record, current_time( 'mysql', true ) );
	}

	/** Lock order matches account writes. Caller must own a checked transaction. */
	public function lock_context( $recipient_id, $program_id, $batch_id, $registration_id = 0 ) {
		$locked = ( new RecipientFieldContext() )->lock( $program_id, $batch_id );
		if ( is_wp_error( $locked ) ) { return $locked; }
		if ( ! $recipient_id ) { return true; }
		// Reviewed links and core deletion lock account -> Recipient. Take the same
		// order and reject a connection that moved after the initial identity read.
		$initial = ( new RecipientRepository() )->find( $recipient_id );
		if ( is_wp_error( $initial ) ) { return $initial; }
		$account_id = (int) ( $initial['wp_user_id'] ?? 0 );
		if ( $account_id ) {
			$account = \Certloom\Application\Recipient\RecipientAccountLinkService::lock_user( $account_id );
			if ( is_wp_error( $account ) ) { return $account; }
			if ( ! is_array( $account ) || 0 !== (int) $account['user_status'] ) { return new \WP_Error( 'certloom_recipient_account_missing', __( 'This recipient’s account is unavailable. Review the connection before issuance.', 'certloom' ) ); }
		}
		$recipient = ( new RecipientRepository() )->find_for_update( $recipient_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return new \WP_Error( 'certloom_collected_recipient_unavailable', __( 'This recipient is unavailable for issuance.', 'certloom' ) ); }
		if ( (int) ( $recipient['wp_user_id'] ?? 0 ) !== $account_id ) { return new \WP_Error( 'certloom_recipient_account_missing', __( 'This recipient’s account connection changed. Refresh before issuance.', 'certloom' ) ); }
		$ownership = ( new RestoredAccountOwnership() )->assert_current( $recipient, true ); if ( is_wp_error( $ownership ) ) { return $ownership; }
		if ( ! empty( $recipient['wp_user_id'] ) ) {
			$user = get_userdata( $recipient['wp_user_id'] );
			if ( ! $user || ! user_can( $user, 'read' ) || ! empty( $user->user_status ) || ! empty( $user->spam ) || ! empty( $user->deleted ) || ( is_multisite() && ! is_user_member_of_blog( $recipient['wp_user_id'] ) ) ) { return new \WP_Error( 'certloom_recipient_account_missing', __( 'This recipient’s account is unavailable. Review and unlink the connection before preparing a new certificate request.', 'certloom' ) ); }
		}
		if ( $registration_id ) { return ( new ProgramRegistrationRepository() )->for_issuance( $recipient_id, $program_id, $batch_id, $registration_id, true ); }
		return true;
	}
}
