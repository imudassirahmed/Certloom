<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Data\SafeJson;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Setup\WizardFieldSetup;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Must run inside the guided setup transaction; its caller owns commit and rollback. */
final class WizardFieldSetupCommand {
	public function prepare( array $draft ) {
		if ( ! array_key_exists( 'setup_field_plan', $draft ) ) { return null; } // Old/quick drafts keep their meaning.
		global $wpdb;
		$program_id = absint( $draft['setup_program_id'] ?? 0 );
		if ( ! $program_id && '' === trim( (string) ( $draft['setup_new_program_title'] ?? '' ) ) ) { return new \WP_Error( 'certloom_setup_fields_program_required', __( 'Choose or add a Program before applying its field choices. Return to the Program step to continue.', 'certloom' ) ); }
		$keys = array( \Ecapla_CertVerify_Fields::OPTION_KEY, \Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY );
		$wpdb->last_error = '';
		$locked = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s) ORDER BY option_name FOR UPDATE', $wpdb->options, $keys[0], $keys[1] ), ARRAY_A );
		if ( ! is_array( $locked ) || '' !== (string) $wpdb->last_error ) { return $this->failed(); }
		DatabaseOptions::invalidate( $keys );
		$stored = array();
		foreach ( $locked as $row ) { $stored[ $row['option_name'] ] = maybe_unserialize( $row['option_value'] ); }
		$valid = \Ecapla_CertVerify_Fields::use_configuration_snapshot( $stored[ $keys[0] ] ?? null, $stored[ $keys[1] ] ?? null );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$catalog = \Ecapla_CertVerify_Fields::get_field_map();
		$retired = \Ecapla_CertVerify_Fields::get_retired_fields();
		$program = null; $raw_metadata = array();
		if ( $program_id ) {
			$wpdb->last_error = '';
			$program = ( new ProgramRepository() )->find_for_update( $program_id );
			if ( is_wp_error( $program ) || ! is_array( $program ) ) { return is_wp_error( $program ) ? $program : $this->failed(); }
			// Read raw envelopes under the same row lock; no intervening consistent read may replace current facts.
			$wpdb->last_error = '';
			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT metadata_json FROM %i WHERE id = %d FOR UPDATE', SchemaManager::table( 'programs' ), $program_id ) );
			if ( '' !== (string) $wpdb->last_error ) { return $this->failed(); }
			$raw_metadata = ( new SafeJson() )->decode_array( $raw, 'certloom_setup_program_metadata_invalid', __( 'The selected Program settings could not be read.', 'certloom' ) );
			if ( is_wp_error( $raw_metadata ) ) { return $raw_metadata; }
		}
		$plan = ( new WizardFieldSetup() )->build( $draft['setup_field_plan'], $catalog, $retired, $program );
		if ( is_wp_error( $plan ) ) { return $plan; }
		// Replace only the two reviewed envelopes, preserving raw profile versions and nested integration metadata.
		foreach ( array( ScopedFieldContract::STORAGE_KEY, ProgramFieldDefaults::KEY ) as $key ) { $raw_metadata[ $key ] = $plan['metadata'][ $key ]; }
		$plan['metadata'] = $raw_metadata;
		if ( $plan['catalog'] !== $catalog ) {
			// update_option compares get_option before writing. An earlier transaction
			// snapshot/cache must not suppress a change back to a previous value.
			$current_option = static function () use ( $stored, $keys ) { return $stored[ $keys[0] ] ?? false; };
			add_filter( 'pre_option_' . $keys[0], $current_option, PHP_INT_MAX );
			try { $saved = \Ecapla_CertVerify_Fields::save_fields( array_values( $plan['catalog'] ) ); }
			finally { remove_filter( 'pre_option_' . $keys[0], $current_option, PHP_INT_MAX ); DatabaseOptions::invalidate( $keys ); }
			if ( ! $saved ) { return $this->failed(); }
			$changed_existing = false;
			foreach ( $catalog as $key => $field ) { if ( $field !== $plan['catalog'][ $key ] ) { $changed_existing = true; break; } }
			if ( $changed_existing ) {
				// Source settings are global. Validate existing consumers rather than quietly breaking another Program.
				$wpdb->last_error = '';
				$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id LIMIT 501', SchemaManager::table( 'programs' ) ) );
				if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) { return $this->failed(); }
				if ( count( $ids ) > 500 ) { return new \WP_Error( 'certloom_setup_catalog_review_limit', __( 'This site has many Programs. Review shared field-source changes in Fields Manager before continuing setup.', 'certloom' ) ); }
				foreach ( $ids as $id ) {
					$current = ( new ProgramRepository() )->find( $id );
					if ( is_wp_error( $current ) || ! is_array( $current ) ) { return is_wp_error( $current ) ? $current : $this->failed(); }
					$valid = ( new ProgramFieldDefaults() )->from_program( $current, $plan['catalog'] );
					if ( is_wp_error( $valid ) ) { return $valid; }
					$valid = ( new ScopedFieldConfigurationGuard() )->validate_program_change( $id, (int) $id === $program_id ? $plan['contract'] : $current['field_contract'], $current['profile'], $current['issuer_id'] );
					if ( is_wp_error( $valid ) ) { return $valid; }
				}
			}
		}
		return $plan;
	}
	private function failed() { return new \WP_Error( 'certloom_setup_fields_save_failed', __( 'Field choices could not be saved safely. No setup changes were applied. Reload Fields and try again.', 'certloom' ) ); }
}
