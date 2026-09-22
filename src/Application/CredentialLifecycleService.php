<?php
namespace Certloom\Application;

use Certloom\Application\Contract\CredentialRepositoryInterface;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Domain\Credential\StateMachine;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\EventRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single command boundary for credential lifecycle mutations.
 *
 * Controllers, REST routes, jobs and integrations must eventually call this
 * service rather than directly mutating Series/Version state columns.
 */
final class CredentialLifecycleService {
	/** @var CredentialRepositoryInterface */
	private $credentials;
	/** @var EventRepositoryInterface */
	private $events;
	/** @var StateMachine */
	private $machine;
	/** @var TransactionInterface */
	private $transaction;

	public function __construct( ?CredentialRepositoryInterface $credentials = null, ?EventRepositoryInterface $events = null, ?StateMachine $machine = null, ?TransactionInterface $transaction = null ) {
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->events      = $events ?: new EventRepository();
		$this->machine     = $machine ?: new StateMachine();
		$this->transaction = $transaction ?: new Transaction();
	}

	/** @return true|\WP_Error */
	public function issue( $series_id, $version_id, array $context = array() ) {
		return $this->transaction->run( function () use ( $series_id, $version_id, $context ) {
			return $this->issue_within_transaction( $series_id, $version_id, $context );
		} );
	}

	/**
	 * Performs the issue transition inside a transaction already owned by a
	 * trusted orchestration service such as Manual Issuance. This keeps Series,
	 * Version, current pointer and append-only event atomic without nesting
	 * START TRANSACTION/COMMIT boundaries.
	 *
	 * @return true|\WP_Error
	 */
	public function issue_within_transaction( $series_id, $version_id, array $context = array() ) {
		$series = $this->credentials->find_series_for_update( $series_id );
		if ( is_wp_error( $series ) ) { return $series; }
		$version = $this->credentials->find_version_for_update( $version_id );
		if ( is_wp_error( $version ) ) { return $version; }
		if ( ! $series || ! $version || absint( $version['series_id'] ) !== absint( $series_id ) ) {
			return new \WP_Error( 'certloom_lifecycle_credential_missing', __( 'The credential series or version could not be found.', 'certloom' ) );
		}
		$review = $this->assert_review_context( $series, $version, $context );
		if ( is_wp_error( $review ) ) { return $review; }
		try {
			$next = $this->machine->issue( $version['issuance_state'], $series['administrative_state'] );
		} catch ( \DomainException $error ) {
			return new \WP_Error( 'certloom_lifecycle_transition_invalid', $error->getMessage() );
		}
		$changed = $this->credentials->set_version_issuance_state( $version_id, $version['issuance_state'], $next['issuance_state'] );
		if ( is_wp_error( $changed ) ) { return $changed; }
		$current = $this->credentials->set_current_version( $series_id, $version_id );
		if ( is_wp_error( $current ) ) { return $current; }
		return $this->append_event( 'credential.issued', $series_id, $version_id, $context, array(
			'issuance_state' => $version['issuance_state'],
			'administrative_state' => $series['administrative_state'],
		), $next );
	}

	/** @return true|\WP_Error */
	public function suspend( $series_id, array $context = array() ) {
		return $this->administrative_transition( 'credential.suspended', 'suspend', $series_id, $context );
	}

	/** @return true|\WP_Error */
	public function revoke( $series_id, array $context = array() ) {
		$result = $this->administrative_transition( 'credential.revoked', 'revoke', $series_id, $context );
		if ( true === $result && function_exists( 'do_action' ) ) {
			do_action( 'certloom_credential_revoked', absint( $series_id ), $context );
		}
		return $result;
	}

	/** @return true|\WP_Error */
	public function reinstate( $series_id, array $context = array() ) {
		return $this->administrative_transition( 'credential.reinstated', 'reinstate', $series_id, $context );
	}

