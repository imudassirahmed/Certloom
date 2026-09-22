<?php
namespace Certloom\Application\Contract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Persistence boundary used by WordPress privacy export/anonymization flows. */
interface PrivacyRepositoryInterface {
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function find_recipients_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 );
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_recipient_for_update( $recipient_id );
	/** @return array<int,int>|\WP_Error */
	public function series_ids_for_recipient( $recipient_id, $limit = 101 );
	/** @return true|\WP_Error */
	public function anonymize_recipient( $recipient_id, $replacement_name, $expected_updated_at, array $identity_hashes = array() );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function export_series_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function export_versions_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function export_events_for_identity( $email, $wp_user_id = 0, $offset = 0, $limit = 50 );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function export_signers_for_email( $email, $offset = 0, $limit = 50 );
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function export_issuers_for_email( $email, $offset = 0, $limit = 50 );
	/** @return bool */
	public function domain_storage_exists();
}
