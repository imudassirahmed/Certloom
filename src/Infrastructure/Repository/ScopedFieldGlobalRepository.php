<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persistent Global credential-field requirement scope. */
final class ScopedFieldGlobalRepository {
	const OPTION = 'certloom_global_scoped_fields_v1';

	/** @return array<string,mixed>|\WP_Error */
	public function get( array $catalog ) {
		if ( ! function_exists( 'get_option' ) ) {
			return ScopedFieldContract::empty_global( false );
		}
		$stored = get_option( self::OPTION, null );
		if ( null === $stored || false === $stored ) {
			return ( new \Certloom\Application\Recipient\CertificateNamePolicy() )->apply_global( ScopedFieldContract::empty_global( false ), $catalog );
		}
		$contract = new ScopedFieldContract();
		$valid = $contract->validate_stored_envelope( $stored, 'global' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$normalized = $contract->normalize_scope( 'global', $valid['fields'], $catalog );
		if ( ! is_wp_error( $normalized ) ) { $normalized['_meta']['configured'] = true; }
		return is_wp_error( $normalized ) ? $normalized : ( new \Certloom\Application\Recipient\CertificateNamePolicy() )->apply_global( $normalized, $catalog );
	}

	/** @return true|\WP_Error */
	public function save( $input, array $catalog ) {
		$normalized = ( new ScopedFieldContract() )->normalize_scope( 'global', $input, $catalog );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
			return new \WP_Error( 'certloom_global_field_contract_storage_unavailable', __( 'Certloom could not access the Global credential-field requirements store.', 'certloom' ) );
		}
		$changed = false;
		$result = ( new Transaction() )->run( static function () use ( $normalized, &$changed ) {
			// Fresh storage, rather than an option-cache hit, determines an actual edit.
			LegacyWriteGuard::invalidate_option( self::OPTION );
			$before = DatabaseOptions::read( array( self::OPTION ) );
			if ( is_wp_error( $before ) ) { return $before; }
			if ( $before[ self::OPTION ]['exists'] && $normalized === $before[ self::OPTION ]['value'] ) { return true; }
			$generation = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $generation ) ) { return $generation; }
			update_option( self::OPTION, $normalized, false );
			if ( ! LegacyWriteGuard::verify_option( self::OPTION, $normalized ) ) {
				return new \WP_Error( 'certloom_global_field_contract_save_failed', __( 'Certloom could not save the Global credential-field requirements.', 'certloom' ) );
			}
			$signal = ( new PendingConfigurationQueue() )->changed();
			if ( is_wp_error( $signal ) ) { return $signal; }
			$changed = true;
			return true;
		} );
		if ( ! is_wp_error( $result ) && $changed ) { do_action( 'certloom_pending_configuration_changed' ); }
		return $result;
	}
}
