<?php
namespace Certloom\Application\Recipient;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Append-only, privacy-safe notice evidence in the existing recoverable request event graph. */
final class RecipientNoticeRecord {
	const TYPE = 'request.notice';
	public static function key( $public_ref, $kind, $slot ) { return hash( 'sha256', 'certloom-recipient-notice-v1:' . $public_ref . ':' . $kind . ':' . $slot ); }
	public static function event_key( $key, $state ) { return hash( 'sha256', 'certloom-recipient-notice-event-v1:' . $key . ':' . $state ); }
	public static function day_key( $recipient_id, $day ) { return hash( 'sha256', 'certloom-recipient-notice-day-v1:' . $recipient_id . ':' . $day ); }
	public function validate( $meta ) {
		$keys = array( 'version', 'notice_key', 'kind', 'slot', 'state', 'user_id', 'destination_sha256', 'job_key', 'attempt_sha256' );
		if ( ! is_array( $meta ) || count( $meta ) !== count( $keys ) || array_diff( array_keys( $meta ), $keys ) || 1 !== $meta['version'] || ! in_array( $meta['kind'], array( 'reminder', 'completion', 'cancellation' ), true ) || ! in_array( $meta['state'], array( 'queued', 'sending', 'sent', 'failed', 'uncertain', 'skipped', 'throttle' ), true ) || ! is_int( $meta['slot'] ) || $meta['slot'] < 0 || $meta['slot'] > 3 || ! is_int( $meta['user_id'] ) || $meta['user_id'] < 1 ) { return $this->invalid(); }
		foreach ( array( 'notice_key', 'destination_sha256' ) as $key ) { if ( ! is_string( $meta[ $key ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $meta[ $key ] ) ) { return $this->invalid(); } }
		if ( ! is_string( $meta['job_key'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $meta['job_key'] ) || ( null !== $meta['attempt_sha256'] && ( ! is_string( $meta['attempt_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $meta['attempt_sha256'] ) ) ) ) { return $this->invalid(); }
		if ( ( 'reminder' === $meta['kind'] ) !== ( $meta['slot'] > 0 ) || ( in_array( $meta['state'], array( 'sending', 'sent', 'failed', 'uncertain' ), true ) && null === $meta['attempt_sha256'] ) ) { return $this->invalid(); }
		return true;
	}
	private function invalid() { return new \WP_Error( 'certloom_recipient_notice_invalid', __( 'This recipient notice needs review before it can be processed.', 'certloom' ) ); }
}
