<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Email\EmailDeliveryCoordinator;
use Certloom\Infrastructure\Repository\EmailDeliveryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Queues configured delivery rules only after a new immutable Version commits. */
final class EmailAutomationListener {
	/** @var EmailDeliveryCoordinator */
	private $delivery;
	/** @var EmailAutomationRuleRepository */
	private $rules;
	/** @var EmailDeliveryRepository */
	private $repository;

	public function __construct(
		EmailDeliveryCoordinator $delivery,
		?EmailAutomationRuleRepository $rules = null,
		?EmailDeliveryRepository $repository = null
	) {
		$this->delivery   = $delivery;
		$this->rules      = $rules ?: new EmailAutomationRuleRepository();
		$this->repository = $repository ?: new EmailDeliveryRepository();
	}

	/** @return void */
	public function register() {
		add_action( 'certloom_credential_issued', array( $this, 'on_issued' ), 10, 5 );
	}

	/** @return void */
	public function on_issued( $version_id, $series_id, $actor_user_id, $source_type, $source_ref ) {
		$version = $this->repository->find_version( absint( $version_id ) );
		if ( is_wp_error( $version ) || ! is_array( $version ) ) {
			return;
		}
		if ( absint( $version['series_id'] ?? 0 ) !== absint( $series_id ) || 'issued' !== (string) ( $version['issuance_state'] ?? '' ) ) {
			return;
		}

		$snapshot   = is_array( $version['snapshot'] ?? null ) ? $version['snapshot'] : array();
		$program_id = absint( $snapshot['program']['id'] ?? 0 );
		if ( ! $program_id ) {
			return;
		}
		$destination = sanitize_email( (string) ( $snapshot['recipient']['email'] ?? '' ) );
		if ( '' === $destination ) {
			do_action( 'certloom_email_automation_skipped', 'recipient_email_missing', absint( $version_id ), (string) $source_type );
			return;
		}

		foreach ( $this->rules->matching( $program_id, $source_type ) as $rule ) {
			$result = $this->delivery->enqueue(
				absint( $version_id ),
				$destination,
				absint( $actor_user_id ),
				'issued',
				true,
				(string) $rule['template_id']
			);
			if ( is_wp_error( $result ) ) {
				do_action( 'certloom_email_automation_failed', (string) $rule['id'], $result, absint( $version_id ), (string) $source_ref );
				continue;
			}
			do_action( 'certloom_email_automation_queued', (string) $rule['id'], (string) $result, absint( $version_id ) );
		}
	}
}
