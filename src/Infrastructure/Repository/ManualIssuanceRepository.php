<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Contract\ManualIssuanceRepositoryInterface;
use Certloom\Application\Data\SafeJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** WordPress/MySQL adapter composing existing normalized-domain repositories. */
final class ManualIssuanceRepository implements ManualIssuanceRepositoryInterface {
	/** @var RecipientRepository */ private $recipients;
	/** @var ProgramRepository */ private $programs;
	/** @var BatchRepository */ private $batches;
	/** @var IssuerRepository */ private $issuers;
	/** @var TemplateRepository */ private $templates;
	/** @var CredentialRepository */ private $credentials;
	public function __construct( ?RecipientRepository $recipients = null, ?ProgramRepository $programs = null, ?IssuerRepository $issuers = null, ?TemplateRepository $templates = null, ?CredentialRepository $credentials = null, ?BatchRepository $batches = null ) {
		$this->recipients = $recipients ?: new RecipientRepository();
		$this->programs = $programs ?: new ProgramRepository();
		$this->batches = $batches ?: new BatchRepository();
		$this->issuers = $issuers ?: new IssuerRepository();
		$this->templates = $templates ?: new TemplateRepository();
		$this->credentials = $credentials ?: new CredentialRepository();
	}
	public function find_recipient( $id ) { return $this->recipients->find( $id ); }
	/** Review-time validation mirrors the existing Recipient admin boundary without mutating storage. */
	public function validate_new_recipient( array $data ) {
		$name = trim( (string) ( $data['display_name'] ?? '' ) );
		if ( '' === $name || $this->length( $name ) > 255 ) { return new \WP_Error( 'certloom_recipient_name_required', __( 'Enter a recipient display name up to 255 characters.', 'certloom' ) ); }
		$out = array( 'display_name' => $name );
		foreach ( array( 'first_name', 'middle_name', 'last_name', 'external_id' ) as $key ) {
			$value = trim( (string) ( $data[ $key ] ?? '' ) );
			if ( $this->length( $value ) > 191 ) { return new \WP_Error( 'certloom_recipient_field_too_long', __( 'Recipient names and external IDs must be 191 characters or fewer.', 'certloom' ) ); }
			$out[ $key ] = '' === $value ? null : $value;
		}
		$email = trim( (string) ( $data['email'] ?? '' ) );
		if ( $this->length( $email ) > 320 || ( '' !== $email && function_exists( 'is_email' ) && ! is_email( $email ) ) ) { return new \WP_Error( 'certloom_recipient_email_invalid', __( 'Enter a valid recipient email address or leave it blank.', 'certloom' ) ); }
		$out['email'] = '' === $email ? null : $email;
		$out['wp_user_id'] = null;
		$custom = isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array();
		$encoded = ( new SafeJson() )->encode_array( $custom, 'certloom_recipient_custom_fields_invalid', __( 'Recipient custom fields could not be encoded safely.', 'certloom' ) );
		if ( is_wp_error( $encoded ) ) { return $encoded; }
		$out['custom_fields'] = $custom;
		return $out;
	}
	public function create_recipient( array $data ) { return $this->recipients->create( $data ); }
	public function find_program( $id ) { return $this->programs->find( $id ); }
	public function find_batch( $id ) { return $this->batches->find( $id ); }
	public function find_issuer( $id ) { return $this->issuers->find( $id ); }
	public function find_published_template( $id ) { return $this->templates->find_published( $id ); }
	public function find_published_template_by_machine_key( $machine_key ) { return $this->templates->find_published_by_machine_key( $machine_key ); }
	public function resolve_published_template_for_program( $program_id, $issuer_id = 0 ) { return $this->templates->resolve_published_assignment_details( array( 'issuer_id' => absint( $issuer_id ), 'program_id' => absint( $program_id ) ) ); }
	public function resolve_published_template_for_context( $program_id, $batch_id = 0, $issuer_id = 0 ) { return $this->templates->resolve_published_assignment_details( array( 'issuer_id' => absint( $issuer_id ), 'program_id' => absint( $program_id ), 'batch_id' => absint( $batch_id ) ) ); }
	public function find_existing_series( $recipient_id, $program_id ) { return $this->credentials->find_series_for_recipient_program( $recipient_id, $program_id ); }
	public function find_series( $id ) { return $this->credentials->find_series( $id ); }
	public function find_version( $id ) { return $this->credentials->find_version( $id ); }
	public function find_version_by_idempotency( $source_type, $key ) { return $this->credentials->find_version_by_source_idempotency( $source_type, $key ); }
	public function create_series( array $data ) { return $this->credentials->create_series( $data ); }
	public function create_version( $series_id, array $data ) { return $this->credentials->create_version( $series_id, $data ); }
	private function length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value ); }
}
