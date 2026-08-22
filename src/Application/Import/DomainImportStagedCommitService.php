<?php
namespace Certloom\Application\Import;

use Certloom\Application\Contract\DomainImportStageInterface;
use Certloom\Application\Contract\DomainImportTargetInterface;

/** Commits only previously validated durable import plans, never reparsing the source file during commit. */
final class DomainImportStagedCommitService {
	private $stage;
	private $target;
	public function __construct( DomainImportStageInterface $stage, DomainImportTargetInterface $target ) { $this->stage = $stage; $this->target = $target; }

	/** @return array<string,mixed> */
	public function commit_batch( $job_key, $after_row = 0, $limit = 500, $duplicate_policy = 'skip' ) {
		$duplicate_policy = in_array( $duplicate_policy, array( 'skip', 'correct' ), true ) ? $duplicate_policy : 'skip';
		$rows = $this->commit_rows( $job_key, $after_row, $limit, $duplicate_policy );
		if ( is_wp_error( $rows ) ) { return array( 'created' => 0, 'corrected' => 0, 'failed' => 1, 'last_row' => absint( $after_row ), 'complete' => false, 'errors' => array( array( 'row' => absint( $after_row ), 'code' => $rows->get_error_code(), 'message' => $rows->get_error_message() ) ) ); }
		$result = array( 'created' => 0, 'corrected' => 0, 'replayed' => 0, 'failed' => 0, 'last_row' => absint( $after_row ), 'complete' => empty( $rows ), 'errors' => array() );
		foreach ( $rows as $row ) {
			$row_number = absint( $row['row_number'] ?? 0 );
			$written = $this->target->import( $row_number, (array) ( $row['plan'] ?? array() ), $duplicate_policy );
			$result['last_row'] = $row_number;
			if ( is_wp_error( $written ) ) {
				$result['failed']++;
				$result['errors'][] = array( 'row' => $row_number, 'code' => $written->get_error_code(), 'message' => $written->get_error_message() );
				break;
			}
			$outcome = (string) ( $written['outcome'] ?? '' );
			if ( ! in_array( $outcome, array( 'created', 'corrected' ), true ) ) {
				$result['failed']++;
				$result['errors'][] = array( 'row' => $row_number, 'code' => 'certloom_import_staged_outcome_invalid', 'message' => 'A preflight-ready row did not produce a create/correct result.' );
				break;
			}
			$receipt = $this->stage->mark_committed(
				$job_key,
				$row_number,
				$outcome,
				$written['series_id'] ?? null,
				$written['version_id'] ?? null
			);
			if ( is_wp_error( $receipt ) ) {
				$result['failed']++;
				$result['errors'][] = array( 'row' => $row_number, 'code' => $receipt->get_error_code(), 'message' => $receipt->get_error_message() );
				break;
			}
			$result[ $outcome ]++;
			if ( ! empty( $written['replayed'] ) ) { $result['replayed']++; }
		}
		if ( ! empty( $rows ) && 0 === $result['failed'] ) {
			$next = $this->commit_rows( $job_key, $result['last_row'], 1, $duplicate_policy );
			$result['complete'] = is_array( $next ) && empty( $next );
		}
		return $result;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function commit_rows( $job_key, $after_row, $limit, $duplicate_policy ) {
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$ready = $this->stage->read_batch( $job_key, $after_row, $limit, 'ready' );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( 'correct' !== $duplicate_policy ) {
			return $ready;
		}
		$duplicates = $this->stage->read_batch( $job_key, $after_row, $limit, 'duplicate' );
		if ( is_wp_error( $duplicates ) ) {
			return $duplicates;
		}
		$rows = array_merge( $ready, $duplicates );
		usort( $rows, static function ( $left, $right ) {
			return absint( $left['row_number'] ?? 0 ) <=> absint( $right['row_number'] ?? 0 );
		} );
		return array_slice( $rows, 0, $limit );
	}
}
