<?php
namespace Certloom\Application\Backup;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\DomainBackupBuilderInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;

/** Bounded job handler for complete-domain safety backups. */
final class DomainBackupJobHandler implements JobHandlerInterface {
	/** @var DomainBackupBuilderInterface */
	private $builder;

	/** @var string */
	private $plugin_version;

	/** @var int */
	private $batch_size;

	public function __construct( DomainBackupBuilderInterface $builder, $plugin_version, $batch_size = 200 ) {
		$this->builder        = $builder;
		$this->plugin_version = (string) $plugin_version;
		$this->batch_size     = max( 1, min( 500, absint( $batch_size ) ) );
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Worker ownership is enforced by JobRunner/Repository.
		$state = $this->state_from_job( $job );
		if ( null === $state ) {
			$state = $this->builder->start(
				(string) ( $job['job_key'] ?? '' ),
				$this->plugin_version
			);
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		if ( empty( $state['complete'] ) ) {
			$state = $this->builder->continue_build( $state, $this->batch_size );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		$records = absint( $state['records'] ?? 0 );
		if ( ! empty( $state['complete'] ) ) {
			return JobSliceResult::succeeded(
				array(
					'path'         => (string) $state['path'],
					'records'      => $records,
					'chain_sha256' => (string) ( $state['hash'] ?? '' ),
				),
				$records
			);
		}

		return JobSliceResult::progress(
			$records,
			'backup',
			array( 'backup_state' => $state )
		);
	}

	/** @return array<string,mixed>|null */
	private function state_from_job( array $job ) {
		return isset( $job['result']['backup_state'] ) && is_array( $job['result']['backup_state'] )
			? $job['result']['backup_state']
			: null;
	}
}
