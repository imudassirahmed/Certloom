<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Restore\WholeStateRestoreJobHandler;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\MaintenanceMutex;
use Certloom\Infrastructure\Database\DatabaseSession;
use Certloom\Infrastructure\Job\JobRuntime;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit recovery controls cannot race an active native restore worker. */
final class WholeStateRecoveryControl {
	public function rollback( JobRuntime $runtime, $key ) {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) || ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{32}$/D', $key ) ) { return $this->invalid(); }
		return DatabaseSession::run( function () use ( $runtime, $key ) {
			global $wpdb; $name = WholeStateRestoreJobHandler::lock_name( $key );
			$wpdb->last_error = ''; $held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
			if ( '1' !== (string) $held || '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			try {
				$result = MaintenanceMutex::run( function () use ( $runtime, $key ) {
					$job = $runtime->repository()->find( $key );
					if ( ! is_array( $job ) || JobTypes::WHOLE_RESTORE !== $job['job_type'] || ! in_array( $job['status'], array( JobStates::QUEUED, JobStates::PAUSED, JobStates::FAILED, JobStates::CANCELLED ), true ) ) { return $this->invalid(); }
					$journal = ( new WholeStateRestoreJournal() )->read();
					$receipt = ( new WholeStateRestoreWorkspace() )->receipt( $key );
					if ( is_wp_error( $receipt ) || $receipt ) { return $this->invalid(); }
					if ( is_wp_error( $journal ) || ( $journal && ( $journal['owner_token'] !== $key || 'committed' === $journal['phase'] || $journal['source_sha256'] !== ( $job['payload']['source']['sha256'] ?? null ) ) ) ) { return $this->invalid(); }
					if ( JobStates::CANCELLED !== $job['status'] ) { $cancelled = $runtime->cancel_non_running( $key ); if ( is_wp_error( $cancelled ) ) { return $cancelled; } }
					if ( $journal ) {
						$lease = ( new DomainMaintenanceLock() )->acquire( $key, 'whole_restore_rollback', 3600 ); if ( is_wp_error( $lease ) ) { return $lease; }
						$rolled_back = ( new WholeStateRestoreWorkspace() )->rollback( $key ); if ( is_wp_error( $rolled_back ) ) { return $rolled_back; }
						if ( ( new WholeStateRestoreJournal() )->read() ) { return array( 'complete' => false ); }
					}
					$payload = $job['payload'];
					if ( ! is_array( $payload['intent'] ?? null ) || ! is_array( $payload['source'] ?? null ) ) { return $this->invalid(); }
					$cleaned = ( new WholeStateRestoreIntentStore() )->cleanup( $payload['intent'], $payload['source'] );
					return is_wp_error( $cleaned ) ? $cleaned : array( 'complete' => true );
				} );
			} finally { $wpdb->last_error = ''; $released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
			return '1' === (string) $released && '' === (string) $wpdb->last_error ? $result : $this->invalid();
		} );
	}
	private function invalid() { return new \WP_Error( 'certloom_whole_recovery_control_invalid', __( 'Recovery is active or its state changed. Refresh its progress before retrying or returning to the previous data.', 'certloom' ) ); }
}
