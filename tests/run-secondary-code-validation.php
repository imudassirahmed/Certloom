<?php
/** Behavioral acceptance for reserved secondary-proof storage validation. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function okh( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS  {$message}\n"; }

require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-secondary-code.php';
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';

$field = array(
	'key'   => Ecapla_CertVerify_Secondary_Code::FIELD_KEY,
	'label' => 'Secondary verification proof',
	'type'  => 'text',
);
$code = '0123-4567-89AB-CDEF';
$hash = Ecapla_CertVerify_Secondary_Code::hash_from_input( $code );
okh( is_string( $hash ) && 0 === strpos( $hash, '$2y$10$' ), 'Certloom generates cost-10 bcrypt secondary proof' );
okh( $hash === Ecapla_CertVerify_Validator::custom_field_value( $hash, $field ), 'central validator accepts Certloom-generated bcrypt proof' );
okh( '' === Ecapla_CertVerify_Validator::custom_field_value( '', $field ), 'central validator accepts explicit empty proof for clear/unconfigured state' );

$invalid = Ecapla_CertVerify_Validator::custom_field_value( 'garbage', $field );
okh( is_wp_error( $invalid ) && 'ecapla_certverify_invalid_secondary_proof_hash' === $invalid->get_error_code(), 'central validator rejects arbitrary proof text' );
$sha = Ecapla_CertVerify_Validator::custom_field_value( hash( 'sha256', 'secret' ), $field );
okh( is_wp_error( $sha ), 'central validator rejects fast SHA-256 proof material' );
$weak_cost = password_hash( 'certloom-secondary-v1|0123456789ABCDEF', PASSWORD_BCRYPT, array( 'cost' => 4 ) );
okh( is_wp_error( Ecapla_CertVerify_Validator::custom_field_value( $weak_cost, $field ) ), 'central validator rejects weaker bcrypt cost not produced by Certloom' );
$high_cost = password_hash( 'certloom-secondary-v1|0123456789ABCDEF', PASSWORD_BCRYPT, array( 'cost' => 11 ) );
okh( is_wp_error( Ecapla_CertVerify_Validator::custom_field_value( $high_cost, $field ) ), 'central validator rejects unexpected higher-cost hash to prevent restore-time verification cost drift' );

$corrupt = array( 'custom_fields' => array( Ecapla_CertVerify_Secondary_Code::FIELD_KEY => 'not-a-bcrypt-hash' ) );
okh( Ecapla_CertVerify_Secondary_Code::has_code( $corrupt ), 'non-empty corrupt stored proof still requires verification fail-closed' );
okh( ! Ecapla_CertVerify_Secondary_Code::matches( $corrupt, $code ), 'corrupt stored proof cannot be satisfied and therefore cannot expose credential' );
okh( ! Ecapla_CertVerify_Secondary_Code::has_code( array( 'custom_fields' => array() ) ), 'missing proof remains backward-compatible unconfigured state' );

$backup_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-ecapla-certverify-backup.php' );
okh( is_string( $backup_source ) && false !== strpos( $backup_source, 'Ecapla_CertVerify_Validator::custom_field_value' ), 'complete backup restore validates reserved proof through the central custom-field validator' );
$presenter_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-ecapla-certverify-public-presentation.php' );
okh( is_string( $presenter_source ) && false !== strpos( $presenter_source, 'equalize_public_miss' ), 'public lookup miss path includes proof-timing equalization for valid-shaped codes' );

echo "\n12 tests, 0 failures\n";
