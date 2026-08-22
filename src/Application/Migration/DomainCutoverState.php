<?php
namespace Certloom\Application\Migration;

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

	/** @return string */
	public function authority() {
		$value = (string) get_option( self::OPTION, self::LEGACY );
		return self::DOMAIN === $value ? self::DOMAIN : self::LEGACY;
	}

	/** @return bool */
	public function is_domain_authoritative() {
		return self::DOMAIN === $this->authority();
	}

	/** @return array<string,mixed> */
	public function receipt() {
		$value = get_option( self::RECEIPT_OPTION, array() );
		return is_array( $value ) ? $value : array();
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
		return 'committed' === (string) ( $receipt['state'] ?? '' )
			&& self::DOMAIN === (string) ( $receipt['authority'] ?? '' )
			&& ! empty( $receipt['completed_at'] )
			&& isset( $receipt['source_generation'] )
			&& '' !== (string) $receipt['source_generation']
			&& isset( $receipt['schema_version'] )
			&& '' !== (string) $receipt['schema_version'];
	}

	/**
	 * Returns authority to legacy and removes any committed cutover receipt.
	 * This is deliberately a controlled rollback helper used before public
	 * Series cutover is considered permanent.
	 *
	 * @return true|\WP_Error
	 */
	public function keep_legacy_authoritative() {
		$saved = update_option( self::OPTION, self::LEGACY, false );
		if ( ! $saved && self::LEGACY !== (string) get_option( self::OPTION, self::LEGACY ) ) {
			return new \WP_Error( 'certloom_domain_cutover_rollback_save_failed', __( 'Certloom could not restore legacy credential write authority.', 'certloom' ) );
		}
		$deleted = delete_option( self::RECEIPT_OPTION );
		if ( ! $deleted && false !== get_option( self::RECEIPT_OPTION, false ) ) {
			return new \WP_Error( 'certloom_domain_cutover_receipt_clear_failed', __( 'Certloom restored legacy write authority but could not clear the old domain cutover receipt.', 'certloom' ) );
		}
		return true;
	}
}
