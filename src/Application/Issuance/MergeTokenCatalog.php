<?php
namespace Certloom\Application\Issuance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical scalar merge vocabulary shared by authoring surfaces and CI.
 *
 * These are immutable issuance-snapshot facts, not live model reads. Custom
 * credential fields are appended by the field catalog at authoring time.
 */
final class MergeTokenCatalog {
	/** @return array<string,array{label:string,tokens:array<string,string>}> */
	public static function groups() {
		return array(
			'recipient' => array(
				'label' => __( 'Recipient', 'certloom' ),
				'tokens' => array(
					'recipient.name' => __( 'Recipient name', 'certloom' ),
					'recipient.first_name' => __( 'First name', 'certloom' ),
					'recipient.middle_name' => __( 'Middle name', 'certloom' ),
					'recipient.last_name' => __( 'Last name', 'certloom' ),
					'recipient.email' => __( 'Recipient email', 'certloom' ),
					'recipient.external_id' => __( 'Recipient external ID', 'certloom' ),
				),
			),
			'credential' => array(
				'label' => __( 'Credential', 'certloom' ),
				'tokens' => array(
					'credential.display_number' => __( 'Credential number', 'certloom' ),
					'credential.title' => __( 'Credential title', 'certloom' ),
					'credential.public_id' => __( 'Public credential ID', 'certloom' ),
					'credential.issue_date' => __( 'Effective date', 'certloom' ),
					'credential.expiry_date' => __( 'Expiry date', 'certloom' ),
					'credential.status' => __( 'Credential status', 'certloom' ),
					'credential.grade' => __( 'Grade / result', 'certloom' ),
					'score' => __( 'Score', 'certloom' ),
				),
			),
			'program' => array(
				'label' => __( 'Program & batch', 'certloom' ),
				'tokens' => array(
					'program.name' => __( 'Program name', 'certloom' ),
					'program.title' => __( 'Program title', 'certloom' ),
					'program.code' => __( 'Program code', 'certloom' ),
					'batch.name' => __( 'Batch name', 'certloom' ),
					'batch.code' => __( 'Batch code', 'certloom' ),
				),
			),
			'authority' => array(
				'label' => __( 'Issuer & signer', 'certloom' ),
				'tokens' => array(
					'issuer.name' => __( 'Issuer name', 'certloom' ),
					'issuer.title' => __( 'Issuer title', 'certloom' ),
					'signer.name' => __( 'Signer name', 'certloom' ),
					'signer.title' => __( 'Signer title', 'certloom' ),
					'signer.email' => __( 'Signer email', 'certloom' ),
				),
			),
			'verification' => array(
				'label' => __( 'Verification & site', 'certloom' ),
				'tokens' => array(
					'verification.url' => __( 'Verification URL', 'certloom' ),
					'site_name' => __( 'Site name', 'certloom' ),
				),
			),
		);
	}

	/** @return array<string,string> */
	public static function scalar_tokens() {
		$out = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group['tokens'] as $token => $label ) { $out[ $token ] = $label; }
		}
		return $out;
	}

	/** @return string[] */
	public static function table_sources() {
		return array( 'credential.results', 'credential.subjects' );
	}
}
