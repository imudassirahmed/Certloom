<?php
namespace Certloom\Application\Issuance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only predicates evaluated over bounded indexed request pages. */
final class PendingRequestFilters {
	public function normalize( array $input ) {
		if ( array_diff( array_keys( $input ), array( 'program_id', 'owner', 'task', 'age', 'as_of' ) ) ) { return $this->invalid(); }
		$filters = array_merge( array( 'program_id' => 0, 'owner' => 'all', 'task' => 'all', 'age' => 'all', 'as_of' => null ), $input );
		if ( ! is_int( $filters['program_id'] ) || $filters['program_id'] < 0 || ! in_array( $filters['owner'], array( 'all', 'recipient', 'organization', 'source' ), true ) || ! in_array( $filters['task'], array( 'all', 'details', 'approval', 'configuration', 'qualification' ), true ) || ! in_array( $filters['age'], array( 'all', 'day', 'week', 'month' ), true ) ) { return $this->invalid(); }
		$now = current_time( 'mysql', true );
		if ( null === $filters['as_of'] ) { $filters['as_of'] = $now; }
		$value = $filters['as_of'];
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) { return $this->invalid(); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value || $value > $now || $date->getTimestamp() < strtotime( $now . ' UTC' ) - 604800 ) { return $this->invalid(); }
		return $filters;
	}

	public function matches( array $row, array $summary, array $filters ) {
		if ( 'all' !== $filters['age'] ) {
			$days = array( 'day' => 1, 'week' => 7, 'month' => 30 );
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $filters['as_of'] . ' UTC' ) - $days[ $filters['age'] ] * 86400 );
			if ( $row['created_at'] > $cutoff ) { return false; }
		}
		$terminal = in_array( $row['status'], array( 'issued', 'cancelled' ), true );
		$config_review = ! $terminal && ( 'local' !== $row['restore_state'] || ! PendingRequestContext::supports_source( $row ) );
		$owners = array_column( $summary['blockers'], 'owner' );
		if ( $config_review ) { $owners[] = 'organization'; }
		if ( ! $terminal && 'not_eligible' === $row['status'] ) { $owners[] = 'source'; }
		if ( 'all' !== $filters['owner'] && ! in_array( $filters['owner'], $owners, true ) ) { return false; }
		if ( 'all' === $filters['task'] ) { return true; }
		if ( $terminal ) { return false; }
		if ( 'configuration' === $filters['task'] && $config_review ) { return true; }
		if ( 'qualification' === $filters['task'] && 'not_eligible' === $row['status'] ) { return true; }
		$codes = array(
			'details' => array( 'missing', 'needs_resubmission', 'design_data_missing', 'registration_required' ),
			'approval' => array( 'awaiting_approval' ),
			'configuration' => array( 'source_mapping_changed', 'source_authority_closed', 'account_connection_unavailable', 'restore_review_required', 'unsupported_source' ),
			'qualification' => array( 'missing_current_evidence', 'missing_source_evidence', 'source_evidence_not_current', 'not_eligible' ),
		);
		$blockers = json_decode( (string) $row['blockers_json'], true ) ?: array();
		return (bool) array_intersect( $codes[ $filters['task'] ], array_column( $blockers, 'code' ) );
	}

	private function invalid() { return new \WP_Error( 'certloom_request_filters_invalid', __( 'Refresh the request filters and try again.', 'certloom' ), array( 'status' => 400 ) ); }
}
