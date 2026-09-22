<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Transport boundary for WordPress-local email delivery. */
interface MailGatewayInterface {
	/** @return true|\WP_Error */
	public function send( $to, $subject, $html_body, $text_body, array $attachments = array() );
}
