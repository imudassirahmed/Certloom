<?php
namespace Certloom\Infrastructure\Verification;

use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operational-readiness gate for normalized Credential Series verification.
 *
 * Public verification is a read path over already-issued immutable credential
 * truth. It must not be blocked by migration/cutover ceremony that is unrelated
 * to the credential being requested. The only runtime barriers retained here are
 * conditions that make that read genuinely unsafe or unavailable: active domain
 * maintenance, missing/outdated normalized storage, or failed schema health.
 *
 * OPTION is retained only as backward-compatible lineage for older diagnostics;
 * it no longer grants or revokes public read authority.
 */
final class PublicVerificationGate {
	const OPTION = 'certloom_series_public_verification_ready';

	/** @var callable */
	private $schema_verifier;

	public function __construct( ?callable $schema_verifier = null ) {
		$this->schema_verifier = $schema_verifier ?: array( SchemaManager::class, 'verify_health_now' );
	}

	/** @return bool */
	public function is_ready() {
		if ( ( new DomainMaintenanceLock() )->active() ) {
			return false;
		}
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) {
			return false;
		}
		if ( SchemaManager::health_marker_is_fresh() ) {
			return true;
		}
		$health = call_user_func( $this->schema_verifier, true );
		return true === $health;
	}

	/**
	 * Backward-compatible readiness probe used by older diagnostics. There is no
	 * separate public-verification switch anymore: if normalized storage is healthy,
	 * public verification is available. A successful probe refreshes the historical
	 * marker for diagnostics that still display it.
	 *
	 * @return true|\WP_Error
	 */
	public function enable() {
		if ( ( new DomainMaintenanceLock() )->active() ) {
			return new \WP_Error( 'certloom_series_verification_maintenance_active', __( 'Certloom cannot verify credentials while credential maintenance is active or its maintenance lock requires recovery.', 'certloom' ) );
		}
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) {
			return new \WP_Error( 'certloom_series_verification_schema_incomplete', __( 'Certloom cannot verify normalized credentials until the credential schema is current.', 'certloom' ) );
		}
		$health = call_user_func( $this->schema_verifier, true );
		if ( is_wp_error( $health ) ) {
			return $health;
		}
		if ( true !== $health ) {
			return new \WP_Error( 'certloom_series_verification_schema_unconfirmed', __( 'Certloom could not confirm the live credential schema health result.', 'certloom' ) );
		}
		update_option( self::OPTION, 1, false );
		return true;
	}

	/**
	 * Compatibility cleanup for the retired manual readiness marker. Removing the
	 * marker no longer disables healthy public verification.
	 *
	 * @return bool
	 */
	public function disable() {
		return delete_option( self::OPTION ) || false === get_option( self::OPTION, false );
	}
}
