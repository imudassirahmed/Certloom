<?php
/** Catalog reads preserve ownership metadata and never repair corrupt policies into permissions. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code, $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $key, $default = false ) { return $GLOBALS['fcc_options'][ $key ] ?? $default; }
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function update_option() { throw new RuntimeException( 'Read-only catalog access must not write options.' ); }
require_once __DIR__ . '/bootstrap-autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-fields.php';
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;
$passed = 0; $failed = 0;
function fcc_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$policy = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'registration', 'writer' => 'recipient', 'collect_when' => 'registration', 'approval_required' => true, 'show_on_certificate' => false, 'help' => 'For this Program only.' );
$field = array( 'key' => 'candidate_registration', 'label' => 'Candidate registration', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', FieldCollectionPolicy::KEY => $policy );
$GLOBALS['fcc_options'] = array( Ecapla_CertVerify_Fields::OPTION_KEY => array( $field ) );
$catalog = Ecapla_CertVerify_Fields::get_field_map();
fcc_check( $policy === $catalog['candidate_registration'][ FieldCollectionPolicy::KEY ], 'canonical catalog preserves the complete collection policy' );
fcc_check( ! array_key_exists( FieldCollectionPolicy::KEY, $catalog['grade'] ), 'legacy core definitions retain their original shape and authority' );
fcc_check( true === Ecapla_CertVerify_Fields::validate_collection_policies( $catalog ), 'normalized active metadata validates before persistence' );
$scopes = new ScopedFieldContract();
$contract = $scopes->resolve( $scopes->normalize_program( array( 'candidate_registration' => 'required' ), $catalog ), null, $catalog );
fcc_check( ! is_wp_error( $contract ) && $policy === $contract['fields'][0][ FieldCollectionPolicy::KEY ], 'resolved requirements carry ownership policy for review and immutable snapshots' );
$original = $GLOBALS['fcc_options'];
$broken = $field; $broken[ FieldCollectionPolicy::KEY ]['_meta']['schema_version'] = 999;
$GLOBALS['fcc_options'][ Ecapla_CertVerify_Fields::OPTION_KEY ] = array( $broken );
Ecapla_CertVerify_Fields::reset_runtime_cache();
$catalog = Ecapla_CertVerify_Fields::get_field_map();
fcc_check( 999 === $catalog['candidate_registration'][ FieldCollectionPolicy::KEY ]['_meta']['schema_version'], 'a corrupt stored policy is retained for strict validation rather than discarded' );
fcc_check( is_wp_error( Ecapla_CertVerify_Fields::validate_collection_policies( $catalog ) ), 'unsupported policy versions fail canonical validation' );
fcc_check( is_wp_error( $scopes->resolve( $scopes->normalize_program( array( 'candidate_registration' => 'required' ), $catalog ), null, $catalog ) ), 'issuance cannot treat a broken explicit policy as a legacy field' );
$GLOBALS['fcc_options'] = array( Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY => array( 'candidate_registration' => $field ) );
Ecapla_CertVerify_Fields::reset_runtime_cache();
$retired = Ecapla_CertVerify_Fields::get_retired_fields();
fcc_check( $policy === $retired['candidate_registration'][ FieldCollectionPolicy::KEY ], 'retirement preserves prior ownership metadata' );
fcc_check( true === Ecapla_CertVerify_Fields::validate_collection_policies( $retired ), 'retired metadata is validated without making it an active definition' );
fcc_check( ! isset( Ecapla_CertVerify_Fields::get_field_map()['candidate_registration'] ), 'retired fields remain outside the active input catalog' );
echo "\n$passed catalog collection checks, $failed failures\n";
exit( $failed ? 1 : 0 );
