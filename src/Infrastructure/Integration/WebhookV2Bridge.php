<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Integration\WebhookV2Contract;
use Certloom\Application\Integration\WebhookFieldMapping;
use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Migration\DomainFeatureGate;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Recipient\CollectedFieldValueProviderFactory;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRegistrationRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Signed trusted results enter the existing durable intent and canonical issuer. */
final class WebhookV2Bridge {
	public function handle( $source_key, $mode, $timestamp, $signature, $raw, $public_ref = '' ) {
		$repo = new WebhookV2ConnectionRepository(); $source = $repo->find( $source_key );
		if ( is_wp_error( $source ) ) { return $source; }
		if ( ! is_array( $source ) ) { return $this->blocked( 'connection_unavailable' ); }
		$auth = $this->authenticate( $source, $mode, $timestamp, $signature, $raw, $public_ref ); if ( is_wp_error( $auth ) ) { return $auth; }
		if ( 'test' === $mode ) { return $this->test( $source, $raw ); }
		if ( ! DomainFeatureGate::write_paths_ready() ) { return $this->blocked( 'authority_closed' ); }
		return ( new Transaction() )->run( function () use ( $repo, $source, $mode, $timestamp, $signature, $raw, $public_ref ) {
			$lock = ( new RecipientFieldContext() )->lock( $source['program_id'], $source['batch_id'] ); if ( is_wp_error( $lock ) ) { return $lock; }
			$current = $repo->find( $source['key'] ); if ( is_wp_error( $current ) ) { return $current; } if ( ! is_array( $current ) ) { return $this->blocked( 'connection_unavailable' ); }
			$auth = $this->authenticate( $current, $mode, $timestamp, $signature, $raw, $public_ref ); if ( is_wp_error( $auth ) ) { return $auth; }
			return 'evidence' === $mode ? $this->update( $current, $raw, $public_ref ) : $this->receive( $current, $raw );
		} );
	}

	private function authenticate( array $source, $mode, $timestamp, $signature, $raw, $public_ref ) {
		$secret = ( new WebhookV2ConnectionRepository() )->secret( $source ); if ( is_wp_error( $secret ) ) { return $secret; }
		return ( new WebhookV2Contract() )->authenticate( $source['key'], $mode, $timestamp, $signature, $raw, $secret, time(), $public_ref );
	}

	private function receive( array $source, $raw ) {
		$identity = ( new WebhookV2Contract() )->intake_identity( $raw ); if ( is_wp_error( $identity ) ) { return $identity; }
		$event_sha = hash( 'sha256', $identity['event_id'] ); $key = hash( 'sha256', 'certloom-webhook-v2-intake:' . $source['key'] . ':' . $event_sha );
		$ref = $source['key'] . ':' . $event_sha . ':' . hash( 'sha256', $raw );
		$requests = new IssuanceRequestRepository(); $existing = $requests->find_by_intake_key( $key ); if ( is_wp_error( $existing ) ) { return $existing; }
		if ( is_array( $existing ) ) {
			if ( 'webhook_v2' !== $existing['source_type'] || ! is_string( $existing['source_ref'] ) || ! hash_equals( $ref, $existing['source_ref'] ) || 'local' !== $existing['restore_state'] ) { return $this->blocked( 'replay_conflict' ); }
			// Exact original bytes were accepted before; config edits cannot rewrite that receipt.
			return $this->receipt( $existing, true );
		}
		$prepared = $this->prepare( $source, $raw ); if ( is_wp_error( $prepared ) ) { return $prepared; }
		$context = $prepared['context'];
		$lock = ( new CollectedFieldValueProviderFactory() )->lock_context( $context['recipient_id'], $context['program_id'], $context['batch_id'], $context['registration_id'] ); if ( is_wp_error( $lock ) ) { return $lock; }
		$row = $requests->receive( $context, $prepared['request'], $prepared['evidence'] ); if ( is_wp_error( $row ) ) { return $row; }
		$row = ( new PendingIssuanceService( $requests ) )->process( $row['id'] ); return is_wp_error( $row ) ? $row : $this->receipt( $row, false );
	}

