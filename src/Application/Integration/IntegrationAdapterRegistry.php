<?php
namespace Certloom\Application\Integration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\IntegrationAdapterInterface;

/**
 * Contract-validating registry for integration adapters.
 *
 * Core adapters are supplied by Certloom. Third-party code may contribute an
 * adapter through the documented WordPress filter, but every contributed value
 * still has to implement IntegrationAdapterInterface and pass the same key/label
 * validation before it becomes available.
 */
final class IntegrationAdapterRegistry {
	/** @var array<string,IntegrationAdapterInterface> */ private $adapters = array();
	/** @var \WP_Error|null */ private $error;

	/** @param IntegrationAdapterInterface[] $adapters */
	public function __construct( array $adapters = array() ) {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'certloom_integration_adapters', $adapters );
			if ( ! is_array( $filtered ) ) {
				$this->error = new \WP_Error( 'certloom_integration_adapter_registry_invalid', __( 'Certloom refused an invalid integration adapter registry extension.', 'certloom' ) );
				return;
			}
			$adapters = $filtered;
		}
		foreach ( $adapters as $adapter ) {
			$result = $this->add_internal( $adapter );
			if ( is_wp_error( $result ) ) { $this->adapters = array(); $this->error = $result; break; }
		}
	}

	/** @return IntegrationAdapterInterface|\WP_Error */
	public function get( $key ) {
		if ( $this->error ) { return $this->error; }
		$key = strtolower( trim( (string) $key ) );
		if ( ! isset( $this->adapters[ $key ] ) ) {
			return new \WP_Error( 'certloom_integration_adapter_unavailable', __( 'That integration adapter is not installed or active in this Certloom release.', 'certloom' ) );
		}
		return $this->adapters[ $key ];
	}

	/** @return array<int,array{key:string,label:string}>|\WP_Error */
	public function describe() {
		if ( $this->error ) { return $this->error; }
		$out = array();
		foreach ( $this->adapters as $key => $adapter ) { $out[] = array( 'key' => $key, 'label' => (string) $adapter->label() ); }
		return $out;
	}

	/** @return true|\WP_Error */
	private function add_internal( $adapter ) {
		if ( ! $adapter instanceof IntegrationAdapterInterface ) {
			return new \WP_Error( 'certloom_integration_adapter_invalid', __( 'Certloom refused an invalid integration adapter.', 'certloom' ) );
		}
		$key = strtolower( trim( (string) $adapter->key() ) );
		if ( '' === $key || strlen( $key ) > 64 || ! preg_match( '/^[a-z][a-z0-9_\-]*$/', $key ) ) {
			return new \WP_Error( 'certloom_integration_adapter_key_invalid', __( 'Certloom refused an integration adapter with an invalid machine key.', 'certloom' ) );
		}
		if ( isset( $this->adapters[ $key ] ) ) {
			return new \WP_Error( 'certloom_integration_adapter_duplicate', __( 'Certloom refused duplicate integration adapter registration.', 'certloom' ) );
		}
		$label = trim( (string) $adapter->label() );
		if ( '' === $label || strlen( $label ) > 120 ) {
			return new \WP_Error( 'certloom_integration_adapter_label_invalid', __( 'Certloom refused an integration adapter with an invalid label.', 'certloom' ) );
		}
		$this->adapters[ $key ] = $adapter;
		ksort( $this->adapters, SORT_STRING );
		return true;
	}
}
