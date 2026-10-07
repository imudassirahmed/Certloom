<?php
namespace Certloom\Infrastructure\Rendering;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Load frozen prefixed classes/functions without sharing Composer identities. */
final class BundledRendererDependencies {
	private static $loaded = false;

	public static function load( $vendor ) {
		if ( self::$loaded ) { return; }
		$root = realpath( $vendor );
		if ( false === $root || is_link( $vendor ) ) { throw new \RuntimeException( 'Certloom renderer dependencies are unavailable.' ); }
		$classmap = require self::file( $root, $root . '/composer/autoload_classmap.php' );
		$files = require self::file( $root, $root . '/composer/autoload_files.php' );
		if ( ! is_array( $classmap ) || ! is_array( $files ) ) { throw new \RuntimeException( 'Certloom renderer dependency maps are invalid.' ); }
		$classes = array();
		foreach ( $classmap as $class => $path ) {
			// Composer's own global InstalledVersions entry belongs to its loader,
			// never this plugin. Every renderer/dependency class is prefixed.
			if ( 0 !== strpos( $class, 'CertloomVendor\\' ) ) { continue; }
			$classes[ $class ] = self::file( $root, $path );
		}
		if ( ! isset( $classes['CertloomVendor\\Mpdf\\Mpdf'] ) ) { throw new \RuntimeException( 'Certloom prefixed PDF dependencies are unavailable.' ); }
		$functions = array();
		foreach ( $files as $path ) { $functions[] = self::file( $root, $path ); }
		spl_autoload_register( static function ( $class ) use ( $classes ) {
			if ( isset( $classes[ $class ] ) ) { require_once $classes[ $class ]; }
		}, true, true );
		// Upstream Composer file IDs are unchanged by namespace scoping. Using
		// its shared global array could skip these prefixed functions when a
		// companion has already loaded the original unprefixed package.
		foreach ( $functions as $path ) { require_once $path; }
		self::$loaded = true;
	}

	private static function file( $root, $path ) {
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) || is_link( $path ) || 0 !== strpos( str_replace( '\\', '/', $real ), rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' ) ) {
			throw new \RuntimeException( 'Certloom renderer dependency path is invalid.' );
		}
		return $real;
	}
}
