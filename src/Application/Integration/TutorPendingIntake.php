<?php
namespace Certloom\Application\Integration;

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Integration\TutorPendingMappingRepository;
use Certloom\Infrastructure\Integration\TutorCompletionEvidence;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Recipient\CollectedFieldValueProviderFactory;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRegistrationRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A Tutor event creates intent; customer answers cannot replace course completion. */
final class TutorPendingIntake {
	public function receive( $course, $user ) {
		if ( ! is_int( $course ) || $course < 1 || ! is_int( $user ) || $user < 1 || ! \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready() ) { return $this->blocked(); }
		$repo = new TutorPendingMappingRepository(); $initial = $repo->for_course( $course ); if ( is_wp_error( $initial ) ) { return $initial; } if ( ! is_array( $initial ) || ! $initial['active'] ) { return $this->blocked(); }
		$result = ( new Transaction() )->run( function () use ( $repo, $initial, $course, $user ) {
			$locked = ( new RecipientFieldContext() )->lock( $initial['program_id'], $initial['batch_id'] ); if ( is_wp_error( $locked ) ) { return $locked; }
			$mapping = $repo->find( $initial['key'] ); if ( is_wp_error( $mapping ) ) { return $mapping; } if ( ! is_array( $mapping ) || ! $mapping['active'] ) { return $this->blocked(); }
			$account = \Certloom\Application\Recipient\RecipientAccountLinkService::lock_user( $user ); if ( is_wp_error( $account ) ) { return $account; } if ( ! is_array( $account ) || 0 !== (int) $account['user_status'] ) { return $this->blocked(); }
			$recipient = ( new RecipientRepository() )->find_by_wp_user_id( $user ); if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return new \WP_Error( 'certloom_integration_recipient_unlinked', __( 'Connect this learner’s exact WordPress account to a Certloom recipient before receiving a certificate request.', 'certloom' ) ); }
			$locked = ( new CollectedFieldValueProviderFactory() )->lock_context( $recipient['id'], $mapping['program_id'], $mapping['batch_id'] ); if ( is_wp_error( $locked ) ) { return $locked; }
			$requests = new IssuanceRequestRepository(); $key = TutorPendingContext::intake_key( $course, $user );
			$existing = $requests->find_by_intake_key( $key, true ); if ( is_wp_error( $existing ) ) { return $existing; }
			if ( is_array( $existing ) ) {
				if ( (int) $existing['recipient_id'] !== (int) $recipient['id'] || TutorPendingContext::SOURCE !== $existing['source_type'] ) { return $this->blocked(); }
				$existing['idempotent_replay'] = true; return $existing;
			}
			$legacy_key = ( new CanonicalJson() )->hash( array( 'source' => 'lms_integration', 'adapter' => 'tutor_lms', 'source_object' => array( 'type' => 'course', 'id' => (string) $course ), 'event_identity_sha256' => hash( 'sha256', 'course:' . $course . ':user:' . $user ) ) );
			$legacy = ( new CredentialRepository() )->find_version_by_source_idempotency( 'lms_integration', $legacy_key, true ); if ( is_wp_error( $legacy ) ) { return $legacy; }
			if ( ! is_array( $legacy ) ) {
				$older_key = ( new CanonicalJson() )->hash( array( 'source' => 'lms_integration', 'adapter' => 'tutor_lms', 'event_identity_sha256' => hash( 'sha256', 'course:' . $course . ':user:' . $user ) ) );
				$legacy = ( new CredentialRepository() )->find_version_by_source_idempotency( 'lms_integration', $older_key, true ); if ( is_wp_error( $legacy ) ) { return $legacy; }
			}
			if ( is_array( $legacy ) ) { return new \WP_Error( 'certloom_tutor_pending_existing_receipt', __( 'This Tutor completion already has a certificate from the previous flow. Use Credentials to review it; changing the mapping does not issue another certificate.', 'certloom' ) ); }
			$valid = $repo->validate_target( $course, $mapping['program_id'], $mapping['batch_id'] ); if ( is_wp_error( $valid ) ) { return $valid; }
			if ( ! \Ecapla_CertVerify_Numbering::is_enabled() ) { return new \WP_Error( 'certloom_tutor_pending_numbering_required', __( 'Enable automatic certificate numbers before receiving automatic Tutor requests.', 'certloom' ) ); }
			$proof = ( new TutorCompletionEvidence() )->read( $course, $user ); if ( is_wp_error( $proof ) ) { return $proof; }
			$instance = 'tutor-pending:' . $mapping['key'] . ':' . $course . ':' . $user;
			$registration = ( new ProgramRegistrationRepository() )->ensure( $recipient['id'], $mapping['program_id'], $mapping['batch_id'], TutorPendingContext::SOURCE, $instance ); if ( is_wp_error( $registration ) ) { return $registration; }
			return $requests->receive( array( 'recipient_id' => (int) $recipient['id'], 'program_id' => $mapping['program_id'], 'batch_id' => $mapping['batch_id'], 'registration_id' => $registration['id'], 'source_type' => TutorPendingContext::SOURCE, 'source_ref' => $mapping['key'] . ':' . $course . ':' . $user . ':' . $proof['comment_id'] . ':' . $proof['sha256'], 'intake_key' => $key, 'mapping_sha256' => $repo->mapping_sha256( $mapping ) ), array( 'presentation_mode' => 'design', 'design_source' => 'inherited', 'title_source' => 'program', 'valid_from' => substr( $proof['completed_at'], 0, 10 ), 'expiry_mode' => 'program' ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) );
		} );
		if ( is_wp_error( $result ) ) { return $result; }
		$processed = ( new PendingIssuanceService() )->process( $result['id'] ); if ( is_wp_error( $processed ) ) { return $processed; }
		$processed['idempotent_replay'] = ! empty( $result['idempotent_replay'] ); return $processed;
	}
	private function blocked() { return new \WP_Error( 'certloom_tutor_pending_authority_closed', __( 'Tutor customer-detail intake is unavailable until its active mapping and credential-writing authority are ready.', 'certloom' ) ); }
}
