<?php
namespace Certloom\Application\Sharing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic, privacy-minimized SOC-01 share-link builder.
 *
 * Certloom never sends credential data to a Certloom-owned service. Social
 * providers receive only the already-public canonical credential URL plus a
 * generic localized message supplied by the caller. Recipient names, email,
 * grades and other credential fields are deliberately excluded here.
 */
final class ShareLinkBuilder {
	const PLATFORM_LINKEDIN = 'linkedin';
	const PLATFORM_WHATSAPP = 'whatsapp';
	const PLATFORM_FACEBOOK = 'facebook';
	const PLATFORM_X        = 'x';
	const PLATFORM_EMAIL    = 'email';

	/** @return bool */
	public function is_shareable_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function links( $canonical_url, $message = '', $email_subject = '' ) {
		$canonical_url = trim( (string) $canonical_url );
		if ( ! $this->is_shareable_url( $canonical_url ) ) {
			return array();
		}

		$message       = $this->clean_message( $message, 240 );
		$email_subject = $this->clean_message( $email_subject, 160 );
		if ( '' === $message ) {
			$message = 'View this verified credential';
		}
		if ( '' === $email_subject ) {
			$email_subject = 'Verified credential';
		}

		$url_encoded     = rawurlencode( $canonical_url );
		$message_encoded = rawurlencode( $message );
		$combined        = rawurlencode( $message . ' ' . $canonical_url );
		$email_body      = rawurlencode( $message . "\n\n" . $canonical_url );

		return array(
			array(
				'platform' => self::PLATFORM_LINKEDIN,
				'url'      => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $url_encoded,
			),
			array(
				'platform' => self::PLATFORM_WHATSAPP,
				'url'      => 'https://wa.me/?text=' . $combined,
			),
			array(
				'platform' => self::PLATFORM_FACEBOOK,
				'url'      => 'https://www.facebook.com/sharer/sharer.php?u=' . $url_encoded,
			),
			array(
				'platform' => self::PLATFORM_X,
				'url'      => 'https://x.com/intent/tweet?text=' . $message_encoded . '&url=' . $url_encoded,
			),
			array(
				'platform' => self::PLATFORM_EMAIL,
				'url'      => 'mailto:?subject=' . rawurlencode( $email_subject ) . '&body=' . $email_body,
			),
		);
	}

	/** @return string */
	private function clean_message( $value, $max ) {
		$value = preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', (string) $value );
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, (int) $max );
		}
		return substr( $value, 0, (int) $max );
	}
}
