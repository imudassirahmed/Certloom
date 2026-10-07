<?php
/** Guest-visible certificate canvas must obey frozen/current field privacy. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
function __( $v ) { return $v; } function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function sanitize_html_class( $v ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( $v ) { return esc_attr( $v ); } function esc_html__( $v ) { return esc_html( $v ); } function esc_attr__( $v ) { return esc_attr( $v ); }
function wp_unique_id( $prefix = '' ) { static $id = 0; return $prefix . ++$id; }
class Ecapla_CertVerify_Fields { public static $map = array(); public static function get_field_map() { return self::$map; } }
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-render.php';
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
$method = new ReflectionMethod( Ecapla_CertVerify_Render::class, 'normalized_certificate_preview_html' );
if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
$layout = json_decode( file_get_contents( __DIR__ . '/fixtures/rendering/english.json' ), true );
$layout['elements'][0]['token'] = 'recipient.email'; $layout = ( new LayoutValidator() )->normalize( $layout );
$json = new CanonicalJson(); $merge = array( 'recipient.email' => 'private-person@example.test' );
$field = array( 'key' => 'email', 'label' => 'Email', 'type' => 'text', 'public_exposure' => 'private', 'sensitivity' => 'personal', 'locked_visibility' => false );
$snapshot = array( 'template' => array( 'layout' => $layout, 'layout_sha256' => $json->hash( $layout ) ), 'merge' => array( 'values' => $merge, 'sha256' => $json->hash( $merge ) ), 'credential' => array( 'verification_url' => 'https://example.test/credential/TEST' ), 'fields' => array( 'contract' => array( 'fields' => array( $field ) ), 'values' => array( 'email' => $merge['recipient.email'] ) ) );
Ecapla_CertVerify_Fields::$map = array( 'email' => $field ); $checks = 0; $failures = 0;
function canvas_privacy_check( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
function canvas_html( array $snapshot ) { global $method; return $method->invoke( null, $snapshot ); }
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'private-person@example.test' ), 'private email never reaches guest certificate HTML' );
$snapshot['fields']['contract']['fields'][0]['public_exposure'] = 'both'; Ecapla_CertVerify_Fields::$map['email']['public_exposure'] = 'both';
canvas_privacy_check( false !== strpos( canvas_html( $snapshot ), 'private-person@example.test' ), 'frozen and current public permission both allow the same printed field' );
Ecapla_CertVerify_Fields::$map['email']['public_exposure'] = 'private';
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'private-person@example.test' ), 'tightening current policy immediately removes private visual disclosure' );
Ecapla_CertVerify_Fields::$map['email']['public_exposure'] = 'both'; $snapshot['fields']['contract']['fields'][0]['public_exposure'] = 'private';
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'private-person@example.test' ), 'loosening current policy cannot widen the frozen issuance ceiling' );
$snapshot['fields']['contract']['fields'][0]['public_exposure'] = 'both'; Ecapla_CertVerify_Fields::$map['email']['locked_visibility'] = true;
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'private-person@example.test' ), 'locked/internal field policy also protects the canvas' );
Ecapla_CertVerify_Fields::$map['email']['locked_visibility'] = false; $snapshot['merge']['values']['recipient.email'] = 'unapproved@example.test'; $snapshot['merge']['sha256'] = $json->hash( $snapshot['merge']['values'] );
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'unapproved@example.test' ), 'public field permission cannot authorize a different merge value' );
$layout['elements'][0]['token'] = 'recipient.name'; $snapshot['template']['layout'] = $layout; $snapshot['template']['layout_sha256'] = $json->hash( $layout ); $snapshot['merge']['values'] = array( 'recipient.name' => 'Public Recipient' ); $snapshot['merge']['sha256'] = $json->hash( $snapshot['merge']['values'] );
canvas_privacy_check( false !== strpos( canvas_html( $snapshot ), 'Public Recipient' ), 'basic public certificate names remain available without optional field contracts' );
$snapshot['merge']['values']['recipient.name'] = 'CORRUPTED-MERGE';
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'CORRUPTED-MERGE' ), 'public canvas rejects altered snapshot data before rendering' );
$layout['_meta']['schema_version'] = 4; $layout['elements'][0]['type'] = 'rich_text'; unset( $layout['elements'][0]['token'] ); $layout['elements'][0]['runs'] = array( array( 'kind' => 'text', 'text' => 'Contact: ' ), array( 'kind' => 'token', 'token' => 'recipient.email' ) );
$layout = ( new LayoutValidator() )->normalize( $layout ); $snapshot['template']['layout'] = $layout; $snapshot['template']['layout_sha256'] = $json->hash( $layout ); $snapshot['merge']['values'] = array( 'recipient.email' => 'PRIVATE-INLINE@example.test' ); $snapshot['merge']['sha256'] = $json->hash( $snapshot['merge']['values'] );
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'PRIVATE-INLINE@example.test' ), 'inline rich-text tokens obey the same public privacy boundary' );
$layout['elements'][0]['runs'][1]['token'] = 'custom:printed_level'; $snapshot['template']['layout'] = $layout; $snapshot['template']['layout_sha256'] = $json->hash( $layout );
$snapshot['merge']['values'] = array( 'custom:printed_level' => 'Public Gold' ); $snapshot['merge']['sha256'] = $json->hash( $snapshot['merge']['values'] );
$field = array( 'key' => 'level', 'label' => 'Level', 'type' => 'text', 'public_exposure' => 'both', 'sensitivity' => 'personal' );
Ecapla_CertVerify_Fields::$map = array( 'level' => $field ); $snapshot['fields'] = array( 'contract' => array( 'fields' => array( $field ) ), 'values' => array( 'level' => 'Public Gold' ) ); $snapshot['issuance']['design_field_aliases'] = array( 'printed_level' => 'level' );
canvas_privacy_check( false !== strpos( canvas_html( $snapshot ), 'Public Gold' ), 'an explicitly bound public custom field remains visible in rich text' );
Ecapla_CertVerify_Fields::$map['level']['public_exposure'] = 'verification';
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'Public Gold' ), 'verification-only custom permission cannot authorize a public profile canvas' );
unset( Ecapla_CertVerify_Fields::$map['level'] );
canvas_privacy_check( false === strpos( canvas_html( $snapshot ), 'Public Gold' ), 'retiring a field removes its visual exposure immediately' );
echo "$checks checks, $failures failures\n"; exit( $failures ? 1 : 0 );
