<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Current stored purchase authority, independent of cached Woo objects and hook arguments. */
final class WooCommerceCompletionEvidence {
	public function read( $order, $product, $user ) {
		global $wpdb;
		foreach ( array( $order, $product, $user ) as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return $this->missing(); } }
		if ( ! WooCommerceAdapter::is_available() ) { return $this->missing(); }
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$storage = get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		if ( ! in_array( $storage, array( 'yes','no' ), true ) || $hpos !== ( 'yes' === $storage ) ) { return $this->missing(); }
		$items_table = $wpdb->prefix . 'woocommerce_order_items'; $meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$orders_table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
		$details_table = $hpos ? $wpdb->prefix . 'wc_order_operational_data' : $wpdb->postmeta;
		foreach ( array_unique( array( $orders_table, $details_table, $items_table, $meta_table, $wpdb->posts ) ) as $table ) {
			$wpdb->last_error = ''; $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( $wpdb->last_error || 'innodb' !== strtolower( (string) $engine ) ) { return $this->missing(); }
		}
		$product_row = $this->rows( $wpdb->prepare( 'SELECT ID,post_type,post_status FROM %i WHERE ID=%d FOR UPDATE', $wpdb->posts, $product ) );
		if ( ! is_array( $product_row ) || 1 !== count( $product_row ) || 'product' !== $product_row[0]['post_type'] || ! in_array( $product_row[0]['post_status'], array( 'publish','private' ), true ) ) { return $this->missing(); }
		if ( $hpos ) {
			$rows = $this->rows( $wpdb->prepare( 'SELECT id,type,status,customer_id FROM %i WHERE id=%d FOR UPDATE', $orders_table, $order ) );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || 'shop_order' !== $rows[0]['type'] || 'wc-completed' !== $rows[0]['status'] || (string) $user !== $rows[0]['customer_id'] ) { return $this->missing(); }
			$dates = $this->rows( $wpdb->prepare( 'SELECT date_completed_gmt FROM %i WHERE order_id=%d LIMIT 2 FOR UPDATE', $details_table, $order ) );
			if ( ! is_array( $dates ) || 1 !== count( $dates ) ) { return $this->missing(); } $completed = $dates[0]['date_completed_gmt'];
			$refunds = $this->rows( $wpdb->prepare( 'SELECT id FROM %i WHERE parent_order_id=%d AND type=%s LIMIT 1 FOR UPDATE', $orders_table, $order, 'shop_order_refund' ) );
		} else {
			$rows = $this->rows( $wpdb->prepare( 'SELECT ID,post_type,post_status FROM %i WHERE ID=%d FOR UPDATE', $orders_table, $order ) );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || 'shop_order' !== $rows[0]['post_type'] || 'wc-completed' !== $rows[0]['post_status'] ) { return $this->missing(); }
			$meta = $this->rows( $wpdb->prepare( 'SELECT meta_key,meta_value FROM %i WHERE post_id=%d AND meta_key IN (%s,%s) LIMIT 5 FOR UPDATE', $details_table, $order, '_customer_user','_date_completed' ) );
			if ( ! is_array( $meta ) || 2 !== count( $meta ) || 2 !== count( array_unique( array_column( $meta,'meta_key' ) ) ) ) { return $this->missing(); }
			$meta = array_column( $meta,'meta_value','meta_key' );
			if ( (string) $user !== $meta['_customer_user'] || ! preg_match( '/^[1-9][0-9]{0,10}$/D', $meta['_date_completed'] ) ) { return $this->missing(); }
			$completed = gmdate( 'Y-m-d H:i:s', (int) $meta['_date_completed'] );
			$refunds = $this->rows( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_parent=%d AND post_type=%s LIMIT 1 FOR UPDATE', $orders_table, $order, 'shop_order_refund' ) );
		}
		$time = is_string( $completed ) ? strtotime( $completed . ' UTC' ) : false;
		if ( ! is_array( $refunds ) || $refunds || false === $time || $time > time() || $time <= 0 || gmdate( 'Y-m-d H:i:s', $time ) !== $completed ) { return $this->missing(); }
		$items = $this->rows( $wpdb->prepare( 'SELECT order_item_id FROM %i WHERE order_id=%d AND order_item_type=%s ORDER BY order_item_id LIMIT 51 FOR UPDATE', $items_table, $order, 'line_item' ) );
		if ( ! is_array( $items ) || ! $items || count( $items ) > WooCommerceBridge::MAX_MAPPED_ITEMS ) { return $this->missing(); }
		$ids = array_map( 'intval', array_column( $items,'order_item_id' ) );
		$meta = $this->rows( $wpdb->prepare( 'SELECT order_item_id,meta_key,meta_value FROM %i WHERE order_item_id IN (' . implode( ',', $ids ) . ') AND meta_key IN (%s,%s,%s) ORDER BY order_item_id,meta_id LIMIT 151 FOR UPDATE', $meta_table, '_product_id','_variation_id','_qty' ) );
		if ( ! is_array( $meta ) || count( $meta ) > 150 ) { return $this->missing(); }
		$by_item = array();
		foreach ( $meta as $value ) { $id = (int) $value['order_item_id']; $key = $value['meta_key']; if ( isset( $by_item[ $id ][ $key ] ) ) { return $this->missing(); } $by_item[ $id ][ $key ] = $value['meta_value']; }
		$matched = array();
		foreach ( $ids as $id ) {
			$item = $by_item[ $id ] ?? array(); if ( ( $item['_product_id'] ?? null ) !== (string) $product ) { continue; }
			$qty = $item['_qty'] ?? null; $variation = $item['_variation_id'] ?? '0';
			if ( ! is_string( $qty ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?$/D', $qty ) || (float) $qty <= 0 || ! preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $variation ) ) { return $this->missing(); }
			$matched[] = array( 'item_id' => $id,'variation_id' => $variation,'quantity' => false === strpos( $qty,'.' ) ? $qty : rtrim( rtrim( $qty,'0' ),'.' ) );
		}
		if ( ! $matched ) { return $this->missing(); }
		$proof = array( 'order_id' => $order,'product_id' => $product,'user_id' => $user,'completed_at' => $completed,'items' => $matched );
		return array( 'completed_at' => $completed,'sha256' => ( new CanonicalJson() )->hash( $proof ) );
	}
	private function rows( $sql ) { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( $sql, ARRAY_A ); return $wpdb->last_error || ! is_array( $rows ) ? null : $rows; }
	private function missing() { return new \WP_Error( 'certloom_pending_missing_current_evidence', __( 'This request needs the original Completed order, purchased product and connected customer. Orders with a refund, changed purchase details or missing completion need organization review.', 'certloom' ), array( 'reason_code' => 'missing_current_evidence' ) ); }
}