	/** @return true|\WP_Error */
	public function void( $series_id, $version_id, array $context = array() ) {
		return $this->transaction->run( function () use ( $series_id, $version_id, $context ) {
			$series = $this->credentials->find_series_for_update( $series_id );
			if ( is_wp_error( $series ) ) {
				return $series;
			}
			$version = $this->credentials->find_version_for_update( $version_id );
			if ( is_wp_error( $version ) ) {
				return $version;
			}
			if ( ! $series || ! $version || absint( $version['series_id'] ) !== absint( $series_id ) ) {
				return new \WP_Error( 'certloom_lifecycle_credential_missing', __( 'The credential series or version could not be found.', 'certloom' ) );
			}
			$review = $this->assert_review_context( $series, $version, $context );
			if ( is_wp_error( $review ) ) { return $review; }
			try {
				$next = $this->machine->void( $version['issuance_state'], $series['administrative_state'] );
			} catch ( \DomainException $error ) {
				return new \WP_Error( 'certloom_lifecycle_transition_invalid', $error->getMessage() );
			}
			$changed = $this->credentials->set_version_issuance_state( $version_id, $version['issuance_state'], $next['issuance_state'] );
			if ( is_wp_error( $changed ) ) {
				return $changed;
			}
			return $this->append_event( 'credential.voided', $series_id, $version_id, $context, array(
				'issuance_state' => $version['issuance_state'],
				'administrative_state' => $series['administrative_state'],
			), $next );
		} );
	}

	/** @return true|\WP_Error */
	private function administrative_transition( $event_type, $method, $series_id, array $context ) {
		return $this->transaction->run( function () use ( $event_type, $method, $series_id, $context ) {
			$series = $this->credentials->find_series_for_update( $series_id );
			if ( is_wp_error( $series ) ) {
				return $series;
			}
			if ( ! $series || empty( $series['current_version_id'] ) ) {
				return new \WP_Error( 'certloom_lifecycle_credential_missing', __( 'An issued credential version is required for this lifecycle action.', 'certloom' ) );
			}
			$version = $this->credentials->find_version_for_update( $series['current_version_id'] );
			if ( is_wp_error( $version ) ) {
				return $version;
			}
			if ( ! $version ) {
				return new \WP_Error( 'certloom_lifecycle_version_missing', __( 'The current credential version could not be found.', 'certloom' ) );
			}
			$review = $this->assert_review_context( $series, $version, $context );
			if ( is_wp_error( $review ) ) { return $review; }
			try {
				$next = $this->machine->{$method}( $version['issuance_state'], $series['administrative_state'] );
			} catch ( \DomainException $error ) {
				return new \WP_Error( 'certloom_lifecycle_transition_invalid', $error->getMessage() );
			}
			$changed = $this->credentials->set_administrative_state( $series_id, $series['administrative_state'], $next['administrative_state'] );
			if ( is_wp_error( $changed ) ) {
				return $changed;
			}
			return $this->append_event( $event_type, $series_id, $version['id'], $context, array(
				'issuance_state' => $version['issuance_state'],
				'administrative_state' => $series['administrative_state'],
			), $next );
		} );
	}

	/**
	 * Validates optional review-bound optimistic concurrency fields inside the
	 * transaction after Series/current-Version locks have been acquired.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_review_context( array $series, array $version, array $context ) {
		if ( array_key_exists( 'expected_series_updated_at', $context ) && (string) $context['expected_series_updated_at'] !== (string) ( $series['updated_at'] ?? '' ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_stale', __( 'The credential changed after this lifecycle action was reviewed. Reload and review it again.', 'certloom' ) );
		}
		if ( array_key_exists( 'expected_current_version_id', $context ) && absint( $context['expected_current_version_id'] ) !== absint( $series['current_version_id'] ?? 0 ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_stale', __( 'The current credential Version changed after this lifecycle action was reviewed. Reload and review it again.', 'certloom' ) );
		}
		if ( array_key_exists( 'expected_version_id', $context ) && absint( $context['expected_version_id'] ) !== absint( $version['id'] ?? 0 ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_stale', __( 'The reviewed credential Version is no longer current. Reload and review it again.', 'certloom' ) );
		}
		if ( array_key_exists( 'expected_version_updated_at', $context ) && (string) $context['expected_version_updated_at'] !== (string) ( $version['updated_at'] ?? '' ) ) {
			return new \WP_Error( 'certloom_lifecycle_review_stale', __( 'The credential Version changed after this lifecycle action was reviewed. Reload and review it again.', 'certloom' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function append_event( $event_type, $series_id, $version_id, array $context, array $old_state, array $new_state ) {
		$event = $this->events->append(
			array(
				'series_id' => $series_id,
				'version_id' => $version_id,
				'event_type' => $event_type,
				'actor_user_id' => ! empty( $context['actor_user_id'] ) ? absint( $context['actor_user_id'] ) : null,
				'old_state' => $old_state,
				'new_state' => $new_state,
				'reason_code' => $context['reason_code'] ?? null,
				'public_reason' => $context['public_reason'] ?? null,
				'internal_note' => $context['internal_note'] ?? null,
				'meta' => $context['meta'] ?? null,
			)
		);
		return is_wp_error( $event ) ? $event : true;
	}
}
