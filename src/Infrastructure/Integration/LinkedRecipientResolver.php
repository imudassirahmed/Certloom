<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationContextRecipientResolverInterface;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Resolves only the explicit unique Recipient.wp_user_id relationship. */
final class LinkedRecipientResolver implements IntegrationContextRecipientResolverInterface {
	/** @var RecipientRepository */ private $recipients;
	public function __construct( ?RecipientRepository $recipients = null ) { $this->recipients = $recipients ?: new RecipientRepository(); }

	/** Resolve an already-reviewed local Recipient identity when an external connector supplies one. @return array<string,mixed>|null|\WP_Error */
	public function resolve_recipient_for_context( IntegrationEventContext $context ) {
		$recipient_id = $context->recipient_id();
		if ( $recipient_id > 0 ) {
			$recipient = $this->recipients->find( $recipient_id );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) || empty( $recipient['id'] ) ) {
				return new \WP_Error( 'certloom_integration_recipient_unlinked', __( 'The integration event is no longer linked to an available Certloom recipient.', 'certloom' ) );
			}
			return $recipient;
		}
		return $this->resolve_recipient_for_wp_user( $context->wp_user_id() );
	}

	public function resolve_recipient_for_wp_user( $wp_user_id ) {
		$wp_user_id = (int) $wp_user_id;
		if ( $wp_user_id < 1 ) { return new \WP_Error( 'certloom_integration_user_invalid', __( 'The integration event does not identify a valid WordPress user.', 'certloom' ) ); }
		$recipient = $this->recipients->find_by_wp_user_id( $wp_user_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || empty( $recipient['id'] ) ) {
			return new \WP_Error( 'certloom_integration_recipient_unlinked', __( 'This learner is not explicitly linked to a Certloom recipient. Certloom will not infer identity from email or name.', 'certloom' ) );
		}
		return $recipient;
	}
}
