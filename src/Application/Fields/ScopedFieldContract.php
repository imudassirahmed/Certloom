<?php
namespace Certloom\Application\Fields;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * FLD-02 canonical Global → Issuer → Program → Batch field-requirement contract.
 *
 * Field definitions remain global in Ecapla_CertVerify_Fields. This contract
 * stores only where those definitions apply. Global and Issuer provide reusable
 * defaults; Program and Batch may add/tighten fields or exclude inherited optional fields.
 * Issued credential truth is never read from this mutable contract after issue:
 * ManualIssuanceService freezes the resolved contract on the immutable Version.
 */
final class ScopedFieldContract {
	const STORAGE_KEY = 'certloom_scoped_fields_v1';
	const SCHEMA = 'certloom-scoped-fields';
	const VERSION = 1;
	const MAX_FIELDS = 50;
	const SCOPES = array( 'global', 'issuer', 'program', 'batch' );

	/** @return array<string,mixed> */
	public static function empty_global( $configured = false ) {
		return self::empty_scope( 'global', $configured );
	}

	/** @return array<string,mixed> */
	public static function empty_issuer( $configured = false ) {
		return self::empty_scope( 'issuer', $configured );
	}

	/** @return array<string,mixed> */
	private static function empty_scope( $scope, $configured = false ) {
		return array( '_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => self::VERSION, 'scope' => (string) $scope, 'configured' => (bool) $configured ), 'fields' => array() );
	}

	/** @return array<string,mixed> */
	public static function empty_program( $configured = false ) {
		return self::empty_scope( 'program', $configured );
	}

	/** @return array<string,mixed> */
	public static function empty_batch( $configured = false ) {
		return self::empty_scope( 'batch', $configured );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function from_scope_metadata( array $metadata, $scope, array $catalog ) {
		$scope = sanitize_key( (string) $scope );
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return new \WP_Error( 'certloom_scoped_field_scope_invalid', __( 'Choose a valid credential-field scope.', 'certloom' ) );
		}
		$stored = $metadata[ self::STORAGE_KEY ] ?? null;
		if ( null === $stored ) { return self::empty_scope( $scope, false ); }
		$stored = $this->validate_stored_envelope( $stored, $scope );
		if ( is_wp_error( $stored ) ) { return $stored; }
		$normalized = $this->normalize_scope( $scope, $stored['fields'], $catalog );
		if ( ! is_wp_error( $normalized ) ) { $normalized['_meta']['configured'] = true; }
		return $normalized;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function with_scope_metadata( array $metadata, $scope, $input, array $catalog ) {
		$normalized = $this->normalize_scope( $scope, $input, $catalog );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$metadata[ self::STORAGE_KEY ] = $normalized;
		return $metadata;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function from_program_metadata( array $metadata, array $catalog ) {
		return $this->from_scope_metadata( $metadata, 'program', $catalog );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function from_batch_metadata( array $metadata, array $catalog ) {
		return $this->from_scope_metadata( $metadata, 'batch', $catalog );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_scope( $scope, $input, array $catalog ) {
		$scope = sanitize_key( (string) $scope );
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return new \WP_Error( 'certloom_scoped_field_scope_invalid', __( 'Choose a valid credential-field scope.', 'certloom' ) );
		}
		$fields = $this->normalize_map( $input, $catalog, $scope );
		if ( is_wp_error( $fields ) ) { return $fields; }
		return array( '_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => self::VERSION, 'scope' => $scope, 'configured' => true ), 'fields' => $fields );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_program( $input, array $catalog ) {
		return $this->normalize_scope( 'program', $input, $catalog );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_batch( $input, array $catalog ) {
		return $this->normalize_scope( 'batch', $input, $catalog );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function with_program_metadata( array $metadata, $input, array $catalog ) {
		return $this->with_scope_metadata( $metadata, 'program', $input, $catalog );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function with_batch_metadata( array $metadata, $input, array $catalog ) {
		return $this->with_scope_metadata( $metadata, 'batch', $input, $catalog );
	}

	/**
	 * Resolve Program base + optional Batch delta into the exact issuance schema.
	 * Batch cannot weaken a Program-required field. A stale conflicting Batch
	 * contract fails closed rather than silently changing issuance semantics.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve( array $program_contract, ?array $batch_contract, array $catalog ) {
		$result = $this->resolve_hierarchy( null, null, $program_contract, $batch_contract, $catalog );
		if ( is_wp_error( $result ) && 'certloom_scoped_field_cannot_exclude_required' === $result->get_error_code() ) {
			return new \WP_Error( 'certloom_batch_field_cannot_exclude_required', $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * Resolves Global → Issuer → Program → Batch into one exact issuance schema.
	 * Descendants may add or tighten requirements. Issuer/Program/Batch may
	 * exclude only inherited optional fields; a required ancestor is immutable.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve_hierarchy( ?array $global_contract, ?array $issuer_contract, array $program_contract, ?array $batch_contract, array $catalog ) {
		$contracts = array(
			'global'  => $global_contract,
			'issuer'  => $issuer_contract,
			'program' => $program_contract,
			'batch'   => $batch_contract,
		);
		$resolved = array();
		$configured = array();
		foreach ( $contracts as $scope => $contract ) {
			if ( ! is_array( $contract ) ) { $configured[ $scope ] = false; continue; }
			$configured[ $scope ] = ! empty( $contract['_meta']['configured'] );
			$normalized = $this->normalize_scope( $scope, $contract['fields'] ?? array(), $catalog );
			if ( is_wp_error( $normalized ) ) { return $normalized; }
			foreach ( $normalized['fields'] as $key => $mode ) {
				if ( 'exclude' === $mode ) {
					if ( isset( $resolved[ $key ] ) && 'required' === $resolved[ $key ]['requirement'] ) {
						return new \WP_Error( 'certloom_scoped_field_cannot_exclude_required', sprintf( /* translators: %s: field label. */ __( 'A child scope cannot exclude required field “%s”. Change the parent requirement first.', 'certloom' ), (string) ( $catalog[ $key ]['label'] ?? $key ) ) );
					}
					unset( $resolved[ $key ] );
					continue;
				}
				if ( isset( $resolved[ $key ] ) && 'required' === $resolved[ $key ]['requirement'] && 'optional' === $mode ) { $mode = 'required'; }
				$resolved[ $key ] = array( 'key' => $key, 'requirement' => $mode, 'origin' => $scope );
			}
		}

		$out = array();
		foreach ( $resolved as $key => $row ) {
			$field = $catalog[ $key ] ?? null;
			if ( ! is_array( $field ) ) { continue; }
			$out[] = array(
				'key' => $key,
				'label' => (string) ( $field['label'] ?? $key ),
				'type' => (string) ( $field['type'] ?? 'text' ),
				'sensitivity' => (string) ( $field['sensitivity'] ?? 'personal' ),
				'public_exposure' => (string) ( $field['public_exposure'] ?? 'private' ),
				'required' => 'required' === $row['requirement'],
				'requirement' => (string) $row['requirement'],
				'origin' => (string) $row['origin'],
				'value_source' => self::value_source( $key ),
			);
		}
		return array(
			'_meta' => array(
				'schema' => self::SCHEMA, 'schema_version' => self::VERSION, 'scope' => 'resolved',
				'global_configured' => ! empty( $configured['global'] ), 'issuer_configured' => ! empty( $configured['issuer'] ),
				'configured' => ! empty( $configured['program'] ), 'batch_configured' => ! empty( $configured['batch'] ),
			),
			'fields' => $out,
		);
	}

	/** @return string */
	public static function value_source( $key ) {
		$key = sanitize_key( (string) $key );
		return in_array( $key, array( 'first_name', 'middle_name', 'last_name', 'email', 'external_id' ), true ) ? 'recipient' : ( 'grade' === $key ? 'credential' : 'credential_custom' );
	}

	/**
	 * Canonical Designer/rendering merge token for one scoped field key.
	 *
	 * Requirement scope and value ownership are deliberately separate. Recipient
	 * identity fields therefore use recipient.* tokens, Grade uses the canonical
	 * credential token, and administrator-created credential values use credential.custom.<key>.
	 * This prevents the Designer from accidentally treating Recipient-owned facts
	 * as mutable credential custom data.
	 *
	 * @return string
	 */
	public static function merge_token( $key ) {
		$key = sanitize_key( (string) $key );
		switch ( $key ) {
			case 'first_name': return 'recipient.first_name';
			case 'middle_name': return 'recipient.middle_name';
			case 'last_name': return 'recipient.last_name';
			case 'email': return 'recipient.email';
			case 'external_id': return 'recipient.external_id';
			case 'grade': return 'credential.grade';
			default: return '' === $key ? '' : 'credential.custom.' . $key;
		}
	}

	/**
	 * Maps canonical (and selected historical) merge tokens back to a scoped
	 * catalog field key for Template compatibility checks. Tokens that are not
	 * governed by FLD-02 return null.
	 *
	 * @return string|null
	 */
	public static function field_key_from_merge_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		$map = array(
			'recipient.first_name' => 'first_name',
			'first_name' => 'first_name',
			'recipient.middle_name' => 'middle_name',
			'middle_name' => 'middle_name',
			'recipient.last_name' => 'last_name',
			'last_name' => 'last_name',
			'recipient.email' => 'email',
			'recipient.external_id' => 'external_id',
			'credential.grade' => 'grade',
			'grade' => 'grade',
		);
		if ( isset( $map[ $token ] ) ) { return $map[ $token ]; }
		if ( 0 === strpos( $token, 'credential.custom.' ) ) {
			$key = sanitize_key( substr( $token, 18 ) );
			return '' === $key ? null : $key;
		}
		if ( 0 === strpos( $token, 'custom:' ) ) {
			$key = sanitize_key( substr( $token, 7 ) );
			return '' === $key ? null : $key;
		}
		if ( 0 === strpos( $token, 'recipient.custom.' ) ) {
			$key = sanitize_key( substr( $token, 17 ) );
			return '' === $key ? null : $key;
		}
		return null;
	}

