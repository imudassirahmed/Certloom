<?php
namespace Certloom\Application\Templates;

/**
 * Non-artifact authoring preferences for the visual Template Designer.
 *
 * Locks and snapping are editor workspace state, not certificate content. They
 * are therefore kept outside certloom-layout so toggling an authoring lock can
 * never change an issued artifact hash or renderer input.
 */
final class TemplateDesignerState {
	const VERSION = 1;

	/** @return array{version:int,locked_ids:array<int,string>,snap_enabled:bool} */
	public function defaults() {
		return array( 'version' => self::VERSION, 'locked_ids' => array(), 'snap_enabled' => true );
	}

	/** @return array{version:int,locked_ids:array<int,string>,snap_enabled:bool} */
	public function normalize( $state, array $layout ) {
		$out = $this->defaults();
		if ( ! is_array( $state ) ) { return $out; }

		$known = array();
		foreach ( isset( $layout['elements'] ) && is_array( $layout['elements'] ) ? $layout['elements'] : array() as $element ) {
			$id = isset( $element['id'] ) ? strtolower( trim( (string) $element['id'] ) ) : '';
			if ( '' !== $id ) { $known[ $id ] = true; }
		}

		$locked = array();
		foreach ( isset( $state['locked_ids'] ) && is_array( $state['locked_ids'] ) ? $state['locked_ids'] : array() as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( isset( $known[ $id ] ) ) { $locked[ $id ] = true; }
		}
		$out['locked_ids'] = array_keys( $locked );
		sort( $out['locked_ids'], SORT_STRING );
		if ( array_key_exists( 'snap_enabled', $state ) ) { $out['snap_enabled'] = (bool) $state['snap_enabled']; }
		return $out;
	}

	/** @return array{version:int,locked_ids:array<int,string>,snap_enabled:bool} */
	public function decode( $json, array $layout ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) { return $this->defaults(); }
		$decoded = json_decode( $json, true );
		return JSON_ERROR_NONE === json_last_error() ? $this->normalize( $decoded, $layout ) : $this->defaults();
	}
}
