<?php
namespace Certloom;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight PSR-4-style autoloader for Certloom-owned classes.
 *
 * Certloom intentionally does not require Composer at runtime. Third-party
 * libraries added later (PDF/QR/etc.) will be dependency-prefixed and loaded
 * behind module boundaries so another WordPress plugin cannot replace them.
 */
final class Autoloader {
	const PREFIX = 'Certloom\\';

	/** @var bool */
	private static $registered = false;

	/**
	 * Registers the Certloom namespace once.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		spl_autoload_register( array( __CLASS__, 'autoload' ), true, true );
	}

	/**
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		if ( false === $relative || '' === $relative ) {
			return;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file     = CERTLOOM_PATH . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
