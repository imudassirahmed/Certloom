<?php
namespace Certloom\Application;

use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Application\Fields\ScopedFieldPresentation;

/**
 * Builds the intentionally small guest-safe verification view model.
 *
 * Core credential facts and FLD-02 fields are projected through explicit
 * visibility/privacy policies. Raw repository rows and mutable Program/Batch
 * configuration are never serialized directly to guests.
 */
final class PublicCredentialPresenter {
	/** @var VisibilityPolicy */
	private $visibility;
	/** @var PublicCredentialId */
	private $public_ids;
	/** @var ScopedFieldPresentation */
	private $fields;

	public function __construct( ?VisibilityPolicy $visibility = null, ?PublicCredentialId $public_ids = null, ?ScopedFieldPresentation $fields = null ) {
		$this->visibility = $visibility ?: new VisibilityPolicy();
		$this->public_ids = $public_ids ?: new PublicCredentialId();
		$this->fields = $fields ?: new ScopedFieldPresentation();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function present( VerificationResult $result, $context = 'verification' ) {
		if ( ! $result->found() ) {
			return array(
				'found'       => false,
				'reason_code' => $result->reason_code(),
			);
		}

		$credential = $result->credential();
		$visibility_mode = is_array( $credential ) ? $this->visibility->guest_mode( $credential['visibility'] ?? '' ) : VisibilityPolicy::MODE_DENY;
		if ( ! is_array( $credential ) || VisibilityPolicy::MODE_DENY === $visibility_mode ) {
			/* Hide existence from unauthenticated callers when guest access is denied. */
			return array(
				'found'       => false,
				'reason_code' => 'not_found',
			);
		}

		$context = 'profile' === strtolower( trim( (string) $context ) ) ? 'profile' : 'verification';
		/* A summary/unlisted credential never gains profile-level disclosure merely because a profile URL was requested. */
		$effective_context = 'profile' === $context && VisibilityPolicy::MODE_PROFILE === $visibility_mode ? 'profile' : 'verification';
		$status = $result->status();
		$snapshot = isset( $credential['snapshot'] ) && is_array( $credential['snapshot'] ) ? $credential['snapshot'] : array();
		$public_fields = $this->fields->public_fields( $snapshot, $effective_context );
		return array(
			'found'              => true,
			'credential_id'      => $this->public_ids->format( $result->public_id() ),
			'certificate_number' => isset( $credential['display_number'] ) ? (string) $credential['display_number'] : '',
			'issued_at'          => self::date_part( $credential['issued_at'] ?? null ),
			'expires_at'         => self::date_part( $credential['expires_at'] ?? null ),
			'status'             => $status ? $status->to_array() : null,
			'fields'             => $public_fields,
		);
	}

	/** @return string|null */
	private static function date_part( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		return '' === $value ? null : substr( $value, 0, 10 );
	}
}
