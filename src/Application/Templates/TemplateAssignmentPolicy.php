<?php
namespace Certloom\Application\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical Template-assignment hierarchy shared by administration and future issuance.
 *
 * More-specific scopes always win. Priority is only meaningful inside one exact scope;
 * the schema currently enforces one durable assignment row per scope identity.
 */
final class TemplateAssignmentPolicy {
	const SCOPE_GLOBAL  = 'global';
	const SCOPE_ISSUER  = 'issuer';
	const SCOPE_PROGRAM = 'program';
	const SCOPE_BATCH   = 'batch';

	/** @return array<int,string> */
	public static function scopes() {
		return array( self::SCOPE_GLOBAL, self::SCOPE_ISSUER, self::SCOPE_PROGRAM, self::SCOPE_BATCH );
	}

	/**
	 * Returns the effective lookup chain from most-specific to least-specific.
	 * Empty identifiers are omitted; Global is always the final fallback.
	 *
	 * @param array<string,mixed> $context
	 * @return array<int,array{scope_type:string,scope_id:int,depth:int}>
	 */
	public function candidates( array $context ) {
		$out = array();
		$depth = 0;
		foreach ( array( self::SCOPE_BATCH, self::SCOPE_PROGRAM, self::SCOPE_ISSUER ) as $scope ) {
			$id = absint( $context[ $scope . '_id' ] ?? 0 );
			if ( $id ) {
				$out[] = array( 'scope_type' => $scope, 'scope_id' => $id, 'depth' => $depth++ );
			}
		}
		$out[] = array( 'scope_type' => self::SCOPE_GLOBAL, 'scope_id' => 0, 'depth' => $depth );
		return $out;
	}

	/** @return array{scope_type:string,scope_id:int}|\WP_Error */
	public function normalize_scope( $scope_type, $scope_id = 0 ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = absint( $scope_id );
		if ( ! in_array( $scope_type, self::scopes(), true ) ) {
			return new \WP_Error( 'certloom_template_assignment_scope_invalid', __( 'Choose a valid template assignment scope.', 'certloom' ) );
		}
		if ( self::SCOPE_GLOBAL === $scope_type ) {
			if ( 0 !== $scope_id ) {
				return new \WP_Error( 'certloom_template_assignment_scope_invalid', __( 'The Global template scope cannot have a record ID.', 'certloom' ) );
			}
		} elseif ( $scope_id < 1 ) {
			return new \WP_Error( 'certloom_template_assignment_scope_invalid', __( 'Choose a valid record for this template assignment scope.', 'certloom' ) );
		}
		return array( 'scope_type' => $scope_type, 'scope_id' => $scope_id );
	}

	/** @return string */
	public static function scope_label( $scope_type ) {
		switch ( (string) $scope_type ) {
			case self::SCOPE_BATCH: return __( 'Batch', 'certloom' );
			case self::SCOPE_PROGRAM: return __( 'Program', 'certloom' );
			case self::SCOPE_ISSUER: return __( 'Issuer', 'certloom' );
			default: return __( 'Global', 'certloom' );
		}
	}
}
