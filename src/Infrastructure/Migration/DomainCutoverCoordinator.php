<?php
namespace Certloom\Infrastructure\Migration;

use Certloom\Application\Contract\LegacySourceSnapshotInterface;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Race-proof final authority switch from the legacy registry to Credential Series.
 *
 * The coordinator owns the shared maintenance lease before taking the final
 * legacy snapshot. Legacy writes use the same boundary, so no certificate edit,
 * import, settings mutation or restore can slip between the parity check and the
 * authority option update.
 */
final class DomainCutoverCoordinator {
	const OWNER_OPTION = 'certloom_domain_cutover_owner';
	const LOCK_TTL     = 300;

	/** @var LegacySourceSnapshotInterface */
	private $source;
	/** @var DomainMaintenanceLock */
	private $maintenance;
	/** @var LegacyMigrationState */
	private $migration;
	/** @var DomainCutoverState */
	private $state;
	/** @var callable */
	private $schema_verifier;
	/** @var callable */
	private $readiness_verifier;

	public function __construct(
		?LegacySourceSnapshotInterface $source = null,
		?DomainMaintenanceLock $maintenance = null,
		?LegacyMigrationState $migration = null,
		?DomainCutoverState $state = null,
		?callable $schema_verifier = null,
		?callable $readiness_verifier = null
	) {
		$this->source          = $source ?: new LegacyCertificateRepository();
		$this->maintenance     = $maintenance ?: new DomainMaintenanceLock();
		$this->migration       = $migration ?: new LegacyMigrationState();
		$this->state           = $state ?: new DomainCutoverState();
		$this->schema_verifier    = $schema_verifier ?: array( SchemaManager::class, 'verify_health_now' );
		$this->readiness_verifier = $readiness_verifier ?: array( $this, 'assert_feature_flags' );
	}

	/** @return true|\WP_Error */
	public function promote() {
		if ( $this->state->is_corrupt() ) {
			return new \WP_Error( 'certloom_domain_cutover_authority_corrupt', __( 'Certloom found invalid credential-authority state. Cutover is blocked until the protected authority option is repaired from known-good recovery evidence.', 'certloom' ) );
		}
		$flags = call_user_func( $this->readiness_verifier );
		if ( is_wp_error( $flags ) ) {
			return $flags;
		}
		if ( true !== $flags ) {
			return new \WP_Error( 'certloom_domain_cutover_readiness_invalid', __( 'Certloom could not confirm the credential-domain cutover readiness policy exactly.', 'certloom' ) );
		}

		$owner = $this->owner_token();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}

		$locked = $this->maintenance->acquire( $owner, 'domain_cutover', self::LOCK_TTL );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$result   = $this->promote_while_locked();
		$released = $this->maintenance->release( $owner );
		if ( is_wp_error( $released ) ) {
			return new \WP_Error(
				'certloom_domain_cutover_cleanup_failed',
				sprintf(
					/* translators: 1: cutover result, 2: maintenance cleanup error. */
					__( 'Certloom cutover could not finish its maintenance cleanup. Cutover result: %1$s Cleanup error: %2$s Credential writes remain paused until this operation is retried or the lease expires.', 'certloom' ),
					is_wp_error( $result ) ? $result->get_error_message() : __( 'authority switch completed', 'certloom' ),
					$released->get_error_message()
				)
			);
		}

