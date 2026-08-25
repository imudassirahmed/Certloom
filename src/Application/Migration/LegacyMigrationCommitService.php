<?php
namespace Certloom\Application\Migration;

use Certloom\Application\Contract\LegacyMigrationTargetInterface;
use Throwable;

/**
 * Bounded migration commit processor.
 *
 * Preflight happens before this service. This class deliberately processes a
 * supplied bounded batch only; it never owns long-running loops/transactions.
 */
final class LegacyMigrationCommitService {
	/** @var LegacyCertificateMapper */
	private $mapper;
	/** @var LegacyMigrationTargetInterface */
	private $target;
	/** @var int */
	private $error_detail_limit;

	public function __construct( LegacyMigrationTargetInterface $target, ?LegacyCertificateMapper $mapper = null, $error_detail_limit = 100 ) {
		$this->target = $target;
		$this->mapper = $mapper ?: new LegacyCertificateMapper();
		$this->error_detail_limit = max( 1, (int) $error_detail_limit );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	public function commit( array $rows ) {
		$result = array(
			'total' => 0,
			'migrated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'last_legacy_id' => 0,
			'error_details' => array(),
		);

		foreach ( $rows as $row ) {
			$result['total']++;
			$legacy_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$result['last_legacy_id'] = max( $result['last_legacy_id'], $legacy_id );
			try {
				$plan = $this->mapper->map( $row );
				$written = $this->target->migrate( $legacy_id, $plan );
				if ( is_wp_error( $written ) ) {
					throw new \RuntimeException( $written->get_error_message() );
				}
				$outcome = isset( $written['outcome'] ) ? (string) $written['outcome'] : 'migrated';
				if ( 'skipped' === $outcome ) {
					$result['skipped']++;
				} else {
					$result['migrated']++;
				}
			} catch ( Throwable $error ) {
				$result['failed']++;
				if ( count( $result['error_details'] ) < $this->error_detail_limit ) {
					$result['error_details'][] = array(
						'legacy_id' => $legacy_id ?: null,
						'message' => $error->getMessage(),
					);
				}
				/* Stop at the first unsafe row. Later rows remain untouched for a fresh retry/preflight. */
				break;
			}
		}
		return $result;
	}
}
