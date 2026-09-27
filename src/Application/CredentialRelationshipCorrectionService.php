<?php
namespace Certloom\Application;

use Certloom\Application\Contract\CredentialRelationshipRepositoryInterface;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\EventRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Immutable correction boundary for Program/Batch relationship changes.
 *
 * Historical Versions remain untouched. A successful correction creates a new
 * Version carrying the corrected relationship context, updates the continuing
 * Series atomically, and appends an audit Event in the same transaction.
 */
final class CredentialRelationshipCorrectionService {
	/** @var CredentialRelationshipRepositoryInterface */ private $credentials;
	/** @var EventRepositoryInterface */ private $events;
	/** @var TransactionInterface */ private $transaction;

	public function __construct( ?CredentialRelationshipRepositoryInterface $credentials = null, ?EventRepositoryInterface $events = null, ?TransactionInterface $transaction = null ) {
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->events      = $events ?: new EventRepository();
		$this->transaction = $transaction ?: new Transaction();
	}

	/**
	 * @param int                 $series_id Credential Series ID.
	 * @param int|null            $program_id Target Program ID.
	 * @param int|null            $batch_id Target Batch ID.
	 * @param array<string,mixed> $context Actor/reason/concurrency context.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function correct( $series_id, $program_id, $batch_id, array $context = array() ) {
		return $this->transaction->run( function () use ( $series_id, $program_id, $batch_id, $context ) {
			$series = $this->credentials->find_series_for_update( $series_id );
			if ( is_wp_error( $series ) ) { return $series; }
			if ( ! $series ) { return new \WP_Error( 'certloom_relationship_series_missing', __( 'The credential series could not be found.', 'certloom' ) ); }

			$expected_updated_at = array_key_exists( 'expected_updated_at', $context ) ? (string) $context['expected_updated_at'] : null;
			if ( null !== $expected_updated_at && $expected_updated_at !== (string) ( $series['updated_at'] ?? '' ) ) {
				return new \WP_Error( 'certloom_relationship_review_stale', __( 'The credential changed after this relationship correction was reviewed. Reload and review it again.', 'certloom' ) );
			}
			$expected_version_id = absint( $context['expected_current_version_id'] ?? 0 );
			if ( ! $expected_version_id ) {
				return new \WP_Error( 'certloom_relationship_review_version_required', __( 'Review this credential again before changing its relationships.', 'certloom' ) );
			}
			if ( $expected_version_id !== absint( $series['current_version_id'] ?? 0 ) ) {
				return new \WP_Error( 'certloom_relationship_review_stale', __( 'The credential changed after this relationship correction was reviewed. Reload and review it again.', 'certloom' ) );
			}

			$target = $this->credentials->validate_relationship_target( $program_id, $batch_id );
			if ( is_wp_error( $target ) ) { return $target; }
			$old_program = ! empty( $series['program_id'] ) ? absint( $series['program_id'] ) : null;
			$old_batch   = ! empty( $series['batch_id'] ) ? absint( $series['batch_id'] ) : null;
			if ( $old_program === $target['program_id'] && $old_batch === $target['batch_id'] ) {
				return array( 'changed' => false, 'series_id' => absint( $series['id'] ), 'version_id' => ! empty( $series['current_version_id'] ) ? absint( $series['current_version_id'] ) : null );
			}
			if ( empty( $series['current_version_id'] ) ) {
				return new \WP_Error( 'certloom_relationship_current_version_required', __( 'A current credential Version is required before an issued relationship can be corrected.', 'certloom' ) );
			}
			$current = $this->credentials->find_version_for_update( $series['current_version_id'] );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! $current || absint( $current['series_id'] ?? 0 ) !== absint( $series['id'] ) ) {
				return new \WP_Error( 'certloom_relationship_current_version_missing', __( 'The current immutable credential Version could not be validated.', 'certloom' ) );
			}

			$snapshot = isset( $current['snapshot'] ) && is_array( $current['snapshot'] ) ? $current['snapshot'] : array();
			$snapshot['__certloom_relationship'] = array(
				'program_id'          => $target['program_id'],
				'batch_id'            => $target['batch_id'],
				'correction_type'     => 'relationship',
				'previous_version_id' => absint( $current['id'] ),
				'previous_program_id' => $old_program,
				'previous_batch_id'   => $old_batch,
			);

			$new_version_id = $this->credentials->create_version(
				absint( $series['id'] ),
				array(
					'previous_version_id' => absint( $current['id'] ),
					'display_number'      => (string) $current['display_number'],
					'issuance_state'      => (string) $current['issuance_state'],
					'legacy_expired'      => ! empty( $current['legacy_expired'] ),
					'valid_from'          => $current['valid_from'] ?? null,
					'issued_at'           => $current['issued_at'] ?? null,
					'expires_at'          => $current['expires_at'] ?? null,
					'grade'               => $current['grade'] ?? null,
					'source_type'         => 'relationship_correction',
					'source_ref'          => isset( $context['source_ref'] ) ? substr( sanitize_text_field( (string) $context['source_ref'] ), 0, 191 ) : null,
					'snapshot'            => $snapshot,
				)
			);
			if ( is_wp_error( $new_version_id ) ) { return $new_version_id; }

			$changed = $this->credentials->set_relationships( absint( $series['id'] ), $old_program, $old_batch, $target['program_id'], $target['batch_id'], (string) ( $series['updated_at'] ?? '' ) );
			if ( is_wp_error( $changed ) ) { return $changed; }
			$current_result = $this->credentials->set_current_version( absint( $series['id'] ), $new_version_id );
			if ( is_wp_error( $current_result ) ) { return $current_result; }

			$event = $this->events->append(
				array(
					'series_id'       => absint( $series['id'] ),
					'version_id'      => $new_version_id,
					'event_type'      => 'credential.relationship_corrected',
					'actor_user_id'   => ! empty( $context['actor_user_id'] ) ? absint( $context['actor_user_id'] ) : null,
					'old_state'       => array( 'program_id' => $old_program, 'batch_id' => $old_batch, 'current_version_id' => absint( $current['id'] ) ),
					'new_state'       => array( 'program_id' => $target['program_id'], 'batch_id' => $target['batch_id'], 'current_version_id' => $new_version_id ),
					'reason_code'     => $context['reason_code'] ?? 'relationship_correction',
					'public_reason'   => $context['public_reason'] ?? null,
					'internal_note'   => $context['internal_note'] ?? null,
					'meta'            => array( 'source_ref' => $context['source_ref'] ?? null ),
				)
			);
			if ( is_wp_error( $event ) ) { return $event; }

			return array( 'changed' => true, 'series_id' => absint( $series['id'] ), 'previous_version_id' => absint( $current['id'] ), 'version_id' => absint( $new_version_id ), 'event_id' => absint( $event ) );
		} );
	}
}
