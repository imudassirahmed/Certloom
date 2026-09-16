<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Explicit WordPress-user → Recipient identity bridge. */
interface IntegrationRecipientResolverInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_recipient_for_wp_user( $wp_user_id );
}
