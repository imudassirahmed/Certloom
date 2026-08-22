<?php
namespace Certloom;

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobDispatcher;
use Certloom\Infrastructure\Job\JobRuntime;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

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
	/** @var JobRuntime|null */
	private static $job_runtime;
	/**
	 * Creates/validates additive next-generation Certloom domain storage.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate() {
		$recovery = ( new DomainRestoreWorkspace() )->recover_interrupted_commit();
		if ( is_wp_error( $recovery ) ) {
			return $recovery;
		}
		$schema = SchemaManager::activate();
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		return JobDispatcher::activate();
	}

	/**
	 * Boots only infrastructure that is safe before the new domain becomes the
	 * public source of truth. Existing 1.5 verification continues unchanged.
	 *
	 * @return void
	 */
	public static function boot() {
		$recovery = ( new DomainRestoreWorkspace() )->recover_interrupted_commit();
		if ( is_wp_error( $recovery ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $recovery->get_error_message() );
		}

		$result = SchemaManager::maybe_upgrade();
		if ( is_wp_error( $result ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $result->get_error_message() );
		}
		if ( ! is_wp_error( $result ) ) {
			self::$job_runtime = new JobRuntime();
			self::$job_runtime->register();
		}
		if ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW && is_admin() ) {
			( new \Certloom\Infrastructure\Admin\DomainAdmin() )->register();
			if ( self::$job_runtime ) {
				( new \Certloom\Infrastructure\Admin\DomainDataToolsAdmin( self::$job_runtime ) )->register();
			}
		}
	}

	/** Clears WordPress-local domain job events for the current site. */
	public static function deactivate() {
		JobDispatcher::deactivate();
	}

	/** @return JobRuntime|null */
	public static function job_runtime() {
		return self::$job_runtime;
	}
}
