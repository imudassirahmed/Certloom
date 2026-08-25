<?php
namespace Certloom\Application;

use Certloom\Domain\Credential\PublicCredentialId;

/**
 * Builds the intentionally small guest-safe verification view model.
 *
 * Recipient/program/issuer details are not read from the verification query in
 * 1.5.2. Later milestones may add public-safe profile fields through dedicated
 * repositories/presenters; raw database rows are never serialized by default.
 */
final class PublicCredentialPresenter {
	/** @var VisibilityPolicy */
	private $visibility;
	/** @var PublicCredentialId */
	private $public_ids;

	public function __construct( ?VisibilityPolicy $visibility = null, ?PublicCredentialId $public_ids = null ) {
		$this->visibility = $visibility ?: new VisibilityPolicy();
		$this->public_ids = $public_ids ?: new PublicCredentialId();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function present( VerificationResult $result ) {
		if ( ! $result->found() ) {
			return array(
				'found'       => false,
				'reason_code' => $result->reason_code(),
			);
		}

		$credential = $result->credential();
		if ( ! is_array( $credential ) || ! $this->visibility->allows_guest_verification( $credential['visibility'] ?? '' ) ) {
			/* Hide existence from unauthenticated callers when guest access is denied. */
			return array(
				'found'       => false,
				'reason_code' => 'not_found',
			);
		}

		$status = $result->status();
		return array(
			'found'              => true,
			'credential_id'      => $this->public_ids->format( $result->public_id() ),
			'certificate_number' => isset( $credential['display_number'] ) ? (string) $credential['display_number'] : '',
			'issued_at'          => self::date_part( $credential['issued_at'] ?? null ),
			'expires_at'         => self::date_part( $credential['expires_at'] ?? null ),
			'status'             => $status ? $status->to_array() : null,
		);
	}

	/** @return string|null */
	private static function date_part( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : substr( $value, 0, 10 );
	}
}
