<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explains whether the exact data required by a selected certificate design is
 * present before an administrator reaches Review/Issue.
 *
 * This is deliberately descriptive rather than mutating: it points the UI to
 * the canonical Recipient/context/credential field that owns a value. Final
 * issuance remains responsible for revalidating the exact frozen request.
 */
final class IssuanceDataReadiness {
	/** @return array<string,mixed> */
	public function inspect( array $required_tokens, array $values, array $context = array(), array $catalog = array() ) {
		$rows = array();
		$missing = 0;
		$ready = 0;
		$generated = 0;

		foreach ( array_values( array_unique( array_map( 'strval', $required_tokens ) ) ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) { continue; }
			$row = $this->row( $token, $values, $context, $catalog );
			$rows[] = $row;
			if ( 'missing' === $row['status'] ) { $missing++; }
			elseif ( 'generated' === $row['status'] ) { $generated++; }
			else { $ready++; }
		}

		return array(
			'ready'           => 0 === $missing,
			'missing_count'   => $missing,
			'ready_count'     => $ready,
			'generated_count' => $generated,
			'total_count'     => count( $rows ),
			'fields'          => $rows,
		);
	}

	/** @return array<string,mixed> */
	private function row( $token, array $values, array $context, array $catalog ) {
		$generated_tokens = array(
			'credential.display_number', 'certificate_number',
			'credential.public_id', 'verification.url', 'verification_url',
			'credential.status', 'certificate_status',
		);
		if ( in_array( $token, $generated_tokens, true ) ) {
			return $this->make_row( $token, $this->label( $token, $catalog ), 'System generated', 'generated', '', 'none', '' );
		}

		$value_exists = array_key_exists( $token, $values );
		$value = $value_exists ? $values[ $token ] : null;
		$has_value = $this->has_value( $value );

		if ( in_array( $token, array( 'credential.expiry_date', 'expiry_date' ), true ) && array_key_exists( 'expires_at', $context ) && null === $context['expires_at'] ) {
			return $this->make_row( $token, __( 'Expiry date', 'certloom' ), __( 'Credential', 'certloom' ), 'ready', __( 'No expiry', 'certloom' ), 'none', '' );
		}

		$meta = $this->source_meta( $token, $catalog, $context );
		$status = $has_value ? 'ready' : 'missing';
		$hint = $has_value ? $this->value_hint( $value ) : '';
		return $this->make_row( $token, $meta['label'], $meta['source'], $status, $hint, $meta['action'], $meta['field_key'] );
	}

	/** @return array<string,string> */
	private function source_meta( $token, array $catalog, array $context ) {
		$recipient = array(
			'recipient.name' => __( 'Recipient name', 'certloom' ), 'recipient_name' => __( 'Recipient name', 'certloom' ),
			'recipient.first_name' => __( 'First name', 'certloom' ), 'first_name' => __( 'First name', 'certloom' ),
			'recipient.middle_name' => __( 'Middle name', 'certloom' ), 'middle_name' => __( 'Middle name', 'certloom' ),
			'recipient.last_name' => __( 'Last name', 'certloom' ), 'last_name' => __( 'Last name', 'certloom' ),
			'recipient.email' => __( 'Email', 'certloom' ), 'recipient.external_id' => __( 'External learner / customer ID', 'certloom' ),
		);
		if ( isset( $recipient[ $token ] ) ) {
			return array( 'label' => $recipient[ $token ], 'source' => __( 'Recipient profile', 'certloom' ), 'action' => 'recipient', 'field_key' => (string) ( ScopedFieldContract::field_key_from_merge_token( $token ) ?: '' ) );
		}
		if ( 0 === strpos( $token, 'recipient.custom.' ) ) {
			$field_key = (string) ( ScopedFieldContract::field_key_from_merge_token( $token ) ?: '' );
			$field = '' !== $field_key && isset( $catalog[ $field_key ] ) && is_array( $catalog[ $field_key ] ) ? $catalog[ $field_key ] : array();
			return array(
				'label' => (string) ( $field['label'] ?? $this->humanize( $field_key ?: $token ) ),
				'source' => __( 'Recipient profile', 'certloom' ),
				'action' => 'recipient',
				'field_key' => $field_key,
			);
		}
		// Older/custom Designer layouts sometimes used recipient.<key> for a fact
		// that was never part of Certloom's canonical Recipient vocabulary. Do not
		// leave those requirements as a dead-end "Credential context" warning:
		// treat the unknown key as an issued-only credential detail. Canonical
		// Recipient tokens above continue to route to the Recipient profile.
		if ( 0 === strpos( $token, 'recipient.' ) ) {
			$field_key = sanitize_key( substr( $token, 10 ) );
			if ( '' !== $field_key && preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $field_key ) ) {
				$field = isset( $catalog[ $field_key ] ) && is_array( $catalog[ $field_key ] ) ? $catalog[ $field_key ] : array();
				return array(
					'label' => (string) ( $field['label'] ?? $this->humanize( $field_key ) ),
					'source' => __( 'Credential details', 'certloom' ),
					'action' => 'credential_detail',
					'field_key' => $field_key,
				);
			}
		}