	/**
	 * Validates the persisted typed envelope before field normalization. The
	 * storage marker is an architectural boundary, so corrupt schema/version/
	 * scope metadata must not be silently ignored during normal reads or restore.
	 *
	 * @param mixed  $stored
	 * @param string $scope
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate_stored_envelope( $stored, $scope ) {
		$scope = sanitize_key( (string) $scope );
		if ( ! in_array( $scope, self::SCOPES, true ) || ! is_array( $stored ) || ! isset( $stored['_meta'] ) || ! is_array( $stored['_meta'] ) || ! array_key_exists( 'fields', $stored ) || ! is_array( $stored['fields'] ) ) {
			return new \WP_Error( 'certloom_scoped_field_storage_invalid', __( 'Stored scoped field requirements are structurally invalid.', 'certloom' ) );
		}
		$meta = $stored['_meta'];
		if ( self::SCHEMA !== (string) ( $meta['schema'] ?? '' ) || self::VERSION !== (int) ( $meta['schema_version'] ?? 0 ) || $scope !== sanitize_key( (string) ( $meta['scope'] ?? '' ) ) || empty( $meta['configured'] ) ) {
			return new \WP_Error( 'certloom_scoped_field_storage_schema_invalid', __( 'Stored scoped field requirements use an invalid schema, version, or scope.', 'certloom' ) );
		}
		if ( count( $stored['fields'] ) > self::MAX_FIELDS ) {
			return new \WP_Error( 'certloom_scoped_field_contract_invalid', __( 'Scoped field requirements must be a bounded field map.', 'certloom' ) );
		}
		$allowed = 'global' === $scope ? array( 'optional', 'required' ) : array( 'optional', 'required', 'exclude' );
		foreach ( $stored['fields'] as $raw_key => $raw_mode ) {
			$key = (string) $raw_key; $mode = (string) $raw_mode;
			if ( '' === $key || sanitize_key( $key ) !== $key || ! in_array( $mode, $allowed, true ) ) {
				return new \WP_Error( 'certloom_scoped_field_storage_rule_invalid', __( 'Stored scoped field requirements contain an invalid field key or requirement rule.', 'certloom' ) );
			}
		}
		return array( '_meta' => $meta, 'fields' => $stored['fields'] );
	}

	/** @return array<string,string>|\WP_Error */
	private function normalize_map( $input, array $catalog, $scope ) {
		if ( null === $input || '' === $input ) { return array(); }
		if ( ! is_array( $input ) || count( $input ) > self::MAX_FIELDS ) {
			return new \WP_Error( 'certloom_scoped_field_contract_invalid', __( 'Scoped field requirements must be a bounded field map.', 'certloom' ) );
		}
		$out = array();
		foreach ( $input as $raw_key => $raw_mode ) {
			$key = sanitize_key( (string) $raw_key );
			$mode = sanitize_key( (string) $raw_mode );
			if ( '' === $key || ! isset( $catalog[ $key ] ) || ! self::is_scopable_definition( $catalog[ $key ] ) ) {
				return new \WP_Error( 'certloom_scoped_field_unknown', __( 'A scoped field requirement references a field that is unavailable for scoped credential-field assignment.', 'certloom' ) );
			}
			$allowed = 'global' === (string) $scope ? array( 'optional', 'required' ) : array( 'optional', 'required', 'exclude' );
			if ( ! in_array( $mode, $allowed, true ) ) {
				return new \WP_Error( 'certloom_scoped_field_requirement_invalid', __( 'Choose a valid optional, required, or inherited-field exclusion rule.', 'certloom' ) );
			}
			$out[ $key ] = $mode;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @return bool */
	public static function is_scopable_definition( array $field ) {
		$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
		if ( '' === $key || ! empty( $field['system_only'] ) || ! empty( $field['locked_visibility'] ) ) { return false; }
		return ! in_array( $key, array( 'cert_number', 'status', 'holder_name', 'issue_date', 'expiry_date', 'status_reason', 'internal_notes' ), true );
	}
}
