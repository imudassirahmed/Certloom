<?php
namespace Certloom\Application\Issuance;

use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\TableDataContract;

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
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build( array $recipient, array $program, $issuer, array $credential, array $layout, array $custom_overrides = array(), $signer = null, $allow_missing_required = false ) {
		$issuer = is_array( $issuer ) ? $issuer : array();
		$signer = is_array( $signer ) ? $signer : array();
		$custom = isset( $recipient['custom_fields'] ) && is_array( $recipient['custom_fields'] ) ? $recipient['custom_fields'] : array();
		$display_name = trim( (string) ( $recipient['display_name'] ?? '' ) );
		$first_name = trim( (string) ( $recipient['first_name'] ?? '' ) );
		$middle_name = trim( (string) ( $recipient['middle_name'] ?? '' ) );
		$last_name = trim( (string) ( $recipient['last_name'] ?? '' ) );
		$program_title = trim( (string) ( $program['title'] ?? '' ) );
		$issuer_name = trim( (string) ( $issuer['name'] ?? '' ) );
		$signer_name = trim( (string) ( $signer['display_name'] ?? '' ) );
		$signer_title = trim( (string) ( $signer['title'] ?? '' ) );
		$signer_email = trim( (string) ( $signer['email'] ?? '' ) );
		$signer_signature_attachment_id = absint( $signer['signature_attachment_id'] ?? 0 );
		$issuer_settings = isset( $issuer['settings'] ) && is_array( $issuer['settings'] ) ? $issuer['settings'] : array();
		$issue_date = (string) ( $credential['valid_from'] ?? '' );
		$expiry_date = (string) ( $credential['expires_at'] ?? '' );
		$verification_url = (string) ( $credential['verification_url'] ?? '' );
		$grade = trim( (string) ( $credential['grade'] ?? '' ) );
		$credential_title = trim( (string) ( $credential['title'] ?? '' ) );
		$repeating = array();
		try {
			foreach ( array( TableDataContract::SUBJECTS => 'subjects', TableDataContract::RESULTS => 'results' ) as $source => $credential_key ) {
				if ( array_key_exists( $credential_key, $credential ) ) { $repeating[ $source ] = TableDataContract::normalize_rows( $source, $credential[ $credential_key ] ); }
			}
		} catch ( \InvalidArgumentException $error ) {
			return new \WP_Error( 'certloom_manual_issue_repeating_data_invalid', __( 'The repeating result/subject data is not valid for immutable credential issuance.', 'certloom' ) );
		}

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
			'signer.name' => $signer_name,
			'signer.title' => $signer_title,
			'signer.email' => $signer_email,
			'credential.display_number' => trim( (string) ( $credential['display_number'] ?? '' ) ),
			'credential.title' => $credential_title,
			'credential.public_id' => trim( (string) ( $credential['public_id'] ?? '' ) ),
			'credential.issue_date' => $issue_date,
			'credential.expiry_date' => $expiry_date,
			'credential.status' => 'issued',
			'credential.grade' => $grade,
			'verification.url' => $verification_url,
			// Product-roadmap aliases retained as first-class P0 merge vocabulary.
			'recipient_name' => $display_name,
			'first_name' => $first_name,
			'middle_name' => $middle_name,
			'last_name' => $last_name,
			'certificate_number' => trim( (string) ( $credential['display_number'] ?? '' ) ),
			'credential_title' => $credential_title,
			'certificate_status' => 'issued',
			'course_name' => $program_title,
			'program_name' => $program_title,
			'issue_date' => $issue_date,
			'expiry_date' => $expiry_date,
			'organization' => $issuer_name,
			'issuer_name' => $issuer_name,
			'issuer_title' => trim( (string) ( $issuer_settings['title'] ?? '' ) ),
			'signer_name' => $signer_name,
			'signer_title' => $signer_title,
			'score' => trim( (string) ( $credential['score'] ?? '' ) ),
			'grade' => $grade,
			'site_name' => function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '',
			'verification_url' => $verification_url,
		);
		if ( $signer_signature_attachment_id ) { $values['signer.signature_asset'] = 'attachment:' . $signer_signature_attachment_id; }
		foreach ( $repeating as $source => $rows ) { $values[ $source ] = $rows; }

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
				if ( $allow_missing_required ) { $values[ $token ] = ''; continue; }
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
			if ( 'signature' === $type && 'signer.signature' === (string) ( $element['source'] ?? '' ) ) {
				$tokens[] = 'signer.signature_asset';
			}
			if ( 'data_table' === $type ) {
				$source = trim( (string) ( $element['source'] ?? '' ) );
				if ( '' !== $source ) { $tokens[] = $source; }
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
