<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Integration\WooCommerceAdapter;
use Certloom\Infrastructure\Integration\WooCommerceBridge;
use Certloom\Infrastructure\Integration\WooCommerceMappingStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin configuration boundary for WooCommerce product → credential mappings. */
final class WooCommerceIntegrationAdmin {
	/** @return void */
	public function register() {
		add_action( 'admin_post_certloom_woocommerce_mapping_save', array( $this, 'save_mapping' ) );
		add_action( 'admin_post_certloom_woocommerce_mapping_delete', array( $this, 'delete_mapping' ) );
	}

	/** @return array<string,mixed> */
	public function screen_data() {
		$status = WooCommerceBridge::status();
		$data = array(
			'status'              => $status,
			'mappings'            => ( new WooCommerceMappingStore() )->all(),
			'products'            => array(),
			'programs'            => array(),
			'configuration_ready' => false,
			'error'               => null,
		);
		if ( empty( $status['available'] ) ) { return $data; }
		$preserve = array_values( array_unique( array_filter( array_map( static function ( $mapping ) { return absint( is_array( $mapping ) ? ( $mapping['product_id'] ?? 0 ) : 0 ); }, (array) $data['mappings'] ) ) ) );
		$data['products'] = $this->products( $preserve );
		if ( is_wp_error( $data['products'] ) ) { $data['error'] = $data['products']; $data['products'] = array(); return $data; }
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) { return $data; }
		$programs  = ( new ScopedContextChoices() )->programs();
		if ( is_wp_error( $programs ) ) { $data['error'] = $programs; return $data; }
		$data['programs'] = (array) $programs;
		$data['configuration_ready'] = true;
		return $data;
	}

	/** @return void */
	public function save_mapping() {
		$this->require_access();
		check_admin_referer( 'certloom_woocommerce_mapping_save' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			$this->redirect( new \WP_Error( 'certloom_woocommerce_mapping_preview_closed', __( 'WooCommerce mapping setup is unavailable until the Certloom Program/Batch workflow is available.', 'certloom' ) ) );
		}
		$product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$program_id  = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! is_object( $product ) ) { $this->redirect( new \WP_Error( 'certloom_woocommerce_product_invalid', __( 'Choose a WooCommerce product that still exists.', 'certloom' ) ) ); }
		$target = ( new IntegrationMappingTargetGuard() )->validate( $program_id, $batch_id );
		if ( is_wp_error( $target ) ) { $this->redirect( $target ); }
		$result = ( new WooCommerceMappingStore() )->save( array(
			'product_id'  => $product_id,
			'program_id'  => $program_id,
			'batch_id'    => $batch_id,
			'template_id' => 0,
			'expiry_mode' => isset( $_POST['expiry_mode'] ) ? sanitize_key( wp_unslash( $_POST['expiry_mode'] ) ) : 'program',
			'expires_at'  => isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '',
			'active'      => ! empty( $_POST['active'] ),
		) );
		$this->redirect( $result, 'woocommerce_mapping_saved' );
	}

	/** @return void */
	public function delete_mapping() {
		$this->require_access();
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		check_admin_referer( 'certloom_woocommerce_mapping_delete_' . $product_id );
		$this->redirect( ( new WooCommerceMappingStore() )->delete( $product_id ), 'woocommerce_mapping_deleted' );
	}

	/** @return array<int,array{id:int,title:string}>|\WP_Error */
	private function products( array $preserve_ids = array() ) {
		if ( ! WooCommerceAdapter::is_available() || ! function_exists( 'wc_get_products' ) ) { return array(); }
		$products = wc_get_products( array( 'status' => array( 'publish', 'private', 'draft' ), 'limit' => ScopedContextChoices::INITIAL_LIMIT, 'orderby' => 'name', 'order' => 'ASC', 'return' => 'objects' ) );
		if ( ! is_array( $products ) ) { return new \WP_Error( 'certloom_woocommerce_products_unavailable', __( 'Certloom could not load WooCommerce products.', 'certloom' ) ); }
		$by_id = array();
		foreach ( $products as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$id = absint( $product->get_id() );
				if ( $id ) { $by_id[ $id ] = array( 'id' => $id, 'title' => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : ( 'Product #' . $id ) ); }
			}
		}
		foreach ( $preserve_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || isset( $by_id[ $product_id ] ) || ! function_exists( 'wc_get_product' ) ) { continue; }
			$product = wc_get_product( $product_id );
			if ( is_object( $product ) ) { $by_id[ $product_id ] = array( 'id' => $product_id, 'title' => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : ( 'Product #' . $product_id ) ); }
		}
		return array_values( $by_id );
	}

	private function require_access() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) ) { wp_die( esc_html__( 'You do not have permission to manage Certloom integrations.', 'certloom' ), '', array( 'response' => 403 ) ); }
	}

	private function redirect( $result, $notice = '' ) {
		$url = admin_url( 'admin.php?page=certloom-integrations' );
		if ( is_wp_error( $result ) ) { $url = add_query_arg( 'certloom_error', $result->get_error_message(), $url ); }
		elseif ( '' !== $notice ) { $url = add_query_arg( 'certloom_notice', sanitize_key( $notice ), $url ); }
		wp_safe_redirect( $url ); exit;
	}
}
