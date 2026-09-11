<?php
namespace Certloom\Application\Issuance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Resolves the exact title frozen on an issued Credential Version.
 *
 * Program-backed issuance inherits the reusable Program title by default.
 * Standalone issuance requires an explicit title. A Program-backed issuance may
 * deliberately override the inherited title without mutating the Program.
 */
final class EffectiveCredentialTitleResolver {
	/** @return array{title:string,source:string,program_id:int}|\WP_Error */
	public function resolve( $source, $explicit_title, array $program = array() ) {
		$source = sanitize_key( (string) $source );
		$explicit_title = trim( sanitize_text_field( (string) $explicit_title ) );
		$program_id = absint( $program['id'] ?? 0 );

		if ( ! in_array( $source, array( 'program', 'custom' ), true ) ) {
			return new \WP_Error( 'certloom_credential_title_source_invalid', __( 'Choose whether the credential title should come from the Program or be entered for this credential.', 'certloom' ) );
		}

		if ( 'program' === $source ) {
			if ( ! $program_id ) {
				return new \WP_Error( 'certloom_credential_title_program_required', __( 'Choose a Program before inheriting its credential title.', 'certloom' ) );
			}
			$title = trim( sanitize_text_field( (string) ( $program['title'] ?? '' ) ) );
			if ( '' === $title ) {
				return new \WP_Error( 'certloom_credential_title_program_missing', __( 'The selected Program does not have a usable title. Update the Program or use a custom credential title.', 'certloom' ) );
			}
			return array( 'title' => $title, 'source' => 'program', 'program_id' => $program_id );
		}

		if ( '' === $explicit_title ) {
			return new \WP_Error( 'certloom_credential_title_required', __( 'Enter a credential title for this independent credential.', 'certloom' ) );
		}
		return array( 'title' => $explicit_title, 'source' => 'custom', 'program_id' => $program_id );
	}
}
