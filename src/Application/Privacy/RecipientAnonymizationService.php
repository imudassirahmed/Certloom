<?php
namespace Certloom\Application\Privacy;

use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\PrivacyRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\PrivacyRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transaction-backed anonymization of mutable Recipient profile data.
 *
 * Immutable Credential Versions and historical Events are never rewritten.
 * When credentials exist, one append-only audit event is written per linked
 * Series so the privacy mutation itself remains explainable later.
 */
final class RecipientAnonymizationService {
	const MAX_SYNC_SERIES = 100;

	/** @var PrivacyRepositoryInterface */ private $privacy;
	/** @var EventRepositoryInterface */ private $events;
	/** @var TransactionInterface */ private $transaction;

	public function __construct( ?PrivacyRepositoryInterface $privacy = null, ?EventRepositoryInterface $events = null, ?TransactionInterface $transaction = null ) {
		$this->privacy     = $privacy ?: new PrivacyRepository();
		$this->events      = $events ?: new EventRepository();
		$this->transaction = $transaction ?: new Transaction();
	}

	/**
	 * @param int $recipient_id Recipient ID.
	 * @param array<string,mixed> $context Optional actor/request context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function anonymize( $recipient_id, array $context = array() ) {
		$recipient_id = absint( $recipient_id );
		if ( ! $recipient_id ) {
			return new \WP_Error( 'certloom_privacy_recipient_required', __( 'A valid recipient is required for privacy anonymization.', 'certloom' ) );
		}

		return $this->transaction->run( function () use ( $recipient_id, $context ) {
			$recipient = $this->privacy->find_recipient_for_update( $recipient_id );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) ) {
				return new \WP_Error( 'certloom_privacy_recipient_missing', __( 'The recipient selected for privacy anonymization no longer exists.', 'certloom' ) );
			}

			$series_ids = $this->privacy->series_ids_for_recipient( $recipient_id, self::MAX_SYNC_SERIES + 1 );
			if ( is_wp_error( $series_ids ) ) { return $series_ids; }
			if ( count( $series_ids ) > self::MAX_SYNC_SERIES ) {
				return new \WP_Error( 'certloom_privacy_anonymize_too_many_credentials', __( 'This recipient has too many credentials for the synchronous privacy eraser. No data was changed; use a dedicated administrator retention workflow.', 'certloom' ) );
			}

			$replacement = $this->replacement_name( $recipient_id );
			if ( $this->already_anonymized( $recipient ) ) {
				return array(
					'recipient_id' => $recipient_id,
					'anonymized' => false,
					'already_anonymized' => true,
					'credential_count' => count( $series_ids ),
					'items_retained' => ! empty( $series_ids ),
					'replacement_name' => $replacement,
				);
			}
			$hashes = isset( $recipient['privacy_identity_hashes'] ) && is_array( $recipient['privacy_identity_hashes'] ) ? $recipient['privacy_identity_hashes'] : array();
			$hashes = ( new IdentityDigest() )->merge_email( $hashes, $recipient['email'] ?? null );
			$updated = $this->privacy->anonymize_recipient( $recipient_id, $replacement, (string) ( $recipient['updated_at'] ?? '' ), $hashes );
			if ( is_wp_error( $updated ) ) { return $updated; }

			foreach ( $series_ids as $series_id ) {
				$event = $this->events->append(
					array(
						'series_id'     => absint( $series_id ),
						'event_type'    => 'recipient.profile_anonymized',
						'actor_user_id' => ! empty( $context['actor_user_id'] ) ? absint( $context['actor_user_id'] ) : null,
						'reason_code'   => 'privacy_erasure_request',
						'internal_note' => __( 'Mutable recipient profile data was anonymized through the WordPress personal-data erasure workflow. Immutable credential evidence was retained.', 'certloom' ),
						'meta'          => array(
							'recipient_id' => $recipient_id,
							'policy'       => 'anonymize_mutable_recipient',
						),
					)
				);
				if ( is_wp_error( $event ) ) { return $event; }
			}

			return array(
				'recipient_id'       => $recipient_id,
				'anonymized'         => true,
				'credential_count'   => count( $series_ids ),
				'items_retained'     => ! empty( $series_ids ),
				'replacement_name'   => $replacement,
			);
		} );
	}

	/** @return bool */
	private function already_anonymized( array $recipient ) {
		if ( empty( $recipient['privacy_anonymized_at'] ) ) { return false; }
		foreach ( array( 'first_name', 'middle_name', 'last_name', 'email', 'wp_user_id', 'external_id', 'custom_fields' ) as $key ) {
			$value = $recipient[ $key ] ?? null;
			if ( null !== $value && '' !== $value && array() !== $value ) { return false; }
		}
		return true;
	}

	/** @return string */
	private function replacement_name( $recipient_id ) {
		$secret = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : 'certloom-privacy';
		$token  = strtoupper( substr( hash_hmac( 'sha256', 'recipient:' . absint( $recipient_id ), $secret ), 0, 12 ) );
		return 'Recipient-ANON-' . $token;
	}
}
