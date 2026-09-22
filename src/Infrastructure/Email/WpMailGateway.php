<?php
namespace Certloom\Infrastructure\Email;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\MailGatewayInterface;

/** Delegates transport to wp_mail() so normal WordPress SMTP plugins continue to work. */
final class WpMailGateway implements MailGatewayInterface {
	/** @return true|\WP_Error */
	public function send( $to, $subject, $html_body, $text_body, array $attachments = array() ) {
		$to = sanitize_email( (string) $to );
		if ( '' === $to || ( function_exists( 'is_email' ) && ! is_email( $to ) ) ) { return new \WP_Error( 'certloom_email_destination_invalid', __( 'The credential email destination is invalid.', 'certloom' ) ); }
		$subject = sanitize_text_field( (string) $subject );
		$html_body = wp_kses_post( (string) $html_body );
		$text_body = trim( wp_strip_all_tags( (string) $text_body ) );
		$callback = static function ( $phpmailer ) use ( $text_body ) { if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) { $phpmailer->AltBody = $text_body; } };
		if ( function_exists( 'add_action' ) ) { add_action( 'phpmailer_init', $callback, PHP_INT_MAX ); }
		try {
			$sent = wp_mail( $to, $subject, $html_body, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
		} finally {
			if ( function_exists( 'remove_action' ) ) { remove_action( 'phpmailer_init', $callback, PHP_INT_MAX ); }
		}
		return $sent ? true : new \WP_Error( 'certloom_email_transport_failed', __( 'WordPress did not accept the credential email for delivery. Check the site mail/SMTP configuration before retrying.', 'certloom' ) );
	}
}