	/** The test path makes no configuration, registration, request, job or mail changes. */
	private function test( array $source, $raw ) {
		$prepared = $this->prepare( $source, $raw ); if ( is_wp_error( $prepared ) ) { return $prepared; }
		$json = ( new CanonicalJson() )->encode( array( '_meta' => array( 'schema' => 'certloom-issuance-intake', 'schema_version' => 1 ), 'request' => $prepared['request'], 'initial_evidence' => $prepared['evidence'] ) );
		$row = array_merge( $prepared['context'], array( 'id' => 0, 'public_ref' => str_repeat( '0', 32 ), 'intake_json' => $json, 'payload_sha256' => hash( 'sha256', $json ), 'evidence_json' => ( new CanonicalJson() )->encode( $prepared['evidence'] ), 'evidence_revision' => 1, 'restore_state' => 'local', 'status' => 'received' ) );
		$review = ( new ManualIssuanceService() )->preview_source_intake( $row );
		if ( is_wp_error( $review ) ) { return array( 'status' => 'tested', 'ready' => false, 'mapping_sha256' => $prepared['context']['mapping_sha256'], 'blockers' => array( array( 'code' => $review->get_error_code() ) ), 'mutations' => false ); }
		$blockers = array();
		foreach ( $review['field_resolution']['missing_fields'] as $field ) { $blockers[] = array( 'code' => $field['source_status'] ?? $field['status'], 'field_key' => $field['key'] ); }
		if ( empty( $review['data_readiness']['ready'] ) ) { $blockers[] = array( 'code' => 'design_data_missing' ); }
		return array( 'status' => 'tested', 'ready' => ! $blockers, 'mapping_sha256' => $prepared['context']['mapping_sha256'], 'blockers' => array_slice( $blockers, 0, 128 ), 'mutations' => false );
	}

	private function prepare( array $source, $raw ) {
		if ( ! $source['active'] ) { return $this->blocked( 'connection_paused' ); }
		if ( ! \Ecapla_CertVerify_Numbering::is_enabled() ) { return $this->blocked( 'automatic_numbering_required' ); }
		$context = ( new RecipientFieldContext() )->load( array( 'program_id' => $source['program_id'], 'batch_id' => $source['batch_id'], 'status' => 'active' ) ); if ( is_wp_error( $context ) ) { return $context; }
		$mapping = ( new WebhookFieldMapping() )->validate( $source['fields'], $context['contract'], $context['catalog'] ); if ( is_wp_error( $mapping ) ) { return $mapping; }
		$data = ( new WebhookV2Contract() )->intake( $raw, $mapping ); if ( is_wp_error( $data ) ) { return $data; }
		if ( $data['completed_at'] > current_time( 'mysql', true ) ) { return $this->blocked( 'completion_in_future' ); }
		$recipient = ( new RecipientRepository() )->find_by_external_id_exact( $data['recipient_external_id'] ); if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || empty( $recipient['id'] ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->blocked( 'recipient_unlinked' ); }
		$registration_required = false;
		foreach ( $context['contract']['fields'] as $field ) { $definition = $context['catalog'][ $field['key'] ]; if ( ! isset( $definition[ FieldCollectionPolicy::KEY ] ) ) { continue; } $policy = ( new FieldCollectionPolicy() )->for_definition( $definition ); if ( is_wp_error( $policy ) ) { return $policy; } if ( 'registration' === $policy['lifetime'] ) { $registration_required = true; } }
		if ( $registration_required && ! $data['registration_id'] ) { return $this->blocked( 'exact_registration_required' ); }
		if ( $data['registration_id'] ) { $registration = ( new ProgramRegistrationRepository() )->for_issuance( $recipient['id'], $source['program_id'], $source['batch_id'], $data['registration_id'] ); if ( is_wp_error( $registration ) ) { return $registration; } }
		$evidence = ( new WebhookFieldMapping() )->values( $data['evidence'], $context['catalog'] ); if ( is_wp_error( $evidence ) ) { return $evidence; }
		$event_sha = hash( 'sha256', $data['event_id'] );
		$intent = array( 'recipient_id' => (int) $recipient['id'], 'program_id' => $source['program_id'], 'batch_id' => $source['batch_id'], 'registration_id' => $data['registration_id'], 'source_type' => 'webhook_v2', 'source_ref' => $source['key'] . ':' . $event_sha . ':' . hash( 'sha256', $raw ), 'intake_key' => hash( 'sha256', 'certloom-webhook-v2-intake:' . $source['key'] . ':' . $event_sha ), 'mapping_sha256' => ( new WebhookV2ConnectionRepository() )->mapping_sha256( $source ) );
		$request = array( 'recipient_mode' => 'existing', 'recipient_id' => (int) $recipient['id'], 'credential_basis' => 'program', 'program_id' => $source['program_id'], 'batch_id' => $source['batch_id'], 'registration_id' => $data['registration_id'], 'title_source' => 'program', 'presentation_mode' => 'design', 'design_source' => 'inherited', 'template_id' => 0, 'valid_from' => substr( $data['completed_at'], 0, 10 ), 'expiry_mode' => 'program', 'custom_merge_fields' => array(), 'grade' => '', 'display_number' => '' );
		return array( 'context' => $intent, 'request' => $request, 'evidence' => $evidence );
	}

