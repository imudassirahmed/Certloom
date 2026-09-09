<?php
namespace Certloom\Application\Issuance;

use Certloom\Domain\Rendering\ElementRegistry;

/**
 * Resolves the P0 issuance merge vocabulary exactly once before rendering.
 *
 * The immutable result is stored on the Credential Version. Renderers consume
 * this snapshot rather than re-reading mutable Recipient/Program/Issuer rows.
 */
final class MergeSnapshotBuilder {
	/**
	 * @param array<string,mixed> $recipient Recipient snapshot.
	 * @param array<string,mixed> $program Program snapshot.
	 * @param array<string,mixed>|null $issuer Issuer snapshot.
	 * @param array<string,mixed> $credential Credential identity/date facts.
	 * @param array<string,mixed> $layout Published canonical layout.
	 * @return array<string,string>|\WP_Error
	 */
	public function build( array $recipient, array $program, $issuer, array $credential, array $layout, array $custom_overrides = array() ) {
		$issuer = is_array( $issuer ) ? $issuer : array();
		$custom = isset( $recipient['custom_fields'] ) && is_array( $recipient['custom_fields'] ) ? $recipient['custom_fields'] : array();
		$display_name = trim( (string) ( $recipient['display_name'] ?? '' ) );
		$first_name = trim( (string) ( $recipient['first_name'] ?? '' ) );
		$middle_name = trim( (string) ( $recipient['middle_name'] ?? '' ) );
		$last_name = trim( (string) ( $recipient['last_name'] ?? '' ) );
		$program_title = trim( (string) ( $program['title'] ?? '' ) );
		$issuer_name = trim( (string) ( $issuer['name'] ?? '' ) );
		$issuer_settings = isset( $issuer['settings'] ) && is_array( $issuer['settings'] ) ? $issuer['settings'] : array();
		$issue_date = (string) ( $credential['valid_from'] ?? '' );
		$expiry_date = (string) ( $credential['expires_at'] ?? '' );
		$verification_url = (string) ( $credential['verification_url'] ?? '' );
		$grade = trim( (string) ( $credential['grade'] ?? '' ) );

		$values = array(
			'recipient.name' => $display_name,
			'recipient.first_name' => $first_name,
			'recipient.middle_name' => $middle_name,
			'recipient.last_name' => $last_name,
			'recipient.email' => trim( (string) ( $recipient['email'] ?? '' ) ),
			'recipient.external_id' => trim( (string) ( $recipient['external_id'] ?? '' ) ),
			'program.name' => $program_title,
			'program.title' => $program_title,
			'program.code' => trim( (string) ( $program['code'] ?? '' ) ),
			'issuer.name' => $issuer_name,
			'issuer.title' => trim( (string) ( $issuer_settings['title'] ?? '' ) ),
			'credential.display_number' => trim( (string) ( $credential['display_number'] ?? '' ) ),
			'credential.public_id' => trim( (string) ( $credential['public_id'] ?? '' ) ),
			'credential.issue_date' => $issue_date,
			'credential.expiry_date' => $expiry_date,
			'credential.status' => 'issued',
			'credential.grade' => $grade,
			'verification.url' => $verification_url,
			// Product-roadmap aliases retained as first-class P0 merge vocabulary.
			'recipient_name' => $display_name,
			'first_name' => $first_name,
			'last_name' => $last_name,
			'certificate_number' => trim( (string) ( $credential['display_number'] ?? '' ) ),
			'certificate_status' => 'issued',
			'course_name' => $program_title,
			'program_name' => $program_title,
			'issue_date' => $issue_date,
			'expiry_date' => $expiry_date,
			'organization' => $issuer_name,
			'issuer_name' => $issuer_name,
			'issuer_title' => trim( (string) ( $issuer_settings['title'] ?? '' ) ),
			'score' => trim( (string) ( $credential['score'] ?? '' ) ),
			'grade' => $grade,
			'site_name' => function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '',
			'verification_url' => $verification_url,
		);

		foreach ( $custom as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || is_array( $value ) || is_object( $value ) || is_resource( $value ) ) { continue; }
			$scalar = null === $value ? '' : ( is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value );
			$values[ 'custom:' . $key ] = $scalar;
			$values[ 'recipient.custom.' . $key ] = $scalar;
		}

		// Manual Issuance may supply credential-specific values only for the
		// namespaced custom merge vocabulary. These values are snapshotted on the
		// Credential Version and never mutate the Recipient row. Built-in merge
		// identities cannot be overridden through this channel.
		foreach ( $custom_overrides as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || is_array( $value ) || is_object( $value ) || is_resource( $value ) ) { continue; }
			$scalar = null === $value ? '' : ( is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value );
			$values[ 'custom:' . $key ] = $scalar;
			$values[ 'recipient.custom.' . $key ] = $scalar;
		}

		$required = $this->required_tokens( $layout );
		foreach ( $required as $token ) {
			if ( ! array_key_exists( $token, $values ) ) {
				return new \WP_Error( 'certloom_manual_issue_merge_missing', sprintf( /* translators: %s: merge token. */ __( 'The published template requires merge field “%s”, but Manual Issuance cannot resolve it from the selected recipient/program context.', 'certloom' ), $token ) );
			}
		}
		return $values;
	}

	/** @return string[] */
	public function required_tokens( array $layout ) {
		$tokens = array();
		foreach ( (array) ( $layout['elements'] ?? array() ) as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$type = strtolower( trim( (string) ( $element['type'] ?? '' ) ) );
			$definition = ElementRegistry::get( $type );
			if ( ! is_array( $definition ) ) { continue; }
			if ( 'merge' === $type ) {
				$token = trim( (string) ( $element['token'] ?? '' ) );
				if ( '' !== $token ) { $tokens[] = $token; }
			}
			if ( 'rich_text' === $type && isset( $element['runs'] ) && is_array( $element['runs'] ) ) {
				foreach ( $element['runs'] as $run ) {
					if ( ! is_array( $run ) || 'token' !== (string) ( $run['kind'] ?? '' ) ) { continue; }
					$token = trim( (string) ( $run['token'] ?? '' ) );
					if ( '' !== $token ) { $tokens[] = $token; }
				}
			}
			foreach ( (array) ( $definition['merge_requirements'] ?? array() ) as $requirement ) {
				if ( 'dynamic_token' === $requirement || 'verification_url' === $requirement ) { continue; }
				$tokens[] = (string) $requirement;
			}
		}
		return array_values( array_unique( $tokens ) );
	}
}
