<?php
namespace Certloom\Application\Import;

use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;

/** Rejects a multi-request import review when live domain/configuration state changed underneath it. */
final class DomainImportConsistencyGuard {
	/** @var DomainGeneration */
	private $generation;
	/** @var DomainBackupRepository */
	private $backup;

	public function __construct( DomainGeneration $generation = null, DomainBackupRepository $backup = null ) {
		$this->generation = $generation ?: new DomainGeneration();
		$this->backup     = $backup ?: new DomainBackupRepository();
	}

	/** @return array{generation:string,configuration_hash:string}|\WP_Error */
	public function snapshot() {
		$config = $this->backup->configuration_snapshot();
		if ( is_wp_error( $config ) ) { return $config; }
		return array(
			'generation'         => $this->generation->current(),
			'configuration_hash' => strtolower( (string) $config['hash'] ),
		);
	}

	/** @return true|\WP_Error */
	public function validate( $baseline_generation, $baseline_configuration_hash ) {
		$baseline_generation = (string) $baseline_generation;
		$baseline_configuration_hash = strtolower( trim( (string) $baseline_configuration_hash ) );
		if ( '' === $baseline_generation || ! preg_match( '/^[a-f0-9]{64}$/', $baseline_configuration_hash ) ) {
			return new \WP_Error( 'certloom_import_baseline_invalid', __( 'The import review is missing its consistency baseline.', 'certloom' ) );
		}
		if ( ! hash_equals( $baseline_generation, $this->generation->current() ) ) {
			return new \WP_Error( 'certloom_import_domain_changed', __( 'Credential data changed while this import was being reviewed. Restart the import so duplicate/status decisions are based on current data.', 'certloom' ) );
		}
		$config = $this->backup->configuration_snapshot();
		if ( is_wp_error( $config ) ) { return $config; }
		if ( ! hash_equals( $baseline_configuration_hash, strtolower( (string) $config['hash'] ) ) ) {
			return new \WP_Error( 'certloom_import_configuration_changed', __( 'Certloom fields or settings changed while this import was being reviewed. Restart the import before committing it.', 'certloom' ) );
		}
		return true;
	}
}
