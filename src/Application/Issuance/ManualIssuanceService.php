<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Contract\ManualIssuanceNumberAllocatorInterface;
use Certloom\Application\Contract\ManualIssuanceRepositoryInterface;
use Certloom\Application\Contract\PublicCredentialUrlInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Application\CredentialLifecycleService;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\I18n\CredentialLocale;
use Certloom\Application\I18n\ProgramTemplateVariantResolver;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Program\ProgramLifecyclePolicy;
use Certloom\Application\Templates\TemplateFieldCompatibility;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Rendering\TableDataContract;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Issuance\ManualIssuanceNumberAllocator;
use Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl;
use Certloom\Infrastructure\Repository\ManualIssuanceRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * P0 one-at-a-time administrator issuance orchestration.
 *
 * Review is mutation-free. Confirm re-runs the review, compares an exact
 * fingerprint, allocates the human number, then creates Recipient (optional),
 * Series, immutable Version, current pointer and append-only issued event under
 * one checked database transaction. Artifact authority remains a later gate.
 */
final class ManualIssuanceService {
	const SOURCE_TYPE = 'manual_admin';
	const SNAPSHOT_SCHEMA = 'certloom-credential-snapshot';
	const SNAPSHOT_VERSION = 4;
	const MAX_NUMBER_RETRIES = 8;

	/** @var ManualIssuanceRepositoryInterface */ private $repository;
	/** @var ManualIssuanceNumberAllocatorInterface */ private $numbers;
	/** @var PublicCredentialUrlInterface */ private $urls;
	/** @var CredentialLifecycleService */ private $lifecycle;
	/** @var TransactionInterface */ private $transaction;
	/** @var CanonicalJson */ private $json;
	/** @var MergeSnapshotBuilder */ private $merges;
	/** @var callable */ private $clock;

	public function __construct( ?ManualIssuanceRepositoryInterface $repository = null, ?ManualIssuanceNumberAllocatorInterface $numbers = null, ?PublicCredentialUrlInterface $urls = null, ?CredentialLifecycleService $lifecycle = null, ?TransactionInterface $transaction = null, ?CanonicalJson $json = null, ?MergeSnapshotBuilder $merges = null, ?callable $clock = null ) {
		$this->repository = $repository ?: new ManualIssuanceRepository();
		$this->numbers = $numbers ?: new ManualIssuanceNumberAllocator();
		$this->urls = $urls ?: new NormalizedPublicCredentialUrl();
		$this->lifecycle = $lifecycle ?: new CredentialLifecycleService();
		$this->transaction = $transaction ?: new Transaction();
		$this->json = $json ?: new CanonicalJson();
		$this->merges = $merges ?: new MergeSnapshotBuilder();
		$this->clock = $clock ?: static function () { return current_time( 'mysql', true ); };
	}

	/** Mutation-free review used by the administrator confirmation screen. @return array<string,mixed>|\WP_Error */
	public function review( array $request ) {
		return $this->build_review( $request, true, true );
	}

	/**
	 * Mutation-free live compose preview. Missing design/scoped values are
	 * returned as readiness metadata instead of blocking the preview request.
	 * Final Review/Issue always revalidates the same request strictly.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function draft_preview( array $request ) {
		return $this->build_review( $request, false, false, true );
	}

	/**
	 * Mutation-free confirmation revalidation for trusted durable workers.
	 *
	 * Unlike the administrator preflight review, this deliberately does not
	 * reject an existing Recipient+Program Series. That duplicate may be the
	 * exact row created by an earlier worker attempt whose cursor/result save
	 * was interrupted. issue_reviewed_for_source() performs the idempotency
	 * lookup before its ordinary duplicate guard, so a true replay resolves to
	 * the existing immutable Version while a different duplicate still fails.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function revalidate_review( array $request ) {
		return $this->build_review( $request, false, false );
	}


	/** Loads a read-only receipt for one Manual Issuance Version. @return array<string,mixed>|\WP_Error */
	public function receipt( $version_id ) {
		$version = $this->repository->find_version( absint( $version_id ) );
		if ( is_wp_error( $version ) ) { return $version; }
		if ( ! is_array( $version ) || self::SOURCE_TYPE !== (string) ( $version['source_type'] ?? '' ) ) {
			return new \WP_Error( 'certloom_manual_issue_receipt_missing', __( 'That Manual Issuance receipt could not be found.', 'certloom' ) );
		}
		return $this->receipt_from_existing( $version, false );
	}

