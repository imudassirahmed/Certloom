<?php
/** Alpha 4.31 public-profile accessibility: authenticated review preview without anonymous gate bypass. */
namespace {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
	$GLOBALS['pp_logged_in'] = false;
	$GLOBALS['pp_can_view'] = false;
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
	function current_time( $type ) { return '2026-09-12 00:00:00'; }
	function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?: time() ); }
	function get_option( $key, $default = false ) { if ( 'date_format' === $key ) return 'Y-m-d'; if ( 'time_format' === $key ) return 'H:i'; return $default; }
	function is_user_logged_in() { return (bool) $GLOBALS['pp_logged_in']; }
	function current_user_can( $capability ) { return (bool) $GLOBALS['pp_can_view'] && 'certloom_view_credentials' === $capability; }
	function home_url( $path = '/' ) { return 'https://example.test' . $path; }
	class WP_Error { private $code; private $message; public function __construct( $code = '', $message = '' ) { $this->code=$code; $this->message=$message; } public function get_error_code(){ return $this->code; } public function get_error_message(){ return $this->message; } }
	class Ecapla_CertVerify_Shortcode { public static function enqueue_assets() {} }
	class Ecapla_CertVerify_Permissions { const CAP_VIEW_CREDENTIALS = 'certloom_view_credentials'; }
}
namespace Certloom\Infrastructure\Verification {
	final class PublicVerificationGate { public static $ready = false; public function is_ready(){ return (bool) self::$ready; } }
}
namespace Certloom\Infrastructure\Migration {
	final class DomainFeatureGate { public static $preview = false; public static function admin_preview_ready(){ return (bool) self::$preview; } }
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
	\Certloom\Infrastructure\Migration\DomainFeatureGate::$preview = true;
	$GLOBALS['pp_logged_in'] = false; $GLOBALS['pp_can_view'] = false;
	$guest = \Ecapla_CertVerify_Render::normalized_credential_profile( 'ABCD-EFGH' );
	pp_check( false !== strpos( $guest, 'temporarily unavailable' ), 'anonymous visitor remains fail-closed before public cutover' );
	pp_check( false === strpos( $guest, 'Amina Khan' ), 'closed anonymous route does not disclose credential profile data' );

	$GLOBALS['pp_logged_in'] = true; $GLOBALS['pp_can_view'] = true;
	$preview = \Ecapla_CertVerify_Render::normalized_credential_profile( 'ABCD-EFGH' );
	pp_check( false !== strpos( $preview, 'Credential preview' ), 'authorized reviewer gets explicit preview eyebrow' );
	pp_check( false !== strpos( $preview, 'Admin preview' ) && false !== strpos( $preview, 'Amina Khan' ), 'authorized reviewer can inspect the normalized credential profile' );
	pp_check( false !== strpos( $preview, 'Current credential record' ) && false !== strpos( $preview, 'does not make public verification available to visitors' ), 'preview never claims public verification authority' );
	pp_check( false === strpos( $preview, 'Verified from the issuer' ) && false === strpos( $preview, 'Copy link' ), 'preview suppresses verified timestamp and public sharing action' );
	pp_check( false !== strpos( $preview, 'Print preview' ), 'preview keeps a safe local print action' );

	$GLOBALS['pp_can_view'] = false;
	$no_cap = \Ecapla_CertVerify_Render::normalized_credential_profile( 'ABCD-EFGH' );
	pp_check( false !== strpos( $no_cap, 'temporarily unavailable' ) && false === strpos( $no_cap, 'Amina Khan' ), 'logged-in user without credential-view capability cannot use preview bypass' );

	\Certloom\Infrastructure\Verification\PublicVerificationGate::$ready = true;
	\Certloom\Infrastructure\Migration\DomainFeatureGate::$preview = false;
	$GLOBALS['pp_logged_in'] = false;
	$verified = \Ecapla_CertVerify_Render::normalized_credential_profile( 'ABCD-EFGH' );
	pp_check( false !== strpos( $verified, 'Verified credential' ) && false !== strpos( $verified, 'Authentic credential record' ), 'accepted public gate renders the normal verified profile' );
	pp_check( false !== strpos( $verified, 'Verified from the issuer') && false !== strpos( $verified, 'Copy link' ), 'verified profile retains verification timestamp and shareable canonical link' );
	pp_check( false === strpos( $verified, 'Admin preview' ), 'verified public profile contains no review-only banner' );

	echo "\n11 public-profile preview-access checks, 0 failures\n";
}
