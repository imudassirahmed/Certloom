<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationRecipientResolverInterface;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Resolves only the explicit unique Recipient.wp_user_id relationship. */
final class LinkedRecipientResolver implements IntegrationRecipientResolverInterface {
	/** @var RecipientRepository */ private $recipients;
	public function __construct( ?RecipientRepository $recipients = null ) { $this->recipients = $recipients ?: new RecipientRepository(); }
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
