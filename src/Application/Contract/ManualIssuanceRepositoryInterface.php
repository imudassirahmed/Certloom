<?php
namespace Certloom\Application\Contract;

/** Narrow persistence boundary required by one-at-a-time administrator issuance. */
interface ManualIssuanceRepositoryInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_recipient( $recipient_id );
	/** @return array<string,mixed>|\WP_Error */
	public function validate_new_recipient( array $data );
	/** @return int|\WP_Error */
	public function create_recipient( array $data );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_program( $program_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_batch( $batch_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_issuer( $issuer_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_signer( $signer_id );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function resolve_signers_for_context( $issuer_id, $program_id, $batch_id = 0, $role_type = 'signer' );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_published_template( $template_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_published_template_by_machine_key( $machine_key );
	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_published_template_for_program( $program_id, $issuer_id = 0 );
	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_published_template_for_context( $program_id, $batch_id = 0, $issuer_id = 0 );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_existing_series( $recipient_id, $program_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_existing_series_for_batch( $recipient_id, $program_id, $batch_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series( $series_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version( $version_id );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version_by_idempotency( $source_type, $idempotency_key );
	/** @return int|\WP_Error */
	public function create_series( array $data );
	/** @return int|\WP_Error */
	public function create_version( $series_id, array $data );
}
