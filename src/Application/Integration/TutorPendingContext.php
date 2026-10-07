<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Integration\TutorPendingMappingRepository;
use Certloom\Infrastructure\Integration\TutorCompletionEvidence;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRegistrationRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reload the exact saved completion and mapping at every review and final commit. */
final class TutorPendingContext {
	const SOURCE = 'tutor_lms_pending';
	public static function intake_key( $course, $user ) { return FirstPartyCompletionIdentity::pending_key( 'tutor_lms', hash( 'sha256', 'course:' . $course . ':user:' . $user ) ); }
	public static function parse( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(tp-[a-f0-9]{12}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([a-f0-9]{64})$/D', $value, $parts ) ) { return null; }
		foreach ( array( 2,3,4 ) as $i ) { if ( strlen( $parts[ $i ] ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $parts[ $i ] ) === strlen( (string) PHP_INT_MAX ) && strcmp( $parts[ $i ], (string) PHP_INT_MAX ) > 0 ) ) { return null; } }
		return array( 'key' => $parts[1], 'course' => (int) $parts[2], 'user' => (int) $parts[3], 'comment' => (int) $parts[4], 'proof' => $parts[5] );
	}
	public function validate( array $row ) {
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready() ) { return $this->blocked( 'source_authority_closed' ); }
		$ref = self::parse( $row['source_ref'] ?? null );
		if ( ! $ref || ! hash_equals( self::intake_key( $ref['course'], $ref['user'] ), (string) ( $row['intake_key'] ?? '' ) ) ) { return $this->blocked( 'source_mapping_changed' ); }
		$repo = new TutorPendingMappingRepository(); $mapping = $repo->find( $ref['key'] ); if ( is_wp_error( $mapping ) ) { return $mapping; }
		if ( ! is_array( $mapping ) || ! $mapping['active'] || $mapping['course_id'] !== $ref['course'] || $mapping['program_id'] !== (int) $row['program_id'] || $mapping['batch_id'] !== (int) $row['batch_id'] || ! hash_equals( $repo->mapping_sha256( $mapping ), (string) ( $row['mapping_sha256'] ?? '' ) ) ) { return $this->blocked( 'source_mapping_changed' ); }
		$valid = $repo->legacy_clear( $ref['course'] ); if ( is_wp_error( $valid ) ) { return $valid; }
		$recipient = ( new RecipientRepository() )->find( $row['recipient_id'] ); if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) || (int) $recipient['wp_user_id'] !== $ref['user'] ) { return $this->blocked( 'account_connection_unavailable' ); }
		$registration = ( new ProgramRegistrationRepository() )->for_issuance( $row['recipient_id'], $row['program_id'], $row['batch_id'], $row['registration_id'] ); if ( is_wp_error( $registration ) ) { return $registration; }
		$instance = 'tutor-pending:' . $ref['key'] . ':' . $ref['course'] . ':' . $ref['user'];
		if ( ! is_array( $registration ) || self::SOURCE !== $registration['source_type'] || $instance !== $registration['source_ref'] || ! hash_equals( hash( 'sha256', 'certloom-registration-v1:' . self::SOURCE . ':' . $instance ), $registration['instance_key'] ) ) { return $this->blocked( 'source_mapping_changed' ); }
		$proof = ( new TutorCompletionEvidence() )->read( $ref['course'], $ref['user'], $ref['comment'] ); if ( is_wp_error( $proof ) ) { return $proof; }
		if ( ! hash_equals( $proof['sha256'], $ref['proof'] ) ) { return $this->blocked( 'missing_current_evidence' ); }
		$context = ( new RecipientFieldContext() )->load( $registration ); if ( is_wp_error( $context ) ) { return $context; }
		$fields = ( new WebhookFieldMapping() )->validate( array(), $context['contract'], $context['catalog'] ); if ( is_wp_error( $fields ) ) { return $fields; }
		// Completion-only Tutor evidence never supplies arbitrary assessment results.
		$evidence = json_decode( (string) $row['evidence_json'], true );
		if ( ! is_array( $evidence ) || ! empty( $evidence['fields'] ) || 'eligible' !== ( $evidence['eligibility'] ?? null ) ) { return $this->blocked( 'missing_current_evidence' ); }
		return true;
	}
	private function blocked( $reason ) { return new \WP_Error( 'certloom_pending_' . $reason, __( 'This Tutor request needs its original completion, account connection and current mapping before issuance.', 'certloom' ), array( 'reason_code' => $reason ) ); }
}
