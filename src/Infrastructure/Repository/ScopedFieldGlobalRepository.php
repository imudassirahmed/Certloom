<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Fields\ScopedFieldContract;

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
			return ScopedFieldContract::empty_global( false );
		}
		$contract = new ScopedFieldContract();
		$valid = $contract->validate_stored_envelope( $stored, 'global' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$normalized = $contract->normalize_scope( 'global', $valid['fields'], $catalog );
		if ( ! is_wp_error( $normalized ) ) { $normalized['_meta']['configured'] = true; }
		return $normalized;
	}

	/** @return true|\WP_Error */
	public function save( $input, array $catalog ) {
		$normalized = ( new ScopedFieldContract() )->normalize_scope( 'global', $input, $catalog );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
			return new \WP_Error( 'certloom_global_field_contract_storage_unavailable', __( 'Certloom could not access the Global credential-field requirements store.', 'certloom' ) );
		}
		$saved = update_option( self::OPTION, $normalized, false );
		return $saved || $normalized === get_option( self::OPTION, null )
			? true
			: new \WP_Error( 'certloom_global_field_contract_save_failed', __( 'Certloom could not save the Global credential-field requirements.', 'certloom' ) );
	}
}
