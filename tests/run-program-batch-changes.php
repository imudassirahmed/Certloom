<?php
namespace Certloom\Infrastructure\Database {
	class Transaction { public static $owns = true; public static function owns_current() { return self::$owns; } }
}
namespace Certloom\Infrastructure\Admin {
	class ScopedFieldConfigurationGuard { public function validate_batch_change( ...$args ) { $GLOBALS['guards'][] = 'fields'; return $GLOBALS['guard_error'] ?? true; } }
	class IntegrationConfigurationDependencyGuard { public function validate_batch_context_change( ...$args ) { $GLOBALS['guards'][] = 'integration'; return true; } }
	class DesignHierarchyConfigurationGuard { public function validate_batch( ...$args ) { $GLOBALS['guards'][] = 'design'; return true; } }
}
namespace Certloom\Infrastructure\Repository {
	class BatchRepository {
		public static $rows = array(); public static $locks = array(); public static $writes = array(); public static $scope;
		public function find( $id, $lock = false ) { if ( $lock ) { self::$locks[] = $id; } return self::$rows[$id] ?? null; }
		public function update( $id, $data ) { self::$writes[$id] = $data; return true; }
		public function create( $data ) { self::$writes[99] = $data; return 99; }
		public function list_page( $s, $page, $size, $status, $scope ) { self::$scope = array( $page, $size, $scope ); return array( 'items' => self::$rows, 'total' => count( self::$rows ) ); }
		public function configuration_choices( $scope, $ids ) { return array( 11 => array( 'batch_template_id' => 9, 'signing' => true ) ); }
	}
	class TemplateRepository {
		public static $assignment = null; public static $saved = array();
		public function get_assignment( ...$args ) { return self::$assignment; }
		public function assign( ...$args ) { self::$saved[] = $args; return true; }
		public function clear_assignment( ...$args ) { self::$saved[] = $args; return true; }
	}
	class ProgramRepository { public function find( $id ) { return 7 === $id ? $GLOBALS['program'] : null; } }
	class SignerRepository { public function find( $id ) { return 4 === $id ? array( 'id' => 4 ) : null; } }
}
namespace Certloom\Infrastructure\Email {
	class EmailDesignAssignmentRepository { public function all() { return array( array( 'scope_type' => 'batch', 'scope_id' => 12, 'purpose' => EmailDesignPurposes::ISSUED ) ); } }
}
namespace {
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, ...$args ) { $this->code = $code; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->code; } }
class Ecapla_CertVerify_Permissions { const CAP_MANAGE_PROGRAMS = 'programs'; const CAP_MANAGE_BATCHES = 'batches'; const CAP_MANAGE_TEMPLATES = 'templates'; }
class Ecapla_CertVerify_Fields { public static function get_field_map() { return array( 'score' => array( 'key' => 'score', 'type' => 'text' ) ); } }
class WP_REST_Request implements ArrayAccess { public $values; public function __construct( $values ) { $this->values = $values; } public function offsetExists( $k ): bool { return isset( $this->values[$k] ); } public function offsetGet( $k ): mixed { return $this->values[$k] ?? null; } public function offsetSet( $k, $v ): void {} public function offsetUnset( $k ): void {} public function get_route() { return '/certloom/v1/admin/programs/7/batches'; } }
class WP_HTTP_Response { public $headers = array(); public function header( $key, $value, $replace = true ) { $this->headers[$key] = $value; } }
function __( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $cap ) { return $GLOBALS['caps'][$cap] ?? false; }
function is_user_logged_in() { return true; }
function rest_ensure_response( $v ) { return $v; }
use Certloom\Infrastructure\Admin\ProgramBatchChanges;
use Certloom\Infrastructure\Admin\ProgramWorkspaceRest;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Application\Fields\ScopedFieldContract;
$checks = 0;
function check( $test, $label ) { global $checks; if ( ! $test ) { throw new RuntimeException( $label ); } ++$checks; }
$caps = array( 'programs' => true, 'batches' => true, 'templates' => true ); $guards = array();
$contract = new ScopedFieldContract();
$program = array( 'id' => 7, 'active' => true, 'field_contract' => $contract->normalize_program( array( 'score' => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ) );
$batch = array( 'id' => 11, 'program_id' => 7, 'name' => 'Existing intake', 'code' => 'MBA-26', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'active' => true, 'instructor_signer_id' => null, 'metadata' => array( 'nested' => array( 'preserve' => 'exactly' ) ), 'field_contract' => ScopedFieldContract::empty_batch() );
BatchRepository::$rows[11] = $batch; BatchRepository::$rows[12] = array_merge( $batch, array( 'id' => 12 ) );
$item = array( 'id' => 11, 'revision' => ProgramBatchChanges::revision( $batch, null ), 'patch' => array( 'start_date' => '2026-02-01' ) );
$service = new ProgramBatchChanges();
check( ! is_wp_error( ProgramBatchChanges::decode( wp_json_encode( array( $item ) ) ) ), 'Valid bounded changes decode.' );
foreach ( array( null, 'invalid JSON', str_repeat( ' ', 131073 ), wp_json_encode( array_fill( 0, 26, $item ) ), wp_json_encode( array( $item, $item ) ), wp_json_encode( array( array_merge( $item, array( 'id' => '11' ) ) ) ), wp_json_encode( array( array_merge( $item, array( 'patch' => array( 'program_id' => 8 ) ) ) ) ), wp_json_encode( array( array_merge( $item, array( 'patch' => array( 'batch_field_requirement' => array( 'score' => array( 'required' ) ) ) ) ) ) ), wp_json_encode( array( array_merge( $item, array( 'patch' => array( 'active' => 'anything' ) ) ) ) ), wp_json_encode( array( array_merge( $item, array( 'patch' => array( 'batch_template_id' => '-1' ) ) ) ) ) ) as $invalid ) { check( is_wp_error( ProgramBatchChanges::decode( $invalid ) ), 'Malformed or unbounded changes fail closed.' ); }
Transaction::$owns = false; check( is_wp_error( $service->persist( $program, array( $item ) ) ), 'Writes require an owning transaction.' ); Transaction::$owns = true;
check( is_wp_error( $service->persist_default( $program, str_repeat( '9', 80 ) ) ), 'Oversized numeric default identities are rejected before lookup or coercion.' );
$caps['batches'] = false; check( is_wp_error( $service->persist( $program, array( $item ) ) ), 'Program permission alone does not permit batch edits.' ); $caps['batches'] = true;
check( ! is_wp_error( $service->persist( $program, array( $item ) ) ), 'Date-only change uses canonical guarded parser.' );
check( array( 'fields', 'integration', 'design' ) === $guards, 'All batch dependency guards run.' );
$write = BatchRepository::$writes[11];
check( $write['name'] === $batch['name'] && $write['end_date'] === $batch['end_date'] && $write['metadata'] === $batch['metadata'], 'Date-only patch preserves name, other dates, structured metadata and unconfigured field envelope.' );
check( $write['active'] === true && $write['start_date'] === '2026-02-01', 'Explicit date changes apply without changing active status.' );
BatchRepository::$writes = array();
$stale = $item; $stale['revision'] = str_repeat( '0', 64 ); check( is_wp_error( $service->persist( $program, array( $stale ) ) ) && ! BatchRepository::$writes, 'Stale revisions prevent any batch write.' );
TemplateRepository::$assignment = array( 'active' => true, 'template_id' => 9, 'updated_at' => 'later' ); check( is_wp_error( $service->persist( $program, array( $item ) ) ), 'Concurrent certificate assignment is part of the revision conflict.' ); TemplateRepository::$assignment = null;
$foreign = $program; $foreign['id'] = 8; check( is_wp_error( $service->persist( $foreign, array( $item ) ) ), 'Foreign Program batches are rejected.' );
$configured = $batch; $configured['field_contract'] = $contract->normalize_batch( array( 'score' => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ); BatchRepository::$rows[11] = $configured;
$item['revision'] = ProgramBatchChanges::revision( $configured, null ); $item['patch'] = array( 'active' => false );
check( ! is_wp_error( $service->persist( $program, array( $item ) ) ), 'Inactive bulk patches save.' );
check( false === BatchRepository::$writes[11]['active'] && BatchRepository::$writes[11]['metadata'][ScopedFieldContract::STORAGE_KEY] === $configured['field_contract'], 'Inactive patch preserves the configured batch contract.' );
$item['patch'] = array( 'batch_field_requirement' => array( 'score' => 'exclude' ) ); check( is_wp_error( $service->persist( $program, array( $item ) ) ), 'Batch cannot exclude a Program-required field.' );
$item['patch'] = array( 'batch_template_id' => 9 ); $caps['templates'] = false; check( is_wp_error( $service->persist( $program, array( $item ) ) ), 'Design changes require design permission.' ); $caps['templates'] = true;
$item['patch'] = array( 'active' => true ); $guard_error = new WP_Error( 'dependency_blocked' ); check( $guard_error === $service->persist( $program, array( $item ) ), 'Canonical configuration failures propagate.' ); unset( $guard_error );
$second = array( 'id' => 12, 'revision' => ProgramBatchChanges::revision( BatchRepository::$rows[12], null ), 'patch' => array( 'active' => false ) ); BatchRepository::$locks = array(); $service->persist( $program, array( $second, $item ) ); check( array( 11, 12 ) === BatchRepository::$locks, 'Bulk changes lock batch rows in deterministic order.' );
$new = array( 'id' => 0, 'key' => 'new-intake', 'patch' => array( 'name' => 'New intake', 'active' => true ) ); check( ! is_wp_error( $service->persist( $program, array( $new ) ) ) && 7 === BatchRepository::$writes[99]['program_id'], 'New batches always inherit the owning Program identity.' );
$rest = new ProgramWorkspaceRest(); check( $rest->permission(), 'Both management capabilities allow REST reads.' ); $caps['programs'] = false; check( ! $rest->permission(), 'Batch-only roles cannot use Program REST.' ); $caps['programs'] = true;
$result = $rest->read( new WP_REST_Request( array( 'program_id' => 7, 'batch_id' => 0, 'page' => 2, 'search' => '' ) ) ); check( array( 2, 25, 7 ) === BatchRepository::$scope && ! isset( $result['items'][0]['metadata'] ), 'REST lists are bounded, Program scoped, and omit technical metadata.' );
check( $result['items'][0]['settings'] === array( 'certificate' => true, 'email' => false, 'signing' => true ) && $result['items'][1]['settings']['email'], 'Batch summary preserves direct certificate, issued email and signing sources.' );
$result = $rest->read( new WP_REST_Request( array( 'program_id' => 7, 'batch_id' => 11 ) ) ); check( $result['revision'] === $item['revision'] && ! isset( $result['patch']['metadata'] ), 'Popup REST payload contains exact revision and editable fields only.' );
BatchRepository::$rows[11]['program_id'] = 8; check( is_wp_error( $rest->read( new WP_REST_Request( array( 'program_id' => 7, 'batch_id' => 11 ) ) ) ), 'REST rejects cross-Program detail requests.' );
$response = $rest->private_headers( new WP_HTTP_Response(), null, new WP_REST_Request( array() ) ); check( 'private, no-store, max-age=0' === $response->headers['Cache-Control'], 'Private headers also cover denied/error REST responses.' );
echo "$checks checks, 0 failures\n";
}