	private function update( array $source, $raw, $public_ref ) {
		if ( ! $source['active'] ) { return $this->blocked( 'connection_paused' ); }
		$requests = new IssuanceRequestRepository(); $row = $requests->find_by_public_ref( $public_ref ); if ( is_wp_error( $row ) ) { return $row; }
		if ( ! is_array( $row ) || 'webhook_v2' !== $row['source_type'] || 'local' !== $row['restore_state'] || $source['program_id'] !== $row['program_id'] || $source['batch_id'] !== (int) $row['batch_id'] || 0 !== strpos( (string) $row['source_ref'], $source['key'] . ':' ) || ! hash_equals( ( new WebhookV2ConnectionRepository() )->mapping_sha256( $source ), (string) $row['mapping_sha256'] ) ) { return $this->blocked( 'request_context_invalid' ); }
		$context = ( new RecipientFieldContext() )->load( array( 'program_id' => $source['program_id'], 'batch_id' => $source['batch_id'], 'status' => 'active' ) ); if ( is_wp_error( $context ) ) { return $context; }
		$mapping = ( new WebhookFieldMapping() )->validate( $source['fields'], $context['contract'], $context['catalog'] ); if ( is_wp_error( $mapping ) ) { return $mapping; }
		$data = ( new WebhookV2Contract() )->evidence_update( $raw, $mapping ); if ( is_wp_error( $data ) ) { return $data; }
		$evidence = ( new WebhookFieldMapping() )->values( $data['evidence'], $context['catalog'] ); if ( is_wp_error( $evidence ) ) { return $evidence; }
		$lock = ( new CollectedFieldValueProviderFactory() )->lock_context( $row['recipient_id'], $row['program_id'], $row['batch_id'], $row['registration_id'] ); if ( is_wp_error( $lock ) ) { return $lock; }
		$updated = $requests->update_evidence( $row['id'], $data['expected_revision'], hash( 'sha256', 'certloom-webhook-v2-evidence:' . $source['key'] . ':' . $public_ref . ':' . $data['event_id'] ), $evidence, 0, hash( 'sha256', $raw ) );
		if ( is_wp_error( $updated ) ) { return $updated; }
		$replayed = $updated['evidence_revision'] === $row['evidence_revision'];
		$updated = ( new PendingIssuanceService( $requests ) )->process( $row['id'] ); return is_wp_error( $updated ) ? $updated : $this->receipt( $updated, $replayed );
	}
	private function receipt( array $row, $replayed ) { return array( 'status' => $row['status'], 'request_ref' => $row['public_ref'], 'revision' => (int) $row['revision'], 'idempotent_replay' => $replayed, 'version_id' => 'issued' === $row['status'] ? (int) $row['version_id'] : null ); }
	private function blocked( $reason ) { return new \WP_Error( 'certloom_webhook_v2_' . $reason, __( 'This app request cannot proceed with its current connection, identity or issuing rules. Check the request and Program before retrying.', 'certloom' ), array( 'reason_code' => $reason ) ); }
}
