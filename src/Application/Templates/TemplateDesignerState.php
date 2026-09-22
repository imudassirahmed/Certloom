<?php
namespace Certloom\Application\Templates;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Non-artifact authoring preferences for the visual Template Designer.
 *
 * Locks, editor visibility and layer labels are workspace organization state,
 * not certificate content. They stay outside certloom-layout so authoring-only
 * changes can never mutate an issued artifact hash or renderer input.
 */
final class TemplateDesignerState {
	const VERSION = 2;
	const MAX_LAYER_LABEL_LENGTH = 80;

	/** @return array{version:int,locked_ids:array<int,string>,hidden_ids:array<int,string>,layer_labels:array<string,string>,snap_enabled:bool} */
	public function defaults() {
		return array( 'version' => self::VERSION, 'locked_ids' => array(), 'hidden_ids' => array(), 'layer_labels' => array(), 'snap_enabled' => true );
	}

	/** @return array{version:int,locked_ids:array<int,string>,hidden_ids:array<int,string>,layer_labels:array<string,string>,snap_enabled:bool} */
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

		$hidden = array();
		foreach ( isset( $state['hidden_ids'] ) && is_array( $state['hidden_ids'] ) ? $state['hidden_ids'] : array() as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( isset( $known[ $id ] ) ) { $hidden[ $id ] = true; }
		}
		$out['hidden_ids'] = array_keys( $hidden );
		sort( $out['hidden_ids'], SORT_STRING );

		$labels = isset( $state['layer_labels'] ) && is_array( $state['layer_labels'] ) ? $state['layer_labels'] : array();
		foreach ( $labels as $id => $label ) {
			$id = strtolower( trim( (string) $id ) );
			if ( ! isset( $known[ $id ] ) || ! is_scalar( $label ) ) { continue; }
			$label = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $label ) );
			if ( '' === $label ) { continue; }
			$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, self::MAX_LAYER_LABEL_LENGTH, 'UTF-8' ) : substr( $label, 0, self::MAX_LAYER_LABEL_LENGTH );
			$out['layer_labels'][ $id ] = $label;
		}
		ksort( $out['layer_labels'], SORT_STRING );

		if ( array_key_exists( 'snap_enabled', $state ) ) { $out['snap_enabled'] = (bool) $state['snap_enabled']; }
		return $out;
	}

	/** @return array{version:int,locked_ids:array<int,string>,hidden_ids:array<int,string>,layer_labels:array<string,string>,snap_enabled:bool} */
	public function decode( $json, array $layout ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) { return $this->defaults(); }
		$decoded = json_decode( $json, true );
		return JSON_ERROR_NONE === json_last_error() ? $this->normalize( $decoded, $layout ) : $this->defaults();
	}
}