		$owner_cleared = $this->clear_owner_token();
		if ( is_wp_error( $owner_cleared ) ) {
			return $owner_cleared;
		}
		return $result;
	}

	/** @return true|\WP_Error */
	private function promote_while_locked() {
		$health = call_user_func( $this->schema_verifier, true );
		if ( is_wp_error( $health ) ) {
			return $health;
		}
		if ( true !== $health ) {
			return new \WP_Error( 'certloom_domain_cutover_schema_verification_invalid', __( 'Certloom could not confirm the live credential-domain schema health result, so cutover was not started.', 'certloom' ) );
		}

		if ( ! $this->migration->is_complete() ) {
			return new \WP_Error( 'certloom_domain_cutover_migration_incomplete', __( 'Certloom cannot make the new credential domain authoritative before legacy migration is complete.', 'certloom' ) );
		}

		$snapshot = $this->source->snapshot();
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( empty( $snapshot ) || ! $this->migration->source_snapshot_matches( $snapshot ) ) {
			return new \WP_Error( 'certloom_domain_cutover_source_changed', __( 'The legacy registry changed after migration. Run a fresh migration preflight and commit before switching write authority.', 'certloom' ) );
		}

		$receipt = array(
			'state'             => 'prepared',
			'authority'         => DomainCutoverState::DOMAIN,
			'schema_version'    => SchemaManager::SCHEMA_VERSION,
			'source_count'      => absint( $snapshot['count'] ?? 0 ),
			'source_max_id'     => absint( $snapshot['max_id'] ?? 0 ),
			'source_generation' => (string) ( $snapshot['generation'] ?? '' ),
			'prepared_at'       => time(),
		);
		if ( '' === $receipt['source_generation'] ) {
			return new \WP_Error( 'certloom_domain_cutover_snapshot_invalid', __( 'Certloom refused cutover because the final legacy source generation is missing.', 'certloom' ) );
		}
		update_option( DomainCutoverState::RECEIPT_OPTION, $receipt, false );
		if ( $receipt !== get_option( DomainCutoverState::RECEIPT_OPTION, null ) ) {
			delete_option( DomainCutoverState::RECEIPT_OPTION );
			if ( false !== get_option( DomainCutoverState::RECEIPT_OPTION, false ) ) {
				return new \WP_Error( 'certloom_domain_cutover_receipt_prepare_cleanup_failed', __( 'Certloom could not save the prepared cutover receipt exactly and could not remove the invalid prepared state.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_domain_cutover_receipt_prepare_failed', __( 'Certloom could not save the prepared cutover receipt exactly, so write authority was not changed.', 'certloom' ) );
		}

		update_option( DomainCutoverState::OPTION, DomainCutoverState::DOMAIN, false );
		if ( DomainCutoverState::DOMAIN !== get_option( DomainCutoverState::OPTION, null ) ) {
			$rolled_back = $this->state->keep_legacy_authoritative();
			if ( is_wp_error( $rolled_back ) ) {
				return new \WP_Error( 'certloom_domain_cutover_save_cleanup_failed', __( 'Certloom could not persist domain write authority exactly and could not confirm rollback to legacy authority. Keep public Series verification disabled and recover from known-good site state before continuing.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_domain_cutover_save_failed', __( 'Certloom could not save the credential write-authority cutover exactly. Legacy write authority was restored safely.', 'certloom' ) );
		}

		$receipt['state']        = 'committed';
		$receipt['completed_at'] = time();
		update_option( DomainCutoverState::RECEIPT_OPTION, $receipt, false );
		if ( $receipt !== get_option( DomainCutoverState::RECEIPT_OPTION, null ) ) {
			$rolled_back = $this->state->keep_legacy_authoritative();
			if ( is_wp_error( $rolled_back ) ) {
				return new \WP_Error( 'certloom_domain_cutover_receipt_commit_rollback_failed', __( 'Certloom changed write authority but could not commit its cutover receipt exactly or confirm rollback. Keep public Series verification disabled and restore from the site database backup before continuing.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_domain_cutover_receipt_commit_failed', __( 'Certloom could not commit the cutover receipt exactly. Legacy write authority was restored safely.', 'certloom' ) );
		}

		return true;
	}

	/** @return true|\WP_Error */
	private function assert_feature_flags() {
		if ( ! DomainFeatureGate::write_paths_ready() ) {
			return new \WP_Error( 'certloom_domain_cutover_write_paths_not_ready', __( 'Certloom cannot switch write authority until this exact release has accepted normalized-domain write authority.', 'certloom' ) );
		}
		if ( ! DomainFeatureGate::data_tools_ready() ) {
			return new \WP_Error( 'certloom_domain_cutover_data_tools_not_ready', __( 'Certloom cannot switch write authority until this exact release has accepted normalized-domain import, backup and restore authority.', 'certloom' ) );
		}
		return true;
	}

	/** @return string|\WP_Error */
	private function owner_token() {
		$stored = strtolower( trim( (string) get_option( self::OWNER_OPTION, '' ) ) );
		if ( preg_match( '/^[a-f0-9]{32}$/', $stored ) ) {
			return $stored;
		}
		if ( '' !== $stored ) {
			if ( $this->maintenance->active() ) {
				return new \WP_Error( 'certloom_domain_cutover_owner_corrupt', __( 'Certloom found an invalid cutover recovery owner while maintenance is active. Wait for maintenance recovery before retrying cutover.', 'certloom' ) );
			}
			$cleared = $this->clear_owner_token();
			if ( is_wp_error( $cleared ) ) {
				return $cleared;
			}
		}
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_domain_cutover_owner_random_failed', __( 'Certloom could not generate a safe cutover recovery owner.', 'certloom' ) );
		}
		$saved = update_option( self::OWNER_OPTION, $token, false );
		return $saved || hash_equals( $token, (string) get_option( self::OWNER_OPTION, '' ) )
			? $token
			: new \WP_Error( 'certloom_domain_cutover_owner_save_failed', __( 'Certloom could not save its cutover recovery owner, so cutover was not started.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private function clear_owner_token() {
		$deleted = delete_option( self::OWNER_OPTION );
		return $deleted || false === get_option( self::OWNER_OPTION, false )
			? true
			: new \WP_Error( 'certloom_domain_cutover_owner_clear_failed', __( 'Certloom finished the maintenance boundary but could not clear its cutover recovery owner. Retry the cutover cleanup before enabling public Series verification.', 'certloom' ) );
	}
}
