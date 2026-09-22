<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * First-party WooCommerce completed-order adapter.
 *
 * Only stable order/product/user facts enter Certloom's provider-neutral event
 * context. Checkout names, billing email, addresses and arbitrary order meta are
 * intentionally excluded: credential ownership must come from the explicit
 * WordPress-user → Recipient link.
 */
final class WooCommerceAdapter implements IntegrationAdapterInterface {
	const KEY                = 'woocommerce';
	const SOURCE_OBJECT_TYPE = 'product';
	const EVENT_TYPE         = 'order_completed';

	public function key() { return self::KEY; }
	public function label() { return __( 'WooCommerce', 'certloom' ); }

	/** @return bool */
	public static function is_available() {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_order' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_event( array $provider_event ) {
		$order_id   = absint( $provider_event['order_id'] ?? 0 );
		$product_id = absint( $provider_event['product_id'] ?? 0 );
		$user_id    = absint( $provider_event['user_id'] ?? 0 );
		if ( $order_id < 1 ) { return new \WP_Error( 'certloom_woocommerce_order_invalid', __( 'WooCommerce did not provide a valid completed order.', 'certloom' ) ); }
		if ( $product_id < 1 ) { return new \WP_Error( 'certloom_woocommerce_product_invalid', __( 'WooCommerce did not provide a valid purchased product for this credential mapping.', 'certloom' ) ); }
		if ( $user_id < 1 ) { return new \WP_Error( 'certloom_woocommerce_user_invalid', __( 'This order is not linked to a WordPress account. Certloom will not infer credential ownership from checkout name or email.', 'certloom' ) ); }

		$completed_at = self::normalize_utc_time( $provider_event['completed_at'] ?? '' );
		if ( is_wp_error( $completed_at ) ) { return $completed_at; }

		return array(
			'adapter'               => self::KEY,
			'event_type'            => self::EVENT_TYPE,
			'source_event_id'       => 'order:' . $order_id . ':product:' . $product_id . ':user:' . $user_id,
			'wp_user_id'            => $user_id,
			'source_object'         => array( 'type' => self::SOURCE_OBJECT_TYPE, 'id' => (string) $product_id ),
			'certificate_threshold' => 100.0,
			'attempts'              => array(
				array(
					'attempt_id'             => 'completed-order:' . $order_id . ':product:' . $product_id,
					'attempted_at'           => $completed_at,
					'completed'              => true,
					'percentage'             => 100.0,
					'provider_pass_threshold' => null,
				),
			),
		);
	}

	/** @return string|\WP_Error */
	private static function normalize_utc_time( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			$timestamp = function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
			return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return new \WP_Error( 'certloom_woocommerce_completion_time_invalid', __( 'WooCommerce completion time could not be normalized safely.', 'certloom' ) );
		}
		$time = strtotime( $value );
		if ( false === $time || gmdate( 'Y-m-d\TH:i:s\Z', $time ) !== $value ) {
			return new \WP_Error( 'certloom_woocommerce_completion_time_invalid', __( 'WooCommerce completion time could not be normalized safely.', 'certloom' ) );
		}
		return $value;
	}
}