		$program = array( 'program.name', 'program.title', 'program.code', 'course_name', 'program_name' );
		if ( in_array( $token, $program, true ) ) {
			return array( 'label' => $this->label( $token, $catalog ), 'source' => ! empty( $context['program_id'] ) ? __( 'Program', 'certloom' ) : __( 'Program — none selected', 'certloom' ), 'action' => 'program', 'field_key' => '' );
		}
		if ( 0 === strpos( $token, 'issuer.' ) || in_array( $token, array( 'organization', 'issuer_name', 'issuer_title' ), true ) ) {
			return array( 'label' => $this->label( $token, $catalog ), 'source' => __( 'Issuer', 'certloom' ), 'action' => 'context', 'field_key' => '' );
		}
		if ( 0 === strpos( $token, 'signer.' ) || in_array( $token, array( 'signer_name', 'signer_title' ), true ) ) {
			return array( 'label' => $this->label( $token, $catalog ), 'source' => __( 'Signer', 'certloom' ), 'action' => 'context', 'field_key' => '' );
		}
		if ( in_array( $token, array( 'subjects', 'results' ), true ) ) {
			return array( 'label' => 'subjects' === $token ? __( 'Subjects', 'certloom' ) : __( 'Results', 'certloom' ), 'source' => __( 'Subjects & results', 'certloom' ), 'action' => 'repeating', 'field_key' => '' );
		}
		if ( in_array( $token, array( 'credential.grade', 'grade' ), true ) ) {
			return array( 'label' => __( 'Grade / result', 'certloom' ), 'source' => __( 'Credential details', 'certloom' ), 'action' => 'grade', 'field_key' => 'grade' );
		}
		// Designer tokens in the credential.<key> namespace are one-off issued
		// credential facts unless they are one of the first-class credential
		// tokens handled above. This keeps custom Designer fields actionable
		// instead of falling through to the vague Credential context bucket.
		if ( 0 === strpos( $token, 'credential.' ) ) {
			$field_key = sanitize_key( substr( $token, 11 ) );
			if ( '' !== $field_key && preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $field_key ) ) {
				$field = isset( $catalog[ $field_key ] ) && is_array( $catalog[ $field_key ] ) ? $catalog[ $field_key ] : array();
				return array(
					'label' => (string) ( $field['label'] ?? $this->humanize( $field_key ) ),
					'source' => __( 'Credential details', 'certloom' ),
					'action' => 'credential_detail',
					'field_key' => $field_key,
				);
			}
		}
		// Accept bounded historical/custom credential-detail namespaces used by
		// Designer power users. They all resolve to one issued-only field key and
		// are frozen with the Credential Version; no mutable Recipient row is changed.
		foreach ( array( 'credential.custom.' => 18, 'credential:' => 11, 'custom.' => 7 ) as $prefix => $offset ) {
			if ( 0 !== strpos( $token, $prefix ) ) { continue; }
			$field_key = sanitize_key( substr( $token, $offset ) );
			if ( '' === $field_key || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $field_key ) ) { continue; }
			$field = isset( $catalog[ $field_key ] ) && is_array( $catalog[ $field_key ] ) ? $catalog[ $field_key ] : array();
			return array(
				'label' => (string) ( $field['label'] ?? $this->humanize( $field_key ) ),
				'source' => __( 'Credential details', 'certloom' ),
				'action' => 'credential_detail',
				'field_key' => $field_key,
			);
		}
		if ( 'score' === $token && isset( $catalog['score'] ) && is_array( $catalog['score'] ) ) {
			return array( 'label' => (string) ( $catalog['score']['label'] ?? __( 'Score', 'certloom' ) ), 'source' => __( 'Credential details', 'certloom' ), 'action' => 'credential_detail', 'field_key' => 'score' );
		}

		$field_key = ScopedFieldContract::field_key_from_merge_token( $token );
		if ( $field_key ) {
			$field = isset( $catalog[ $field_key ] ) && is_array( $catalog[ $field_key ] ) ? $catalog[ $field_key ] : array();
			return array(
				'label' => (string) ( $field['label'] ?? $this->humanize( $field_key ) ),
				'source' => __( 'Credential details', 'certloom' ),
				'action' => 'credential_detail',
				'field_key' => $field_key,
			);
		}

		// A plain, non-reserved Designer token can be supplied as a one-off
		// credential detail for an Independent credential. This keeps legacy/custom
		// designs usable without pretending the value belongs to Recipient/Program.
		if ( preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $token ) ) {
			$field_key = sanitize_key( $token );
			return array( 'label' => $this->label( $token, $catalog ), 'source' => __( 'Credential details', 'certloom' ), 'action' => 'credential_detail', 'field_key' => $field_key );
		}

		return array( 'label' => $this->label( $token, $catalog ), 'source' => __( 'Credential context', 'certloom' ), 'action' => 'context', 'field_key' => '' );
	}

	/** @return array<string,mixed> */
	private function make_row( $token, $label, $source, $status, $hint, $action, $field_key ) {
		return array(
			'token'     => (string) $token,
			'label'     => (string) $label,
			'source'    => (string) $source,
			'status'    => (string) $status,
			'value_hint'=> (string) $hint,
			'action'    => (string) $action,
			'field_key' => sanitize_key( (string) $field_key ),
		);
	}

	/** @return string */
	private function label( $token, array $catalog ) {
		$field_key = ScopedFieldContract::field_key_from_merge_token( $token );
		if ( $field_key && isset( $catalog[ $field_key ]['label'] ) ) { return (string) $catalog[ $field_key ]['label']; }
		$map = array(
			'credential.title' => __( 'Credential title', 'certloom' ), 'credential_title' => __( 'Credential title', 'certloom' ),
			'credential.issue_date' => __( 'Effective date', 'certloom' ), 'issue_date' => __( 'Effective date', 'certloom' ),
			'credential.expiry_date' => __( 'Expiry date', 'certloom' ), 'expiry_date' => __( 'Expiry date', 'certloom' ),
			'program.name' => __( 'Program', 'certloom' ), 'program.title' => __( 'Program title', 'certloom' ), 'program.code' => __( 'Program code', 'certloom' ),
			'course_name' => __( 'Program title', 'certloom' ), 'program_name' => __( 'Program title', 'certloom' ),
			'issuer.name' => __( 'Issuer name', 'certloom' ), 'issuer.title' => __( 'Issuer title', 'certloom' ), 'organization' => __( 'Issuer name', 'certloom' ), 'issuer_name' => __( 'Issuer name', 'certloom' ), 'issuer_title' => __( 'Issuer title', 'certloom' ),
			'signer.name' => __( 'Signer name', 'certloom' ), 'signer.title' => __( 'Signer position / designation', 'certloom' ), 'signer.email' => __( 'Signer email', 'certloom' ), 'signer.signature_asset' => __( 'Signer signature', 'certloom' ), 'signer_name' => __( 'Signer name', 'certloom' ), 'signer_title' => __( 'Signer position / designation', 'certloom' ),
			'site_name' => __( 'Site name', 'certloom' ),
		);
		return isset( $map[ $token ] ) ? $map[ $token ] : $this->humanize( $token );
	}

	/** @return bool */
	private function has_value( $value ) {
		if ( is_array( $value ) ) { return ! empty( $value ); }
		if ( is_scalar( $value ) || null === $value ) { return '' !== trim( (string) $value ); }
		return false;
	}

	/** @return string */
	private function value_hint( $value ) {
		if ( is_array( $value ) ) { return sprintf( /* translators: %d: row count. */ _n( '%d row', '%d rows', count( $value ), 'certloom' ), count( $value ) ); }
		$value = trim( (string) $value );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value, 'UTF-8' ) > 60 ) { return mb_substr( $value, 0, 57, 'UTF-8' ) . '…'; }
		return strlen( $value ) > 60 ? substr( $value, 0, 57 ) . '…' : $value;
	}

	/** @return string */
	private function humanize( $value ) {
		$value = preg_replace( '/^(recipient|credential|program|issuer|signer)\./', '', (string) $value );
		$value = str_replace( array( 'custom:', '.', '_', '-' ), ' ', $value );
		return ucwords( trim( preg_replace( '/\s+/', ' ', $value ) ) );
	}
}
