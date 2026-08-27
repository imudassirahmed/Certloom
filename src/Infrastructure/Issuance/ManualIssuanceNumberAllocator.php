<?php
namespace Certloom\Infrastructure\Issuance;

use Certloom\Application\Contract\ManualIssuanceNumberAllocatorInterface;
use Certloom\Infrastructure\Repository\CredentialRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shares the established ID-01/ID-02 numbering policy across legacy + normalized stores. */
final class ManualIssuanceNumberAllocator implements ManualIssuanceNumberAllocatorInterface {
	/** @var CredentialRepository */ private $credentials;
	public function __construct( ?CredentialRepository $credentials = null ) { $this->credentials = $credentials ?: new CredentialRepository(); }
	public function is_automatic() { return \Ecapla_CertVerify_Numbering::is_enabled(); }
	public function policy_identity() { return \Ecapla_CertVerify_Numbering::policy(); }
	/** @return array<string,mixed>|\WP_Error */
	public function review( $manual_number = '' ) {
		if ( $this->is_automatic() ) {
			return \Ecapla_CertVerify_Numbering::preview_candidate( array( $this, 'exists' ) );
		}
		$number = $this->normalize_manual( $manual_number );
		if ( is_wp_error( $number ) ) { return $number; }
		$exists = $this->exists( $number );
		if ( is_wp_error( $exists ) ) { return $exists; }
		if ( $exists ) { return new \WP_Error( 'certloom_manual_issue_number_duplicate', __( 'That certificate number is already in use.', 'certloom' ) ); }
		return array( 'certificate_number' => $number, 'mode' => 'manual', 'sample' => false, 'skipped_collisions' => 0 );
	}
	/** @return array<string,mixed>|\WP_Error */
	public function allocate( $manual_number = '' ) {
		if ( $this->is_automatic() ) { return \Ecapla_CertVerify_Numbering::allocate_candidate( array( $this, 'exists' ) ); }
		return $this->review( $manual_number );
	}
	/** @return bool|\WP_Error */
	public function exists( $number ) {
		$legacy = \Ecapla_CertVerify_Database::certificate_number_exists( (string) $number );
		if ( is_wp_error( $legacy ) ) { return $legacy; }
		if ( $legacy ) { return true; }
		$domain = $this->credentials->find_series_by_display_number( (string) $number );
		return is_wp_error( $domain ) ? $domain : is_array( $domain );
	}
	/** @return string|\WP_Error */
	private function normalize_manual( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( '' === $value || $length > 100 ) { return new \WP_Error( 'certloom_manual_issue_number_invalid', __( 'Enter a certificate number up to 100 characters, or enable automatic numbering.', 'certloom' ) ); }
		return $value;
	}
}
