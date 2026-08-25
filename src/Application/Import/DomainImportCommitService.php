<?php
namespace Certloom\Application\Import;

use Certloom\Application\Contract\DomainImportTargetInterface;

/** Bounded deterministic import commit with exact result accounting. */
final class DomainImportCommitService {
	private $mapper;
	private $target;

	public function __construct( DomainImportTargetInterface $target, ?DomainImportRowMapper $mapper = null ) {
		$this->target = $target;
		$this->mapper = $mapper ?: new DomainImportRowMapper();
	}

	/** @return array<string,mixed> */
	public function commit( array $rows, $job_key, $row_offset = 0, $duplicate_policy = 'skip', $allow_historical_expired = false ) {
		$duplicate_policy = in_array( $duplicate_policy, array( 'skip', 'correct' ), true ) ? $duplicate_policy : 'skip';
		$result = array( 'created' => 0, 'corrected' => 0, 'duplicates' => 0, 'failed' => 0, 'last_row' => absint( $row_offset ), 'errors' => array() );
		foreach ( array_values( $rows ) as $index => $row ) {
			$row_number = absint( $row_offset ) + $index + 1;
			try {
				$plan = $this->mapper->map( is_array( $row ) ? $row : array(), $job_key, $row_number, $allow_historical_expired );
			} catch ( \Throwable $error ) {
				$result['failed']++;
				$result['last_row'] = $row_number;
				$result['errors'][] = array( 'row' => $row_number, 'code' => 'preflight_mismatch', 'message' => $error->getMessage() );
				break;
			}
			$written = $this->target->import( $row_number, $plan, $duplicate_policy );
			$result['last_row'] = $row_number;
			if ( is_wp_error( $written ) ) {
				$result['failed']++;
				$result['errors'][] = array( 'row' => $row_number, 'code' => $written->get_error_code(), 'message' => $written->get_error_message() );
				break;
			}
			$outcome = (string) ( $written['outcome'] ?? '' );
			if ( 'created' === $outcome ) { $result['created']++; }
			elseif ( 'corrected' === $outcome ) { $result['corrected']++; }
			elseif ( 'duplicate' === $outcome || 'skipped' === $outcome ) { $result['duplicates']++; }
			else {
				$result['failed']++;
				$result['errors'][] = array( 'row' => $row_number, 'code' => 'unknown_outcome', 'message' => 'Import target returned an unknown outcome.' );
				break;
			}
		}
		return $result;
	}
}
