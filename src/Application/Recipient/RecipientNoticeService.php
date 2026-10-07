<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Issuance\PendingRequestPolicy;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Recipient\RecipientNoticeLedger;
use Certloom\Application\Job\JobService;
use Certloom\Domain\Job\JobTypes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Recipient-bound optional notices. A notice is never an issued-certificate delivery. */
final class RecipientNoticeService {
	public function queue( $request_id ) {
		$initial = ( new IssuanceRequestRepository() )->find( $request_id );
		if ( is_wp_error( $initial ) || ! is_array( $initial ) ) { return is_wp_error( $initial ) ? $initial : $this->invalid(); }
		$result = ( new Transaction() )->run( function () use ( $initial ) {
			$context = $this->lock( $initial ); if ( is_wp_error( $context ) || null === $context ) { return $context; }
			$row = $context['row']; $policy = $context['policy']; $user = $context['user'];
			$choice = $this->choice( $row, $policy, $user->ID );
			if ( is_wp_error( $choice ) || null === $choice ) { return $choice; }
			$key = RecipientNoticeRecord::key( $row['public_ref'], $choice['kind'], $choice['slot'] );
			$ledger = new RecipientNoticeLedger();
			$existing = $ledger->find( $row['id'], RecipientNoticeRecord::event_key( $key, 'queued' ) );
			if ( is_wp_error( $existing ) || null !== $existing ) { return is_wp_error( $existing ) ? $existing : null; }
			$limited = $ledger->used_today( $row['recipient_id'] );
			if ( is_wp_error( $limited ) || $limited ) { return is_wp_error( $limited ) ? $limited : null; }
			$job = ( new JobService( new \Certloom\Infrastructure\Job\JobRepository() ) )->create( JobTypes::RECIPIENT_NOTICE, 0, array( 'schema_version' => 1, 'request_id' => $row['id'], 'notice_key' => $key ), 1 );
			if ( is_wp_error( $job ) ) { return $job; }
			$meta = array( 'version' => 1, 'notice_key' => $key, 'kind' => $choice['kind'], 'slot' => $choice['slot'], 'state' => 'queued', 'user_id' => (int) $user->ID, 'destination_sha256' => hash( 'sha256', strtolower( trim( $user->user_email ) ) ), 'job_key' => $job, 'attempt_sha256' => null );
			$saved = $ledger->append( $row, $meta );
			if ( is_wp_error( $saved ) ) { return $saved; }
			$meta['state'] = 'throttle';
			$saved = $ledger->append( $row, $meta, RecipientNoticeRecord::day_key( $row['recipient_id'], gmdate( 'Y-m-d' ) ) );
			return is_wp_error( $saved ) ? $saved : $job;
		} );
		if ( is_string( $result ) ) {
			$scheduled = ( new \Certloom\Infrastructure\Job\JobRuntime() )->dispatcher()->schedule( $result );
			if ( is_wp_error( $scheduled ) ) { return $scheduled; }
		}
		return $result;
	}

