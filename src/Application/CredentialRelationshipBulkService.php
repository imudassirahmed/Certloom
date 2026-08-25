<?php
namespace Certloom\Application;

use Certloom\Application\Contract\CredentialRelationshipRepositoryInterface;
use Certloom\Infrastructure\Repository\CredentialRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reviewed, bounded orchestration over the accepted single-Series correction command. */
final class CredentialRelationshipBulkService {
	const MAX_SELECTED = 100;
	const REVIEW_TTL   = 900;
	/** @var CredentialRelationshipRepositoryInterface */ private $credentials;
	/** @var CredentialRelationshipCorrectionService */ private $corrections;

	public function __construct( ?CredentialRelationshipRepositoryInterface $credentials = null, ?CredentialRelationshipCorrectionService $corrections = null ) {
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->corrections = $corrections ?: new CredentialRelationshipCorrectionService( $this->credentials );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function prepare( array $series_ids, $program_id, $batch_id, $owner_user_id = 0 ) {
		$ids = array_values( array_filter( array_unique( array_map( 'absint', $series_ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		if ( empty( $ids ) || count( $ids ) > self::MAX_SELECTED ) { return new \WP_Error( 'certloom_relationship_bulk_selection_invalid', __( 'Select between 1 and 100 credentials for relationship correction.', 'certloom' ) ); }
		$target = $this->credentials->validate_relationship_target( $program_id, $batch_id );
		if ( is_wp_error( $target ) ) { return $target; }
		$rows = array();
		foreach ( $ids as $id ) {
			$series = $this->credentials->find_series( $id );
			if ( is_wp_error( $series ) ) { return $series; }
			if ( ! $series ) { return new \WP_Error( 'certloom_relationship_bulk_series_missing', __( 'One of the selected credential Series no longer exists.', 'certloom' ) ); }
			$rows[] = array( 'series_id' => $id, 'updated_at' => (string) ( $series['updated_at'] ?? '' ), 'program_id' => $series['program_id'], 'batch_id' => $series['batch_id'] );
		}
		$review = array( 'rows' => $rows, 'target' => $target, 'prepared_at' => time(), 'owner_user_id' => absint( $owner_user_id ) );
		$encoded = wp_json_encode( $review );
		if ( false === $encoded ) { return new \WP_Error( 'certloom_relationship_bulk_review_encode_failed', __( 'Certloom could not encode the relationship review safely.', 'certloom' ) ); }
		$review['digest'] = hash_hmac( 'sha256', $encoded, $this->review_key() );
		return $review;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function commit( array $review, array $context = array() ) {
		$digest = isset( $review['digest'] ) ? (string) $review['digest'] : '';
		$copy = $review; unset( $copy['digest'] );
		$encoded = wp_json_encode( $copy );
		if ( false === $encoded || '' === $digest || ! hash_equals( $digest, hash_hmac( 'sha256', $encoded, $this->review_key() ) ) ) { return new \WP_Error( 'certloom_relationship_bulk_review_corrupt', __( 'The reviewed relationship correction failed its integrity check.', 'certloom' ) ); }
		if ( empty( $review['prepared_at'] ) || absint( $review['prepared_at'] ) + self::REVIEW_TTL < time() ) { return new \WP_Error( 'certloom_relationship_bulk_review_expired', __( 'The reviewed relationship correction expired. Prepare and review it again.', 'certloom' ) ); }
		$owner = absint( $review['owner_user_id'] ?? 0 );
		$actor = absint( $context['actor_user_id'] ?? 0 );
		if ( $owner && $owner !== $actor ) { return new \WP_Error( 'certloom_relationship_bulk_review_owner', __( 'The reviewed relationship correction belongs to another administrator.', 'certloom' ) ); }
		$rows = isset( $review['rows'] ) && is_array( $review['rows'] ) ? $review['rows'] : array();
		if ( empty( $rows ) || count( $rows ) > self::MAX_SELECTED || empty( $review['target'] ) ) { return new \WP_Error( 'certloom_relationship_bulk_review_invalid', __( 'The reviewed relationship correction is invalid.', 'certloom' ) ); }
		$result = array( 'changed' => 0, 'unchanged' => 0, 'failed' => array() );
		foreach ( $rows as $row ) {
			$local = $context;
			$local['expected_updated_at'] = (string) ( $row['updated_at'] ?? '' );
			$local['source_ref'] = isset( $context['source_ref'] ) ? $context['source_ref'] : 'bulk_relationship_correction';
			$changed = $this->corrections->correct( absint( $row['series_id'] ?? 0 ), $review['target']['program_id'] ?? null, $review['target']['batch_id'] ?? null, $local );
			if ( is_wp_error( $changed ) ) { $result['failed'][] = array( 'series_id' => absint( $row['series_id'] ?? 0 ), 'reason' => $changed->get_error_message() ); continue; }
			! empty( $changed['changed'] ) ? $result['changed']++ : $result['unchanged']++;
		}
		return $result;
	}

	/** @return string */
	private function review_key() {
		return wp_salt( 'auth' ) . '|certloom-relationship-review-v1';
	}
}
