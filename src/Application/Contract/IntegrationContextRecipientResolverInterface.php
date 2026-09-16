<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Integration\IntegrationEventContext;

/**
 * Optional richer identity boundary for integrations whose provider event is
 * explicitly linked to a Certloom Recipient without a WordPress-user bridge.
 *
 * Existing LMS/commerce adapters remain compatible with the original
 * WordPress-user resolver. External organization connectors may resolve a
 * local recipient_id that was established before normalization.
 */
interface IntegrationContextRecipientResolverInterface extends IntegrationRecipientResolverInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_recipient_for_context( IntegrationEventContext $context );
}
