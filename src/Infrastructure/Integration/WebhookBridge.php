<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Integration\IntegrationAdapterRegistry;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Application\Integration\IntegrationIssuanceCoordinator;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Signed inbound webhook → explicit Recipient → canonical issuance. */
final class WebhookBridge {
	const LAST_RESULT_OPTION = 'certloom_webhook_last_result';
	const MAX_CLOCK_SKEW      = 300;
	const MAX_BODY_BYTES      = 8192;

	/** @return bool */
	public static function authority_ready() { return \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready(); }

	/** @return array<string,mixed>|\WP_Error */
	public static function handle( $source_key, $timestamp, $signature, $raw_body ) {
		$source_key = sanitize_key( (string) $source_key );
		$timestamp  = trim( (string) $timestamp );
		$signature  = strtolower( trim( (string) $signature ) );
		$raw_body   = (string) $raw_body;
		$repo       = new WebhookSourceRepository();
		$source     = $repo->find( $source_key );
		if ( ! is_array( $source ) || empty( $source['active'] ) ) {
			return self::fail( 'certloom_webhook_source_unavailable', $source_key, __( 'This webhook source is unavailable.', 'certloom' ) );
		}
		if ( strlen( $raw_body ) < 2 || strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			return self::fail( 'certloom_webhook_body_invalid', $source_key, __( 'The webhook request body is invalid or too large.', 'certloom' ) );
		}
		if ( ! preg_match( '/^\d{10}$/', $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return self::fail( 'certloom_webhook_timestamp_invalid', $source_key, __( 'The webhook timestamp is outside the allowed five-minute window.', 'certloom' ) );
		}
		if ( 0 === strpos( $signature, 'sha256=' ) ) { $signature = substr( $signature, 7 ); }
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return self::fail( 'certloom_webhook_signature_invalid', $source_key, __( 'The webhook signature is invalid.', 'certloom' ) );
		}
		$secret = $repo->secret( $source_key );
		if ( is_wp_error( $secret ) ) { return $secret; }
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::fail( 'certloom_webhook_signature_invalid', $source_key, __( 'The webhook signature is invalid.', 'certloom' ) );
		}

		$data = json_decode( $raw_body, true );
		if ( ! is_array( $data ) ) {
			return self::fail( 'certloom_webhook_payload_invalid', $source_key, __( 'The webhook payload must be valid JSON.', 'certloom' ) );
		}
		$keys = array_keys( $data ); sort( $keys, SORT_STRING );
		$allowed = array( 'completed_at', 'event_id', 'recipient_external_id' );
		if ( $keys !== $allowed ) {
			return self::fail( 'certloom_webhook_payload_invalid', $source_key, __( 'Webhook payload must contain only event_id, recipient_external_id and completed_at.', 'certloom' ) );
		}
		$event_id    = trim( (string) $data['event_id'] );
		$external_id = trim( (string) $data['recipient_external_id'] );
		$completed   = trim( (string) $data['completed_at'] );
		if ( '' === $external_id || strlen( $external_id ) > 191 ) {
			return self::fail( 'certloom_webhook_recipient_external_invalid', $source_key, __( 'The webhook requires the Recipient External ID.', 'certloom' ) );
		}
		$recipient = ( new RecipientRepository() )->find_by_external_id_exact( $external_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) || empty( $recipient['id'] ) ) {
			return self::fail( 'certloom_webhook_recipient_unlinked', $source_key, __( 'No Certloom Recipient has this External ID. Link the person before sending the event.', 'certloom' ) );
		}
		if ( ! self::authority_ready() ) {
			return self::fail( 'certloom_webhook_authority_closed', $source_key, __( 'Webhook eligibility was verified, but automatic credential writing is not yet production-authorized in this Certloom build.', 'certloom' ) );
		}

		$adapter = ( new IntegrationAdapterRegistry( array( new WebhookAdapter() ) ) )->get( WebhookAdapter::KEY );
		if ( is_wp_error( $adapter ) ) { return $adapter; }
		$normalized = $adapter->normalize_event( array( 'source_key' => $source_key, 'event_id' => $event_id, 'recipient_id' => absint( $recipient['id'] ), 'completed_at' => $completed ) );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$context = IntegrationEventContext::from_array( $normalized );
		if ( is_wp_error( $context ) ) { return $context; }
		$coordinator = new IntegrationIssuanceCoordinator( new LinkedRecipientResolver(), $repo );
		$review = $coordinator->review( $context );
		if ( is_wp_error( $review ) ) { return self::record_error( $review, $source_key, $raw_body, $context ); }
		$result = $coordinator->issue( $context, (string) $review['review_fingerprint'], 0 );
		if ( is_wp_error( $result ) ) { return self::record_error( $result, $source_key, $raw_body, $context ); }
		$status = ! empty( $result['idempotent_replay'] ) ? 'replayed' : 'issued';
		self::record( $status, $source_key, '', hash( 'sha256', $raw_body ), $context->sha256(), absint( $result['version_id'] ?? 0 ) );
		return array( 'status' => $status, 'version_id' => absint( $result['version_id'] ?? 0 ), 'series_id' => absint( $result['series_id'] ?? 0 ) );
	}

	/** @return \WP_Error */
	private static function fail( $code, $source_key, $message ) {
		$error = new \WP_Error( $code, $message ); self::record( 'blocked', $source_key, $code ); return $error;
	}
	/** @return \WP_Error */
	private static function record_error( $error, $source_key, $raw_body, IntegrationEventContext $context ) {
		self::record( 'failed', $source_key, $error->get_error_code(), hash( 'sha256', $raw_body ), $context->sha256() ); return $error;
	}
	/** @return void */
	private static function record( $status, $source_key, $error_code = '', $payload_sha = '', $context_sha = '', $version_id = 0 ) {
		update_option( self::LAST_RESULT_OPTION, array(
			'status' => in_array( $status, array( 'issued', 'replayed', 'blocked', 'failed' ), true ) ? $status : 'failed',
			'source_key' => sanitize_key( (string) $source_key ),
			'error_code' => sanitize_key( (string) $error_code ),
			'payload_sha256' => preg_match( '/^[a-f0-9]{64}$/', (string) $payload_sha ) ? $payload_sha : '',
			'context_sha256' => preg_match( '/^[a-f0-9]{64}$/', (string) $context_sha ) ? $context_sha : '',
			'version_id' => absint( $version_id ),
			'finished_at' => current_time( 'mysql', true ),
		), false );
	}
}
