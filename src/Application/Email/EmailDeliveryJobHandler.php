<?php
namespace Certloom\Application\Email;

use Certloom\Application\Contract\EmailDeliveryRepositoryInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\MailGatewayInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Email\EmailEnvelopeStore;
use Certloom\Infrastructure\Email\WpMailGateway;
use Certloom\Infrastructure\Repository\EmailDeliveryRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One external side-effect slice. Interrupted sends never replay automatically. */
final class EmailDeliveryJobHandler implements JobHandlerInterface {
	private $repository;
	private $envelopes;
	private $mail;

	public function __construct( ?EmailDeliveryRepositoryInterface $repository = null, ?EmailEnvelopeStore $envelopes = null, ?MailGatewayInterface $mail = null ) {
		$this->repository = $repository ?: new EmailDeliveryRepository();
		$this->envelopes = $envelopes ?: new EmailEnvelopeStore();
		$this->mail = $mail ?: new WpMailGateway();
	}

	public function handle( array $job, $worker_token ) {
		$payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : array();
		$key = (string) ( $payload['delivery_key'] ?? '' );
		$path = (string) ( $payload['envelope_path'] ?? '' );
		$delivery = $this->repository->find_delivery( $key );
		if ( is_wp_error( $delivery ) ) { return $delivery; }
		if ( ! is_array( $delivery ) ) { return new \WP_Error( 'certloom_email_delivery_missing', __( 'The credential email delivery record is unavailable.', 'certloom' ) ); }
		if ( 'sent' === (string) $delivery['status'] ) {
			$this->envelopes->delete( $path );
			return JobSliceResult::succeeded( array( 'delivery_key' => $key, 'status' => 'sent', 'idempotent_terminal' => true ), 1 );
		}
		if ( 'uncertain' === (string) $delivery['status'] ) {
			return JobSliceResult::failed( array( 'code' => 'certloom_email_delivery_uncertain', 'message' => __( 'This email delivery is uncertain after an interrupted worker and requires administrator review before any resend.', 'certloom' ) ), array( 'delivery_key' => $key, 'status' => 'uncertain' ), 0 );
		}
		$envelope = $this->envelopes->read( $path );
		if ( is_wp_error( $envelope ) ) { return $this->preflight_failure( $key, 'envelope_unavailable', $envelope ); }
		if ( 'certloom-email-envelope' !== (string) ( $envelope['schema'] ?? '' ) || $key !== (string) ( $envelope['delivery_key'] ?? '' ) || ! hash_equals( (string) ( $delivery['composition_sha256'] ?? '' ), (string) ( $envelope['composition_sha256'] ?? '' ) ) || ! hash_equals( (string) ( $delivery['destination_sha256'] ?? '' ), (string) ( $envelope['destination_sha256'] ?? '' ) ) ) {
			return $this->preflight_failure( $key, 'envelope_evidence_mismatch', new \WP_Error( 'certloom_email_envelope_evidence_mismatch', __( 'Certloom refused an email envelope that no longer matches its reviewed delivery evidence.', 'certloom' ) ) );
		}
		try { $attempt = bin2hex( random_bytes( 16 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_email_attempt_key_failed', __( 'Certloom could not create a secure email attempt identifier.', 'certloom' ) ); }
		$sending = $this->repository->mark_sending( $key, $attempt );
		if ( is_wp_error( $sending ) ) { return $sending; }
		// The short claim transaction has committed before the external side effect.
		$sent = $this->mail->send( (string) ( $envelope['to'] ?? '' ), (string) ( $envelope['subject'] ?? '' ), (string) ( $envelope['html_body'] ?? '' ), (string) ( $envelope['text_body'] ?? '' ), is_array( $envelope['attachments'] ?? null ) ? $envelope['attachments'] : array() );
		$evidence = array( 'transport' => 'wp_mail', 'transport_result' => is_wp_error( $sent ) ? 'rejected' : 'accepted', 'attempt_token_sha256' => hash( 'sha256', $attempt ) );
		if ( is_wp_error( $sent ) ) {
			$recorded = $this->repository->mark_failed( $key, $sent->get_error_code(), $evidence, false );
			return is_wp_error( $recorded ) ? $recorded : JobSliceResult::failed( array( 'code' => $sent->get_error_code(), 'message' => $sent->get_error_message() ), array( 'delivery_key' => $key, 'status' => 'failed' ), 1 );
		}
		$recorded = $this->repository->mark_sent( $key, $evidence );
		// Mail may already have been accepted. Keep the envelope and surface any
		// persistence conflict; stale recovery will hold uncertainty, never resend.
		if ( is_wp_error( $recorded ) ) { return $recorded; }
		$deleted = $this->envelopes->delete( $path );
		return JobSliceResult::succeeded( array( 'delivery_key' => $key, 'status' => 'sent', 'private_envelope_deleted' => ! is_wp_error( $deleted ) && false !== $deleted ), 1 );
	}

	private function preflight_failure( $key, $code, \WP_Error $error ) {
		$recorded = $this->repository->mark_failed( $key, $code, array(), false );
		return is_wp_error( $recorded ) ? $recorded : $error;
	}
}
