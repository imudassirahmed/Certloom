<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit opt-in; existing completion-only mappings retain their v1 meaning. */
final class WooCommercePendingMappingRepository {
	public function validate_recovery_value( $value ) { return \Certloom\Application\Restore\WholeStatePendingMappingValue::validate( $value, 'wp', 'product_id', self::MAX_MAPPINGS ); }
	const OPTION = 'certloom_woocommerce_pending_mappings_v1';
	const MAX_MAPPINGS = 100;
	public function read() {
		$value = get_option( self::OPTION, null );
		if ( null === $value ) { return array(); }
		if ( ! is_array( $value ) || count( $value ) !== 2 || 1 !== ( $value['schema_version'] ?? null ) || ! is_array( $value['mappings'] ?? null ) || count( $value['mappings'] ) > self::MAX_MAPPINGS || ( $value['mappings'] && array_keys( $value['mappings'] ) !== range( 0, count( $value['mappings'] ) - 1 ) ) ) { return $this->invalid(); }
		$out = array(); $products = array();
		foreach ( $value['mappings'] as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== 7 || ! self::key( $row['key'] ?? null ) || ! is_int( $row['product_id'] ?? null ) || $row['product_id'] < 1 || ! is_int( $row['program_id'] ?? null ) || $row['program_id'] < 1 || ! is_int( $row['batch_id'] ?? null ) || $row['batch_id'] < 0 || ! is_bool( $row['active'] ?? null ) || ! is_int( $row['revision'] ?? null ) || $row['revision'] < 1 || ! is_string( $row['updated_at'] ?? null ) || ! $this->date( $row['updated_at'] ) || isset( $out[ $row['key'] ] ) || isset( $products[ $row['product_id'] ] ) ) { return $this->invalid(); }
			$out[ $row['key'] ] = $row; $products[ $row['product_id'] ] = true;
		}
		ksort( $out, SORT_STRING ); return $out;
	}
	public static function key( $value ) { return is_string( $value ) && 1 === preg_match( '/^wp-[a-f0-9]{12}$/D', $value ); }
	public function find( $key ) { if ( ! self::key( $key ) ) { return $this->invalid(); } $rows = $this->read(); return is_wp_error( $rows ) ? $rows : ( $rows[ $key ] ?? null ); }
	public function for_product( $id ) { $rows = $this->read(); if ( is_wp_error( $rows ) ) { return $rows; } foreach ( $rows as $row ) { if ( $row['product_id'] === (int) $id ) { return $row; } } return null; }
	public function revision( array $row ) { return ( new CanonicalJson() )->hash( $row ); }
	public function mapping_sha256( array $row ) { unset( $row['revision'], $row['updated_at'] ); return ( new CanonicalJson() )->hash( array( 'schema_version' => 1, 'mapping' => $row ) ); }
	public function create( $product, $program, $batch ) {
		if ( ! is_int( $product ) || $product < 1 || ! is_int( $program ) || $program < 1 || ! is_int( $batch ) || $batch < 0 ) { return $this->invalid(); }
		return $this->command( $program, $batch, function ( array $rows ) use ( $product, $program, $batch ) {
			if ( count( $rows ) >= self::MAX_MAPPINGS ) { return $this->invalid(); }
			foreach ( $rows as $row ) { if ( $row['product_id'] === $product ) { return $this->invalid(); } }
			$valid = $this->validate_target( $product, $program, $batch ); if ( is_wp_error( $valid ) ) { return $valid; }
			try { $key = 'wp-' . bin2hex( random_bytes( 6 ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
			if ( isset( $rows[ $key ] ) ) { return $this->invalid(); }
			$rows[ $key ] = array( 'key' => $key, 'product_id' => $product, 'program_id' => $program, 'batch_id' => $batch, 'active' => true, 'revision' => 1, 'updated_at' => current_time( 'mysql', true ) );
			return array( 'rows' => $rows, 'value' => $key );
		} );
	}
	public function change( $key, $fingerprint, $action ) {
		if ( ! in_array( $action, array( 'pause', 'activate', 'delete' ), true ) || ! self::key( $key ) || ! is_string( $fingerprint ) || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) { return $this->invalid(); }
		$row = $this->find( $key ); if ( is_wp_error( $row ) || ! is_array( $row ) ) { return is_wp_error( $row ) ? $row : $this->invalid(); }
		return $this->command( 'activate' === $action ? $row['program_id'] : 0, 'activate' === $action ? $row['batch_id'] : 0, function ( array $rows ) use ( $key, $fingerprint, $action ) {
			if ( ! isset( $rows[ $key ] ) || ! hash_equals( $this->revision( $rows[ $key ] ), $fingerprint ) ) { return new \WP_Error( 'certloom_woocommerce_pending_stale', __( 'This WooCommerce connection changed. Refresh before updating it.', 'certloom' ) ); }
			$row = $rows[ $key ];
			if ( 'delete' === $action ) { unset( $rows[ $key ] ); }
			else {
				if ( 'activate' === $action ) { $valid = $this->validate_target( $row['product_id'], $row['program_id'], $row['batch_id'] ); if ( is_wp_error( $valid ) ) { return $valid; } }
				$active = 'activate' === $action; if ( $row['active'] === $active ) { return array( 'rows' => $rows, 'value' => true ); }
				if ( PHP_INT_MAX === $row['revision'] ) { return $this->invalid(); }
				$row['active'] = $active; $row['revision']++; $row['updated_at'] = current_time( 'mysql', true ); $rows[ $key ] = $row;
			}
			return array( 'rows' => $rows, 'value' => true );
		} );
	}
	public function legacy_clear( $product ) {
		$legacy = get_option( WooCommerceMappingStore::OPTION, null );
		if ( null === $legacy || false === $legacy || array() === $legacy ) { return true; }
		if ( ! is_array( $legacy ) || 1 !== ( $legacy['schema_version'] ?? null ) || ! is_array( $legacy['mappings'] ?? null ) || count( $legacy['mappings'] ) > WooCommerceMappingStore::MAX_MAPPINGS ) { return $this->invalid(); }
		foreach ( $legacy['mappings'] as $row ) { if ( ! is_array( $row ) || ! isset( $row['product_id'], $row['program_id'] ) ) { return $this->invalid(); } if ( (int) $row['product_id'] === (int) $product && ( ! array_key_exists( 'active', $row ) || ! empty( $row['active'] ) ) ) { return new \WP_Error( 'certloom_woocommerce_pending_mapping_overlap', __( 'Pause or remove this product’s completion-only mapping before enabling customer-detail collection. One product uses one issuance flow.', 'certloom' ) ); } }
		return true;
	}
	public function validate_target( $product, $program, $batch ) {
		$clear = $this->legacy_clear( $product ); if ( is_wp_error( $clear ) ) { return $clear; }
		$post = get_post( $product ); if ( ! WooCommerceAdapter::is_available() || ! $post || 'product' !== $post->post_type || ! in_array( $post->post_status, array( 'publish','private' ), true ) ) { return new \WP_Error( 'certloom_woocommerce_pending_product_unavailable', __( 'Choose an available published or private WooCommerce product.', 'certloom' ) ); }
		$context = ( new RecipientFieldContext() )->load( array( 'program_id' => $program, 'batch_id' => $batch, 'status' => 'active' ) ); if ( is_wp_error( $context ) ) { return $context; }
		return ( new \Certloom\Infrastructure\Admin\IntegrationMappingTargetGuard() )->validate_snapshot( $context['program'], $context['batch'], null, null, array() );
	}
	private function command( $program, $batch, callable $command ) {
		$changed = false;
		$result = ( new Transaction() )->run( function () use ( $program, $batch, $command, &$changed ) {
			$locked = ( new RecipientFieldContext() )->lock( $program, $batch ); if ( is_wp_error( $locked ) ) { return $locked; }
			$rows = $this->read(); if ( is_wp_error( $rows ) ) { return $rows; } $out = $command( $rows ); if ( is_wp_error( $out ) ) { return $out; }
			if ( $rows === $out['rows'] ) { return $out['value']; }
			ksort( $out['rows'], SORT_STRING ); $payload = array( 'schema_version' => 1, 'mappings' => array_values( $out['rows'] ) );
			$generation = ( new DomainGeneration() )->mark_before_mutation(); if ( is_wp_error( $generation ) ) { return $generation; }
			update_option( self::OPTION, $payload, false ); if ( ! LegacyWriteGuard::verify_option( self::OPTION, $payload ) ) { return $this->invalid(); }
			$signal = ( new PendingConfigurationQueue() )->changed(); if ( is_wp_error( $signal ) ) { return $signal; } $changed = true; return $out['value'];
		} );
		DatabaseOptions::invalidate( array( self::OPTION, PendingConfigurationQueue::OPTION, DomainGeneration::OPTION ) ); LegacyWriteGuard::invalidate_cache();
		if ( ! is_wp_error( $result ) && $changed ) { do_action( 'certloom_pending_configuration_changed' ); } return $result;
	}
	private function date( $date ) { $time = strtotime( $date . ' UTC' ); return false !== $time && gmdate( 'Y-m-d H:i:s', $time ) === $date; }
	private function invalid() { return new \WP_Error( 'certloom_woocommerce_pending_mapping_invalid', __( 'The WooCommerce customer-detail connection could not be read or saved safely. Review its product, Program and current revision.', 'certloom' ) ); }
}
