<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\WooCommerceAdapter;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Provider-backed, bounded product search. Choices confer no mapping or issuance authority. */
final class WooCommerceProductChoices {
	public function search( $query ) {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) ) { return new \WP_Error( 'certloom_product_lookup_forbidden', __( 'You do not have permission to search integration products.', 'certloom' ), array( 'status' => 403 ) ); }
		if ( ! WooCommerceAdapter::is_available() || ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Data_Store' ) ) { return $this->unavailable(); }
		$query = sanitize_text_field( (string) $query );
		$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 120, 'UTF-8' ) : substr( $query, 0, 120 );
		try {
			if ( '' === trim( $query ) ) {
				$products = wc_get_products( array( 'status' => array( 'publish', 'private', 'draft' ), 'limit' => ScopedContextChoices::INITIAL_LIMIT, 'orderby' => 'name', 'order' => 'ASC', 'return' => 'objects', 'paginate' => false ) );
			} else {
				$store = \WC_Data_Store::load( 'product' );
				if ( ! is_object( $store ) || ! is_callable( array( $store, 'search_products' ) ) ) { return $this->unavailable(); }
				$ids = $store->search_products( $query, '', false, true, ScopedContextChoices::INITIAL_LIMIT );
				// Provider results include parent IDs, an absent-parent zero, and up to two numeric-ID additions.
				if ( ! is_array( $ids ) || count( $ids ) > 2 * ScopedContextChoices::INITIAL_LIMIT + 2 ) { return $this->unavailable(); }
				$ids = array_values( array_filter( $ids, static function ( $id ) { return 0 !== $id && '0' !== $id; } ) );
				foreach ( $ids as $id ) { if ( ! $this->positive_id( $id ) ) { return $this->unavailable(); } }
				// Woo may append a literal product ID after twenty text/SKU matches. Keep that exact choice reachable.
				if ( $this->positive_id( $query ) ) { $exact = wc_get_product( (int) $query ); if ( $exact instanceof \WC_Product && ! $exact->is_type( 'variation' ) && in_array( $exact->get_status(), array( 'publish', 'private', 'draft' ), true ) ) { array_unshift( $ids, (int) $query ); } }
				$products = array();
				foreach ( array_slice( array_unique( $ids ), 0, ScopedContextChoices::INITIAL_LIMIT ) as $id ) { $product = wc_get_product( absint( $id ) ); if ( is_object( $product ) ) { $products[] = $product; } }
			}
			if ( ! is_array( $products ) || count( $products ) > ScopedContextChoices::INITIAL_LIMIT ) { return $this->unavailable(); }
			$choices = array();
			foreach ( $products as $product ) {
				if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) || ! in_array( $product->get_status(), array( 'publish', 'private', 'draft' ), true ) ) { continue; }
				$id = $product->get_id(); if ( ! $this->positive_id( $id ) ) { return $this->unavailable(); } $id = (int) $id; if ( isset( $choices[ $id ] ) ) { continue; }
				$name = (string) $product->get_name(); $sku = (string) $product->get_sku();
				$label = ( '' === trim( $name ) ? __( 'Untitled product', 'certloom' ) : $name ) . ' #' . $id;
				if ( '' !== $sku ) { $label .= ' · ' . $sku; }
				if ( 'publish' !== $product->get_status() ) { $label .= ' (' . ( 'draft' === $product->get_status() ? __( 'Draft', 'certloom' ) : __( 'Private', 'certloom' ) ) . ')'; }
				$choices[ $id ] = array( 'id' => $id, 'label' => $label );
			}
			return array_values( $choices );
		} catch ( \Throwable $error ) { return $this->unavailable(); }
	}

	private function positive_id( $value ) {
		return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/^[1-9][0-9]{0,15}$/D', (string) $value ) && (string) (int) $value === (string) $value && (int) $value <= 9007199254740991;
	}

	private function unavailable() { return new \WP_Error( 'certloom_product_lookup_unavailable', __( 'WooCommerce products could not be searched. Try again shortly.', 'certloom' ), array( 'status' => 503 ) ); }
}
