<?php
namespace Certloom\Application\Issuance;

use Certloom\Infrastructure\Migration\DomainFeatureGate;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Job\JobRuntime;
use Certloom\Infrastructure\Job\JobDispatcher;
use Certloom\Infrastructure\Recipient\PendingWorkflowHealth;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Staff operations schedule only pending checks; canonical workers keep all issuing authority. */
final class PendingWorkflowOperations {
	public function health( $actor ) {
		$allowed = $this->allowed( $actor ); if ( is_wp_error( $allowed ) ) { return $allowed; }
		$result = ( new PendingWorkflowHealth() )->read();
		if ( ! is_wp_error( $result ) ) { $result['can_repair'] = user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) && DomainFeatureGate::write_paths_ready(); }
		return $result;
	}
	public function repair( $actor ) {
		$allowed = $this->allowed( $actor ); if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( ! user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) || ! DomainFeatureGate::write_paths_ready() ) { return $this->forbidden(); }
		// The shared transaction fence also covers legacy recovery and cron-option writes.
		$result = ( new \Certloom\Infrastructure\Database\Transaction() )->run( static function () {
			$health = ( new PendingWorkflowHealth() )->read(); if ( is_wp_error( $health ) ) { return $health; }
			// Paused work was deliberately stopped. Repair must not silently resume it.
			if ( $health['jobs']['paused']['count'] ) { return new \WP_Error( 'certloom_pending_paused_review_required', __( 'A background check is paused. An administrator can review Background job history in Credential data tools before resuming it. Scheduler repair keeps that decision.', 'certloom' ), array( 'status' => 409 ) ); }
			$watchdog = JobDispatcher::activate(); if ( is_wp_error( $watchdog ) ) { return $watchdog; }
			$coordinator = ( new JobRuntime() )->pending_requests();
			$due = $coordinator->dispatch(); if ( is_wp_error( $due ) ) { return $due; }
			$config = $coordinator->configuration_changed(); if ( is_wp_error( $config ) ) { return $config; }
			$runtime = new JobRuntime();
			$lifecycle = ( new \Certloom\Infrastructure\Recipient\PendingLifecycleCoordinator( $runtime->service(), $runtime->dispatcher() ) )->dispatch(); if ( is_wp_error( $lifecycle ) ) { return $lifecycle; }
			return array( 'outcome' => 'scheduled', 'checks_queued' => ( null !== $due ? 1 : 0 ) + ( null !== $config ? 1 : 0 ) + ( null !== $lifecycle ? 1 : 0 ) );
		} );
		\Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( 'cron', \Certloom\Infrastructure\Recipient\PendingWorkflowCoordinator::JOB_OPTION, \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ) );
		return $result;
	}
	private function allowed( $actor ) {
		if ( ! $actor || ! user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { return $this->forbidden(); }
		if ( ! DomainFeatureGate::admin_preview_ready() || SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) || ( new DomainMaintenanceLock() )->active() ) { return new \WP_Error( 'certloom_pending_operations_unavailable', __( 'Background checks are temporarily unavailable during maintenance or setup.', 'certloom' ), array( 'status' => 503 ) ); }
		return true;
	}
	private function forbidden() { return new \WP_Error( 'certloom_pending_operations_forbidden', __( 'You do not have permission to manage these background checks.', 'certloom' ), array( 'status' => 403 ) ); }
}
