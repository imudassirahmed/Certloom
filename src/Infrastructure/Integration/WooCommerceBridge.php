<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Integration\IntegrationAdapterRegistry;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Application\Integration\IntegrationIssuanceCoordinator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Runtime bridge from a completed WooCommerce order to canonical Certloom issuance. */
final class WooCommerceBridge {
	const LAST_RESULT_OPTION = 'certloom_woocommerce_last_result';
	const HOOK               = 'woocommerce_order_status_completed';
	const MAX_MAPPED_ITEMS   = 50;

	/** @return void */
	public static function register() { add_action( self::HOOK, array( __CLASS__, 'order_completed' ), 20, 1 ); }

	/** @return bool */
	public static function authority_ready() { return \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready(); }

	/** @return array<string,mixed> */
	public static function status() {
		$mappings = ( new WooCommerceMappingStore() )->all();
		$pending = ( new WooCommercePendingMappingRepository() )->read(); $pending = is_wp_error( $pending ) ? array() : $pending;
		$last = get_option( self::LAST_RESULT_OPTION, array() );
		return array(
			'available'            => WooCommerceAdapter::is_available(),
			'version'              => defined( 'WC_VERSION' ) ? sanitize_text_field( (string) WC_VERSION ) : '',
			'authority_ready'      => self::authority_ready(),
			'mapping_count'        => count( $mappings ) + count( $pending ),
			'active_mapping_count' => count( array_filter( array_merge( $mappings, $pending ), static function ( $row ) { return is_array( $row ) && ! empty( $row['active'] ); } ) ),
			'last_result'          => is_array( $last ) ? $last : array(),
		);
	}

	/** @return void */
	public static function order_completed( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! WooCommerceAdapter::is_available() || $order_id < 1 || ! function_exists( 'wc_get_order' ) ) { return; }
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) ) { return; }
		$user_id = method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0;
		if ( $user_id < 1 ) {
			self::record_result( 'blocked', $order_id, 0, 'certloom_woocommerce_guest_unlinked' );
			return;
		}
		$store = new WooCommerceMappingStore();
		$legacy = $store->all(); $pending = ( new WooCommercePendingMappingRepository() )->read();
		if ( is_wp_error( $pending ) ) { self::record_result( 'blocked', $order_id, 0, $pending->get_error_code() ); return; }
		$pending_products = array_column( $pending, null, 'product_id' );
		$items = method_exists( $order, 'get_items' ) ? (array) $order->get_items( 'line_item' ) : array();
		$mapped_products = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) { continue; }
			$product_id = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;
			if ( $product_id < 1 ) { continue; }
			$mapping = $pending_products[ $product_id ] ?? null;
			if ( ! is_array( $mapping ) || ! $mapping['active'] ) { $mapping = $legacy[ (string) $product_id ] ?? null; }
			if ( ! is_array( $mapping ) || empty( $mapping['active'] ) ) { continue; }
			$mapped_products[ $product_id ] = $product_id;
		}
		if ( count( $mapped_products ) > self::MAX_MAPPED_ITEMS ) {
			self::record_result( 'blocked', $order_id, 0, 'certloom_woocommerce_mapped_item_limit' );
			return;
		}
		if ( ! self::authority_ready() && $mapped_products ) {
			self::record_result( 'blocked', $order_id, (int) reset( $mapped_products ), 'certloom_woocommerce_authority_closed' );
			return;
		}
		foreach ( $mapped_products as $product_id ) {
			if ( ! empty( $pending_products[ $product_id ]['active'] ) ) {
				$result = ( new \Certloom\Application\Integration\WooCommercePendingIntake() )->receive( $order_id, $product_id, $user_id );
				if ( is_wp_error( $result ) ) { self::record_result( 'blocked', $order_id, $product_id, $result->get_error_code() ); }
				else { $state = 'issued' === $result['status'] ? ( ! empty( $result['idempotent_replay'] ) ? 'replayed' : 'issued' ) : ( in_array( $result['status'], array( 'received','waiting','ready','processing' ), true ) ? 'waiting' : 'blocked' ); self::record_result( $state, $order_id, $product_id, '', '', (int) ( $result['version_id'] ?? 0 ) ); }
				continue;
			}
			self::issue_mapped_product( $order, $order_id, $product_id, $user_id, $store );
		}
	}

	/** @return void */
	private static function issue_mapped_product( $order, $order_id, $product_id, $user_id, WooCommerceMappingStore $store ) {
		$completed_at = '';
		if ( method_exists( $order, 'get_date_completed' ) ) {
			$date = $order->get_date_completed();
			if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) { $completed_at = gmdate( 'Y-m-d\TH:i:s\Z', (int) $date->getTimestamp() ); }
		}
		$registry = new IntegrationAdapterRegistry( array( new WooCommerceAdapter() ) );
		$adapter = $registry->get( WooCommerceAdapter::KEY );
		if ( is_wp_error( $adapter ) ) { self::record_result( 'failed', $order_id, $product_id, $adapter->get_error_code() ); return; }
		$event = $adapter->normalize_event( array( 'order_id' => $order_id, 'product_id' => $product_id, 'user_id' => $user_id, 'completed_at' => $completed_at ) );
		if ( is_wp_error( $event ) ) { self::record_result( 'failed', $order_id, $product_id, $event->get_error_code() ); return; }
		$context = IntegrationEventContext::from_array( $event );
		if ( is_wp_error( $context ) ) { self::record_result( 'failed', $order_id, $product_id, $context->get_error_code() ); return; }
		$coordinator = new IntegrationIssuanceCoordinator( new LinkedRecipientResolver(), $store );
		$review = $coordinator->review( $context );
		if ( is_wp_error( $review ) ) { self::record_result( 'failed', $order_id, $product_id, $review->get_error_code(), $context->sha256() ); return; }
		$result = $coordinator->issue( $context, (string) $review['review_fingerprint'], 0 );
		if ( is_wp_error( $result ) ) { self::record_result( 'failed', $order_id, $product_id, $result->get_error_code(), $context->sha256() ); return; }
		self::record_result( ! empty( $result['idempotent_replay'] ) ? 'replayed' : 'issued', $order_id, $product_id, '', $context->sha256(), absint( $result['version_id'] ?? 0 ) );
	}

	/** Privacy-safe operational evidence only; never stores checkout identity or order payloads. */
	private static function record_result( $status, $order_id, $product_id, $error_code = '', $context_sha256 = '', $version_id = 0 ) {
		$status = in_array( $status, array( 'issued', 'replayed', 'waiting', 'blocked', 'failed' ), true ) ? $status : 'failed';
		$error_code = sanitize_key( (string) $error_code );
		$context_sha256 = strtolower( trim( (string) $context_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $context_sha256 ) ) { $context_sha256 = ''; }
		update_option( self::LAST_RESULT_OPTION, array(
			'status'         => $status,
			'order_id'       => absint( $order_id ),
			'product_id'     => absint( $product_id ),
			'error_code'     => $error_code,
			'context_sha256' => $context_sha256,
			'version_id'     => absint( $version_id ),
			'finished_at'    => current_time( 'mysql', true ),
		), false );
	}
}
