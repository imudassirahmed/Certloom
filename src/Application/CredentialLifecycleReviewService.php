<?php
namespace Certloom\Application;

use Certloom\Domain\Credential\StateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tamper-evident, owner-bound review contract for administrator lifecycle actions.
 *
 * The review contains the exact Series/current-Version identity and optimistic
 * concurrency timestamps that were shown to the administrator. Mutation code
 * must revalidate those fields inside its transaction before changing state.
 */
final class CredentialLifecycleReviewService {
	const REVIEW_TTL = 900;

	/** @var StateMachine */
	private $machine;

	public function __construct( ?StateMachine $machine = null ) {
		$this->machine = $machine ?: new StateMachine();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function prepare( array $series, array $version, $action, array $context = array(), $owner_user_id = 0 ) {
		$series_id  = absint( $series['id'] ?? 0 );
		$version_id = absint( $version['id'] ?? 0 );
		$action     = sanitize_key( (string) $action );
		if ( ! $series_id || ! $version_id || absint( $series['current_version_id'] ?? 0 ) !== $version_id ) {
			return new \WP_Error( 'certloom_lifecycle_review_current_version_invalid', __( 'A current credential Version is required before this lifecycle action can be reviewed.', 'certloom' ) );
		}
		if ( ! in_array( $action, array( 'suspend', 'revoke', 'reinstate', 'void' ), true ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_action_invalid', __( 'Choose a valid credential lifecycle action.', 'certloom' ) );
		}

		try {
			$next = $this->machine->{$action}( (string) $version['issuance_state'], (string) $series['administrative_state'] );
		} catch ( \DomainException $error ) {
			return new \WP_Error( 'certloom_lifecycle_review_transition_invalid', $error->getMessage() );
		}

		$review = array(
			'schema'             => 'certloom-lifecycle-review',
			'schema_version'     => 1,
			'prepared_at'        => time(),
			'owner_user_id'      => absint( $owner_user_id ),
			'series_id'          => $series_id,
			'public_id'          => (string) ( $series['public_id'] ?? '' ),
			'series_updated_at'  => (string) ( $series['updated_at'] ?? '' ),
			'version_id'         => $version_id,
			'version_number'     => absint( $version['version_number'] ?? 0 ),
			'version_updated_at' => (string) ( $version['updated_at'] ?? '' ),
			'display_number'     => (string) ( $version['display_number'] ?? '' ),
			'action'             => $action,
			'old_state'          => array(
				'issuance_state'       => (string) $version['issuance_state'],
				'administrative_state' => (string) $series['administrative_state'],
			),
			'new_state'          => array(
				'issuance_state'       => (string) $next['issuance_state'],
				'administrative_state' => (string) $next['administrative_state'],
			),
			'reason_code'        => $this->reason_code( $context['reason_code'] ?? null ),
			'public_reason'      => $this->bounded_text( $context['public_reason'] ?? null, 1000 ),
			'internal_note'      => $this->bounded_text( $context['internal_note'] ?? null, 2000 ),
		);
		$encoded = wp_json_encode( $review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return new \WP_Error( 'certloom_lifecycle_review_encode_failed', __( 'Certloom could not encode the lifecycle review safely.', 'certloom' ) );
		}
		$review['digest'] = hash_hmac( 'sha256', $encoded, $this->review_key() );
		return $review;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function verify( array $review, $actor_user_id = 0 ) {
		$digest = isset( $review['digest'] ) ? strtolower( trim( (string) $review['digest'] ) ) : '';
		$copy   = $review;
		unset( $copy['digest'] );
		$encoded = wp_json_encode( $copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded || ! preg_match( '/^[a-f0-9]{64}$/', $digest ) || ! hash_equals( $digest, hash_hmac( 'sha256', $encoded, $this->review_key() ) ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_corrupt', __( 'The reviewed lifecycle action failed its integrity check. Review it again.', 'certloom' ) );
		}
		if ( 'certloom-lifecycle-review' !== (string) ( $copy['schema'] ?? '' ) || 1 !== absint( $copy['schema_version'] ?? 0 ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_schema_invalid', __( 'The reviewed lifecycle action uses an unsupported review contract.', 'certloom' ) );
		}
		$prepared = absint( $copy['prepared_at'] ?? 0 );
		if ( ! $prepared || $prepared + self::REVIEW_TTL < time() ) {
			return new \WP_Error( 'certloom_lifecycle_review_expired', __( 'The reviewed lifecycle action expired. Reload the credential and review it again.', 'certloom' ) );
		}
		$owner = absint( $copy['owner_user_id'] ?? 0 );
		$actor = absint( $actor_user_id );
		if ( $owner && $owner !== $actor ) {
			return new \WP_Error( 'certloom_lifecycle_review_owner', __( 'The reviewed lifecycle action belongs to another administrator.', 'certloom' ) );
		}
		if ( ! absint( $copy['series_id'] ?? 0 ) || ! absint( $copy['version_id'] ?? 0 ) || ! in_array( (string) ( $copy['action'] ?? '' ), array( 'suspend', 'revoke', 'reinstate', 'void' ), true ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_invalid', __( 'The reviewed lifecycle action is incomplete.', 'certloom' ) );
		}
		$copy['digest'] = $digest;
		return $copy;
	}

	/** @return string|null */
	private function reason_code( $value ) {
		$value = sanitize_key( (string) $value );
		return '' === $value ? null : substr( $value, 0, 64 );
	}

	/** @return string|null */
	private function bounded_text( $value, $max ) {
		$value = null === $value ? '' : sanitize_textarea_field( (string) $value );
		if ( '' === trim( $value ) ) {
			return null;
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max, 'UTF-8' );
		}
		return substr( $value, 0, $max );
	}

	/** @return string */
	private function review_key() {
		return wp_salt( 'auth' ) . '|certloom-lifecycle-review-v1';
	}
}
