<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\PendingConfigurationSelectionInterface;
use Certloom\Application\Contract\PendingRequestExecutorInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Domain\Issuance\IssuanceRequestState;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Recheck current authority in ten-row slices after accepted configuration edits. */
final class PendingConfigurationJobHandler implements JobHandlerInterface {
	private $selection; private $executor;
	public function __construct( ?PendingConfigurationSelectionInterface $selection = null, ?PendingRequestExecutorInterface $executor = null ) { $this->selection = $selection ?: new PendingConfigurationQueue(); $this->executor = $executor ?: new PendingIssuanceService(); }
	public function handle( array $job, $worker_token ) {
		$payload = $job['payload'] ?? null;
		if ( ! is_array( $payload ) || count( $payload ) !== 4 || array_diff( array_keys( $payload ), array( 'schema_version', 'generation', 'program_id', 'max_id' ) ) || 1 !== ( $payload['schema_version'] ?? null ) || ! is_string( $payload['generation'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $payload['generation'] ) || ! is_int( $payload['program_id'] ?? null ) || $payload['program_id'] < 0 || ! is_int( $payload['max_id'] ?? null ) || $payload['max_id'] < 1 ) { return $this->invalid(); }
		$cursor = (int) ( $job['cursor'] ?? 0 );
		if ( $cursor < 0 || $cursor > $payload['max_id'] ) { return $this->invalid(); }
		$rows = $this->selection->configuration_rows( $payload['program_id'], $cursor, $payload['max_id'], 11 );
		if ( is_wp_error( $rows ) ) { return $rows; }
		if ( ! is_array( $rows ) || count( $rows ) > 11 ) { return $this->invalid(); }
		$last = $cursor;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['id'] ?? null ) || $row['id'] <= $last || $row['id'] > $payload['max_id'] || ! is_int( $row['program_id'] ?? null ) || $row['program_id'] < 1 || ( $payload['program_id'] && $payload['program_id'] !== $row['program_id'] ) || ! in_array( $row['status'] ?? null, IssuanceRequestState::all(), true ) || ! in_array( $row['restore_state'] ?? null, array( 'local', 'paused', 'reconciled' ), true ) || ! is_string( $row['source_type'] ?? null ) || ! array_key_exists( 'mapping_sha256', $row ) || ( null !== $row['mapping_sha256'] && ( ! is_string( $row['mapping_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $row['mapping_sha256'] ) ) ) ) { return $this->invalid(); }
			$last = $row['id'];
		}
		$result = array( 'checked' => (int) ( $job['result']['checked'] ?? 0 ), 'reassessed' => (int) ( $job['result']['reassessed'] ?? 0 ), 'issued' => (int) ( $job['result']['issued'] ?? 0 ) );
		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			// Configuration is no new qualification, mapping, restore activation or source grant.
			if ( 'local' === $row['restore_state'] && PendingRequestContext::supports_source( $row ) && in_array( $row['status'], PendingConfigurationQueue::STATUSES, true ) ) {
				$receipt = $this->executor->process( $row['id'] );
				if ( is_wp_error( $receipt ) ) { return $receipt; }
				if ( ! is_array( $receipt ) || $row['id'] !== ( $receipt['id'] ?? null ) || ! in_array( $receipt['status'] ?? null, IssuanceRequestState::all(), true ) ) { return $this->invalid(); }
				++$result['reassessed']; if ( 'issued' === $receipt['status'] ) { ++$result['issued']; }
			}
			++$result['checked']; $cursor = $row['id'];
		}
		if ( count( $rows ) > 10 ) { return JobSliceResult::progress( $cursor, 'reassessing_configuration', $result ); }
		$acknowledged = $this->selection->acknowledge( $payload['generation'] );
		return is_wp_error( $acknowledged ) ? $acknowledged : ( true === $acknowledged ? JobSliceResult::succeeded( $result, $cursor ) : $this->invalid() );
	}
	private function invalid() { return new \WP_Error( 'certloom_pending_configuration_job_invalid', __( 'The certificate configuration worker has invalid selection data.', 'certloom' ) ); }
}
