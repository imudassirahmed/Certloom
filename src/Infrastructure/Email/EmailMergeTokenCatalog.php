<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Issuance\MergeTokenCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human-facing Email Studio view of Certloom's immutable merge vocabulary.
 *
 * Delivery resolves these tokens from the issued Credential Version's
 * snapshot.merge.values map. This catalog is discovery-only; it never reads a
 * mutable Recipient/Program value while an email is being composed.
 */
final class EmailMergeTokenCatalog {
	/** @return array<string,array{label:string,tokens:array<string,string>}> */
	public function groups() {
		$groups = MergeTokenCatalog::groups();

		$custom = array();
		if ( class_exists( '\\Ecapla_CertVerify_Fields' ) && is_callable( array( '\\Ecapla_CertVerify_Fields', 'get_manageable_fields' ) ) ) {
			$canonical_keys = array(
				'cert_number', 'status', 'holder_name', 'issue_date', 'expiry_date',
				'first_name', 'middle_name', 'last_name', 'email', 'external_id', 'grade',
				'status_reason', 'internal_notes',
			);
			foreach ( (array) \Ecapla_CertVerify_Fields::get_manageable_fields() as $field ) {
				if ( ! is_array( $field ) || ! empty( $field['system_only'] ) ) { continue; }
				$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
				if ( '' === $key || in_array( $key, $canonical_keys, true ) ) { continue; }
				$label = trim( (string) ( $field['label'] ?? '' ) );
				$custom[ 'credential.custom.' . $key ] = '' !== $label ? $label : ucwords( str_replace( '_', ' ', $key ) );
			}
		}
		if ( $custom ) {
			$groups['custom'] = array( 'label' => __( 'Custom fields', 'certloom' ), 'tokens' => $custom );
		}

		// Compatibility aliases remain discoverable, but are kept separate so new
		// designs naturally choose the canonical namespaced vocabulary above.
		$groups['legacy'] = array(
			'label' => __( 'Compatibility aliases', 'certloom' ),
			'tokens' => array(
				'recipient_name'    => __( 'Recipient name (legacy)', 'certloom' ),
				'first_name'        => __( 'First name (legacy)', 'certloom' ),
				'middle_name'       => __( 'Middle name (legacy)', 'certloom' ),
				'last_name'         => __( 'Last name (legacy)', 'certloom' ),
				'credential_number' => __( 'Credential number (email legacy)', 'certloom' ),
				'certificate_number'=> __( 'Certificate number (legacy)', 'certloom' ),
				'credential_title'  => __( 'Credential title (legacy)', 'certloom' ),
				'certificate_status'=> __( 'Certificate status (legacy)', 'certloom' ),
				'program_title'     => __( 'Program title (email legacy)', 'certloom' ),
				'program_name'      => __( 'Program name (legacy)', 'certloom' ),
				'course_name'       => __( 'Course name (legacy)', 'certloom' ),
				'batch_name'        => __( 'Batch name (legacy)', 'certloom' ),
				'batch_code'        => __( 'Batch code (legacy)', 'certloom' ),
				'cohort_name'       => __( 'Cohort name (legacy)', 'certloom' ),
				'issuer_name'       => __( 'Issuer name (legacy)', 'certloom' ),
				'issuer_title'      => __( 'Issuer title (legacy)', 'certloom' ),
				'organization'      => __( 'Organization (legacy)', 'certloom' ),
				'signer_name'       => __( 'Signer name (legacy)', 'certloom' ),
				'signer_title'      => __( 'Signer title (legacy)', 'certloom' ),
				'issue_date'        => __( 'Issue date (legacy)', 'certloom' ),
				'expiry_date'       => __( 'Expiry date (legacy)', 'certloom' ),
				'grade'             => __( 'Grade (legacy)', 'certloom' ),
				'verification_url'  => __( 'Verification URL (legacy)', 'certloom' ),
				'verification_link' => __( 'Verification link (legacy)', 'certloom' ),
			),
		);

		return $groups;
	}

	/** @return array<string,string> */
	public function flat() {
		$out = array();
		foreach ( $this->groups() as $group ) {
			foreach ( (array) $group['tokens'] as $token => $label ) { $out[ $token ] = $label; }
		}
		return $out;
	}

	/** @return array<string,string> */
	public function sample_values() {
		$sample = array(
			'recipient.name' => __( 'Sara Khan', 'certloom' ), 'recipient.first_name' => __( 'Sara', 'certloom' ), 'recipient.middle_name' => '', 'recipient.last_name' => __( 'Khan', 'certloom' ), 'recipient.email' => 'sara@example.test', 'recipient.external_id' => 'STU-1042',
			'credential.display_number' => 'CL-2026-1042', 'credential.title' => __( 'Professional Leadership Credential', 'certloom' ), 'credential.public_id' => '9ZK4-2X7P', 'credential.issue_date' => '4 Sep 2026', 'credential.expiry_date' => '4 Sep 2028', 'credential.status' => __( 'Issued', 'certloom' ), 'credential.grade' => __( 'Distinction', 'certloom' ), 'score' => '92',
			'program.title' => __( 'Professional Leadership Program', 'certloom' ), 'program.name' => __( 'Professional Leadership Program', 'certloom' ), 'program.code' => 'PLP-2026', 'batch.name' => __( 'September 2026', 'certloom' ), 'batch.code' => 'SEP-26',
			'issuer.name' => __( 'Example Institute', 'certloom' ), 'issuer.title' => __( 'Credentialing Office', 'certloom' ), 'signer.name' => __( 'Dr. Maya Rahman', 'certloom' ), 'signer.title' => __( 'Program Director', 'certloom' ), 'signer.email' => 'maya@example.test',
			'verification.url' => '#', 'verification_link' => __( 'Verify this credential', 'certloom' ), 'site_name' => __( 'Example Institute', 'certloom' ),
		);
		$aliases = array(
			'recipient_name'=>'recipient.name','first_name'=>'recipient.first_name','middle_name'=>'recipient.middle_name','last_name'=>'recipient.last_name','credential_number'=>'credential.display_number','certificate_number'=>'credential.display_number','credential_title'=>'credential.title','certificate_status'=>'credential.status','program_title'=>'program.title','program_name'=>'program.name','course_name'=>'program.name','batch_name'=>'batch.name','batch_code'=>'batch.code','cohort_name'=>'batch.name','issuer_name'=>'issuer.name','issuer_title'=>'issuer.title','organization'=>'issuer.name','signer_name'=>'signer.name','signer_title'=>'signer.title','issue_date'=>'credential.issue_date','expiry_date'=>'credential.expiry_date','grade'=>'credential.grade','verification_url'=>'verification.url',
		);
		foreach ( $aliases as $alias => $canonical ) { $sample[ $alias ] = (string) ( $sample[ $canonical ] ?? '' ); }
		foreach ( $this->groups()['custom']['tokens'] ?? array() as $token => $label ) { $sample[ $token ] = sprintf( /* translators: %s: field label. */ __( 'Sample %s', 'certloom' ), $label ); }
		return $sample;
	}
}
