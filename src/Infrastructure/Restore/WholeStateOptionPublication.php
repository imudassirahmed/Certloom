<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateTemplateRecommendationInventory;
use Certloom\Infrastructure\Backup\WholeStateOptionRepository;
use Certloom\Infrastructure\Backup\WholeStateTemplateRecommendationRepository;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checked exact raw publication for the locally fixed recovery scope and one bounded namespace. */
final class WholeStateOptionPublication {
	public function snapshot() {
		global $wpdb;
		$fixed = ( new WholeStateOptionRepository() )->snapshot(); if ( is_wp_error( $fixed ) ) { return $fixed; }
		$dynamic = ( new WholeStateTemplateRecommendationRepository() )->snapshot(); if ( is_wp_error( $dynamic ) ) { return $dynamic; }
		$names = array_merge( WholeStateOptionInventory::keys(), array_keys( $dynamic['options'] ) ); $autoloads = array();
		foreach ( array_chunk( $names, 200 ) as $keys ) {
			$wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,autoload FROM %i WHERE option_name IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ') ORDER BY option_name', array_merge( array( $wpdb->options ), $keys ) ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
			foreach ( $rows as $row ) { if ( ! in_array( $row['option_name'] ?? null, $keys, true ) || isset( $autoloads[$row['option_name']] ) || ! $this->autoload( $row['autoload'] ?? null ) ) { return $this->invalid(); } $autoloads[$row['option_name']] = $row['autoload']; }
		}
		ksort( $autoloads, SORT_STRING );
		$result = array( 'fixed' => $fixed['options'], 'recommendations' => $dynamic['options'], 'autoloads' => $autoloads );
		if ( is_wp_error( $this->validate( $result ) ) || is_wp_error( ( new WholeStateOptionRepository() )->verify( $fixed['fingerprint'] ) ) || is_wp_error( ( new WholeStateTemplateRecommendationRepository() )->verify( $dynamic['fingerprint'] ) ) ) { return $this->invalid(); }
		return $result;
	}
	public function validate( array $snapshot ) {
		if ( count( $snapshot ) !== 3 || array_diff( array_keys( $snapshot ), array( 'fixed', 'recommendations', 'autoloads' ) ) || ! is_array( $snapshot['autoloads'] ?? null ) ) { return $this->invalid(); }
		$fixed = ( new WholeStateOptionRecord() )->validate_set( $snapshot['fixed'] ?? null, WholeStateOptionInventory::keys() ); if ( is_wp_error( $fixed ) ) { return $fixed; }
		$dynamic = ( new WholeStateTemplateRecommendationInventory() )->validate_records( $snapshot['recommendations'] ?? null ); if ( is_wp_error( $dynamic ) ) { return $dynamic; }
		$present = array_filter( $fixed + $dynamic, static function ( $record ) { return $record['exists']; } );
		if ( count( $present ) !== count( $snapshot['autoloads'] ) || array_diff_key( $present, $snapshot['autoloads'] ) || array_diff_key( $snapshot['autoloads'], $present ) ) { return $this->invalid(); }
		foreach ( $snapshot['autoloads'] as $value ) { if ( ! $this->autoload( $value ) ) { return $this->invalid(); } }
		return $snapshot;
	}
	/** Caller must own the maintenance fence; no archive can choose arbitrary option names. */
	public function apply( array $snapshot, $owner_token, ?array $expected = null ) {
		$valid = $this->validate( $snapshot ); if ( is_wp_error( $valid ) || ! ( new DomainMaintenanceLock() )->owned_active( $owner_token ) ) { return $this->invalid(); }
		if ( null !== $expected && is_wp_error( $this->validate( $expected ) ) ) { return $this->invalid(); }
		$keys = array();
		$result = DomainMaintenanceLock::run_internal( function () use ( $snapshot, $expected, &$keys ) { return ( new Transaction() )->run( function () use ( $snapshot, $expected, &$keys ) {
			global $wpdb;
			// Lock fixed keys and the exact namespace in canonical order. Gap locks
			// prevent a concurrently inserted recommendation escaping replacement.
			$fixed_keys = WholeStateOptionInventory::keys(); $wpdb->last_error = '';
			$fixed = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM %i FORCE INDEX (option_name) WHERE option_name IN (' . implode( ',', array_fill( 0, count( $fixed_keys ), '%s' ) ) . ') ORDER BY option_name FOR UPDATE', array_merge( array( $wpdb->options ), $fixed_keys ) ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $fixed ) ) { return $this->invalid(); }
			$wpdb->last_error = '';
			$dynamic = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM %i FORCE INDEX (option_name) WHERE option_name LIKE %s ORDER BY option_name LIMIT %d FOR UPDATE', $wpdb->options, $wpdb->esc_like( WholeStateTemplateRecommendationInventory::PREFIX ) . '%', WholeStateTemplateRecommendationInventory::MAX_OPTIONS + 1 ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $dynamic ) || count( $dynamic ) > WholeStateTemplateRecommendationInventory::MAX_OPTIONS ) { return $this->invalid(); }
			$current = $this->snapshot(); if ( is_wp_error( $current ) || ( null !== $expected && $this->fingerprint( $current ) !== $this->fingerprint( $expected ) ) ) { return $this->invalid(); }
			$records = $snapshot['fixed'] + $snapshot['recommendations'];
			foreach ( $current['recommendations'] as $name => $record ) { if ( ! isset( $records[$name] ) ) { $records[$name] = ( new WholeStateOptionRecord() )->from_storage( null ); } }
			ksort( $records, SORT_STRING ); $keys = array_keys( $records ); $before = $current['fixed'] + $current['recommendations'];
			foreach ( $records as $key => $record ) {
				$exists = ! empty( $before[$key]['exists'] ); $wpdb->last_error = '';
				if ( ! $record['exists'] ) {
					if ( $exists && ( 1 !== $wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ) || '' !== (string) $wpdb->last_error ) ) { return $this->invalid(); } continue;
				}
				$raw = base64_decode( $record['storage_base64'], true ); $autoload = $snapshot['autoloads'][$key];
				if ( $exists && $record === $before[$key] && $autoload === $current['autoloads'][$key] ) { continue; }
				$data = array( 'option_value' => $raw, 'autoload' => $autoload );
				$written = $exists ? $wpdb->update( $wpdb->options, $data, array( 'option_name' => $key ) ) : $wpdb->insert( $wpdb->options, array_merge( array( 'option_name' => $key ), $data ) );
				if ( 1 !== $written || '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			}
			$after = $this->snapshot(); return ! is_wp_error( $after ) && $this->fingerprint( $after ) === $this->fingerprint( $snapshot ) ? true : $this->invalid();
		} ); } );
		DatabaseOptions::invalidate( $keys ); if ( class_exists( 'Ecapla_CertVerify_Fields', false ) ) { \Ecapla_CertVerify_Fields::reset_runtime_cache(); }
		return $result;
	}
	public function fingerprint( array $snapshot ) { try { return ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $snapshot ); } catch ( \Throwable $error ) { return ''; } }
	private function autoload( $value ) { return is_string( $value ) && in_array( $value, array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_option_publication_failed', __( 'Certloom could not publish the complete reviewed settings safely. Recovery remains paused and the previous settings are retained for rollback.', 'certloom' ) ); }
}
