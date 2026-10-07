<?php
namespace Certloom\Application\Restore;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;

/** Validates the live-data snapshot captured when an administrator confirms a restore. */
final class DomainRestoreConfirmationValidator {
	/** @var DomainGeneration */
	private $generation;
	/** @var DomainBackupRepository */
	private $backup_repository;

	public function __construct( ?DomainGeneration $generation = null, ?DomainBackupRepository $backup_repository = null ) {
		$this->generation        = $generation ?: new DomainGeneration();
		$this->backup_repository = $backup_repository ?: new DomainBackupRepository();
	}

	/** @return true|\WP_Error */
	public function validate( array $payload, $confirmation_key, $message ) {
		if ( true !== ( $payload[ $confirmation_key ] ?? false ) ) {
			return new \WP_Error( 'certloom_domain_restore_confirmation_required', $message );
		}
		$path = trim( (string) ( $payload['path'] ?? '' ) );
		$baseline_generation = (string) ( $payload['baseline_generation'] ?? '' );
		$baseline_config_hash = strtolower( (string) ( $payload['baseline_configuration_hash'] ?? '' ) );
		if ( '' === $path || '' === $baseline_generation || ! preg_match( '/^[a-f0-9]{64}$/', $baseline_config_hash ) ) {
			return new \WP_Error( 'certloom_domain_restore_confirmation_invalid', __( 'The Certloom restore confirmation snapshot is incomplete.', 'certloom' ) );
		}
		$generation = $this->generation->read_current();
		if ( is_wp_error( $generation ) ) { return $generation; }
		if ( ! hash_equals( $baseline_generation, $generation ) ) {
			return new \WP_Error( 'certloom_domain_restore_confirmation_stale', __( 'Certloom credential data changed after the restore was confirmed. Review and confirm the restore again.', 'certloom' ) );
		}
		$config = $this->backup_repository->configuration_snapshot();
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		if ( ! hash_equals( $baseline_config_hash, strtolower( (string) $config['hash'] ) ) ) {
			return new \WP_Error( 'certloom_domain_restore_confirmation_stale', __( 'Certloom configuration changed after the restore was confirmed. Review and confirm the restore again.', 'certloom' ) );
		}
		return true;
	}
}
