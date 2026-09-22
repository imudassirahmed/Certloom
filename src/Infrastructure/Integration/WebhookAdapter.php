<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Provider-neutral adapter for administrator-created signed webhook sources. */
final class WebhookAdapter implements IntegrationAdapterInterface {
	const KEY = 'webhook';
	const SOURCE_OBJECT_TYPE = 'source';
	const EVENT_TYPE = 'eligibility_confirmed';
	public function key(){ return self::KEY; }
	public function label(){ return __( 'Webhook / API', 'certloom' ); }
	/** @return array<string,mixed>|\WP_Error */
	public function normalize_event( array $event ) {
		$source_key = strtolower( trim( (string) ( $event['source_key'] ?? '' ) ) );
		$event_id = trim( (string) ( $event['event_id'] ?? '' ) );
		$recipient_id = absint( $event['recipient_id'] ?? 0 );
		if ( ! self::machine_key( $source_key ) ) { return new \WP_Error( 'certloom_webhook_source_invalid', __( 'The webhook source is invalid.', 'certloom' ) ); }
		if ( ! self::stable_id( $event_id ) ) { return new \WP_Error( 'certloom_webhook_event_invalid', __( 'The webhook event requires a stable event ID.', 'certloom' ) ); }
		if ( $recipient_id < 1 ) { return new \WP_Error( 'certloom_webhook_recipient_invalid', __( 'The webhook event is not explicitly linked to a Certloom Recipient.', 'certloom' ) ); }
		$completed_at = self::utc( $event['completed_at'] ?? '' ); if ( is_wp_error( $completed_at ) ) { return $completed_at; }
		return array(
			'adapter' => self::KEY,
			'event_type' => self::EVENT_TYPE,
			'source_event_id' => $event_id,
			'wp_user_id' => 0,
			'recipient_id' => $recipient_id,
			'source_object' => array( 'type' => self::SOURCE_OBJECT_TYPE, 'id' => $source_key ),
			'certificate_threshold' => 100.0,
			'attempts' => array( array( 'attempt_id' => 'event:' . $event_id, 'attempted_at' => $completed_at, 'completed' => true, 'percentage' => 100.0, 'provider_pass_threshold' => null ) ),
		);
	}
	private static function machine_key($v){return ''!==$v&&strlen($v)<=64&&1===preg_match('/^[a-z][a-z0-9_\-]*$/',$v);} private static function stable_id($v){$v=trim((string)$v);return ''!==$v&&strlen($v)<=191&&1===preg_match('/^[A-Za-z0-9._:@\/-]+$/',$v);} private static function utc($v){$v=trim((string)$v);if(''===$v||strlen($v)>64)return new \WP_Error('certloom_webhook_time_invalid',__('The webhook event requires a valid completion time.','certloom'));try{$d=new \DateTimeImmutable($v);}catch(\Throwable$e){return new \WP_Error('certloom_webhook_time_invalid',__('The webhook event requires a valid completion time.','certloom'));}return$d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}
}
