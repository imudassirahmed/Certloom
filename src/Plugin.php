<?php
namespace Certloom;

use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Next-generation Certloom module bootstrap inside the existing plugin.
 *
 * During the in-place upgrade period the proven legacy public registry
 * remains active while the new additive domain schema is built alongside it.
 * This avoids destructive cutovers and gives the later migration milestone a
 * stable source and destination to validate independently.
 */
final class Plugin {
	/**
	 * Creates/validates additive next-generation Certloom domain storage.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate() {
		return SchemaManager::activate();
	}

	/**
	 * Boots only infrastructure that is safe before the new domain becomes the
	 * public source of truth. Existing 1.5 verification continues unchanged.
	 *
	 * @return void
	 */
	public static function boot() {
		$result = SchemaManager::maybe_upgrade();
		if ( is_wp_error( $result ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $result->get_error_message() );
		}
	}
}
