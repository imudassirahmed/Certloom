<?php
/** Alpha 4.42 public-profile access: healthy normalized credentials verify publicly without preview ceremony. */
namespace {
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
	function __( $text, $domain = null ) { return $text; }
	function _x( $text, $context, $domain = null ) { return $text; }
	function esc_html__( $text, $domain = null ) { return $text; }
	function esc_attr__( $text, $domain = null ) { return $text; }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $text ) { return (string) $text; }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
	function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
	function sanitize_textarea_field( $text ) { return trim( strip_tags( (string) $text ) ); }
	function sanitize_key( $text ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $text ) ); }
	function sanitize_html_class( $text ) { return sanitize_key( $text ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_unique_id( $prefix = '' ) { static $n = 0; return $prefix . ++$n; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function current_time( $type ) { return '2026-09-13 00:00:00'; }
	function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?: time() ); }
	function get_option( $key, $default = false ) { if ( 'date_format' === $key ) return 'Y-m-d'; if ( 'time_format' === $key ) return 'H:i'; return $default; }
	function home_url( $path = '/' ) { return 'https://example.test' . $path; }
	class WP_Error { private $code; private $message; public function __construct( $code = '', $message = '' ) { $this->code=$code; $this->message=$message; } public function get_error_code(){ return $this->code; } public function get_error_message(){ return $this->message; } }
	class Ecapla_CertVerify_Shortcode { public static function enqueue_assets() {} }
	class Ecapla_CertVerify_Settings { public static function get( $key, $default = '' ) { return $default; } }
}
namespace Certloom\Infrastructure\Verification {
	final class PublicVerificationGate { public static $ready = false; public function is_ready(){ return (bool) self::$ready; } }
}
namespace Certloom\Infrastructure\Repository { final class CredentialRepository {} }
namespace Certloom\Application {
	final class TestPreviewResult { public function public_id(){ return 'ABCDEFGH'; } public function credential(){ return array( 'snapshot' => array() ); } }
	final class CredentialVerificationService { public function __construct( $repo ){} public function verify_public_id( $id ){ return new TestPreviewResult(); } }
	final class PublicCredentialPresenter { public function present( $result, $context = 'verification' ){ return array( 'found'=>true, 'credential_id'=>'ABCD-EFGH', 'certificate_number'=>'CERT-000001', 'issued_at'=>'2026-09-01', 'expires_at'=>null, 'status'=>array('code'=>'valid'), 'fields'=>array(), 'profile'=>array('recipient_name'=>'Amina Khan','program_title'=>'Professional Certificate','issuer_name'=>'Ecapla') ); } }
}
namespace Certloom\Infrastructure\Issuance {
	final class NormalizedPublicCredentialUrl { public function verification_url( $id ){ return 'https://example.test/certloom/credential/id/ABCD-EFGH/'; } }
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-render.php';
	function pp_check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL  ' . $message ); } echo "PASS  {$message}\n"; }

	\Certloom\Infrastructure\Verification\PublicVerificationGate::$ready = false;
	$closed = \Ecapla_CertVerify_Render::normalized_credential_profile( 'ABCD-EFGH' );
	pp_check( false !== strpos( $closed, 'credential store is not ready' ), 'genuinely unavailable credential storage remains fail-closed' );
	pp_check( false === strpos( $closed, 'Amina Khan' ), 'storage failure does not disclose credential data' );
	pp_check( false === strpos( $closed, 'Admin preview' ), 'there is no authenticated preview bypass/banner anymore' );

	\Certloom\Infrastructure\Verification\PublicVerificationGate::$ready = true;
	$verified = \Ecapla_CertVerify_Render::normalized_credential_profile( 'ABCD-EFGH' );
	pp_check( false !== strpos( $verified, 'Verified credential' ) && false !== strpos( $verified, 'Authentic credential record' ), 'healthy normalized credential renders as normal verified public profile' );
	pp_check( false !== strpos( $verified, 'Amina Khan' ), 'verified public profile exposes only presenter-approved credential data' );
	pp_check( false !== strpos( $verified, 'Verified from the issuer') && false !== strpos( $verified, 'Copy link' ), 'verified profile retains verification timestamp and canonical sharing action' );
	pp_check( false !== strpos( $verified, 'Print' ), 'verified profile remains printable' );
	pp_check( false === strpos( $verified, 'Admin preview' ) && false === strpos( $verified, 'Credential preview' ), 'verified profile contains no development-only preview language' );

	echo "\n8 public-profile operational-access checks, 0 failures\n";
}
