<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Contract\IntegrationAdapterInterface;

/**
 * Closed registry for Certloom-owned adapters.
 *
 * There is intentionally no WordPress filter/action registration surface here.
 * Concrete adapter releases are added by Certloom code and tested against the
 * same normalized contract before they become available to administrators.
 */
final class IntegrationAdapterRegistry {
	/** @var array<string,IntegrationAdapterInterface> */ private $adapters = array();
	/** @var \WP_Error|null */ private $error;

	/** @param IntegrationAdapterInterface[] $adapters */
	public function __construct( array $adapters = array() ) {
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
