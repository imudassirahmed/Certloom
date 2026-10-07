<?php
/** Actual Program form parser isolates Fields/Details while retaining canonical guards. */
namespace Certloom\Infrastructure\Admin {
	class ScopedFieldConfigurationGuard { public function validate_program_change( ...$args ) { $GLOBALS['workspace_guards'][] = 'fields'; return $GLOBALS['workspace_guard_error'] ?? true; } }
	class IntegrationConfigurationDependencyGuard { public function validate_program_context_change( ...$args ) { $GLOBALS['workspace_guards'][] = 'integrations'; return true; } }
	class DesignHierarchyConfigurationGuard { public function validate_program( ...$args ) { $GLOBALS['workspace_guards'][] = 'design'; return true; } }
}
namespace Certloom\Infrastructure\Repository {
	class TemplateRepository { public function get_assignment( ...$args ) { return array( 'active' => true, 'template_id' => 44 ); } }
}
namespace {
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
class Ecapla_CertVerify_Fields { public static $map; public static function get_field_map() { return self::$map; } }
class Ecapla_CertVerify_Validator { public static function custom_field_value( $v, $field ) { return (string) $v; } }
function __( $s ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return sanitize_text_field( $v ); }
function wp_unslash( $v ) { return $v; }
function get_locale() { return 'en_US'; }
function get_option( $key, $default = false ) { return $default; }
use Certloom\Infrastructure\Admin\DomainAdmin;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Program\ProgramProfile;

$checks = 0;
function section_check( $ok, $label ) { global $checks; if ( ! $ok ) { throw new \RuntimeException( $label ); } ++$checks; }
$catalog = array( 'student_id' => array( 'key' => 'student_id', 'label' => 'Student ID', 'type' => 'text' ), 'campus' => array( 'key' => 'campus', 'label' => 'Campus', 'type' => 'text', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'staff', 'lifetime' => 'program', 'collect_when' => 'never', 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) ) );
Ecapla_CertVerify_Fields::$map = $catalog;
$contract = new ScopedFieldContract();
$profile = ProgramProfile::defaults();
$existing = array( 'id' => 7, 'issuer_id' => null, 'title' => 'MBA', 'code' => 'MBA', 'description' => 'Stored description', 'active' => true, 'validity_days' => null, 'profile' => $profile, 'field_contract' => $contract->normalize_program( array( 'student_id' => 'required' ), $catalog ), 'metadata' => array( 'structured' => array( 'keep' => 'exact' ), ProgramFieldDefaults::KEY => array( '_meta' => array( 'schema' => ProgramFieldDefaults::SCHEMA, 'schema_version' => 1 ), 'values' => array( 'campus' => 'Main' ) ) ) );
$admin = new DomainAdmin();
$method = new ReflectionMethod( $admin, 'program_request' );
$workspace_guards = array();
$_POST = array( 'program_form_complete' => '1', 'program_field_requirement' => array( 'student_id' => 'optional', 'campus' => '' ), 'title' => 'Injected title', 'profile' => array( 'invalid' => true ), 'program_template_id' => '999', 'program_metadata_present' => '1', 'program_meta_key' => array( 'erase' ), 'program_meta_value' => array( 'injected' ) );
$data = $method->invoke( $admin, $existing, 'fields' );
section_check( ! is_wp_error( $data ) && array( 'metadata' ) === array_keys( $data ), 'Field save emits only the metadata patch, excluding core Program properties.' );
section_check( $data['metadata']['structured'] === $existing['metadata']['structured'], 'Field save preserves current structured technical metadata.' );
section_check( $data['metadata'][ ProgramFieldDefaults::KEY ] === $existing['metadata'][ ProgramFieldDefaults::KEY ], 'Omitted field defaults preserve their stored values.' );
section_check( $contract->from_program_metadata( $data['metadata'], $catalog )['fields']['student_id'] === 'optional', 'Field editor changes its own requirement.' );
section_check( ( new ProgramProfile() )->from_metadata( $data['metadata'] ) === ( new ProgramProfile() )->normalize( $profile ), 'Field save preserves the stored Program profile.' );
section_check( array( 'fields', 'integrations', 'design' ) === $workspace_guards, 'Field saves run all existing dependency guards.' );
$_POST = array( 'program_form_complete' => '1', 'title' => 'MBA revised', 'code' => 'MBA', 'description' => 'Revised description', 'active' => '1', 'profile' => $profile, 'program_field_requirement' => array( 'student_id' => 'exclude' ), 'program_field_defaults_present' => '1', 'program_field_default' => array( 'campus' => 'Injected' ) );
$data = $method->invoke( $admin, $existing, 'details' );
section_check( ! is_wp_error( $data ) && 'MBA revised' === $data['title'], 'Details save changes its own title.' );
section_check( $contract->from_program_metadata( $data['metadata'], $catalog ) === $existing['field_contract'], 'Details save preserves the current field contract despite unrelated posted controls.' );
section_check( $data['metadata'][ ProgramFieldDefaults::KEY ] === $existing['metadata'][ ProgramFieldDefaults::KEY ], 'Details save cannot overwrite stored field defaults.' );
$unconfigured = $existing; $unconfigured['field_contract'] = ScopedFieldContract::empty_program();
$unchanged = $method->invoke( $admin, $unconfigured, 'details' );
section_check( ! is_wp_error( $unchanged ) && $contract->from_program_metadata( $unchanged['metadata'], $catalog ) === $unconfigured['field_contract'], 'Details save preserves an unconfigured legacy field contract rather than activating a new one.' );
foreach ( array( 'fields', 'details' ) as $section ) { $_POST = array(); $error = $method->invoke( $admin, $existing, $section ); section_check( is_wp_error( $error ) && 'certloom_program_form_incomplete' === $error->get_error_code(), 'Truncated section form is rejected: ' . $section ); }
$_POST = array( 'program_form_complete' => '1', 'program_field_requirement' => array( 'student_id' => array( 'required' ) ) );
section_check( is_wp_error( $method->invoke( $admin, $existing, 'fields' ) ), 'Malformed field rules are rejected without casting diagnostics.' );
$_POST = array( 'program_form_complete' => '1', 'program_field_requirement' => array( 'student_id' => 'optional' ) );
$workspace_guard_error = new WP_Error( 'dependency_blocked' );
section_check( $workspace_guard_error === $method->invoke( $admin, $existing, 'fields' ), 'Canonical dependency failure prevents a field patch.' );
echo "$checks checks, 0 failures\n";
}
