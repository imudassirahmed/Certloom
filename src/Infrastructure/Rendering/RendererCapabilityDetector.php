<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\RendererInterface;

/** Fail-soft renderer runtime capability check for System Status and adapters. */
final class RendererCapabilityDetector {
	/** @return array<string,mixed> */
	public function inspect( RendererInterface $renderer ) {
		$missing = array();
		try {
			$requirements = (array) $renderer->requirements();
			$renderer_id = (string) $renderer->id();
			$renderer_version = (string) $renderer->version();
			$formats = array_values( array_map( function ( $value ) { return strtolower( trim( (string) $value ) ); }, (array) $renderer->formats() ) );
		} catch ( \Throwable $error ) {
			return array(
				'available' => false,
				'renderer_id' => get_class( $renderer ),
				'renderer_version' => '',
				'formats' => array(),
				'missing' => array( 'runtime:requirements' ),
			);
		}
		if ( isset( $requirements['php_min'] ) && version_compare( PHP_VERSION, (string) $requirements['php_min'], '<' ) ) {
			$missing[] = 'PHP>=' . (string) $requirements['php_min'];
		}
		foreach ( isset( $requirements['extensions'] ) ? (array) $requirements['extensions'] : array() as $extension ) {
			if ( ! extension_loaded( (string) $extension ) ) { $missing[] = 'ext:' . (string) $extension; }
		}
		foreach ( isset( $requirements['functions'] ) ? (array) $requirements['functions'] : array() as $function ) {
			if ( ! function_exists( (string) $function ) ) { $missing[] = 'function:' . (string) $function; }
		}
		foreach ( isset( $requirements['classes'] ) ? (array) $requirements['classes'] : array() as $class ) {
			try {
				if ( ! class_exists( (string) $class ) ) { $missing[] = 'class:' . (string) $class; }
			} catch ( \Throwable $error ) {
				$missing[] = 'class:' . (string) $class;
			}
		}
		return array(
			'available' => empty( $missing ),
			'renderer_id' => $renderer_id,
			'renderer_version' => $renderer_version,
			'formats' => $formats,
			'missing' => $missing,
		);
	}
}
