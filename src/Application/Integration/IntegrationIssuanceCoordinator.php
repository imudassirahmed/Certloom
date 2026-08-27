<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Contract\IntegrationMappingResolverInterface;
use Certloom\Application\Contract\IntegrationRecipientResolverInterface;
use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Application\Rendering\CanonicalJson;

/**
 * Provider-neutral reviewed issuance bridge.
 *
 * INT-01 owns normalization, eligibility, identity, mapping and immutable
 * provenance. It delegates all actual credential creation to the existing
 * ManualIssuanceService transaction path.
 */
final class IntegrationIssuanceCoordinator {
	/** @var IntegrationRecipientResolverInterface */ private $recipients;
	/** @var IntegrationMappingResolverInterface */ private $mappings;
	/** @var ManualIssuanceService */ private $issuance;
	/** @var CanonicalJson */ private $json;

	public function __construct( IntegrationRecipientResolverInterface $recipients, IntegrationMappingResolverInterface $mappings, ?ManualIssuanceService $issuance = null, ?CanonicalJson $json = null ) {
		$this->recipients = $recipients;
		$this->mappings = $mappings;
		$this->issuance = $issuance ?: new ManualIssuanceService();
		$this->json = $json ?: new CanonicalJson();
	}

	/** Mutation-free normalized review. @return array<string,mixed>|\WP_Error */
	public function review( IntegrationEventContext $context ) {
		return $this->build_review( $context, false );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function build_review( IntegrationEventContext $context, $allow_existing_series ) {
		if ( ! $context->is_eligible() ) {
			return new \WP_Error( 'certloom_integration_not_eligible', __( 'No completed attempt satisfies the certificate eligibility threshold.', 'certloom' ) );
		}
		$recipient = $this->recipients->resolve_recipient_for_wp_user( $context->wp_user_id() );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || empty( $recipient['id'] ) ) {
			return new \WP_Error( 'certloom_integration_recipient_unlinked', __( 'This learner is not explicitly linked to a Certloom recipient. Certloom will not infer identity from email or name.', 'certloom' ) );
		}
		$mapping = $this->mappings->resolve_mapping( $context );
		if ( is_wp_error( $mapping ) ) { return $mapping; }
		if ( ! is_array( $mapping ) ) { return new \WP_Error( 'certloom_integration_mapping_missing', __( 'No active Certloom mapping exists for this integration source object.', 'certloom' ) ); }
		$mapping = $this->normalize_mapping( $mapping, $context );
		if ( is_wp_error( $mapping ) ) { return $mapping; }
		$attempt = $context->qualifying_attempt();
		$request = array(
			'recipient_mode' => 'existing',
			'recipient_id' => (int) $recipient['id'],
			'program_id' => $mapping['program_id'],
			'template_id' => $mapping['template_id'],
			'valid_from' => substr( (string) $attempt['attempted_at'], 0, 10 ),
			'expiry_mode' => (string) $mapping['expiry_mode'],
			'expires_at' => $mapping['expires_at'],
			'grade' => '',
			'display_number' => '',
			'custom_merge_fields' => array(),
		);
		$issuance_review = $allow_existing_series ? $this->issuance->revalidate_review( $request ) : $this->issuance->review( $request );
		if ( is_wp_error( $issuance_review ) ) { return $issuance_review; }
		$payload = array(
			'integration_context_sha256' => $context->sha256(),
			'integration_idempotency_key' => $context->idempotency_key(),
			'mapping' => $mapping,
			'recipient_id' => (int) $recipient['id'],
			'issuance_review_fingerprint' => (string) $issuance_review['review_fingerprint'],
		);
		try { $review_fingerprint = $this->json->hash( $payload ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_integration_review_hash_failed', __( 'Certloom could not fingerprint the integration issuance review.', 'certloom' ) ); }
		return array(
			'context' => $context->to_array(),
			'context_sha256' => $context->sha256(),
			'idempotency_key' => $context->idempotency_key(),
			'recipient' => array( 'id' => (int) $recipient['id'], 'display_name' => (string) ( $recipient['display_name'] ?? '' ) ),
			'mapping' => $mapping,
			'request' => $request,
			'issuance_review' => $issuance_review,
			'review_fingerprint' => $review_fingerprint,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function issue( IntegrationEventContext $context, $expected_review_fingerprint, $actor_user_id = 0 ) {
		$current = $this->build_review( $context, true );
		if ( is_wp_error( $current ) ) { return $current; }
		$expected = strtolower( trim( (string) $expected_review_fingerprint ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( (string) $current['review_fingerprint'], $expected ) ) {
			return new \WP_Error( 'certloom_integration_review_stale', __( 'The integration event, learner link, mapping, or issuance inputs changed after review. Review the event again before issuing.', 'certloom' ) );
		}
		return $this->issuance->issue_reviewed_for_integration(
			$current['request'],
			(string) $current['issuance_review']['review_fingerprint'],
			(int) $actor_user_id,
			$context->to_array(),
			$context->sha256(),
			$context->idempotency_key(),
			$context->adapter_key() . ':' . $context->source_object_type() . ':' . $context->source_object_id()
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_mapping( array $mapping, IntegrationEventContext $context ) {
		$adapter = strtolower( trim( (string) ( $mapping['adapter'] ?? '' ) ) );
		$type = strtolower( trim( (string) ( $mapping['source_object_type'] ?? '' ) ) );
		$id = trim( (string) ( $mapping['source_object_id'] ?? '' ) );
		if ( $adapter !== $context->adapter_key() || $type !== $context->source_object_type() || $id !== $context->source_object_id() ) {
			return new \WP_Error( 'certloom_integration_mapping_mismatch', __( 'The server-owned integration mapping does not match the normalized source event.', 'certloom' ) );
		}
		$program_id = (int) ( $mapping['program_id'] ?? 0 );
		$template_id = (int) ( $mapping['template_id'] ?? 0 );
		if ( $program_id < 1 || $template_id < 1 || isset( $mapping['active'] ) && ! $mapping['active'] ) {
			return new \WP_Error( 'certloom_integration_mapping_invalid', __( 'The integration mapping must target an active Certloom Program and published Template.', 'certloom' ) );
		}
		$expiry_mode = strtolower( trim( (string) ( $mapping['expiry_mode'] ?? 'program' ) ) );
		if ( ! in_array( $expiry_mode, array( 'program', 'permanent', 'date' ), true ) ) {
			return new \WP_Error( 'certloom_integration_mapping_expiry_invalid', __( 'The integration mapping contains an invalid credential expiry mode.', 'certloom' ) );
		}
		$expires_at = null;
		if ( 'date' === $expiry_mode ) {
			$expires_at = trim( (string) ( $mapping['expires_at'] ?? '' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) ) { return new \WP_Error( 'certloom_integration_mapping_expiry_invalid', __( 'The integration mapping requires a valid explicit expiry date.', 'certloom' ) ); }
		}
		return array(
			'adapter' => $adapter,
			'source_object_type' => $type,
			'source_object_id' => $id,
			'program_id' => $program_id,
			'template_id' => $template_id,
			'expiry_mode' => $expiry_mode,
			'expires_at' => $expires_at,
		);
	}
}
