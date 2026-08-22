<?php
namespace Certloom\Application\Migration;

/**
 * Identifies which storage model is allowed to receive authoritative credential writes.
 *
 * Until the new registry/admin milestone is ready, the proven 1.5 registry remains
 * authoritative even if the additive Series/Version migration code has been tested.
 */
final class DomainCutoverState {
	const OPTION = 'certloom_domain_write_authority';
	const LEGACY = 'legacy';
	const DOMAIN = 'domain';

	/** @return string */
	public function authority() {
		$value = (string) get_option( self::OPTION, self::LEGACY );
		return self::DOMAIN === $value ? self::DOMAIN : self::LEGACY;
	}

	/** @return bool */
	public function is_domain_authoritative() {
		return self::DOMAIN === $this->authority();
	}

	/**
	 * Step 3 calls this only after new registry/admin mutation paths are ready and
	 * the final migration parity gate has completed. There is deliberately no
	 * automatic call from activation or 1.5.2 migration code.
	 *
	 * @return true|\WP_Error
	 */
	public function promote_to_domain( array $current_source_snapshot = array() ) {
		if ( ! defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) || true !== CERTLOOM_DOMAIN_WRITE_PATHS_READY ) {
			return new \WP_Error( 'certloom_domain_cutover_write_paths_not_ready', __( 'Certloom cannot switch write authority until the new registry/admin write paths are ready.', 'certloom' ) );
		}
		if ( ! defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) || true !== CERTLOOM_DOMAIN_DATA_TOOLS_READY ) {
			return new \WP_Error( 'certloom_domain_cutover_data_tools_not_ready', __( 'Certloom cannot switch write authority until import, backup, restore, and other registry data tools write the new credential domain safely.', 'certloom' ) );
		}
		$migration = new LegacyMigrationState();
		if ( ! $migration->is_complete() ) {
			return new \WP_Error( 'certloom_domain_cutover_migration_incomplete', __( 'Certloom cannot make the new credential domain authoritative before legacy migration is complete.', 'certloom' ) );
		}
		if ( empty( $current_source_snapshot ) || ! $migration->source_snapshot_matches( $current_source_snapshot ) ) {
			return new \WP_Error( 'certloom_domain_cutover_source_changed', __( 'The legacy registry changed after migration. Run a fresh migration preflight and commit before switching write authority.', 'certloom' ) );
		}
		$saved = update_option( self::OPTION, self::DOMAIN, false );
		return $saved || self::DOMAIN === (string) get_option( self::OPTION, self::LEGACY )
			? true
			: new \WP_Error( 'certloom_domain_cutover_save_failed', __( 'Certloom could not save the credential write-authority cutover.', 'certloom' ) );
	}

	/** @return bool */
	public function keep_legacy_authoritative() {
		$saved = update_option( self::OPTION, self::LEGACY, false );
		return $saved || self::LEGACY === (string) get_option( self::OPTION, self::LEGACY );
	}
}