	/**
	 * Confirms exactly the reviewed request. A retry of an already committed
	 * review is idempotent and returns the existing receipt without allocating a
	 * second number or creating another credential.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function issue( array $request, $expected_review_fingerprint, $actor_user_id ) {
		$expected_review_fingerprint = strtolower( trim( (string) $expected_review_fingerprint ) );
		$idempotency = $this->idempotency_key( self::SOURCE_TYPE, $expected_review_fingerprint );
		return $this->issue_internal(
			$request,
			$expected_review_fingerprint,
			$actor_user_id,
			self::SOURCE_TYPE,
			'user:' . absint( $actor_user_id ),
			$idempotency,
			'manual_issue'
		);
	}

	/**
	 * Trusted reviewed-issuance entry point for the durable Bulk Issuance worker.
	 *
	 * This does not bypass Manual Issuance semantics: the exact row review is
	 * re-executed, duplicate/numbering checks are repeated, and the same atomic
	 * Recipient → Series → Version → lifecycle transaction is used. The caller
	 * may only select Certloom's internal bulk source identity.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function issue_reviewed_for_source( array $request, $expected_review_fingerprint, $actor_user_id, $source_type, $source_ref, $source_idempotency_key, $reason_code = 'bulk_issue' ) {
		$source_type = sanitize_key( (string) $source_type );
		if ( 'bulk_admin' !== $source_type ) {
			return new \WP_Error( 'certloom_issue_source_invalid', __( 'Certloom refused an unsupported issuance source.', 'certloom' ) );
		}
		$source_ref = trim( sanitize_text_field( (string) $source_ref ) );
		if ( '' === $source_ref || $this->length( $source_ref ) > 191 ) {
			return new \WP_Error( 'certloom_issue_source_ref_invalid', __( 'The bulk issuance source reference is invalid.', 'certloom' ) );
		}
		$source_idempotency_key = strtolower( trim( (string) $source_idempotency_key ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $source_idempotency_key ) ) {
			return new \WP_Error( 'certloom_issue_idempotency_invalid', __( 'The bulk issuance row identity is invalid.', 'certloom' ) );
		}
		$reason_code = sanitize_key( (string) $reason_code );
		if ( '' === $reason_code || strlen( $reason_code ) > 64 ) {
			return new \WP_Error( 'certloom_issue_reason_invalid', __( 'The bulk issuance lifecycle reason is invalid.', 'certloom' ) );
		}
		return $this->issue_internal( $request, $expected_review_fingerprint, $actor_user_id, $source_type, $source_ref, $source_idempotency_key, $reason_code );
	}

	/**
	 * Trusted reviewed-issuance entry point for INT-01 integration orchestration.
	 *
	 * The source identity is fixed by Certloom and cannot be caller-selected.
	 * The normalized provider-neutral context and its SHA-256 are frozen into
	 * the immutable Version snapshot; arbitrary provider payloads are rejected
	 * before this boundary by IntegrationEventContext.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function issue_reviewed_for_integration( array $request, $expected_review_fingerprint, $actor_user_id, array $integration_context, $integration_context_sha256, $source_idempotency_key, $source_ref ) {
		$schema = (string) ( $integration_context['_meta']['schema'] ?? '' );
		$version = (int) ( $integration_context['_meta']['schema_version'] ?? 0 );
		if ( 'certloom-integration-event' !== $schema || 1 !== $version ) {
			return new \WP_Error( 'certloom_integration_context_schema_invalid', __( 'Certloom refused an unsupported normalized integration context.', 'certloom' ) );
		}
		$integration_context_sha256 = strtolower( trim( (string) $integration_context_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $integration_context_sha256 ) ) {
			return new \WP_Error( 'certloom_integration_context_hash_invalid', __( 'The normalized integration context fingerprint is invalid.', 'certloom' ) );
		}
		try { $actual_sha = $this->json->hash( $integration_context ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_integration_context_hash_failed', __( 'Certloom could not fingerprint the normalized integration context safely.', 'certloom' ) ); }
		if ( ! hash_equals( $actual_sha, $integration_context_sha256 ) ) {
			return new \WP_Error( 'certloom_integration_context_tampered', __( 'The normalized integration context changed after review. No credential was issued.', 'certloom' ) );
		}
		$source_idempotency_key = strtolower( trim( (string) $source_idempotency_key ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $source_idempotency_key ) ) {
			return new \WP_Error( 'certloom_integration_idempotency_invalid', __( 'The integration source-event identity is invalid.', 'certloom' ) );
		}
		$source_ref = trim( (string) $source_ref );
		if ( '' === $source_ref || $this->length( $source_ref ) > 191 || ! preg_match( '/^[A-Za-z0-9._:@\/\-]+$/', $source_ref ) ) {
			return new \WP_Error( 'certloom_integration_source_ref_invalid', __( 'The integration source reference is invalid.', 'certloom' ) );
		}
		return $this->issue_internal(
			$request,
			$expected_review_fingerprint,
			$actor_user_id,
			'lms_integration',
			$source_ref,
			$source_idempotency_key,
			'integration_issue',
			array( 'context' => $integration_context, 'sha256' => $integration_context_sha256 )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function issue_internal( array $request, $expected_review_fingerprint, $actor_user_id, $source_type, $source_ref, $idempotency, $reason_code, $source_context = null ) {
		$expected_review_fingerprint = strtolower( trim( (string) $expected_review_fingerprint ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_review_fingerprint ) ) {
			return new \WP_Error( 'certloom_manual_issue_review_required', __( 'Review the credential before issuing it.', 'certloom' ) );
		}
		$preflight = $this->build_review( $request, false, false );
		if ( is_wp_error( $preflight ) ) { return $preflight; }
		if ( ! hash_equals( (string) $preflight['review_fingerprint'], $expected_review_fingerprint ) ) {
			return new \WP_Error( 'certloom_manual_issue_review_stale', __( 'The issuance inputs or related records changed after review. Review the credential again before issuing it.', 'certloom' ) );
		}
		$existing = $this->repository->find_version_by_idempotency( $source_type, $idempotency );
		if ( is_wp_error( $existing ) ) { return $existing; }
		if ( is_array( $existing ) ) { return $this->receipt_from_existing( $existing, true ); }
		$duplicate = $this->duplicate_for_review( $preflight );
		if ( is_wp_error( $duplicate ) ) { return $duplicate; }

		$automatic = $this->numbers->is_automatic();
		for ( $attempt = 0; $attempt < self::MAX_NUMBER_RETRIES; $attempt++ ) {
			$number = $this->numbers->allocate( (string) ( $preflight['request']['display_number'] ?? '' ) );
			if ( is_wp_error( $number ) ) { return $number; }
			$issued_at = (string) call_user_func( $this->clock );
			$result = $this->transaction->run( function () use ( $request, $expected_review_fingerprint, $idempotency, $actor_user_id, $number, $issued_at, $source_type, $source_ref, $reason_code, $source_context ) {
				$current = $this->build_review( $request, false, false );
				if ( is_wp_error( $current ) ) { return $current; }
				if ( ! hash_equals( (string) $current['review_fingerprint'], $expected_review_fingerprint ) ) {
					return new \WP_Error( 'certloom_manual_issue_review_stale', __( 'The issuance inputs or related records changed while the credential was being confirmed. No credential was issued.', 'certloom' ) );
				}
				$already = $this->repository->find_version_by_idempotency( $source_type, $idempotency );
				if ( is_wp_error( $already ) ) { return $already; }
				if ( is_array( $already ) ) { return array( 'idempotent_version' => $already ); }
				$duplicate = $this->duplicate_for_review( $current );
				if ( is_wp_error( $duplicate ) ) { return $duplicate; }

				$recipient = $current['recipient'];
				$recipient_id = absint( $recipient['id'] ?? 0 );
				if ( 'new' === $current['request']['recipient_mode'] ) {
					$recipient_id = $this->repository->create_recipient( $current['request']['new_recipient'] );
					if ( is_wp_error( $recipient_id ) ) { return $recipient_id; }
					$recipient = $this->repository->find_recipient( $recipient_id );
					if ( is_wp_error( $recipient ) ) { return $recipient; }
					if ( ! is_array( $recipient ) ) { return new \WP_Error( 'certloom_manual_issue_recipient_create_unreadable', __( 'The new recipient was created but could not be re-read safely. Issuance was rolled back.', 'certloom' ) ); }
				}
				$series_id = $this->repository->create_series( array(
					'recipient_id' => $recipient_id,
					'program_id' => absint( $current['program']['id'] ?? 0 ),
					'batch_id' => ! empty( $current['batch']['id'] ) ? absint( $current['batch']['id'] ) : null,
					'issuer_id' => ! empty( $current['issuer']['id'] ) ? absint( $current['issuer']['id'] ) : null,
					'administrative_state' => States::ADMIN_ACTIVE,
					'compliance_state' => States::COMPLIANCE_NOT_APPLICABLE,
					'visibility' => 'verification_summary',
				) );
				if ( is_wp_error( $series_id ) ) { return $series_id; }
				$series = $this->repository->find_series( $series_id );
				if ( is_wp_error( $series ) ) { return $series; }
				if ( ! is_array( $series ) || empty( $series['public_id'] ) ) { return new \WP_Error( 'certloom_manual_issue_series_unreadable', __( 'The new credential identity could not be re-read safely. Issuance was rolled back.', 'certloom' ) ); }
				$verification_url = $this->urls->verification_url( (string) $series['public_id'] );
				if ( is_wp_error( $verification_url ) ) { return $verification_url; }
				$display_number = (string) ( $number['certificate_number'] ?? '' );
				$credential_facts = array(
					'display_number' => $display_number,
					'public_id' => (string) $series['public_id'],
					'valid_from' => $current['valid_from'],
					'expires_at' => $current['expires_at'],
					'issued_at' => $issued_at,
					'grade' => $current['request']['grade'],
					'title' => (string) ( $current['request']['credential_title'] ?? '' ),
					'score' => (string) ( $current['field_values']['score'] ?? $current['request']['custom_merge_fields']['score'] ?? '' ),
					'verification_url' => $verification_url,
				);
				foreach ( $current['request']['repeating_data'] as $key => $rows ) { $credential_facts[ $key ] = $rows; }
				$layout = isset( $current['template_revision']['layout'] ) && is_array( $current['template_revision']['layout'] ) ? $current['template_revision']['layout'] : array();
				$merge = $this->merges->build( $recipient, $current['program'], $current['issuer'], $credential_facts, $layout, $this->credential_custom_values( $current['field_contract'], $current['field_values'], $current['request']['custom_merge_fields'], $current['request']['design_field_aliases'] ?? array() ), $current['signer'], false, $current['batch'] ?? null );
				if ( is_wp_error( $merge ) ) { return $merge; }
				$snapshot = $this->snapshot( $current, $recipient, $series, $credential_facts, $merge, $expected_review_fingerprint, $actor_user_id, $source_type, $source_ref, $source_context );
				if ( is_wp_error( $snapshot ) ) { return $snapshot; }
				$version_id = $this->repository->create_version( $series_id, array(
					'display_number' => $display_number,
					'issuance_state' => States::ISSUANCE_DRAFT,
					'valid_from' => $current['valid_from'],
					'issued_at' => $issued_at,
					'expires_at' => $current['expires_at'],
					'grade' => $current['request']['grade'],
					'source_type' => $source_type,
					'source_ref' => $source_ref,
					'source_idempotency_key' => $idempotency,
					'snapshot' => $snapshot,
				) );
				if ( is_wp_error( $version_id ) ) { return $version_id; }
				$transition = $this->lifecycle->issue_within_transaction( $series_id, $version_id, array(
					'actor_user_id' => absint( $actor_user_id ),
					'reason_code' => $reason_code,
					'internal_note' => null,
					'meta' => array(
						'source' => $source_type,
						'source_ref' => $source_ref,
						'integration_context_sha256' => is_array( $source_context ) ? (string) ( $source_context['sha256'] ?? '' ) : null,
						'review_fingerprint' => $expected_review_fingerprint,
						'program_id' => absint( $current['program']['id'] ?? 0 ),
						'program_title' => (string) ( $current['program']['title'] ?? '' ),
						'issuer_id' => absint( $current['issuer']['id'] ?? 0 ),
						'issuer_name' => (string) ( $current['issuer']['name'] ?? '' ),
						'signer_id' => ! empty( $current['signer']['id'] ) ? absint( $current['signer']['id'] ) : null,
						'signer_name' => (string) ( $current['signer']['display_name'] ?? '' ),
						'signer_resolution' => (string) ( $current['signer_resolution']['mode'] ?? 'none' ),
						'batch_id' => ! empty( $series['batch_id'] ) ? absint( $series['batch_id'] ) : null,
						'batch_name' => null,
						'template_id' => absint( $current['template']['id'] ?? 0 ),
						'template_name' => (string) ( $current['template']['name'] ?? '' ),
						'template_revision_id' => absint( $current['template_revision']['id'] ?? 0 ),
						'presentation_mode' => (string) ( $current['request']['presentation_mode'] ?? 'details_only' ),
						'certificate_image_id' => absint( $current['request']['certificate_image_id'] ?? 0 ),
					),
				) );
				if ( is_wp_error( $transition ) ) { return $transition; }
				$version = $this->repository->find_version( $version_id );
				if ( is_wp_error( $version ) ) { return $version; }
				return is_array( $version ) ? array( 'version' => $version ) : new \WP_Error( 'certloom_manual_issue_version_unreadable', __( 'The issued credential could not be re-read safely. Issuance was rolled back.', 'certloom' ) );
			} );

			if ( ! is_wp_error( $result ) ) {
				if ( isset( $result['idempotent_version'] ) && is_array( $result['idempotent_version'] ) ) {
					return $this->receipt_from_existing( $result['idempotent_version'], true );
				}
				if ( isset( $result['version'] ) && is_array( $result['version'] ) ) {
					$receipt = $this->receipt_from_existing( $result['version'], false );
					if ( ! is_wp_error( $receipt ) ) {
						$automation_source = $source_type;
						if ( 'lms_integration' === $source_type && is_array( $source_context ) ) {
							$adapter = sanitize_key( (string) ( $source_context['context']['adapter'] ?? '' ) );
							if ( '' !== $adapter ) {
								$automation_source = $adapter;
							}
						}
						if ( function_exists( 'do_action' ) ) {
							\do_action( 'certloom_credential_issued', absint( $receipt['version_id'] ), absint( $receipt['series_id'] ), absint( $actor_user_id ), $automation_source, (string) $source_ref );
						}
					}
					return $receipt;
				}
			}
			$after = $this->repository->find_version_by_idempotency( $source_type, $idempotency );
			if ( ! is_wp_error( $after ) && is_array( $after ) ) { return $this->receipt_from_existing( $after, true ); }
			if ( $automatic && is_wp_error( $result ) && 'certloom_display_number_duplicate' === $result->get_error_code() ) { continue; }
			return $result;
		}
		return new \WP_Error( 'certloom_manual_issue_number_race_exhausted', __( 'Certificate-number collisions continued while issuing. Nothing was duplicated; review the numbering policy and try again.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function build_review( array $request, $include_number_preview, $check_duplicate, $allow_missing_required_fields = false ) {
		$request = $this->normalize_request( $request );
		if ( is_wp_error( $request ) ) { return $request; }
		if ( 'existing' === $request['recipient_mode'] ) {
			$recipient = $this->repository->find_recipient( $request['recipient_id'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) ) { return new \WP_Error( 'certloom_manual_issue_recipient_missing', __( 'Choose a recipient that still exists.', 'certloom' ) ); }
		} else {
			$recipient = $this->repository->validate_new_recipient( $request['new_recipient'] );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			$recipient['id'] = null;
		}
		$program = array();
		if ( ! empty( $request['program_id'] ) ) {
			$program = $this->repository->find_program( $request['program_id'] );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_manual_issue_program_missing', __( 'Choose a Program that still exists, or leave Program blank for standalone issuance.', 'certloom' ) ); }
			if ( empty( $program['active'] ) ) { return new \WP_Error( 'certloom_manual_issue_program_inactive', __( 'Inactive Programs cannot receive new credential issuances.', 'certloom' ) ); }
		}
		$program_lifecycle = null;
		if ( ! empty( $program['id'] ) ) {
			$program_lifecycle = ( new ProgramLifecyclePolicy() )->resolve( $program );
			if ( is_wp_error( $program_lifecycle ) ) { return $program_lifecycle; }
		}
		$title_resolution = ( new EffectiveCredentialTitleResolver() )->resolve( (string) $request['title_source'], (string) $request['credential_title'], $program );
		if ( is_wp_error( $title_resolution ) ) { return $title_resolution; }
		$request['credential_title'] = (string) $title_resolution['title'];
		$request['title_source'] = (string) $title_resolution['source'];
		$request['title_program_id'] = absint( $title_resolution['program_id'] ?? 0 );
		$batch = null;
		if ( ! empty( $request['batch_id'] ) ) {
			$batch = $this->repository->find_batch( $request['batch_id'] );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) ) { return new \WP_Error( 'certloom_manual_issue_batch_missing', __( 'Choose a Batch that still exists.', 'certloom' ) ); }
			if ( empty( $batch['active'] ) ) { return new \WP_Error( 'certloom_manual_issue_batch_inactive', __( 'Inactive Batches cannot receive new credential issuances.', 'certloom' ) ); }
			if ( absint( $batch['program_id'] ?? 0 ) !== absint( $program['id'] ?? 0 ) ) { return new \WP_Error( 'certloom_manual_issue_batch_program_mismatch', __( 'The selected Batch belongs to a different Program.', 'certloom' ) ); }
		}
		if ( is_array( $program_lifecycle ) && ProgramLifecyclePolicy::REPEAT_BATCH === (string) ( $program_lifecycle['repeat_policy'] ?? '' ) && ! is_array( $batch ) ) {
			return new \WP_Error( 'certloom_manual_issue_repeat_batch_required', __( 'This Program issues one credential per Batch or session. Choose a Batch before reviewing the credential.', 'certloom' ) );
		}
		$issuer = null;
		$program_issuer_id = absint( $program['issuer_id'] ?? 0 );
		$explicit_issuer_id = absint( $request['issuer_id'] ?? 0 );
		if ( $program_issuer_id && $explicit_issuer_id && $program_issuer_id !== $explicit_issuer_id ) {
			return new \WP_Error( 'certloom_manual_issue_issuer_program_mismatch', __( 'The selected Issuer does not match the selected Program. Use the Program’s Issuer or clear the explicit Issuer.', 'certloom' ) );
		}
		$effective_issuer_id = $program_issuer_id ?: $explicit_issuer_id;
		if ( $effective_issuer_id ) {
			$issuer = $this->repository->find_issuer( $effective_issuer_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
			if ( ! is_array( $issuer ) ) { return new \WP_Error( 'certloom_manual_issue_issuer_missing', __( 'The selected Issuer no longer exists.', 'certloom' ) ); }
		}

		$catalog = $this->field_catalog();
		$global_contract = ( new ScopedFieldGlobalRepository() )->get( $catalog );
		if ( is_wp_error( $global_contract ) ) { return $global_contract; }
		$issuer_contract = is_array( $issuer ) && is_array( $issuer['field_contract'] ?? null ) ? $issuer['field_contract'] : null;
		$field_contract = ( new ScopedFieldContract() )->resolve_hierarchy(
			$global_contract,
			$issuer_contract,
			is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program(),
			is_array( $batch ) && is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : null,
			$catalog
		);
		if ( is_wp_error( $field_contract ) ) { return $field_contract; }
		// Hierarchy contracts define reusable fields, but an individual issued
		// credential may still carry genuine one-off catalog-backed facts required
		// by its selected design. Add those facts to the immutable issuance contract
		// without mutating Global/Issuer/Program/Batch configuration.
		$field_contract = $this->issuance_field_contract( $field_contract, $request, empty( $program['id'] ) );
		$field_values = $this->resolve_field_values( $field_contract, $recipient, $request, $allow_missing_required_fields );
		if ( is_wp_error( $field_values ) ) { return $field_values; }

		$signer_resolution = $this->resolve_signer( $request, $issuer, $program, $batch );
		if ( is_wp_error( $signer_resolution ) ) { return $signer_resolution; }
		$signer = isset( $signer_resolution['signer'] ) && is_array( $signer_resolution['signer'] ) ? $signer_resolution['signer'] : null;
		$locale = ( new CredentialLocale() )->resolve( ! empty( $program ) ? ( $program['profile']['credential_locale'] ?? CredentialLocale::SITE_DEFAULT ) : CredentialLocale::SITE_DEFAULT );
		if ( is_wp_error( $locale ) ) { return $locale; }
		$template = array();
		$revision = array( 'layout' => array() );
		$template_resolution = 'design' === (string) $request['presentation_mode'] ? 'explicit' : 'none';
		$template_resolution_details = null;
		$template_locale_variant = null;
		if ( 'design' === (string) $request['presentation_mode'] && empty( $request['template_id'] ) ) {
			$template_resolution = 'explicit';
			// Canonical design precedence is:
			// Global → Issuer → Program → Batch, with the most-specific direct
			// assignment winning. Program locale variants live at Program scope,
			// so a direct Batch assignment must win over a locale variant too.
			$resolved = $this->repository->resolve_published_template_for_context( absint( $program['id'] ?? 0 ), absint( $batch['id'] ?? 0 ), absint( $issuer['id'] ?? 0 ) );
			if ( is_wp_error( $resolved ) ) { return $resolved; }
			if ( is_array( $resolved ) ) { $template_resolution_details = $resolved; }

			if ( is_array( $resolved ) && 'batch' === (string) ( $resolved['scope_type'] ?? '' ) && ! empty( $resolved['template_id'] ) ) {
				$request['template_id'] = absint( $resolved['template_id'] );
				$template_resolution = 'batch';
			} else {
				$variant = ! empty( $program ) ? ( new ProgramTemplateVariantResolver() )->resolve( isset( $program['profile'] ) && is_array( $program['profile'] ) ? $program['profile'] : array(), $locale['effective'] ) : null;
				if ( is_wp_error( $variant ) ) { return $variant; }
				if ( is_array( $variant ) && ! empty( $variant['template_key'] ) ) {
					$variant_template = $this->repository->find_published_template_by_machine_key( (string) $variant['template_key'] );
					if ( is_wp_error( $variant_template ) || ! is_array( $variant_template ) || ! empty( $variant_template['archived_at'] ) || empty( $variant_template['published_revision'] ) ) { return new \WP_Error( 'certloom_manual_issue_locale_template_unavailable', __( 'The Program locale maps to a certificate design that is no longer published. Review the Program language variants before issuing.', 'certloom' ) ); }
					$request['template_id'] = absint( $variant_template['id'] ?? 0 );
					$template_resolution = (string) ( $variant['resolution_scope'] ?? 'program_locale_fallback' );
					$template_resolution_details = array(
						'scope_type'  => 'program_locale',
						'scope_id'    => absint( $program['id'] ?? 0 ),
						'template_id' => absint( $variant_template['id'] ?? 0 ),
					);
					$template_locale_variant = $variant;
				} elseif ( is_array( $resolved ) && ! empty( $resolved['template_id'] ) ) {
					$request['template_id'] = absint( $resolved['template_id'] );
					$template_resolution = (string) ( $resolved['scope_type'] ?? 'assigned' );
				} else {
					return new \WP_Error( 'certloom_manual_issue_program_design_missing', __( 'This credential does not resolve to a published certificate design. Configure the inheritance chain Global → Issuer → Program → Batch, or choose a one-off design before issuing.', 'certloom' ) );
				}
			}
		}
		if ( 'design' === (string) $request['presentation_mode'] ) {
			$template = $this->repository->find_published_template( $request['template_id'] );
			if ( is_wp_error( $template ) ) {
				if ( null !== $template_locale_variant ) { return new \WP_Error( 'certloom_manual_issue_locale_template_unavailable', __( 'The Program locale maps to a certificate design that is no longer published. Review the Program language variants before issuing.', 'certloom' ) ); }
				return $template;
			}
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['published_revision'] ) ) {
				return new \WP_Error( null !== $template_locale_variant ? 'certloom_manual_issue_locale_template_unavailable' : 'certloom_manual_issue_template_unavailable', null !== $template_locale_variant ? __( 'The Program locale maps to a certificate design that is no longer published. Review the Program language variants before issuing.', 'certloom' ) : __( 'Choose an active published template.', 'certloom' ) );
			}
			$revision = $template['published_revision'];
			$template_fields = ( new TemplateFieldCompatibility() )->validate(
				(array) ( $revision['layout'] ?? array() ),
				is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program(),
				is_array( $batch ) && is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : null,
				$this->field_catalog()
			);
			if ( is_wp_error( $template_fields ) ) { return $template_fields; }
		}
		$expires_at = $this->expiry_date( $request, $program, $program_lifecycle );
		if ( is_wp_error( $expires_at ) ) { return $expires_at; }
		if ( is_array( $program_lifecycle ) && 'required' === (string) ( $program_lifecycle['renewal'] ?? '' ) && null === $expires_at ) {
			return new \WP_Error( 'certloom_manual_issue_renewal_requires_expiry', __( 'This Program requires renewal after expiry, so this credential must have an expiry date.', 'certloom' ) );
		}
		$numbering = null;
		if ( $include_number_preview ) {
			$numbering = $this->numbers->review( $request['display_number'] );
			if ( is_wp_error( $numbering ) ) { return $numbering; }
		}
		$preview_display_number = $this->numbers->is_automatic()
			? ( $include_number_preview && is_array( $numbering ) && ! empty( $numbering['certificate_number'] ) ? (string) $numbering['certificate_number'] : 'AUTO-PENDING' )
			: $request['display_number'];
		$probe_facts = array(
			'display_number' => $preview_display_number,
			'public_id' => 'PUBLIC-ID-PENDING',
			'valid_from' => $request['valid_from'],
			'expires_at' => $expires_at,
			'grade' => $request['grade'],
			'title' => (string) $request['credential_title'],
			'score' => (string) ( $field_values['score'] ?? $request['custom_merge_fields']['score'] ?? '' ),
			'verification_url' => 'https://example.invalid/certloom/pending',
		);
		foreach ( $request['repeating_data'] as $key => $rows ) { $probe_facts[ $key ] = $rows; }
		$layout = isset( $revision['layout'] ) && is_array( $revision['layout'] ) ? $revision['layout'] : array();
		$merge_probe = $this->merges->build( $recipient, $program, $issuer, $probe_facts, $layout, $this->credential_custom_values( $field_contract, $field_values, $request['custom_merge_fields'], $request['design_field_aliases'] ?? array() ), $signer, 'design' === (string) $request['presentation_mode'], $batch );
		if ( is_wp_error( $merge_probe ) ) { return $merge_probe; }
		$required_merge_tokens = $this->merges->required_tokens( $layout );
		$data_readiness = ( new IssuanceDataReadiness() )->inspect(
			$required_merge_tokens,
			$merge_probe,
			array(
				'program_id' => absint( $program['id'] ?? 0 ),
				'batch_id'   => absint( $batch['id'] ?? 0 ),
				'issuer_id'  => absint( $issuer['id'] ?? 0 ),
				'signer_id'  => absint( $signer['id'] ?? 0 ),
				'expires_at' => $expires_at,
			),
			$this->field_catalog()
		);
		if ( 'design' === (string) $request['presentation_mode'] && ! $allow_missing_required_fields && empty( $data_readiness['ready'] ) ) {
			$missing = isset( $data_readiness['fields'] ) && is_array( $data_readiness['fields'] ) ? array_values( array_filter( $data_readiness['fields'], static function ( $row ) { return is_array( $row ) && 'missing' === (string) ( $row['status'] ?? '' ); } ) ) : array();
			$first = isset( $missing[0]['label'] ) ? (string) $missing[0]['label'] : __( 'required design data', 'certloom' );
			return new \WP_Error(
				'certloom_manual_issue_design_data_missing',
				sprintf( /* translators: %s: first missing design field. */ __( 'Complete the data required by the selected design before review. First missing field: %s.', 'certloom' ), $first ),
				array( 'fields' => $missing, 'readiness' => $data_readiness )
			);
		}
		$fingerprint_payload = array(
			'contract' => 'certloom-manual-issuance-review',
			'version' => 1,
			'recipient_mode' => $request['recipient_mode'],
			'recipient' => $this->recipient_fingerprint( $recipient ),
			'program' => $this->program_fingerprint( $program ),
			'program_lifecycle' => $program_lifecycle,
			'batch' => $this->batch_fingerprint( $batch ),
			'field_contract' => $field_contract,
			'field_values' => $field_values,
			'issuer' => $this->issuer_fingerprint( $issuer ),
			'signer' => $this->signer_fingerprint( $signer, $signer_resolution ),
			'presentation_mode' => (string) $request['presentation_mode'],
			'certificate_image_id' => absint( $request['certificate_image_id'] ?? 0 ),
			'credential_basis' => (string) ( $request['credential_basis'] ?? ( ! empty( $program ) ? 'program' : 'independent' ) ),
			'title_source' => (string) ( $request['title_source'] ?? 'custom' ),
			'credential_title' => (string) $request['credential_title'],
			'template' => 'design' === (string) $request['presentation_mode'] ? $this->template_fingerprint( $template, $revision ) : null,
			'design_source' => (string) ( $request['design_source'] ?? 'inherited' ),
			'template_resolution' => $template_resolution,
			'template_resolution_scope_id' => is_array( $template_resolution_details ) ? absint( $template_resolution_details['scope_id'] ?? 0 ) : 0,
			'valid_from' => $request['valid_from'],
			'expires_at' => $expires_at,
			'expiry_mode' => $request['expiry_mode'],
			'grade' => $request['grade'],
			'numbering_policy' => $this->numbers->policy_identity(),
			'manual_display_number' => $this->numbers->is_automatic() ? '' : $request['display_number'],
			'custom_merge_fields' => $request['custom_merge_fields'],
			'design_field_aliases' => $request['design_field_aliases'],
			'repeating_data' => $request['repeating_data'],
			'credential_locale' => $locale,
			'template_locale_variant' => $template_locale_variant,
		);
		try { $fingerprint = $this->json->hash( $fingerprint_payload ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_manual_issue_review_hash_failed', __( 'Certloom could not create a deterministic issuance review fingerprint.', 'certloom' ) ); }
		$out = array(
			'request' => $request,
			'recipient' => $recipient,
			'program' => $program,
			'program_lifecycle' => $program_lifecycle,
			'batch' => $batch,
			'field_contract' => $field_contract,
			'field_values' => $field_values,
			'issuer' => $issuer,
			'signer' => $signer,
			'signer_resolution' => $signer_resolution,
			'template' => $template,
			'template_revision' => $revision,
			'template_resolution' => $template_resolution,
			'template_resolution_details' => $template_resolution_details,
			'credential_locale' => $locale,
			'template_locale_variant' => $template_locale_variant,
			'valid_from' => $request['valid_from'],
			'expires_at' => $expires_at,
			'numbering' => $numbering,
			'required_merge_tokens' => $required_merge_tokens,
			'data_readiness' => $data_readiness,
			'preview_merge_values' => $merge_probe,
			'review_fingerprint' => $fingerprint,
		);
		if ( $check_duplicate ) {
			$duplicate = $this->duplicate_for_review( $out );
			if ( is_wp_error( $duplicate ) ) { return $duplicate; }
		}
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_request( array $request ) {
		$mode = sanitize_key( (string) ( $request['recipient_mode'] ?? 'existing' ) );
		if ( ! in_array( $mode, array( 'existing', 'new' ), true ) ) { return new \WP_Error( 'certloom_manual_issue_recipient_mode_invalid', __( 'Choose an existing recipient or create a new recipient.', 'certloom' ) ); }
		$recipient_id = 'existing' === $mode ? absint( $request['recipient_id'] ?? 0 ) : 0;
		if ( 'existing' === $mode && ! $recipient_id ) { return new \WP_Error( 'certloom_manual_issue_recipient_required', __( 'Choose an existing recipient.', 'certloom' ) ); }
		$program_id = absint( $request['program_id'] ?? 0 );
		$credential_basis = sanitize_key( (string) ( $request['credential_basis'] ?? ( $program_id ? 'program' : 'independent' ) ) );
		if ( ! in_array( $credential_basis, array( 'program', 'independent' ), true ) ) { return new \WP_Error( 'certloom_manual_issue_basis_invalid', __( 'Choose a Program credential or an independent credential.', 'certloom' ) ); }
		if ( 'program' === $credential_basis && ! $program_id ) { return new \WP_Error( 'certloom_manual_issue_program_required', __( 'Choose a Program, or switch to Independent credential.', 'certloom' ) ); }
		if ( 'independent' === $credential_basis ) { $program_id = 0; }
		$credential_title = trim( sanitize_text_field( (string) ( $request['credential_title'] ?? '' ) ) );
		if ( $this->length( $credential_title ) > 255 ) { return new \WP_Error( 'certloom_manual_issue_title_too_long', __( 'Credential title must be 255 characters or fewer.', 'certloom' ) ); }
		$title_source = sanitize_key( (string) ( $request['title_source'] ?? ( $program_id && '' === $credential_title ? 'program' : 'custom' ) ) );
		if ( ! in_array( $title_source, array( 'program', 'custom' ), true ) ) { return new \WP_Error( 'certloom_manual_issue_title_source_invalid', __( 'Choose whether to inherit the Program title or use a custom credential title.', 'certloom' ) ); }
		if ( ! $program_id ) { $title_source = 'custom'; }
		// The service predates the 1.7 appearance selector and historically always
		// issued through a published design. Keep that default for durable workers
		// and integrations that have not yet started sending presentation_mode.
		$presentation_mode = sanitize_key( (string) ( $request['presentation_mode'] ?? 'design' ) );
		if ( ! in_array( $presentation_mode, array( 'details_only', 'image', 'design' ), true ) ) { return new \WP_Error( 'certloom_manual_issue_presentation_invalid', __( 'Choose a supported credential appearance.', 'certloom' ) ); }
		$certificate_image_id = absint( $request['certificate_image_id'] ?? 0 );
		if ( 'image' === $presentation_mode && ! $certificate_image_id ) { return new \WP_Error( 'certloom_manual_issue_image_required', __( 'Choose a PNG or JPEG certificate image.', 'certloom' ) ); }
		$batch_id = absint( $request['batch_id'] ?? 0 );
		$issuer_id = absint( $request['issuer_id'] ?? 0 );
		$template_id = absint( $request['template_id'] ?? 0 );
		$signer_id = absint( $request['signer_id'] ?? 0 );
		$design_source = 'none';
		if ( 'design' === $presentation_mode ) {
			$design_source = sanitize_key( (string) ( $request['design_source'] ?? ( $template_id ? 'assigned' : 'inherited' ) ) );
			if ( ! in_array( $design_source, array( 'inherited', 'assigned' ), true ) ) { return new \WP_Error( 'certloom_manual_issue_design_source_invalid', __( 'Choose whether to use the inherited design or an assigned design.', 'certloom' ) ); }
			if ( 'assigned' === $design_source && ! $template_id ) { return new \WP_Error( 'certloom_manual_issue_template_required', __( 'Choose a published design for this credential.', 'certloom' ) ); }
			if ( 'inherited' === $design_source ) { $template_id = 0; }
		} else {
			$template_id = 0;
		}
		if ( $batch_id && ! $program_id ) { return new \WP_Error( 'certloom_manual_issue_batch_program_required', __( 'Choose the Program that owns the selected Batch, or clear the Batch for standalone issuance.', 'certloom' ) ); }
		$valid_from = $this->date( $request['valid_from'] ?? '' );
		if ( is_wp_error( $valid_from ) ) { return new \WP_Error( 'certloom_manual_issue_valid_from_invalid', __( 'Choose a valid credential effective date.', 'certloom' ) ); }
		$expiry_mode = sanitize_key( (string) ( $request['expiry_mode'] ?? ( $program_id ? 'program' : 'permanent' ) ) );
		if ( ! in_array( $expiry_mode, array( 'program', 'permanent', 'date' ), true ) ) { return new \WP_Error( 'certloom_manual_issue_expiry_mode_invalid', __( 'Choose the Program default, a permanent credential, or a specific expiry date.', 'certloom' ) ); }
		if ( 'program' === $expiry_mode && ! $program_id ) { return new \WP_Error( 'certloom_manual_issue_program_expiry_requires_program', __( 'Program validity can only be used when a Program is selected. Choose No expiry or a specific expiry date for standalone credentials.', 'certloom' ) ); }
		$expires_at = '';
		if ( 'date' === $expiry_mode ) {
			$expires_at = $this->date( $request['expires_at'] ?? '' );
			if ( is_wp_error( $expires_at ) ) { return new \WP_Error( 'certloom_manual_issue_expiry_invalid', __( 'Choose a valid credential expiry date.', 'certloom' ) ); }
			if ( $expires_at < $valid_from ) { return new \WP_Error( 'certloom_manual_issue_expiry_before_start', __( 'The expiry date cannot be earlier than the credential effective date.', 'certloom' ) ); }
		}
		$grade = trim( sanitize_text_field( (string) ( $request['grade'] ?? '' ) ) );
		if ( $this->length( $grade ) > 191 ) { return new \WP_Error( 'certloom_manual_issue_grade_too_long', __( 'Grade must be 191 characters or fewer.', 'certloom' ) ); }
		$new = array();
		if ( 'new' === $mode ) {
			foreach ( array( 'display_name', 'first_name', 'middle_name', 'last_name', 'external_id' ) as $key ) { $new[ $key ] = sanitize_text_field( (string) ( $request['new_recipient'][ $key ] ?? '' ) ); }
			$raw_email = trim( (string) ( $request['new_recipient']['email'] ?? '' ) );
			$new['email'] = '' === $raw_email ? '' : sanitize_email( $raw_email );
			$new['wp_user_id'] = absint( $request['new_recipient']['wp_user_id'] ?? 0 );
			$new['custom_fields'] = $this->scalar_map( $request['new_recipient']['custom_fields'] ?? array() );
			if ( is_wp_error( $new['custom_fields'] ) ) { return $new['custom_fields']; }
		}
		$display_number = trim( sanitize_text_field( (string) ( $request['display_number'] ?? '' ) ) );
		$custom_merge_fields = $this->scalar_map( $request['custom_merge_fields'] ?? array() );
		if ( is_wp_error( $custom_merge_fields ) ) { return $custom_merge_fields; }
		$design_field_aliases = $this->design_field_alias_map( $request['design_field_aliases'] ?? array() );
		if ( is_wp_error( $design_field_aliases ) ) { return $design_field_aliases; }
		$repeating_data = $this->repeating_data( $request['repeating_data'] ?? null );
		if ( is_wp_error( $repeating_data ) ) { return $repeating_data; }
		return array(
			'recipient_mode' => $mode,
			'recipient_id' => $recipient_id,
			'new_recipient' => $new,
			'credential_basis' => $credential_basis,
			'title_source' => $title_source,
			'credential_title' => $credential_title,
			'presentation_mode' => $presentation_mode,
			'certificate_image_id' => $certificate_image_id,
			'program_id' => $program_id,
			'batch_id' => $batch_id,
			'issuer_id' => $issuer_id,
			'template_id' => $template_id,
			'design_source' => $design_source,
			'signer_id' => $signer_id,
			'valid_from' => $valid_from,
			'expiry_mode' => $expiry_mode,
			'expires_at' => $expires_at,
			'grade' => $grade,
			'display_number' => $this->numbers->is_automatic() ? '' : $display_number,
			'custom_merge_fields' => $custom_merge_fields,
			'design_field_aliases' => $design_field_aliases,
			'repeating_data' => $repeating_data,
		);
	}

	/** @return array<string,array<int,array<string,string>>>|\WP_Error */
	private function repeating_data( $value ) {
		if ( null === $value || '' === $value ) { return array(); }
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'subjects', 'results' ) ) ) {
			return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'Repeating result/subject data must use the supported subjects/results rowsets only.', 'certloom' ) );
		}
		$out = array();
		try {
			if ( array_key_exists( 'subjects', $value ) ) { $out['subjects'] = TableDataContract::normalize_rows( TableDataContract::SUBJECTS, $value['subjects'] ); }
			if ( array_key_exists( 'results', $value ) ) { $out['results'] = TableDataContract::normalize_rows( TableDataContract::RESULTS, $value['results'] ); }
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'Repeating result/subject data contains unsupported fields, values, or too many rows.', 'certloom' ) );
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	private function field_catalog() { return class_exists( 'Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array(); }

	/** Resolve exact FLD-02 values without letting mutable Program/Batch settings leak past issuance. @return array<string,string>|\WP_Error */
	private function resolve_field_values( array $contract, array $recipient, array $request, $allow_missing_required = false ) {
		$rows = isset( $contract['fields'] ) && is_array( $contract['fields'] ) ? $contract['fields'] : array();
		$submitted = isset( $request['custom_merge_fields'] ) && is_array( $request['custom_merge_fields'] ) ? $request['custom_merge_fields'] : array();
		$configured = ! empty( $contract['_meta']['configured'] );
		$assigned = array();
		foreach ( $rows as $row ) { $key = sanitize_key( (string) ( $row['key'] ?? '' ) ); if ( '' !== $key ) { $assigned[ $key ] = true; } }
		if ( $configured && '' !== trim( (string) ( $request['grade'] ?? '' ) ) && ! isset( $assigned['grade'] ) ) {
			return new \WP_Error( 'certloom_manual_issue_unassigned_grade', __( 'Grade / result is not assigned to the selected Program/Batch credential-field contract.', 'certloom' ) );
		}
		if ( empty( $rows ) ) { return array(); }
		$catalog = $this->field_catalog();
		$allowed_custom = array(); $values = array();
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) ); if ( '' === $key || ! isset( $catalog[ $key ] ) ) { continue; }
			$source = (string) ( $row['value_source'] ?? ScopedFieldContract::value_source( $key ) );
			if ( 'recipient' === $source ) { $raw = $recipient[ $key ] ?? ''; }
			elseif ( 'credential' === $source && 'grade' === $key ) { $raw = $request['grade'] ?? ''; }
			else { $raw = $submitted[ $key ] ?? ''; $allowed_custom[ $key ] = true; }
			$clean = \Ecapla_CertVerify_Validator::custom_field_value( $raw, $catalog[ $key ] );
			if ( is_wp_error( $clean ) ) { return $clean; }
			if ( ! $allow_missing_required && ! empty( $row['required'] ) && '' === trim( (string) $clean ) ) { return new \WP_Error( 'certloom_manual_issue_required_field_missing', sprintf( /* translators: %s: label. */ __( 'Complete required credential field “%s” before issuing.', 'certloom' ), (string) ( $row['label'] ?? $key ) ) ); }
			$values[ $key ] = (string) $clean;
		}
		ksort( $values, SORT_STRING ); return $values;
	}

	/** Merge reusable contract values with issued-only credential details. @return array<string,string> */
	private function credential_custom_values( array $contract, array $values, array $one_off, array $aliases = array() ) {
		$rows = isset( $contract['fields'] ) && is_array( $contract['fields'] ) ? $contract['fields'] : array();
		// One-off values are valid even when a Program has adopted FLD-02. They are
		// frozen only on this Credential Version and never mutate the reusable contract.
		$out = $one_off;
		foreach ( $rows as $row ) {
			if ( 'credential_custom' !== (string) ( $row['value_source'] ?? '' ) ) { continue; }
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' !== $key && array_key_exists( $key, $values ) ) { $out[ $key ] = (string) $values[ $key ]; }
		}
		foreach ( $aliases as $target => $source ) {
			$target = sanitize_key( (string) $target ); $source = sanitize_key( (string) $source );
			if ( '' === $target || '' === $source || ! array_key_exists( $source, $out ) || array_key_exists( $target, $out ) ) { continue; }
			$out[ $target ] = (string) $out[ $source ];
		}
		ksort( $out, SORT_STRING ); return $out;
	}

	/**
	 * Independent credentials may deliberately carry catalog-backed details even
	 * though no Program field contract exists. Build an immutable ad-hoc contract
	 * only for submitted, globally defined fields so Details-only credentials can
	 * present those facts with the same privacy metadata as Program credentials.
	 * Legacy unknown custom merge keys remain render-only for compatibility.
	 *
	 * @return array<string,mixed>
	 */
	private function issuance_field_contract( array $contract, array $request, $independent = false ) {
		$catalog = $this->field_catalog();
		$submitted = isset( $request['custom_merge_fields'] ) && is_array( $request['custom_merge_fields'] ) ? $request['custom_merge_fields'] : array();
		$rows = isset( $contract['fields'] ) && is_array( $contract['fields'] ) ? array_values( $contract['fields'] ) : array();
		$base_count = count( $rows );
		$existing = array();
		foreach ( $rows as $row ) { if ( is_array( $row ) ) { $key = sanitize_key( (string) ( $row['key'] ?? '' ) ); if ( '' !== $key ) { $existing[ $key ] = true; } } }
		foreach ( array_keys( $submitted ) as $raw_key ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key || isset( $existing[ $key ] ) || ! isset( $catalog[ $key ] ) || ! is_array( $catalog[ $key ] ) || ! ScopedFieldContract::is_scopable_definition( $catalog[ $key ] ) || 'credential_custom' !== ScopedFieldContract::value_source( $key ) ) { continue; }
			$field = $catalog[ $key ];
			$rows[] = array(
				'key' => $key,
				'label' => (string) ( $field['label'] ?? $key ),
				'type' => (string) ( $field['type'] ?? 'text' ),
				'sensitivity' => (string) ( $field['sensitivity'] ?? 'personal' ),
				'public_exposure' => (string) ( $field['public_exposure'] ?? 'private' ),
				'required' => false,
				'requirement' => 'optional',
				'origin' => 'credential',
				'value_source' => 'credential_custom',
			);
			$existing[ $key ] = true;
		}
		$contract['fields'] = $rows;
		if ( ! isset( $contract['_meta'] ) || ! is_array( $contract['_meta'] ) ) { $contract['_meta'] = array(); }
		if ( $independent ) { $contract['_meta']['independent'] = true; }
		if ( count( $rows ) > $base_count ) { $contract['_meta']['one_off'] = true; }
		return $contract;
	}

	/** @return array<string,string>|\WP_Error */
	private function design_field_alias_map( $value ) {
		if ( null === $value || '' === $value ) { return array(); }
		if ( ! is_array( $value ) || count( $value ) > 30 ) { return new \WP_Error( 'certloom_manual_issue_design_field_aliases_invalid', __( 'Design field mappings must be a bounded list.', 'certloom' ) ); }
		$out = array();
		foreach ( $value as $target => $source ) {
			$target = sanitize_key( (string) $target ); $source = sanitize_key( (string) $source );
			if ( '' === $target || '' === $source || strlen( $target ) > 64 || strlen( $source ) > 64 ) { return new \WP_Error( 'certloom_manual_issue_design_field_aliases_invalid', __( 'Design field mappings contain an invalid field key.', 'certloom' ) ); }
			$out[ $target ] = $source;
		}
		ksort( $out, SORT_STRING ); return $out;
	}

	/** Resolve an explicit signer override or the deterministic Batch → Program → Issuer → global hierarchy. @return array<string,mixed>|\WP_Error */
	private function resolve_signer( array $request, $issuer, array $program, $batch ) {
		$explicit_id = absint( $request['signer_id'] ?? 0 );
		if ( $explicit_id ) {
			$signer = $this->repository->find_signer( $explicit_id );
			if ( is_wp_error( $signer ) ) { return $signer; }
			if ( ! is_array( $signer ) || empty( $signer['active'] ) ) {
				return new \WP_Error( 'certloom_manual_issue_signer_unavailable', __( 'The selected Signer is no longer active or available. Review the credential again.', 'certloom' ) );
			}
			return array( 'mode' => 'explicit', 'signer' => $signer, 'scope_type' => null, 'scope_id' => null, 'assignment_id' => null, 'priority' => null, 'assignment_updated_at' => null );
		}
		$candidates = $this->repository->resolve_signers_for_context( absint( $issuer['id'] ?? 0 ), absint( $program['id'] ?? 0 ), absint( $batch['id'] ?? 0 ), 'signer' );
		if ( is_wp_error( $candidates ) ) { return $candidates; }
		if ( empty( $candidates ) ) {
			return array( 'mode' => 'none', 'signer' => null, 'scope_type' => null, 'scope_id' => null, 'assignment_id' => null, 'priority' => null, 'assignment_updated_at' => null );
		}
		$row = reset( $candidates );
		if ( ! is_array( $row ) || empty( $row['signer_id'] ) ) {
			return new \WP_Error( 'certloom_manual_issue_signer_resolution_invalid', __( 'Certloom could not resolve the configured Signer hierarchy safely.', 'certloom' ) );
		}
		$signer = array(
			'id' => absint( $row['signer_id'] ),
			'display_name' => (string) ( $row['display_name'] ?? '' ),
			'slug' => (string) ( $row['slug'] ?? '' ),
			'title' => null === ( $row['title'] ?? null ) ? null : (string) $row['title'],
			'email' => null === ( $row['email'] ?? null ) ? null : (string) $row['email'],
			'signature_attachment_id' => ! empty( $row['signature_attachment_id'] ) ? absint( $row['signature_attachment_id'] ) : null,
			'active' => true,
			'updated_at' => (string) ( $row['signer_updated_at'] ?? '' ),
		);
		return array(
			'mode' => 'hierarchy',
			'signer' => $signer,
			'scope_type' => (string) ( $row['scope_type'] ?? '' ),
			'scope_id' => absint( $row['scope_id'] ?? 0 ),
			'assignment_id' => absint( $row['id'] ?? 0 ),
			'priority' => absint( $row['priority'] ?? 0 ),
			'assignment_updated_at' => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/** @return string|null|\WP_Error */
	private function expiry_date( array $request, array $program, $program_lifecycle = null ) {
		if ( 'permanent' === $request['expiry_mode'] ) { return null; }
		if ( 'date' === $request['expiry_mode'] ) { return $request['expires_at']; }
		if ( empty( $program['id'] ) ) { return new \WP_Error( 'certloom_manual_issue_program_expiry_requires_program', __( 'Program validity can only be used when a Program is selected.', 'certloom' ) ); }
		if ( ! is_array( $program_lifecycle ) ) {
			$program_lifecycle = ( new ProgramLifecyclePolicy() )->resolve( $program );
			if ( is_wp_error( $program_lifecycle ) ) { return $program_lifecycle; }
		}
		return ( new ProgramLifecyclePolicy() )->expiry_date( $request['valid_from'], $program_lifecycle );
	}

	/** @return true|\WP_Error */
	private function duplicate_for_review( array $review ) {
		if ( 'existing' !== (string) ( $review['request']['recipient_mode'] ?? '' ) ) { return true; }
		if ( empty( $review['program']['id'] ) ) { return true; }
		$policy = isset( $review['program_lifecycle'] ) && is_array( $review['program_lifecycle'] ) ? $review['program_lifecycle'] : ( new ProgramLifecyclePolicy() )->resolve( (array) $review['program'] );
		if ( is_wp_error( $policy ) ) { return $policy; }
		$repeat = (string) ( $policy['repeat_policy'] ?? ProgramLifecyclePolicy::REPEAT_LIFECYCLE );
		if ( ProgramLifecyclePolicy::REPEAT_SEPARATE === $repeat ) { return true; }
		$recipient_id = absint( $review['recipient']['id'] ?? 0 );
		$program_id = absint( $review['program']['id'] ?? 0 );
		if ( ProgramLifecyclePolicy::REPEAT_BATCH === $repeat ) {
			$batch_id = absint( $review['batch']['id'] ?? 0 );
			if ( ! $batch_id ) { return new \WP_Error( 'certloom_manual_issue_repeat_batch_required', __( 'This Program issues one credential per Batch or session. Choose a Batch before reviewing the credential.', 'certloom' ) ); }
			$existing = $this->repository->find_existing_series_for_batch( $recipient_id, $program_id, $batch_id );
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( is_array( $existing ) ) {
				return new \WP_Error( 'certloom_manual_issue_duplicate_batch_series', __( 'This recipient already has a credential for the selected Program and Batch. Choose another Batch or continue from the existing credential.', 'certloom' ), array( 'series_id' => absint( $existing['id'] ?? 0 ) ) );
			}
			return true;
		}
		$existing = $this->repository->find_existing_series( $recipient_id, $program_id );
		if ( is_wp_error( $existing ) ) { return $existing; }
		if ( is_array( $existing ) ) {
			return new \WP_Error( 'certloom_manual_issue_duplicate_series', __( 'This recipient already has the credential lifecycle for the selected Program. Renewal or re-certification should continue from the existing credential instead of creating a second lifecycle.', 'certloom' ), array( 'series_id' => absint( $existing['id'] ?? 0 ) ) );
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function snapshot( array $review, array $recipient, array $series, array $credential, array $merge, $fingerprint, $actor_user_id, $source_type = self::SOURCE_TYPE, $source_ref = null, $source_context = null ) {
		$template = $review['template']; $revision = $review['template_revision'];
		$presentation_mode = (string) ( $review['request']['presentation_mode'] ?? 'details_only' );
		try { $merge_sha = $this->json->hash( $merge ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_manual_issue_merge_hash_failed', __( 'Certloom could not create the immutable merge snapshot fingerprint. Issuance was rolled back.', 'certloom' ) ); }
		return array(
			'_meta' => array( 'schema' => self::SNAPSHOT_SCHEMA, 'schema_version' => self::SNAPSHOT_VERSION ),
			'issuance' => array( 'source' => (string) $source_type, 'source_ref' => null === $source_ref ? null : (string) $source_ref, 'actor_user_id' => absint( $actor_user_id ), 'review_fingerprint' => (string) $fingerprint, 'design_source' => (string) ( $review['request']['design_source'] ?? 'none' ), 'custom_merge_fields' => $review['request']['custom_merge_fields'], 'design_field_aliases' => $review['request']['design_field_aliases'] ?? array(), 'integration_context_sha256' => is_array( $source_context ) ? (string) ( $source_context['sha256'] ?? '' ) : null, 'integration_context' => is_array( $source_context ) && isset( $source_context['context'] ) && is_array( $source_context['context'] ) ? $source_context['context'] : null ),
			'presentation' => array(
				'mode' => $presentation_mode,
				'certificate_image_id' => 'image' === $presentation_mode ? absint( $review['request']['certificate_image_id'] ?? 0 ) : null,
			),
			'recipient' => $this->recipient_snapshot( $recipient ),
			'program' => array_merge( $this->program_snapshot( $review['program'] ), array( 'lifecycle' => isset( $review['program_lifecycle'] ) && is_array( $review['program_lifecycle'] ) ? $review['program_lifecycle'] : null ) ),
			'batch' => $this->batch_snapshot( $review['batch'] ?? null ),
			'fields' => array( 'contract' => isset( $review['field_contract'] ) && is_array( $review['field_contract'] ) ? $review['field_contract'] : array(), 'values' => isset( $review['field_values'] ) && is_array( $review['field_values'] ) ? $review['field_values'] : array() ),
			'locale' => isset( $review['credential_locale'] ) && is_array( $review['credential_locale'] ) ? $review['credential_locale'] : array(),
			'issuer' => $this->issuer_snapshot( $review['issuer'] ),
			'signer' => $this->signer_snapshot( $review['signer'] ?? null, $review['signer_resolution'] ?? null ),
			'template' => array(
				'id' => absint( $template['id'] ?? 0 ), 'machine_key' => (string) ( $template['machine_key'] ?? '' ), 'name' => (string) ( $template['name'] ?? '' ),
				'resolution_scope' => (string) ( $review['template_resolution'] ?? 'explicit' ), 'resolution_scope_id' => isset( $review['template_resolution_details'] ) && is_array( $review['template_resolution_details'] ) ? absint( $review['template_resolution_details']['scope_id'] ?? 0 ) : null,
				'locale_variant' => isset( $review['template_locale_variant'] ) && is_array( $review['template_locale_variant'] ) ? array( 'matched_locale' => (string) ( $review['template_locale_variant']['matched_locale'] ?? '' ), 'template_key' => (string) ( $review['template_locale_variant']['template_key'] ?? '' ), 'fallback_chain' => isset( $review['template_locale_variant']['fallback_chain'] ) && is_array( $review['template_locale_variant']['fallback_chain'] ) ? array_values( $review['template_locale_variant']['fallback_chain'] ) : array() ) : null,
				'published_revision_id' => absint( $revision['id'] ?? 0 ), 'revision_number' => absint( $revision['revision_number'] ?? 0 ),
				'layout_schema' => (string) ( $revision['layout_schema'] ?? '' ), 'layout_schema_version' => absint( $revision['layout_schema_version'] ?? 0 ),
				'layout_sha256' => (string) ( $revision['layout_sha256'] ?? '' ), 'layout' => $revision['layout'],
			),
			'credential' => array(
				'series_id' => absint( $series['id'] ?? 0 ), 'public_id' => (string) ( $series['public_id'] ?? '' ), 'display_number' => (string) $credential['display_number'], 'title' => (string) ( $review['request']['credential_title'] ?? '' ), 'title_source' => (string) ( $review['request']['title_source'] ?? 'custom' ), 'title_program_id' => absint( $review['request']['title_program_id'] ?? 0 ),
				'valid_from' => $credential['valid_from'], 'issued_at' => $credential['issued_at'], 'expires_at' => $credential['expires_at'], 'grade' => $credential['grade'],
				'repeating_data' => isset( $review['request']['repeating_data'] ) && is_array( $review['request']['repeating_data'] ) ? $review['request']['repeating_data'] : array(),
				'issuance_state' => States::ISSUANCE_ISSUED, 'administrative_state' => States::ADMIN_ACTIVE, 'verification_url' => (string) $credential['verification_url'],
			),
			'merge' => array( 'values' => $merge, 'sha256' => $merge_sha ),
			'artifact' => array( 'authority' => 'design' === $presentation_mode ? 'gated' : ( 'image' === $presentation_mode ? 'uploaded_image' : 'not_applicable' ), 'renderer_receipt' => null ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function receipt_from_existing( array $version, $idempotent ) {
		$series = $this->repository->find_series( absint( $version['series_id'] ?? 0 ) );
		if ( is_wp_error( $series ) ) { return $series; }
		if ( ! is_array( $series ) ) { return new \WP_Error( 'certloom_manual_issue_receipt_series_missing', __( 'The issued Credential Series could not be loaded for its receipt.', 'certloom' ) ); }
		$verification_url = $this->urls->verification_url( (string) ( $series['public_id'] ?? '' ) );
		if ( is_wp_error( $verification_url ) ) { return $verification_url; }
		$snapshot = isset( $version['snapshot'] ) && is_array( $version['snapshot'] ) ? $version['snapshot'] : array();
		return array(
			'series_id' => absint( $series['id'] ?? 0 ),
			'version_id' => absint( $version['id'] ?? 0 ),
			'public_id' => (string) ( $series['public_id'] ?? '' ),
			'display_number' => (string) ( $version['display_number'] ?? '' ),
			'issuance_state' => (string) ( $version['issuance_state'] ?? '' ),
			'issued_at' => $version['issued_at'] ?? null,
			'expires_at' => $version['expires_at'] ?? null,
			'verification_url' => $verification_url,
			'artifact_status' => 'renderer_authority_gated',
			'exports' => array(),
			'idempotent_replay' => (bool) $idempotent,
			'snapshot' => $snapshot,
		);
	}

	private function idempotency_key( $source_type, $fingerprint ) { try { return $this->json->hash( array( 'source' => (string) $source_type, 'review_fingerprint' => (string) $fingerprint ) ); } catch ( \Throwable $e ) { return hash( 'sha256', (string) $source_type . ':' . (string) $fingerprint ); } }
	/** @return string|\WP_Error */ private function date( $value ) { $value = trim( (string) $value ); if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return new \WP_Error( 'certloom_manual_issue_date_invalid', __( 'Use a valid YYYY-MM-DD date.', 'certloom' ) ); } return $value; }
	/** @return array<string,string>|\WP_Error */
	private function scalar_map( $value ) {
		if ( null === $value || '' === $value ) { return array(); }
		if ( ! is_array( $value ) || count( $value ) > 30 ) {
			return new \WP_Error( 'certloom_manual_issue_custom_fields_invalid', __( 'Custom fields must be a bounded key/value list with at most 30 entries.', 'certloom' ) );
		}
		$out = array(); $bytes = 0;
		foreach ( $value as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' === $key || strlen( $key ) > 64 || array_key_exists( $key, $out ) || is_array( $v ) || is_object( $v ) || is_resource( $v ) ) {
				return new \WP_Error( 'certloom_manual_issue_custom_fields_invalid', __( 'Custom fields must use unique machine keys up to 64 characters and scalar values.', 'certloom' ) );
			}
			$scalar = sanitize_text_field( null === $v ? '' : (string) $v );
			if ( $this->length( $scalar ) > 2000 ) { return new \WP_Error( 'certloom_manual_issue_custom_field_too_long', __( 'Each custom field value must be 2,000 characters or fewer.', 'certloom' ) ); }
			$bytes += strlen( $key ) + strlen( $scalar );
			if ( $bytes > 32768 ) { return new \WP_Error( 'certloom_manual_issue_custom_fields_too_large', __( 'The combined custom-field payload is too large for one Manual Issuance.', 'certloom' ) ); }
			$out[ $key ] = $scalar;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}
	private function length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value ); }
	/** @return array<string,mixed> */ private function recipient_fingerprint( array $r ) { return $this->recipient_snapshot( $r ) + array( 'updated_at' => (string) ( $r['updated_at'] ?? '' ) ); }
	/** @return array<string,mixed> */ private function program_fingerprint( array $p ) { return $this->program_snapshot( $p ) + array( 'updated_at' => (string) ( $p['updated_at'] ?? '' ) ); }
	/** @return array<string,mixed>|null */ private function batch_fingerprint( $b ) { $out=$this->batch_snapshot($b); return is_array($out)?$out+array('updated_at'=>(string)($b['updated_at']??'')):null; }
	/** @return array<string,mixed>|null */ private function issuer_fingerprint( $i ) { $out=$this->issuer_snapshot($i); return is_array($out)?$out+array('updated_at'=>(string)($i['updated_at']??'')):null; }
	/** @return array<string,mixed>|null */
	private function signer_fingerprint( $signer, $resolution ) {
		$out = $this->signer_snapshot( $signer, $resolution );
		if ( ! is_array( $out ) ) { return null; }
		$out['profile_updated_at'] = is_array( $signer ) ? (string) ( $signer['updated_at'] ?? '' ) : '';
		return $out;
	}
	/** @return array<string,mixed>|null */
	private function signer_snapshot( $signer, $resolution ) {
		if ( ! is_array( $signer ) ) { return null; }
		$resolution = is_array( $resolution ) ? $resolution : array( 'mode' => 'none' );
		return array(
			'id' => absint( $signer['id'] ?? 0 ),
			'display_name' => (string) ( $signer['display_name'] ?? '' ),
			'slug' => (string) ( $signer['slug'] ?? '' ),
			'title' => null === ( $signer['title'] ?? null ) ? null : (string) $signer['title'],
			'email' => null === ( $signer['email'] ?? null ) ? null : (string) $signer['email'],
			'signature_attachment_id' => ! empty( $signer['signature_attachment_id'] ) ? absint( $signer['signature_attachment_id'] ) : null,
			'resolution' => array(
				'mode' => (string) ( $resolution['mode'] ?? 'none' ),
				'scope_type' => null === ( $resolution['scope_type'] ?? null ) ? null : (string) $resolution['scope_type'],
				'scope_id' => ! empty( $resolution['scope_id'] ) ? absint( $resolution['scope_id'] ) : null,
				'assignment_id' => ! empty( $resolution['assignment_id'] ) ? absint( $resolution['assignment_id'] ) : null,
				'priority' => null === ( $resolution['priority'] ?? null ) ? null : absint( $resolution['priority'] ),
				'assignment_updated_at' => null === ( $resolution['assignment_updated_at'] ?? null ) ? null : (string) $resolution['assignment_updated_at'],
			),
		);
	}
	/** @return array<string,mixed> */ private function template_fingerprint( array $t, array $r ) { return array( 'id'=>absint($t['id']??0),'machine_key'=>(string)($t['machine_key']??''),'name'=>(string)($t['name']??''),'updated_at'=>(string)($t['updated_at']??''),'published_revision_id'=>absint($r['id']??0),'revision_number'=>absint($r['revision_number']??0),'layout_schema'=>(string)($r['layout_schema']??''),'layout_schema_version'=>absint($r['layout_schema_version']??0),'layout_sha256'=>(string)($r['layout_sha256']??'') ); }
	/** @return array<string,mixed> */ private function recipient_snapshot( array $r ) { return array( 'id'=>!empty($r['id'])?absint($r['id']):null,'display_name'=>(string)($r['display_name']??''),'first_name'=>$r['first_name']??null,'middle_name'=>$r['middle_name']??null,'last_name'=>$r['last_name']??null,'email'=>$r['email']??null,'external_id'=>$r['external_id']??null,'custom_fields'=>isset($r['custom_fields'])&&is_array($r['custom_fields'])?$r['custom_fields']:array() ); }
	/** @return array<string,mixed> */ private function program_snapshot( array $p ) { return array( 'id'=>absint($p['id']??0),'code'=>$p['code']??null,'title'=>(string)($p['title']??''),'description'=>$p['description']??null,'issuer_id'=>!empty($p['issuer_id'])?absint($p['issuer_id']):null,'validity_days'=>null!==($p['validity_days']??null)?absint($p['validity_days']):null,'profile'=>isset($p['profile'])&&is_array($p['profile'])?$p['profile']:array(),'metadata'=>isset($p['metadata'])&&is_array($p['metadata'])?$p['metadata']:array() ); }
	/** @return array<string,mixed>|null */ private function batch_snapshot( $b ) { if(!is_array($b))return null; return array( 'id'=>absint($b['id']??0),'program_id'=>absint($b['program_id']??0),'code'=>(string)($b['code']??''),'name'=>(string)($b['name']??''),'start_date'=>$b['start_date']??null,'end_date'=>$b['end_date']??null ); }
	/** @return array<string,mixed>|null */ private function issuer_snapshot( $i ) { if(!is_array($i))return null; return array( 'id'=>absint($i['id']??0),'name'=>(string)($i['name']??''),'slug'=>(string)($i['slug']??''),'logo_attachment_id'=>!empty($i['logo_attachment_id'])?absint($i['logo_attachment_id']):null,'seal_attachment_id'=>!empty($i['seal_attachment_id'])?absint($i['seal_attachment_id']):null,'settings'=>isset($i['settings'])&&is_array($i['settings'])?$i['settings']:array() ); }
}
