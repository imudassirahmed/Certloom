<?php
namespace Certloom\Infrastructure\Verification;

use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard gate preventing accidental public cutover before migration/schema proof. */
final class PublicVerificationGate {
	const OPTION = 'certloom_series_public_verification_ready';

	/** @return bool */
	public function is_ready() {
		if ( ! get_option( self::OPTION, false ) ) {
			return false;
		}
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) {
			return false;
		}
		$health = get_option( SchemaManager::HEALTH_OPTION, array() );
		if ( ! is_array( $health ) || SchemaManager::SCHEMA_VERSION !== (string) ( $health['version'] ?? '' ) || empty( $health['checked_at'] ) ) {
			return false;
		}
		return ( new LegacyMigrationState() )->is_complete() && ( new DomainCutoverState() )->is_domain_authoritative();
	}

	/**
	 * Enabling cutover is intentionally explicit and refuses incomplete migration.
	 *
	 * @return true|\WP_Error
	 */
	public function enable() {
		if ( ! ( new LegacyMigrationState() )->is_complete() ) {
			return new \WP_Error( 'certloom_series_verification_migration_incomplete', __( 'Certloom cannot enable Series verification before legacy migration is complete.', 'certloom' ) );
		}
		if ( ! ( new DomainCutoverState() )->is_domain_authoritative() ) {
			return new \WP_Error( 'certloom_series_verification_write_authority_legacy', __( 'Certloom cannot enable Series verification while the legacy registry is still the authoritative write model.', 'certloom' ) );
		}
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) {
			return new \WP_Error( 'certloom_series_verification_schema_incomplete', __( 'Certloom cannot enable Series verification before the domain schema is current.', 'certloom' ) );
		}
		$health = get_option( SchemaManager::HEALTH_OPTION, array() );
		if ( ! is_array( $health ) || SchemaManager::SCHEMA_VERSION !== (string) ( $health['version'] ?? '' ) ) {
			return new \WP_Error( 'certloom_series_verification_schema_unverified', __( 'Certloom cannot enable Series verification before the domain schema health check passes.', 'certloom' ) );
		}
		$saved = update_option( self::OPTION, 1, false );
		return $saved || 1 === (int) get_option( self::OPTION, 0 )
			? true
			: new \WP_Error( 'certloom_series_verification_gate_save_failed', __( 'Certloom could not save the public verification cutover marker.', 'certloom' ) );
	}

	/** @return bool */
	public function disable() {
		return delete_option( self::OPTION ) || false === get_option( self::OPTION, false );
	}
}
