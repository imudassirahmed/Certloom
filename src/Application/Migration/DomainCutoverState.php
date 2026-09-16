<?php
namespace Certloom\Application\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Durable authority/receipt state for the legacy -> Series/Version cutover.
 *
 * The actual promotion is performed only by the infrastructure cutover
 * coordinator while it owns the shared maintenance boundary. Keeping state
 * storage separate prevents callers from accidentally performing the final
 * authority switch from an unlocked/stale legacy snapshot.
 */
final class DomainCutoverState {
	const OPTION         = 'certloom_domain_write_authority';
	const RECEIPT_OPTION = 'certloom_domain_cutover_receipt';
	const LEGACY         = 'legacy';
	const DOMAIN         = 'domain';
	const CORRUPT        = 'corrupt';

	/** @return string legacy|domain|corrupt */
	public function authority() {
		$value = get_option( self::OPTION, null );
		if ( null === $value || false === $value || '' === $value || self::LEGACY === $value ) {
			return self::LEGACY;
		}
		if ( self::DOMAIN === $value ) {
			return self::DOMAIN;
		}
		return self::CORRUPT;
	}

	/** @return bool */
	public function is_domain_authoritative() {
		return self::DOMAIN === $this->authority();
	}

	/** @return bool */
	public function is_corrupt() {
		return self::CORRUPT === $this->authority();
	}

	/**
	 * Whether any cutover-receipt option is present, including prepared or malformed state.
	 *
	 * A malformed/non-committed receipt is protected recovery evidence, not the
	 * same thing as no receipt. Callers that require a pristine pre-cutover
	 * baseline must therefore use this method rather than receipt() emptiness.
	 *
	 * @return bool
	 */
	public function has_any_receipt_state() {
		$missing = new \stdClass();
		$value   = get_option( self::RECEIPT_OPTION, $missing );
		return $missing !== $value && false !== $value && '' !== $value && array() !== $value;
	}

	/** @return array<string,mixed> */
	public function receipt() {
		$value = get_option( self::RECEIPT_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * True only for the pristine pre-cutover baseline.
	 *
	 * Missing/empty authority means legacy for backward compatibility, but any
	 * receipt state (prepared, committed, or malformed) is protected evidence
	 * and prevents callers from treating the site as a clean legacy baseline.
	 *
	 * @return bool
	 */
	public function is_clean_legacy_state() {
		return self::LEGACY === $this->authority() && ! $this->has_any_receipt_state();
	}

	/**
	 * A committed receipt proves the official locked coordinator completed the
	 * authority switch. Merely editing the authority option must never be enough
	 * to enable public Series verification.
	 *
	 * @return bool
	 */
	public function has_committed_receipt() {
		$receipt = $this->receipt();
		$prepared = absint( $receipt['prepared_at'] ?? 0 );
		$completed = absint( $receipt['completed_at'] ?? 0 );
		return 'committed' === (string) ( $receipt['state'] ?? '' )
			&& self::DOMAIN === (string) ( $receipt['authority'] ?? '' )
			&& array_key_exists( 'source_count', $receipt )
			&& array_key_exists( 'source_max_id', $receipt )
			&& isset( $receipt['source_generation'] )
			&& '' !== (string) $receipt['source_generation']
			&& isset( $receipt['schema_version'] )
			&& '' !== (string) $receipt['schema_version']
			&& $prepared > 0
			&& $completed >= $prepared;
	}

	/**
	 * Confirms the durable cutover receipt describes the exact completed migration
	 * boundary. This protects public verification from malformed/partially restored
	 * option state: authority + a syntactically committed receipt is not enough.
	 *
	 * @param LegacyMigrationState|null $migration Completed migration state reader.
	 * @return bool
	 */
	public function receipt_matches_completed_migration( ?LegacyMigrationState $migration = null ) {
		$migration = $migration ?: new LegacyMigrationState();
		if ( ! $this->has_committed_receipt() || ! $migration->is_complete() ) {
			return false;
		}
		$receipt = $this->receipt();
		$state = $migration->get();
		$receipt_generation = (string) ( $receipt['source_generation'] ?? '' );
		$state_generation = (string) ( $state['source_generation'] ?? '' );
		return absint( $receipt['source_count'] ?? -1 ) === absint( $state['source_count'] ?? -2 )
			&& absint( $receipt['source_max_id'] ?? -1 ) === absint( $state['source_max_id'] ?? -2 )
			&& '' !== $receipt_generation
			&& '' !== $state_generation
			&& hash_equals( $state_generation, $receipt_generation );
	}

	/**
	 * Returns authority to legacy and removes any committed cutover receipt.
	 * This is deliberately a controlled rollback helper used before public
	 * Series cutover is considered permanent.
	 *
	 * @return true|\WP_Error
	 */
	public function keep_legacy_authoritative() {
		update_option( self::OPTION, self::LEGACY, false );
		if ( self::LEGACY !== get_option( self::OPTION, null ) ) {
			return new \WP_Error( 'certloom_domain_cutover_rollback_save_failed', __( 'Certloom could not restore legacy credential write authority exactly.', 'certloom' ) );
		}
		delete_option( self::RECEIPT_OPTION );
		if ( false !== get_option( self::RECEIPT_OPTION, false ) ) {
			return new \WP_Error( 'certloom_domain_cutover_receipt_clear_failed', __( 'Certloom restored legacy write authority but could not clear the old domain cutover receipt.', 'certloom' ) );
		}
		return true;
	}
}
