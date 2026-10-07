<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One organization choice reuses exact field submissions, approvals and readiness. */
final class CertificateNamePolicy {
	public function current( array $catalog ) {
		$source = class_exists( 'Ecapla_CertVerify_Settings' ) ? \Ecapla_CertVerify_Settings::get( 'certificate_name_source', 'profile' ) : 'profile';
		$key = class_exists( 'Ecapla_CertVerify_Settings' ) ? \Ecapla_CertVerify_Settings::get( 'certificate_name_field_key', '' ) : '';
		return $this->normalize( $source, $key, $catalog );
	}
	public function normalize( $source, $key, array $catalog ) {
		if ( ! in_array( $source, array( 'profile', 'approved_field' ), true ) || ! is_string( $key ) || strlen( $key ) > 64 || ( '' !== $key && sanitize_key( $key ) !== $key ) ) { return $this->invalid(); }
		if ( 'profile' === $source ) { return array( 'source' => 'profile', 'field_key' => '' ); }
		$field = $catalog[ $key ] ?? null;
		if ( ! is_array( $field ) || 'text' !== ( $field['type'] ?? '' ) || ! array_key_exists( FieldCollectionPolicy::KEY, $field ) ) { return $this->invalid(); }
		$policy = ( new FieldCollectionPolicy() )->for_definition( $field );
		if ( is_wp_error( $policy ) || 'recipient' !== $policy['writer'] || 'recipient' !== $policy['lifetime'] || 'profile' !== $policy['collect_when'] || ! $policy['approval_required'] || ! $policy['show_on_certificate'] || ! in_array( $field['public_exposure'] ?? 'private', array( 'profile', 'both' ), true ) ) { return $this->invalid(); }
		return array( 'source' => 'approved_field', 'field_key' => $key );
	}
	public function apply_global( array $global, array $catalog ) {
		$policy = $this->current( $catalog );
		return is_wp_error( $policy ) ? $policy : $this->apply_policy( $global, $catalog, $policy );
	}
	public function apply_policy( array $global, array $catalog, array $policy ) {
		if ( 'profile' === $policy['source'] ) { return $global; }
		$fields = $global['fields']; $fields[ $policy['field_key'] ] = 'required';
		return ( new ScopedFieldContract() )->normalize_scope( 'global', $fields, $catalog );
	}
	/** Reject incompatible saved exclusions before a new organization-wide source is saved. */
	public function validate_change( array $policy, array $catalog ) {
		if ( 'profile' === $policy['source'] ) { return true; }
		$stored = get_option( \Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository::OPTION, null );
		$global = null === $stored || false === $stored ? ScopedFieldContract::empty_global() : ( new ScopedFieldContract() )->validate_stored_envelope( $stored, 'global' );
		if ( is_wp_error( $global ) ) { return $global; }
		$prospective = $this->apply_policy( $global, $catalog, $policy );
		if ( is_wp_error( $prospective ) ) { return $prospective; }
		global $wpdb;
		$scanned = 0; $key = $policy['field_key'];
		foreach ( array( 'issuers' => 'settings_json', 'programs' => 'metadata_json', 'batches' => 'metadata_json' ) as $entity => $column ) {
			$after = 0;
			do {
				$wpdb->last_error = '';
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,' . $column . ' AS metadata FROM %i WHERE id>%d AND ' . $column . ' LIKE %s ORDER BY id LIMIT 100', \Certloom\Infrastructure\Database\SchemaManager::table( $entity ), $after, '%' . $wpdb->esc_like( $key ) . '%' ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
				foreach ( $rows as $row ) {
					if ( ++$scanned > 1000 ) { return new \WP_Error( 'certloom_certificate_name_review_limit', __( 'This name field has too many saved requirements for one settings change. Review its Program requirements in stages before enabling the organization-wide name source.', 'certloom' ) ); }
					$after = (int) $row['id']; $metadata = json_decode( $row['metadata'], true );
					if ( ! is_array( $metadata ) || JSON_ERROR_NONE !== json_last_error() ) { return $this->invalid(); }
					if ( 'exclude' === ( $metadata[ ScopedFieldContract::STORAGE_KEY ]['fields'][ $key ] ?? null ) ) { return new \WP_Error( 'certloom_certificate_name_excluded', __( 'An Issuer, Program or Batch excludes this name field. Remove that exclusion before requiring it as the organization-wide certificate name.', 'certloom' ) ); }
				}
			} while ( 100 === count( $rows ) );
		}
		return true;
	}
	/** Values come only from the shared approved-value provider, never raw candidates. */
	public function project( array $recipient, array $values, array $catalog ) {
		$policy = $this->current( $catalog );
		if ( is_wp_error( $policy ) ) { return $policy; }
		if ( 'approved_field' === $policy['source'] ) {
			$name = $values[ $policy['field_key'] ] ?? '';
			if ( ! is_string( $name ) || ! $this->valid_name( $name, true ) ) { return $this->invalid_value(); }
			$recipient['display_name'] = trim( $name );
		}
		return $recipient;
	}
	public function valid_name( $value, $allow_empty = false ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f<>]/u', $value ) ) { return false; }
		$value = trim( $value ); $length = preg_match_all( '/./us', $value );
		return false !== $length && $length <= 255 && ( $allow_empty || $length > 0 );
	}
	public function invalid_value() { return new \WP_Error( 'certloom_certificate_name_value_invalid', __( 'Enter a certificate name on one line, using 255 characters or fewer.', 'certloom' ) ); }
	private function invalid() { return new \WP_Error( 'certloom_certificate_name_policy_invalid', __( 'Choose a text field collected in personal details, supplied by the recipient, reviewed by your organization and permitted on certificate artwork.', 'certloom' ) ); }
}
