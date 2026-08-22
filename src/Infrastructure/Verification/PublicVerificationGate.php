<?php
namespace Certloom\Infrastructure\Verification;

use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard gate preventing accidental public cutover before migration/schema proof. */
final class PublicVerificationGate {
	const OPTION = 'certloom_series_public_verification_ready';

	/** @var callable */
	private $schema_verifier;

	public function __construct( callable $schema_verifier = null ) {
		$this->schema_verifier = $schema_verifier ?: array( SchemaManager::class, 'verify_health_now' );
	}

	/** @return bool */
	public function is_ready() {
		if ( ! get_option( self::OPTION, false ) ) {
			return false;
		}
		/* A restore/import/cutover maintenance boundary makes public domain reads unavailable. */
		if ( ( new DomainMaintenanceLock() )->active() ) {
			return false;
		}
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) {
			return false;
		}
		$health = get_option( SchemaManager::HEALTH_OPTION, array() );
		if ( ! is_array( $health ) || SchemaManager::SCHEMA_VERSION !== (string) ( $health['version'] ?? '' ) || empty( $health['checked_at'] ) ) {
			return false;
		}
		$migration = new LegacyMigrationState();
		$cutover = new DomainCutoverState();
		return $migration->is_complete()
			&& $cutover->is_domain_authoritative()
			&& $cutover->receipt_matches_completed_migration( $migration );
	}

	/**
	 * Enabling cutover is intentionally explicit and refuses incomplete migration.
	 * A live schema/engine check is performed here; a cached health marker alone
	 * is not strong enough for a one-way public authority switch.
	 *
	 * @return true|\WP_Error
	 */
	public function enable() {
		if ( ( new DomainMaintenanceLock() )->active() ) {
			return new \WP_Error( 'certloom_series_verification_maintenance_active', __( 'Certloom cannot enable Series verification while credential maintenance is active or its maintenance lock requires recovery.', 'certloom' ) );
		}
		$migration = new LegacyMigrationState();
		if ( ! $migration->is_complete() ) {
			return new \WP_Error( 'certloom_series_verification_migration_incomplete', __( 'Certloom cannot enable Series verification before legacy migration is complete.', 'certloom' ) );
		}
		$cutover = new DomainCutoverState();
		if ( $cutover->is_corrupt() ) {
			return new \WP_Error( 'certloom_series_verification_authority_corrupt', __( 'Certloom cannot enable Series verification while credential-authority state is invalid.', 'certloom' ) );
		}
		if ( ! $cutover->is_domain_authoritative() ) {
			return new \WP_Error( 'certloom_series_verification_write_authority_legacy', __( 'Certloom cannot enable Series verification while the legacy registry is still the authoritative write model.', 'certloom' ) );
		}
		if ( ! $cutover->receipt_matches_completed_migration( $migration ) ) {
			return new \WP_Error( 'certloom_series_verification_cutover_receipt_missing', __( 'Certloom cannot enable Series verification until the locked domain-cutover coordinator has committed a receipt matching the completed migration boundary.', 'certloom' ) );
		}
		if ( SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION, '0' ) ) {
			return new \WP_Error( 'certloom_series_verification_schema_incomplete', __( 'Certloom cannot enable Series verification before the domain schema is current.', 'certloom' ) );
		}
		$health = call_user_func( $this->schema_verifier, true );
		if ( is_wp_error( $health ) ) {
			return $health;
		}
		if ( true !== $health ) {
			return new \WP_Error( 'certloom_series_verification_schema_unconfirmed', __( 'Certloom could not confirm the live domain schema health result, so Series verification remains disabled.', 'certloom' ) );
		}
		update_option( self::OPTION, 1, false );
		return 1 === (int) get_option( self::OPTION, 0 )
			? true
			: new \WP_Error( 'certloom_series_verification_gate_save_failed', __( 'Certloom could not save the public verification cutover marker exactly.', 'certloom' ) );
	}

	/** @return bool */
	public function disable() {
		return delete_option( self::OPTION ) || false === get_option( self::OPTION, false );
	}
}