	/** Commit one attempt before transport; retries of an interrupted attempt never resend. */
	public function deliver( $request_id, $notice_key, $job_key ) {
		$initial = ( new IssuanceRequestRepository() )->find( $request_id );
		if ( is_wp_error( $initial ) || ! is_array( $initial ) ) { return is_wp_error( $initial ) ? $initial : $this->invalid(); }
		$claim = ( new Transaction() )->run( function () use ( $initial, $notice_key, $job_key ) {
			$ledger = new RecipientNoticeLedger();
			$context = $this->lock( $initial ); if ( is_wp_error( $context ) ) { return $context; }
			// Still lock the original Recipient/request when access has been removed.
			if ( null === $context ) {
				$recipient = ( new RecipientRepository() )->find_for_update( $initial['recipient_id'] ); if ( is_wp_error( $recipient ) ) { return $recipient; }
				$row = ( new IssuanceRequestRepository() )->find( $initial['id'], true ); if ( is_wp_error( $row ) || ! is_array( $row ) ) { return is_wp_error( $row ) ? $row : $this->invalid(); }
			} else { $row = $context['row']; }
			$meta = $ledger->find( $row['id'], RecipientNoticeRecord::event_key( $notice_key, 'queued' ) );
			if ( is_wp_error( $meta ) || ! is_array( $meta ) || $meta['job_key'] !== $job_key ) { return is_wp_error( $meta ) ? $meta : $this->invalid(); }
			foreach ( array( 'sent', 'failed', 'uncertain', 'skipped' ) as $state ) {
				$final = $ledger->find( $row['id'], RecipientNoticeRecord::event_key( $notice_key, $state ) );
				if ( is_wp_error( $final ) ) { return $final; }
				if ( is_array( $final ) ) { return array( 'state' => $state ); }
			}
			$sending = $ledger->find( $row['id'], RecipientNoticeRecord::event_key( $notice_key, 'sending' ) );
			if ( is_wp_error( $sending ) ) { return $sending; }
			if ( is_array( $sending ) ) {
				$sending['state'] = 'uncertain'; $saved = $ledger->append( $row, $sending );
				return is_wp_error( $saved ) ? $saved : array( 'state' => 'uncertain' );
			}
			$choice = null === $context ? null : $this->choice( $row, $context['policy'], $context['user']->ID );
			if ( is_wp_error( $choice ) ) { return $choice; }
			if ( null === $choice || $choice['kind'] !== $meta['kind'] || $choice['slot'] !== $meta['slot'] || (int) $context['user']->ID !== $meta['user_id'] || ! hash_equals( $meta['destination_sha256'], hash( 'sha256', strtolower( trim( $context['user']->user_email ) ) ) ) ) {
				$meta['state'] = 'skipped'; $saved = $ledger->append( $row, $meta );
				return is_wp_error( $saved ) ? $saved : array( 'state' => 'skipped' );
			}
			$token = bin2hex( random_bytes( 16 ) ); $meta['state'] = 'sending'; $meta['attempt_sha256'] = hash( 'sha256', $token );
			$saved = $ledger->append( $row, $meta );
			return is_wp_error( $saved ) ? $saved : array( 'state' => 'sending', 'meta' => $meta, 'recipient' => $context['user']->user_email );
		} );
		if ( is_wp_error( $claim ) || 'sending' !== ( $claim['state'] ?? null ) ) { return $claim; }
		$switched = switch_to_user_locale( $claim['meta']['user_id'] );
		try {
			$messages = array( 'reminder' => __( 'Please sign in to complete the details needed for your certificate request. Your organization reviews approvals and qualification separately.', 'certloom' ), 'completion' => __( 'Your certificate request is complete. Sign in to your account to see your credentials.', 'certloom' ), 'cancellation' => __( 'Your organization has closed a certificate request, or its request deadline has passed. Sign in to review it and contact your organization if you need help.', 'certloom' ) );
			$body = $messages[ $claim['meta']['kind'] ] . "\n\n" . \Certloom\Infrastructure\Recipient\RecipientCollectionUi::account_url();
			$accepted = wp_mail( $claim['recipient'], __( 'Your certificate request', 'certloom' ), $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} finally { if ( $switched ) { restore_previous_locale(); } }
		$meta = $claim['meta']; $meta['state'] = $accepted ? 'sent' : 'failed';
		return ( new Transaction() )->run( function () use ( $initial, $meta ) {
			$recipient = ( new RecipientRepository() )->find_for_update( $initial['recipient_id'] ); if ( is_wp_error( $recipient ) ) { return $recipient; }
			$row = ( new IssuanceRequestRepository() )->find( $initial['id'], true ); if ( is_wp_error( $row ) || ! is_array( $row ) ) { return is_wp_error( $row ) ? $row : $this->invalid(); }
			$ledger = new RecipientNoticeLedger();
			$sending = $ledger->find( $row['id'], RecipientNoticeRecord::event_key( $meta['notice_key'], 'sending' ) );
			if ( is_wp_error( $sending ) || ! is_array( $sending ) || $sending['attempt_sha256'] !== $meta['attempt_sha256'] || $sending['job_key'] !== $meta['job_key'] ) { return is_wp_error( $sending ) ? $sending : $this->invalid(); }
			$uncertain = $ledger->find( $row['id'], RecipientNoticeRecord::event_key( $meta['notice_key'], 'uncertain' ) );
			if ( is_wp_error( $uncertain ) || null !== $uncertain ) { return is_wp_error( $uncertain ) ? $uncertain : array( 'state' => 'uncertain' ); }
			$saved = $ledger->append( $row, $meta );
			return is_wp_error( $saved ) ? $saved : array( 'state' => $meta['state'] );
		} );
	}

	private function lock( array $initial ) {
		global $wpdb;
		$locked = ( new \Certloom\Infrastructure\Recipient\RecipientFieldContext() )->lock( in_array( $initial['status'], array( 'issued', 'cancelled' ), true ) ? 0 : $initial['program_id'], $initial['batch_id'] );
		if ( is_wp_error( $locked ) ) { return $locked; }
		$policy = ( new PendingRequestPolicy() )->read( true ); if ( is_wp_error( $policy ) ) { return $policy; }
		$recipient = ( new RecipientRepository() )->find( $initial['recipient_id'] ); if ( is_wp_error( $recipient ) ) { return $recipient; }
		$id = (int) ( $recipient['wp_user_id'] ?? 0 ); if ( ! $id ) { return null; }
		$account = RecipientAccountLinkService::lock_user( $id ); if ( is_wp_error( $account ) ) { return $account; }
		$wpdb->last_error = '';
		$core_email = $wpdb->get_var( $wpdb->prepare( 'SELECT user_email FROM %i WHERE ID=%d FOR UPDATE', $wpdb->users, $id ) );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		$recipient = ( new RecipientRepository() )->find_for_update( $initial['recipient_id'] ); if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( is_array( $recipient ) ) { $ownership = ( new \Certloom\Infrastructure\Recipient\RestoredAccountOwnership() )->assert_current( $recipient, true ); if ( is_wp_error( $ownership ) ) { return 'certloom_recipient_account_missing' === $ownership->get_error_code() ? null : $ownership; } }
		$user = get_userdata( $id );
		if ( ! is_array( $recipient ) || (int) $recipient['wp_user_id'] !== $id || ! empty( $recipient['privacy_anonymized_at'] ) || ! is_array( $account ) || 0 !== (int) $account['user_status'] || ! $user || ! user_can( $user, 'read' ) || ! empty( $user->user_status ) || ! empty( $user->spam ) || ! empty( $user->deleted ) || ( is_multisite() && ! is_user_member_of_blog( $id ) ) || ! is_email( $user->user_email ) || $core_email !== $user->user_email ) { return null; }
		$row = ( new IssuanceRequestRepository() )->find( $initial['id'], true );
		return is_wp_error( $row ) || ! is_array( $row ) ? ( is_wp_error( $row ) ? $row : $this->invalid() ) : array( 'row' => $row, 'policy' => $policy, 'user' => $user );
	}
	private function choice( array $row, array $policy, $user_id ) {
		if ( 'local' !== $row['restore_state'] || ( new \Certloom\Application\Issuance\IssuanceIntake() )->is_erased( $row ) ) { return null; }
		if ( in_array( $row['status'], array( 'issued', 'cancelled' ), true ) ) {
			$kind = 'issued' === $row['status'] ? 'completion' : 'cancellation';
			if ( ! $policy[ $kind . '_notice' ] ) { return null; }
			$since = ( new PendingRequestPolicy() )->read_notice_since( true ); if ( is_wp_error( $since ) ) { return $since; }
			return null !== $since[ $kind ] && $row['updated_at'] >= $since[ $kind ] ? array( 'kind' => $kind, 'slot' => 0 ) : null;
		}
		if ( 'waiting' !== $row['status'] || ! $policy['reminder_days'] ) { return null; }
		$age = time() - strtotime( $row['created_at'] . ' UTC' );
		$slot = (int) floor( $age / ( $policy['reminder_days'] * DAY_IN_SECONDS ) );
		if ( $slot < 1 || $slot > $policy['reminder_limit'] ) { return null; }
		$form = \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::run( static function () use ( $user_id, $row ) {
			return ( new RecipientCollectionService() )->read_for_user( $user_id, (int) $row['registration_id'], 0, $row['id'] );
		} );
		if ( is_wp_error( $form ) ) { return $form; }
		foreach ( $form['fields'] as $field ) { if ( $field['required'] && in_array( $field['status'], array( 'missing', 'rejected' ), true ) ) { return array( 'kind' => 'reminder', 'slot' => $slot ); } }
		return null;
	}
	private function invalid() { return new \WP_Error( 'certloom_recipient_notice_context_invalid', __( 'The recipient notice could not be confirmed safely.', 'certloom' ) ); }
}
