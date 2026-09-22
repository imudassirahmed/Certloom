<?php
namespace Certloom\Application;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;

/**
 * Golden-path public credential verification application service.
 *
 * This service is intentionally transport/presentation neutral. REST, no-JS,
 * shortcode, Gutenberg and Elementor adapters must all delegate to the same
 * application service once the new domain becomes authoritative.
 */
final class CredentialVerificationService {
	/** @var CredentialQueryRepositoryInterface */
	private $credentials;
	/** @var PublicCredentialId */
	private $public_ids;
	/** @var StatusService */
	private $status;

	public function __construct( CredentialQueryRepositoryInterface $credentials, ?PublicCredentialId $public_ids = null, ?StatusService $status = null ) {
		$this->credentials = $credentials;
		$this->public_ids  = $public_ids ?: new PublicCredentialId();
		$this->status      = $status ?: new StatusService();
	}

	/**
	 * Verifies one checksummed public Credential Series ID.
	 *
	 * Malformed/checksum-invalid IDs stop before repository access. Database/read
	 * failures propagate as WP_Error so transport adapters can fail closed rather
	 * than presenting a false "not found" result.
	 *
	 * @param string      $input Public ID in stored or formatted form.
	 * @param string|null $today Canonical Y-m-d override for deterministic tests.
	 * @return VerificationResult|\WP_Error
	 */
	public function verify_public_id( $input, $today = null ) {
		$public_id = $this->public_ids->normalize( $input );
		if ( ! $this->public_ids->is_valid( $public_id ) ) {
			return new VerificationResult( false, $public_id, 'invalid_identifier' );
		}

		$credential = $this->credentials->find_current_by_public_id( $public_id );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		if ( ! is_array( $credential ) ) {
			return new VerificationResult( false, $public_id, 'not_found' );
		}

		/*
		 * A Series that cannot resolve its current Version is structurally incomplete.
		 * Treat this as service/storage failure, not "not found" and never as valid.
		 */
		if ( empty( $credential['version_id'] ) ) {
			return new \WP_Error( 'certloom_verification_current_version_missing', __( 'Certloom found the credential identity but could not resolve its current issued version.', 'certloom' ) );
		}

		try {
			$status = $this->status->evaluate(
				array(
					'issuance_state'       => isset( $credential['issuance_state'] ) ? (string) $credential['issuance_state'] : '',
					'administrative_state' => isset( $credential['administrative_state'] ) ? (string) $credential['administrative_state'] : '',
					'legacy_expired'       => ! empty( $credential['legacy_expired'] ),
					'valid_from'           => $credential['valid_from'] ?? null,
					'expires_at'           => $credential['expires_at'] ?? null,
					'compliance_state'     => isset( $credential['compliance_state'] ) && '' !== (string) $credential['compliance_state']
						? (string) $credential['compliance_state']
						: States::COMPLIANCE_NOT_APPLICABLE,
				),
				$today
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_verification_state_invalid', __( 'Certloom found the credential but its lifecycle state is invalid or incomplete.', 'certloom' ) );
		}

		return new VerificationResult( true, $public_id, 'found', $credential, $status );
	}
}
