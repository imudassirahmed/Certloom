<?php
/** Certificate names reuse personal-field authority; legacy names remain compatible. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $v ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
class Ecapla_CertVerify_Settings { public static $settings = array(); public static function get( $key, $default = '' ) { return self::$settings[ $key ] ?? $default; } }
use Certloom\Application\Recipient\CertificateNamePolicy;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;
$pass = 0; $fail = 0;
function cn( $ok, $message ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $message\n"; } else { ++$fail; echo "FAIL $message\n"; } }
$definition = array( 'key' => 'legal_name', 'label' => 'Legal name', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'profile', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'recipient', 'lifetime' => 'recipient', 'collect_when' => 'profile', 'approval_required' => true, 'show_on_certificate' => true, 'help' => '' ) );
$catalog = array( 'legal_name' => $definition ); $policy = new CertificateNamePolicy(); $global = ScopedFieldContract::empty_global();
cn( $global === $policy->apply_global( $global, $catalog ), 'default policy preserves the entire legacy global contract' );
cn( array( 'source' => 'approved_field', 'field_key' => 'legal_name' ) === $policy->normalize( 'approved_field', 'legal_name', $catalog ), 'exact approved reusable text name is accepted' );
foreach ( array( array( 'type', 'number' ), array( 'public_exposure', 'private' ), array( 'public_exposure', 'verification' ) ) as $change ) { $bad = $catalog; $bad['legal_name'][ $change[0] ] = $change[1]; cn( is_wp_error( $policy->normalize( 'approved_field', 'legal_name', $bad ) ), 'inappropriate certificate name semantics fail closed: ' . $change[0] . '-' . $change[1] ); }
foreach ( array( 'writer' => 'source', 'lifetime' => 'registration', 'collect_when' => 'before_issue', 'approval_required' => false, 'show_on_certificate' => false ) as $key => $value ) { $bad = $catalog; $bad['legal_name']['collection_policy'][ $key ] = $value; cn( is_wp_error( $policy->normalize( 'approved_field', 'legal_name', $bad ) ), 'name cannot bypass reusable recipient approval: ' . $key ); }
cn( is_wp_error( $policy->normalize( 'approved_field', 'unknown', $catalog ) ) && is_wp_error( $policy->normalize( 'anything', '', $catalog ) ), 'missing fields and unsupported policy sources are refused' );
$recipient = array( 'display_name' => 'Account nickname', 'custom_fields' => array( 'legal_name' => 'Raw candidate' ) );
cn( $recipient === $policy->project( $recipient, array(), $catalog ), 'profile names keep the original source and recipient record' );
Ecapla_CertVerify_Settings::$settings = array( 'certificate_name_source' => 'approved_field', 'certificate_name_field_key' => 'legal_name' );
$required = $policy->apply_global( $global, $catalog );
cn( ! is_wp_error( $required ) && array( 'legal_name' => 'required' ) === $required['fields'] && empty( $global['fields'] ), 'approved name becomes a required global fact without mutating saved requirements' );
$full = $global; $full_catalog = $catalog;
for ( $i = 0; $i < ScopedFieldContract::MAX_FIELDS; ++$i ) { $key = 'extra_' . $i; $full['fields'][ $key ] = 'optional'; $full_catalog[ $key ] = array_merge( $definition, array( 'key' => $key ) ); }
cn( is_wp_error( $policy->apply_global( $full, $full_catalog ) ), 'organization-wide name selection respects the global field capacity instead of producing an unreadable contract' );
cn( '' === $policy->project( $recipient, array(), $catalog )['display_name'], 'preview never substitutes a raw candidate or old account name for a missing approved value' );
$projected = $policy->project( $recipient, array( 'legal_name' => 'Amina Khan' ), $catalog );
cn( 'Amina Khan' === $projected['display_name'] && 'Account nickname' === $recipient['display_name'], 'typed approved value is projected without modifying the canonical recipient identity' );
foreach ( array( "Multi\nline", '<script>name</script>', "Bad\0name", str_repeat( '名', 256 ), "\xff" ) as $bad ) { cn( ! $policy->valid_name( $bad ), 'invalid names cannot enter certificate identity' ); }
cn( $policy->valid_name( str_repeat( '名', 255 ) ) && $policy->valid_name( 'محمد احمد' ), 'bounded international names are accepted without requiring mbstring' );
echo "\n$pass passed, $fail failures\n"; exit( $fail ? 1 : 0 );
