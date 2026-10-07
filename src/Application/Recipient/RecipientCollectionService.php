<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRegistrationRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Application\Issuance\PendingRequestContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Authenticated completion is available before a recipient has any Credential. */
final class RecipientCollectionService {
	private $recipients;
	private $registrations;
	private $submissions;
	private $contexts;
	public function __construct() {
		$this->recipients = new RecipientRepository();
		$this->registrations = new ProgramRegistrationRepository();
		$this->submissions = new FieldSubmissionRepository();
		$this->contexts = new RecipientFieldContext();
	}

	public function read_for_user( $user_id, $registration_id = 0, $after_registration_id = 0, $request_id = 0 ) {
		$recipient = $this->recipients->find_by_wp_user_id( $user_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return array( 'linked' => false, 'fields' => array(), 'registrations' => array() ); }
		$registration = $this->registration_for_owner( $recipient, $registration_id );
		if ( is_wp_error( $registration ) ) { return $registration; }
		$context = $this->collection_context( $recipient, $registration, $request_id );
		if ( is_wp_error( $context ) ) { return $context; }
		return $this->view( $recipient, $registration, $context, $after_registration_id );
	}

	public function save_for_user( $user_id, $registration_id, $expected_revision, $values, $request_id = 0 ) {
		if ( ! is_string( $expected_revision ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_revision ) ) { return $this->stale(); }
		$affected_recipient = 0;
		$result = ( new Transaction() )->run( function () use ( $user_id, $registration_id, $expected_revision, $values, $request_id, &$affected_recipient ) {
			$linked = $this->recipients->find_by_wp_user_id( $user_id );
			if ( is_wp_error( $linked ) ) { return $linked; }
			if ( ! is_array( $linked ) ) { return $this->forbidden(); }
			$registration = $this->registration_for_owner( $linked, $registration_id );
			if ( is_wp_error( $registration ) ) { return $registration; }
			$context = $this->collection_context( $linked, $registration, $request_id );
			if ( is_wp_error( $context ) ) { return $context; }
			$scope = array( (int) ( $context['program']['id'] ?? 0 ), (int) ( $context['batch']['id'] ?? 0 ) );
			$locked = $this->contexts->lock( $context['program']['id'] ?? 0, $context['batch']['id'] ?? 0 );
			if ( is_wp_error( $locked ) ) { return $locked; }
			$ownership = new \Certloom\Infrastructure\Recipient\RestoredAccountOwnership();
			if ( $ownership->requires_identity( $linked ) ) { $user_lock = RecipientAccountLinkService::lock_user( (int) $user_id ); if ( is_wp_error( $user_lock ) ) { return $user_lock; } }
			$recipient = $this->recipients->find_for_update( $linked['id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || (int) $recipient['wp_user_id'] !== (int) $user_id || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->forbidden(); }
			$owned = $ownership->assert_current( $recipient, true ); if ( is_wp_error( $owned ) ) { return $owned; }
			return \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::run( function () use ( $recipient, $registration_id, $request_id, $scope, $expected_revision, $values, $user_id, &$affected_recipient ) {
			$registration = $this->registration_for_owner( $recipient, $registration_id, true );
			if ( is_wp_error( $registration ) ) { return $registration; }
			$context = $this->collection_context( $recipient, $registration, $request_id, true );
			if ( is_wp_error( $context ) ) { return $context; }
			if ( $scope !== array( (int) ( $context['program']['id'] ?? 0 ), (int) ( $context['batch']['id'] ?? 0 ) ) ) { return $this->stale(); }
			$current = $this->view( $recipient, $registration, $context );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! hash_equals( $current['revision'], $expected_revision ) ) { return $this->stale(); }
			$kind = is_array( $registration ) ? 'registration' : 'recipient';
			$definitions = $this->editable_definitions( $context, $kind );
			if ( is_wp_error( $definitions ) ) { return $definitions; }
			$patches = ( new RecipientFieldEditValidator() )->validate_form( $values, $definitions, $kind );
			if ( is_wp_error( $patches ) ) { return $patches; }
			foreach ( $patches as $scope => $clean ) {
				if ( ! $clean ) { continue; }
				$result = $this->apply_patch( $recipient, $registration, $definitions, $scope, $clean, $user_id );
				if ( is_wp_error( $result ) ) { return $result; }
			}
			$affected_recipient = (int) $recipient['id'];
			return $this->read_for_user( $user_id, $registration_id, 0, $request_id );
			} );
		} );
		if ( ! is_wp_error( $result ) && $affected_recipient ) { do_action( 'certloom_recipient_fields_changed', $affected_recipient ); }
		return $result;
	}

	/** Caller holds configuration, Recipient and registration locks in one checked transaction. */
	private function apply_patch( array $recipient, $registration, array $definitions, $kind, array $clean, $user_id ) {
		$is_registration = 'registration' === $kind;
		$stored = $is_registration ? $registration['fields'] : $recipient['custom_fields'];
		$scope_id = $is_registration ? (int) $registration['id'] : (int) $recipient['id'];
		$latest_map = $this->submissions->latest_map( $recipient['id'], $kind, $scope_id );
		if ( is_wp_error( $latest_map ) ) { return $latest_map; }
		$changed = false;
		foreach ( $clean as $key => $value ) {
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definitions[ $key ] );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( $policy['approval_required'] ) {
				$latest = $latest_map[ $key ] ?? null;
				if ( is_array( $latest ) && ( new FieldApprovalProof() )->matches( $latest, $definitions[ $key ], $recipient['id'], $kind, $scope_id, $value ) ) { continue; }
				$proof = ( new FieldApprovalProof() )->digest( $definitions[ $key ], $recipient['id'], $kind, $scope_id, $value );
				if ( is_wp_error( $proof ) ) { return $proof; }
				$result = $this->submissions->propose( $recipient['id'], $kind, $scope_id, $key, $value, $proof, $user_id );
				if ( is_wp_error( $result ) ) { return $result; }
			} elseif ( ! array_key_exists( $key, $stored ) || (string) $stored[ $key ] !== $value ) {
				$stored[ $key ] = $value; $changed = true;
			}
		}
		if ( $changed ) {
			$result = $is_registration ? $this->registrations->save_fields( $registration['id'], $registration['revision'], $stored ) : $this->recipients->update( $recipient['id'], array( 'custom_fields' => $stored ) );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	/** Staff decisions use exact revision, policy, owner and candidate; issued history is untouched. */
	public function review_queue( $actor_id, $after_id = 0 ) {
		if ( ! $actor_id || ! user_can( $actor_id, \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS ) ) { return $this->forbidden(); }
		$rows = $this->submissions->pending_for_review( $after_id );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$catalog = \Ecapla_CertVerify_Fields::get_field_map(); $items = array(); $contexts = array();
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			$definition = $catalog[ $row['field_key'] ] ?? null;
			$can_approve = false;
			$context_key = $row['scope_kind'] . ':' . $row['context_program_id'] . ':' . $row['context_batch_id'] . ':' . $row['context_status'];
			if ( ! array_key_exists( $context_key, $contexts ) ) {
				$context = $this->contexts->load( 'registration' === $row['scope_kind'] ? array( 'program_id' => $row['context_program_id'], 'batch_id' => $row['context_batch_id'], 'status' => $row['context_status'] ) : null );
				$contexts[ $context_key ] = is_wp_error( $context ) ? $context : $this->editable_definitions( $context, $row['scope_kind'], true );
			}
			$definitions = $contexts[ $context_key ];
			if ( is_array( $definition ) && ! is_wp_error( $definitions ) && isset( $definitions[ $row['field_key'] ] ) ) {
				$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
				if ( ! is_wp_error( $policy ) && $policy['approval_required'] && ( new FieldCollectionPolicy() )->recipient_editable( $policy ) && $policy['lifetime'] === $row['scope_kind'] ) {
					$proof = ( new FieldApprovalProof() )->digest( $definition, $row['recipient_id'], $row['scope_kind'], $row['scope_id'], $row['candidate_value'] );
					$can_approve = ! is_wp_error( $proof ) && hash_equals( $row['policy_sha256'], $proof );
				}
			}
			$items[] = array( 'id' => (int) $row['id'], 'revision' => (int) $row['revision'], 'recipient_name' => $row['recipient_name'], 'scope' => $row['scope_kind'], 'program_title' => $row['program_title'], 'label' => (string) ( $definition['label'] ?? $row['field_key'] ), 'value' => $row['candidate_value'], 'can_approve' => $can_approve );
		}
		return array( 'items' => $items, 'has_more' => count( $rows ) > 50, 'next_id' => count( $rows ) > 50 ? (int) end( $items )['id'] : null );
	}

	/** Staff decisions use exact revision, policy, owner and candidate; issued history is untouched. */
	public function decide( $submission_id, $expected_revision, $approve, $actor_id ) {
		if ( ! is_bool( $approve ) || ! is_int( $expected_revision ) || $expected_revision < 1 || ! $actor_id || ! user_can( $actor_id, \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS ) ) { return $this->forbidden(); }
		$result = ( new Transaction() )->run( function () use ( $submission_id, $expected_revision, $approve, $actor_id ) {
			$submission = $this->submissions->find( $submission_id );
			if ( is_wp_error( $submission ) ) { return $submission; }
			if ( ! is_array( $submission ) ) { return $this->forbidden(); }
			$registration = 'registration' === $submission['scope_kind'] ? $this->registrations->find( $submission['scope_id'] ) : null;
			if ( is_wp_error( $registration ) ) { return $registration; }
			$locked = $this->contexts->lock( $approve ? ( $registration['program_id'] ?? 0 ) : 0, $approve ? ( $registration['batch_id'] ?? 0 ) : 0 );
			if ( is_wp_error( $locked ) ) { return $locked; }
			$recipient = $this->recipients->find_for_update( $submission['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || ! empty( $recipient['privacy_anonymized_at'] ) ) { return $this->forbidden(); }
			return \Certloom\Infrastructure\Recipient\RecipientCommandReadScope::run( function () use ( $submission, $recipient, $submission_id, $expected_revision, $approve, $actor_id, $registration ) {
			if ( 'registration' === $submission['scope_kind'] ) {
				$registration = $this->registrations->find( $submission['scope_id'], true );
				if ( is_wp_error( $registration ) ) { return $registration; }
				if ( ! is_array( $registration ) || (int) $registration['recipient_id'] !== (int) $recipient['id'] ) { return $this->forbidden(); }
			}
			$submission = $this->submissions->find( $submission_id, true );
			if ( is_wp_error( $submission ) ) { return $submission; }
			if ( ! is_array( $submission ) || 'pending' !== $submission['status'] || (int) $submission['revision'] !== (int) $expected_revision ) { return $this->stale(); }
			if ( ! $approve ) { return $this->submissions->decide( $submission_id, $expected_revision, 'rejected', $actor_id ); }
			$context = $this->contexts->load( $registration );
			if ( is_wp_error( $context ) ) { return $context; }
			$definitions = $this->editable_definitions( $context, $submission['scope_kind'], true );
			if ( is_wp_error( $definitions ) ) { return $definitions; }
			$key = $submission['field_key'];
			if ( ! isset( $definitions[ $key ] ) ) { return $this->stale(); }
			$definition = $definitions[ $key ];
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			if ( ! $policy['approval_required'] ) { return $this->stale(); }
			$proof = ( new FieldApprovalProof() )->digest( $definition, $recipient['id'], $submission['scope_kind'], $submission['scope_id'], $submission['candidate_value'] );
			if ( is_wp_error( $proof ) ) { return $proof; }
			if ( ! hash_equals( $proof, $submission['policy_sha256'] ) ) { return $this->stale(); }
			$clean = ( new RecipientFieldEditValidator() )->validate( array( $key => $submission['candidate_value'] ), $definitions, $submission['scope_kind'] );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( $approve ) {
				$stored = is_array( $registration ) ? $registration['fields'] : $recipient['custom_fields'];
				$stored[ $key ] = $clean[ $key ];
				$result = is_array( $registration ) ? $this->registrations->save_fields( $registration['id'], $registration['revision'], $stored ) : $this->recipients->update( $recipient['id'], array( 'custom_fields' => $stored ) );
				if ( is_wp_error( $result ) ) { return $result; }
			}
			return $this->submissions->decide( $submission_id, $expected_revision, $approve ? 'approved' : 'rejected', $actor_id );
			} );
		} );
		if ( ! is_wp_error( $result ) ) { do_action( 'certloom_recipient_fields_changed', (int) $result['recipient_id'] ); }
		return $result;
	}

	private function view( array $recipient, $registration, $context = null, $after_registration_id = 0 ) {
		$context = is_array( $context ) ? $context : $this->contexts->load( $registration );
		if ( is_wp_error( $context ) ) { return $context; }
		$kind = is_array( $registration ) ? 'registration' : 'recipient';
		$definitions = $this->editable_definitions( $context, $kind );
		if ( is_wp_error( $definitions ) ) { return $definitions; }
		$stored_maps = array( 'recipient' => $recipient['custom_fields'], 'registration' => $registration['fields'] ?? array() );
		$scope_id = is_array( $registration ) ? (int) $registration['id'] : (int) $recipient['id'];
		$fields = array(); $required = array(); $submission_versions = array();
		$latest_maps = array();
		foreach ( array( 'recipient' => $recipient['id'], 'registration' => $registration['id'] ?? 0 ) as $scope => $id ) {
			if ( ! $id ) { continue; }
			$latest_maps[ $scope ] = $this->submissions->latest_map( $recipient['id'], $scope, $id );
			if ( is_wp_error( $latest_maps[ $scope ] ) ) { return $latest_maps[ $scope ]; }
		}
		foreach ( $context['contract']['fields'] as $row ) { $required[ $row['key'] ] = ! empty( $row['required'] ); }
		foreach ( $definitions as $key => $definition ) {
			$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
			if ( is_wp_error( $policy ) ) { return $policy; }
			$field_kind = $policy['lifetime'];
			$field_scope_id = 'recipient' === $field_kind ? (int) $recipient['id'] : $scope_id;
			$value = $stored_maps[ $field_kind ][ $key ] ?? '';
			if ( ! is_scalar( $value ) && null !== $value ) { return $this->stale(); }
			$value = (string) $value;
			$status = '' === trim( $value ) ? 'missing' : 'complete';
			$latest = $latest_maps[ $field_kind ][ $key ] ?? null;
			if ( is_array( $latest ) ) {
				$submission_versions[ $field_kind ][ $key ] = array( (int) $latest['id'], (int) $latest['revision'], $latest['status'] );
				if ( $policy['approval_required'] ) {
					$status = ( new FieldApprovalProof() )->matches( $latest, $definition, $recipient['id'], $field_kind, $field_scope_id, $value ) ? 'complete' : ( 'rejected' === $latest['status'] ? 'rejected' : 'awaiting_approval' );
					$value = (string) $latest['candidate_value'];
					if ( 'complete' === $status && '' === trim( $value ) ) { $status = 'missing'; }
				}
			} elseif ( $policy['approval_required'] && '' !== trim( $value ) ) { $status = 'awaiting_approval'; }
			$fields[] = array( 'key' => $key, 'label' => (string) ( $definition['label'] ?? $key ), 'type' => (string) ( $definition['type'] ?? 'text' ), 'lifetime' => $field_kind, 'value' => $value, 'required' => $required[ $key ] ?? false, 'approval_required' => $policy['approval_required'], 'help' => $policy['help'], 'status' => $status );
		}
		$registrations = array(); $has_more_registrations = false;
		if ( ! is_array( $registration ) ) {
			$rows = $this->registrations->summaries_for_recipient( $recipient['id'], $after_registration_id );
			if ( is_wp_error( $rows ) ) { return $rows; }
			$has_more_registrations = count( $rows ) > 50;
			foreach ( array_slice( $rows, 0, 50 ) as $row ) {
				$registrations[] = array( 'id' => (int) $row['id'], 'title' => (string) $row['title'], 'status' => $row['status'] );
			}
		}
		try {
			$revision = hash_hmac( 'sha256', ( new CanonicalJson() )->encode( array( 'recipient' => (int) $recipient['id'], 'user' => (int) $recipient['wp_user_id'], 'context' => $scope_id, 'kind' => $kind, 'request_id' => $context['request_id'] ?? 0, 'program_id' => $context['program']['id'] ?? 0, 'batch_id' => $context['batch']['id'] ?? 0, 'definitions' => $definitions, 'contract' => $context['contract'], 'stored' => $stored_maps, 'submissions' => $submission_versions, 'registration_revision' => $registration['revision'] ?? null ) ), wp_salt( 'auth' ) );
		} catch ( \Throwable $error ) { return $this->stale(); }
		$issuer_fields = array();
		if ( is_array( $registration ) ) {
			foreach ( $context['contract']['fields'] as $row ) {
				$definition = $context['catalog'][ $row['key'] ] ?? null;
				if ( ! is_array( $definition ) || ! isset( $definition[ FieldCollectionPolicy::KEY ] ) ) { continue; }
				$policy = ( new FieldCollectionPolicy() )->for_definition( $definition );
				if ( is_wp_error( $policy ) ) { return $policy; }
				if ( 'recipient' !== $policy['writer'] && 'internal' !== ( $definition['sensitivity'] ?? '' ) ) {
					$issuer_fields[] = array( 'label' => (string) ( $definition['label'] ?? $row['key'] ), 'help' => $policy['help'] );
				}
			}
		}
		$name_policy = ( new CertificateNamePolicy() )->current( $context['catalog'] );
		if ( is_wp_error( $name_policy ) ) { return $name_policy; }
		$name = array( 'source' => $name_policy['source'], 'value' => 'profile' === $name_policy['source'] ? $recipient['display_name'] : '', 'organization_managed' => isset( $recipient['custom_fields'][ RecipientAccountLink::KEY ] ) && 'organization' === $recipient['custom_fields'][ RecipientAccountLink::KEY ]['name_authority'] );
		return array( 'linked' => true, 'kind' => $kind, 'registration_id' => is_array( $registration ) ? $scope_id : 0, 'request_id' => $context['request_id'] ?? 0, 'title' => $context['program']['title'] ?? __( 'Your certificate details', 'certloom' ), 'revision' => $revision, 'fields' => $fields, 'certificate_name' => $name, 'issuer_fields' => $issuer_fields, 'registrations' => $registrations, 'has_more_registrations' => $has_more_registrations, 'next_registration_id' => $has_more_registrations ? (int) end( $registrations )['id'] : null );
	}

	/** Request IDs are ownership proofs for context only; forms cannot alter qualification. */
	private function collection_context( array $recipient, $registration, $request_id, $lock = false ) {
		if ( ! $request_id ) { return $this->contexts->load( $registration ); }
		$row = ( new IssuanceRequestRepository() )->find( $request_id, $lock );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! is_array( $row ) || (int) $row['recipient_id'] !== (int) $recipient['id'] || (int) ( $row['registration_id'] ?? 0 ) !== (int) ( $registration['id'] ?? 0 ) || 'local' !== $row['restore_state'] || ! PendingRequestContext::supports_source( $row ) ) { return $this->forbidden(); }
		if ( 'waiting' !== $row['status'] ) { return $this->stale(); }
		$context = $this->contexts->load( array( 'program_id' => (int) $row['program_id'], 'batch_id' => (int) ( $row['batch_id'] ?? 0 ), 'status' => $registration['status'] ?? 'active' ) );
		if ( ! is_wp_error( $context ) ) { $context['request_id'] = (int) $row['id']; }
		return $context;
	}

	private function editable_definitions( array $context, $kind, $for_decision = false ) {
		return ( new RecipientCollectionFields() )->select( $context, $kind, $for_decision );
	}

	private function registration_for_owner( array $recipient, $id, $lock = false ) {
		if ( ! $id ) { return null; }
		$row = $this->registrations->find( $id, $lock );
		if ( is_wp_error( $row ) ) { return $row; }
		return is_array( $row ) && (int) $row['recipient_id'] === (int) $recipient['id'] && 'cancelled' !== $row['status'] ? $row : $this->forbidden();
	}
	private function forbidden() { return new \WP_Error( 'certloom_recipient_collection_forbidden', __( 'These details are not linked to this account.', 'certloom' ), array( 'status' => 403 ) ); }
	private function stale() { return new \WP_Error( 'certloom_recipient_collection_stale', __( 'These details or their settings changed. Refresh the form before saving or reviewing again.', 'certloom' ), array( 'status' => 409 ) ); }
}
