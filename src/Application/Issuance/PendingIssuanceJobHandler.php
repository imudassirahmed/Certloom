<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\PendingRequestExecutorInterface;
use Certloom\Application\Contract\PendingRequestSelectionInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Ten indexed requests per slice; durable intent, not this job, owns idempotency. */
final class PendingIssuanceJobHandler implements JobHandlerInterface {
	private $selection; private $executor;
	public function __construct( ?PendingRequestSelectionInterface $selection = null, ?PendingRequestExecutorInterface $executor = null ) { $this->selection = $selection ?: new IssuanceRequestRepository(); $this->executor = $executor ?: new PendingIssuanceService(); }
	public function handle( array $job, $worker_token ) {
		$payload = $job['payload'] ?? null;
		if ( ! is_array( $payload ) || count( $payload ) !== 3 || array_diff( array_keys( $payload ), array( 'schema_version', 'cutoff', 'max_id' ) ) || 1 !== ( $payload['schema_version'] ?? null ) || ! is_int( $payload['max_id'] ?? null ) || $payload['max_id'] < 1 || ! is_string( $payload['cutoff'] ?? null ) ) { return $this->invalid(); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $payload['cutoff'], new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $payload['cutoff'] || $payload['cutoff'] > current_time( 'mysql', true ) ) { return $this->invalid(); }
		$cursor = (int) ( $job['cursor'] ?? 0 );
		if ( $cursor < 0 || $cursor > $payload['max_id'] ) { return $this->invalid(); }
		$ids = $this->selection->due_ids( $payload['cutoff'], $cursor, $payload['max_id'], 11 );
		if ( is_wp_error( $ids ) ) { return $ids; }
		if ( ! is_array( $ids ) || count( $ids ) > 11 ) { return $this->invalid(); }
		$last = $cursor;
		foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id <= $last || $id > $payload['max_id'] ) { return $this->invalid(); } $last = $id; }
		$result = array( 'processed' => (int) ( $job['result']['processed'] ?? 0 ), 'issued' => (int) ( $job['result']['issued'] ?? 0 ), 'waiting' => (int) ( $job['result']['waiting'] ?? 0 ), 'blocked' => (int) ( $job['result']['blocked'] ?? 0 ) );
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			$row = $this->executor->process( $id );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $row ) || $id !== ( $row['id'] ?? null ) || ! in_array( $row['status'] ?? null, \Certloom\Domain\Issuance\IssuanceRequestState::all(), true ) ) { return $this->invalid(); }
			++$result['processed'];
			if ( isset( $result[ $row['status'] ] ) ) { ++$result[ $row['status'] ]; }
			$cursor = $id;
		}
		return count( $ids ) > 10 ? JobSliceResult::progress( $cursor, 'reassessing', $result ) : JobSliceResult::succeeded( $result, $cursor );
	}
	private function invalid() { return new \WP_Error( 'certloom_pending_job_invalid', __( 'The pending-certificate worker has invalid selection data.', 'certloom' ) ); }
}
