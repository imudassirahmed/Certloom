<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Current catalog and complete requirement hierarchy for a collection context. */
final class RecipientFieldContext {
	public static function option_keys() {
		$keys = array( \Ecapla_CertVerify_Fields::OPTION_KEY, ScopedFieldGlobalRepository::OPTION, \Ecapla_CertVerify_Settings::OPTION_KEY, \Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository::OPTION, \Certloom\Infrastructure\Integration\TutorPendingMappingRepository::OPTION, \Certloom\Infrastructure\Integration\TutorLmsMappingStore::OPTION, \Certloom\Infrastructure\Integration\WooCommercePendingMappingRepository::OPTION, \Certloom\Infrastructure\Integration\WooCommerceMappingStore::OPTION, 'woocommerce_custom_orders_table_enabled' );
		sort( $keys ); return $keys;
	}
	/** Caller owns a transaction. Configuration locks precede Recipient locks. */
	public function lock( $program_id = 0, $batch_id = 0 ) {
		global $wpdb;
		$keys = self::option_keys();
		foreach ( $keys as $key ) {
			$wpdb->last_error = '';
			$wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s FOR UPDATE', $wpdb->options, $key ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		}
		DatabaseOptions::invalidate( $keys );
		\Ecapla_CertVerify_Fields::reset_runtime_cache();
		if ( $program_id ) {
			$wpdb->last_error = '';
			$program = $wpdb->get_row( $wpdb->prepare( 'SELECT id, issuer_id FROM %i WHERE id = %d FOR UPDATE', SchemaManager::table( 'programs' ), absint( $program_id ) ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $program ) ) { return $this->invalid(); }
			foreach ( array( 'issuers' => (int) $program['issuer_id'], 'batches' => absint( $batch_id ) ) as $entity => $id ) {
				if ( ! $id ) { continue; }
				$wpdb->last_error = '';
				$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', SchemaManager::table( $entity ), $id ) );
				if ( '' !== (string) $wpdb->last_error || ! $found ) { return $this->invalid(); }
			}
		}
		return true;
	}

	public function load( $registration = null ) {
		$catalog = \Ecapla_CertVerify_Fields::get_field_map();
		$valid = \Ecapla_CertVerify_Fields::validate_collection_policies( $catalog );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$global = ( new ScopedFieldGlobalRepository() )->get( $catalog );
		if ( is_wp_error( $global ) ) { return $global; }
		$program = null; $batch = null; $issuer = null;
		if ( is_array( $registration ) ) {
			$program = RecipientCommandReadScope::active() ? ( new ProgramRepository() )->find_for_update( $registration['program_id'] ) : ( new ProgramRepository() )->find( $registration['program_id'] );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) || empty( $program['active'] ) || 'cancelled' === $registration['status'] ) { return $this->invalid(); }
			if ( ! empty( $program['issuer_id'] ) ) {
				$issuer = ( new IssuerRepository() )->find( $program['issuer_id'], RecipientCommandReadScope::active() );
				if ( is_wp_error( $issuer ) ) { return $issuer; }
				if ( ! is_array( $issuer ) ) { return $this->invalid(); }
			}
			if ( ! empty( $registration['batch_id'] ) ) {
				$batch = ( new BatchRepository() )->find( $registration['batch_id'], RecipientCommandReadScope::active() );
				if ( is_wp_error( $batch ) ) { return $batch; }
				if ( ! is_array( $batch ) || empty( $batch['active'] ) || (int) $batch['program_id'] !== (int) $program['id'] ) { return $this->invalid(); }
			}
		}
		$contract = ( new ScopedFieldContract() )->resolve_hierarchy( $global, $issuer['field_contract'] ?? null, $program['field_contract'] ?? ScopedFieldContract::empty_program(), $batch['field_contract'] ?? null, $catalog );
		return is_wp_error( $contract ) ? $contract : array( 'catalog' => $catalog, 'contract' => $contract, 'program' => $program, 'batch' => $batch );
	}

	private function invalid() { return new \WP_Error( 'certloom_recipient_field_context_invalid', __( 'These details need an active Program and valid field settings. Contact the issuing organization.', 'certloom' ) ); }
}
