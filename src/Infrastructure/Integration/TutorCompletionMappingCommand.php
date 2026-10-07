<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\LegacyWriteGuard;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Serialize the supported v1 admin writer with opt-in pending configuration. */
final class TutorCompletionMappingCommand {
	public function save( array $row, $actor ) {
		if ( ! user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) || ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { return new \WP_Error( 'certloom_tutor_mapping_forbidden', __( 'You do not have permission to configure Tutor issuance.', 'certloom' ) ); }
		$changed = false;
		$result = ( new Transaction() )->run( function () use ( $row, &$changed ) {
			$program = (int) ( $row['program_id'] ?? 0 ); $batch = (int) ( $row['batch_id'] ?? 0 );
			$locked = ( new RecipientFieldContext() )->lock( $program, $batch ); if ( is_wp_error( $locked ) ) { return $locked; }
			$pending = ( new TutorPendingMappingRepository() )->for_course( $row['course_id'] ?? 0 ); if ( is_wp_error( $pending ) ) { return $pending; }
			if ( ! empty( $row['active'] ) && is_array( $pending ) && $pending['active'] ) { return new \WP_Error( 'certloom_tutor_pending_mapping_overlap', __( 'Pause the customer-detail connection before enabling this course’s completion-only mapping.', 'certloom' ) ); }
			$target = ( new \Certloom\Infrastructure\Admin\IntegrationMappingTargetGuard() )->validate( $program, $batch ); if ( is_wp_error( $target ) ) { return $target; }
			$before = ( new TutorLmsMappingStore() )->all();
			$saved = ( new TutorLmsMappingStore() )->save( $row ); if ( is_wp_error( $saved ) ) { return $saved; }
			$verified = $this->verify_write(); if ( is_wp_error( $verified ) ) { return $verified; }
			$changed = $before !== ( new TutorLmsMappingStore() )->all();
			return $changed ? $this->signal() : true;
		} );
		$this->invalidate();
		if ( ! is_wp_error( $result ) && $changed ) { do_action( 'certloom_pending_configuration_changed' ); } return $result;
	}
	public function delete( $course, $actor ) {
		if ( ! is_int( $course ) || $course < 1 || ! user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) || ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { return new \WP_Error( 'certloom_tutor_mapping_forbidden', __( 'You do not have permission to configure Tutor issuance.', 'certloom' ) ); }
		$changed = false;
		$result = ( new Transaction() )->run( function () use ( $course, &$changed ) {
			// Configuration locks suffice for removal, including an orphaned target.
			$locked = ( new RecipientFieldContext() )->lock(); if ( is_wp_error( $locked ) ) { return $locked; }
			$before = ( new TutorLmsMappingStore() )->all();
			if ( ! isset( $before[ (string) $course ] ) ) { return true; }
			$saved = ( new TutorLmsMappingStore() )->delete( $course ); if ( is_wp_error( $saved ) ) { return $saved; }
			$verified = $this->verify_write(); if ( is_wp_error( $verified ) ) { return $verified; }
			$changed = true; return $this->signal();
		} );
		$this->invalidate();
		if ( ! is_wp_error( $result ) && $changed ) { do_action( 'certloom_pending_configuration_changed' ); } return $result;
	}
	private function verify_write() {
		return LegacyWriteGuard::verify_option( TutorLmsMappingStore::OPTION, get_option( TutorLmsMappingStore::OPTION, null ) ) ? true : new \WP_Error( 'certloom_tutor_mapping_save_failed', __( 'Certloom could not save the Tutor LMS mapping safely.', 'certloom' ) );
	}
	private function signal() {
		$generation = ( new DomainGeneration() )->mark_before_mutation(); if ( is_wp_error( $generation ) ) { return $generation; }
		// Changing either flow can release or hold pending work in another Program.
		return ( new PendingConfigurationQueue() )->changed();
	}
	private function invalidate() {
		DatabaseOptions::invalidate( array( TutorLmsMappingStore::OPTION, PendingConfigurationQueue::OPTION, DomainGeneration::OPTION ) ); LegacyWriteGuard::invalidate_cache();
	}
}
