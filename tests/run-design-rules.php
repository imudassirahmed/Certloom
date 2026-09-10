<?php
/** Shared Design Rules manager safety and bulk-mutation contract tests. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Admin\DesignRuleManager;
use Certloom\Infrastructure\Email\EmailDesignPurposes;

$checks = 0;
function dr_ok( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } $checks++; }
function dr_same( $expected, $actual, $message ) { dr_ok( $expected === $actual, $message . ' expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) ); }

final class DRTargets {
	public $rows;
	public function __construct( array $rows ) { $this->rows = $rows; }
	public function find( $id ) { return $this->rows[ absint( $id ) ] ?? null; }
}
final class DRTemplates {
	public $assignments = array(); public $writes = 0; public $snapshot_error_id = 0;
	public $published = array( 91 => array( 'id' => 91, 'name' => 'Executive', 'published_revision' => array( 'layout' => array() ) ) );
	private function key( $scope, $id ) { return sanitize_key( $scope ) . ':' . absint( $id ); }
	public function get_assignment( $scope, $id ) {
		if ( $this->snapshot_error_id && absint( $id ) === $this->snapshot_error_id ) { return new WP_Error( 'snapshot_read_failed', 'snapshot read failed' ); }
		return $this->assignments[ $this->key( $scope, $id ) ] ?? null;
	}
	public function assign( $template_id, $scope, $id, $priority = 100, $expected_id = 0, $expected_updated_at = '' ) {
		$this->writes++; $key = $this->key( $scope, $id );
		$this->assignments[ $key ] = array( 'id' => $expected_id ?: ( 1000 + absint( $id ) ), 'template_id' => absint( $template_id ), 'priority' => absint( $priority ), 'active' => true, 'updated_at' => '2026-09-04 00:00:10' );
		return true;
	}
	public function clear_assignment( $scope, $id, $expected_id = 0, $expected_updated_at = '' ) {
		$this->writes++; $key = $this->key( $scope, $id ); $old = $this->assignments[ $key ] ?? array();
		$this->assignments[ $key ] = array( 'id' => $expected_id ?: ( $old['id'] ?? 1000 + absint( $id ) ), 'template_id' => absint( $old['template_id'] ?? 0 ), 'priority' => absint( $old['priority'] ?? 100 ), 'active' => false, 'updated_at' => '2026-09-04 00:00:11' );
		return true;
	}
	public function find_published( $id ) { return $this->published[ absint( $id ) ] ?? new WP_Error( 'missing', 'missing' ); }
	public function resolve_published_assignment_details( array $target ) { return array( 'template_id' => 1, 'template_name' => 'Inherited', 'scope_type' => 'global', 'scope_id' => 0, 'published_layout' => array() ); }
	public function resolve_published_assignment_details_excluding( array $target, $scope, $id ) { return array( 'template_id' => 1, 'template_name' => 'Inherited', 'scope_type' => 'global', 'scope_id' => 0, 'published_layout' => array() ); }
}
final class DREmails {
	public $designs = array();
	public function get( $id ) { return $this->designs[ sanitize_key( $id ) ] ?? null; }
}
final class DREmailRules {
	public $rows = array(); public $writes = 0; public $fail_save_once_for_id = 0;
	private function key( $scope, $id, $purpose ) { return sanitize_key( $purpose ) . '|' . sanitize_key( $scope ) . ':' . absint( $id ); }
	public function get( $scope, $id, $purpose ) { return $this->rows[ $this->key( $scope, $id, $purpose ) ] ?? null; }
	public function save( $scope, $id, $purpose, $design_id ) {
		$this->writes++;
		if ( $this->fail_save_once_for_id && absint( $id ) === $this->fail_save_once_for_id ) { $this->fail_save_once_for_id = 0; return new WP_Error( 'email_write_failed', 'email write failed' ); }
		$this->rows[ $this->key( $scope, $id, $purpose ) ] = array( 'scope_type' => $scope, 'scope_id' => absint( $id ), 'purpose' => sanitize_key( $purpose ), 'design_id' => sanitize_key( $design_id ), 'updated_at' => '2026-09-04 00:00:12' );
		return true;
	}
	public function remove( $scope, $id, $purpose ) { $this->writes++; unset( $this->rows[ $this->key( $scope, $id, $purpose ) ] ); return true; }
	public function resolve( $issuer_id, $program_id, $batch_id, $purpose ) { return array( 'design' => array( 'id' => 'fallback', 'name' => 'Fallback', 'purpose' => $purpose, 'active' => 1 ), 'scope_type' => 'global', 'scope_id' => 0, 'purpose' => $purpose ); }
	public function resolve_excluding( $issuer_id, $program_id, $batch_id, $purpose, $scope, $id ) { return $this->resolve( $issuer_id, $program_id, $batch_id, $purpose ); }
}
final class DRGuard {
	public $calls = 0; public function validate_global( $id ){ $this->calls++; return true; } public function validate_issuer( array $row, $id ){ $this->calls++; return true; } public function validate_program( array $row, $id ){ $this->calls++; return true; } public function validate_batch( array $program, array $batch, $id ){ $this->calls++; return true; }
}

function dr_manager( DRTemplates $templates, DREmails $emails, DREmailRules $rules, DRGuard $guard ) {
	$manager = new DesignRuleManager();
	$props = array(
		'templates' => $templates,
		'emails' => $emails,
		'email_rules' => $rules,
		'issuers' => new DRTargets( array( 1 => array( 'id' => 1, 'name' => 'Issuer One' ), 2 => array( 'id' => 2, 'name' => 'Issuer Two' ) ) ),
		'programs' => new DRTargets( array() ),
		'batches' => new DRTargets( array() ),
		'guard' => $guard,
	);
	$reflection = new ReflectionClass( $manager );
	foreach ( $props as $name => $value ) { $property = $reflection->getProperty( $name ); $property->setAccessible( true ); $property->setValue( $manager, $value ); }
	return $manager;
}

$templates = new DRTemplates(); $emails = new DREmails(); $rules = new DREmailRules(); $guard = new DRGuard();
$emails->designs['issued-modern'] = array( 'id' => 'issued-modern', 'name' => 'Issued Modern', 'purpose' => EmailDesignPurposes::ISSUED, 'active' => 1 );
$emails->designs['paused'] = array( 'id' => 'paused', 'name' => 'Paused', 'purpose' => EmailDesignPurposes::ISSUED, 'active' => 0 );
$emails->designs['revoked'] = array( 'id' => 'revoked', 'name' => 'Revoked', 'purpose' => 'credential_revoked', 'active' => 1 );
$manager = dr_manager( $templates, $emails, $rules, $guard );

$too_many = array_fill( 0, DesignRuleManager::MAX_TARGETS + 1, array( 'scope_type' => 'issuer', 'scope_id' => 1 ) );
$result = $manager->apply_many( $too_many, DesignRuleManager::MODE_DESIGN, 91, DesignRuleManager::MODE_NO_CHANGE, '', EmailDesignPurposes::ISSUED );
dr_ok( is_wp_error( $result ) && 'certloom_design_rule_target_limit' === $result->get_error_code(), 'bulk limit rejects instead of silently truncating' );
dr_same( 0, $templates->writes, 'limit rejection happens before writes' );

// Duplicate targets normalize to one mutation.
$result = $manager->apply_many( array( array( 'scope_type' => 'issuer', 'scope_id' => 1 ), array( 'scope_type' => 'issuer', 'scope_id' => 1 ) ), DesignRuleManager::MODE_DESIGN, 91, DesignRuleManager::MODE_NO_CHANGE, '', EmailDesignPurposes::ISSUED );
dr_same( true, $result, 'duplicate target bulk operation succeeds' );
dr_same( 1, $templates->writes, 'duplicate targets are written once' );

// Preview must tell the same truth as Apply.
$target = $manager->target( 'issuer', 1 );
$result = $manager->email_after( $target, DesignRuleManager::MODE_DESIGN, 'paused', EmailDesignPurposes::ISSUED );
dr_ok( is_wp_error( $result ) && 'certloom_design_rule_email_invalid' === $result->get_error_code(), 'preview rejects unpublished email design' );
$result = $manager->email_after( $target, DesignRuleManager::MODE_DESIGN, 'revoked', EmailDesignPurposes::ISSUED );
dr_ok( is_wp_error( $result ) && 'certloom_design_rule_email_invalid' === $result->get_error_code(), 'preview rejects wrong-purpose email design' );
$result = $manager->email_after( $target, DesignRuleManager::MODE_DESIGN, 'issued-modern', EmailDesignPurposes::ISSUED );
dr_ok( is_array( $result ) && 'issued-modern' === $result['design']['id'], 'preview accepts published same-purpose email design' );

// Snapshot failure aborts the whole selection before the first write.
$templates = new DRTemplates(); $templates->snapshot_error_id = 2; $emails2 = new DREmails(); $emails2->designs = $emails->designs; $rules2 = new DREmailRules(); $guard2 = new DRGuard(); $manager2 = dr_manager( $templates, $emails2, $rules2, $guard2 );
$result = $manager2->apply_many( array( array( 'scope_type' => 'issuer', 'scope_id' => 1 ), array( 'scope_type' => 'issuer', 'scope_id' => 2 ) ), DesignRuleManager::MODE_DESIGN, 91, DesignRuleManager::MODE_NO_CHANGE, '', EmailDesignPurposes::ISSUED );
dr_ok( is_wp_error( $result ) && 'snapshot_read_failed' === $result->get_error_code(), 'snapshot read error is returned' );
dr_same( 0, $templates->writes, 'no certificate mutation occurs before all snapshots are safe' );
dr_same( 0, $rules2->writes, 'no email mutation occurs before all snapshots are safe' );

// Failure after a certificate write restores all selected records, including a
// previously inactive direct certificate row (inheritance state).
$templates = new DRTemplates();
$templates->assignments['issuer:1'] = array( 'id' => 101, 'template_id' => 11, 'priority' => 100, 'active' => true, 'updated_at' => '2026-09-04 00:00:01' );
$templates->assignments['issuer:2'] = array( 'id' => 102, 'template_id' => 22, 'priority' => 100, 'active' => false, 'updated_at' => '2026-09-04 00:00:02' );
$emails3 = new DREmails(); $emails3->designs = $emails->designs;
$rules3 = new DREmailRules();
$rules3->rows['credential_issued|issuer:1'] = array( 'scope_type' => 'issuer', 'scope_id' => 1, 'purpose' => EmailDesignPurposes::ISSUED, 'design_id' => 'old-one', 'updated_at' => '2026-09-04 00:00:03' );
$rules3->rows['credential_issued|issuer:2'] = array( 'scope_type' => 'issuer', 'scope_id' => 2, 'purpose' => EmailDesignPurposes::ISSUED, 'design_id' => 'old-two', 'updated_at' => '2026-09-04 00:00:04' );
$rules3->fail_save_once_for_id = 2;
$manager3 = dr_manager( $templates, $emails3, $rules3, new DRGuard() );
$result = $manager3->apply_many( array( array( 'scope_type' => 'issuer', 'scope_id' => 1 ), array( 'scope_type' => 'issuer', 'scope_id' => 2 ) ), DesignRuleManager::MODE_DESIGN, 91, DesignRuleManager::MODE_DESIGN, 'issued-modern', EmailDesignPurposes::ISSUED );
dr_ok( is_wp_error( $result ) && 'email_write_failed' === $result->get_error_code(), 'original mutation error is preserved when rollback succeeds' );
dr_same( 11, $templates->assignments['issuer:1']['template_id'], 'first certificate assignment is restored' );
dr_ok( ! empty( $templates->assignments['issuer:1']['active'] ), 'first direct certificate rule is active again' );
dr_ok( empty( $templates->assignments['issuer:2']['active'] ), 'previously inherited second certificate remains inherited after rollback' );
dr_same( 'old-one', $rules3->rows['credential_issued|issuer:1']['design_id'], 'first email rule is restored' );
dr_same( 'old-two', $rules3->rows['credential_issued|issuer:2']['design_id'], 'second email rule is restored' );

$source = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Admin/DesignRuleManager.php' );
dr_ok( false === strpos( $source, 'array_slice( $targets, 0, 200 )' ), 'manager no longer silently truncates bulk targets' );
dr_ok( false !== strpos( $source, 'certloom_design_rule_rollback_incomplete' ), 'incomplete rollback is surfaced as a critical explicit error' );
dr_ok( false !== strpos( $source, 'Snapshot the complete selection before the first mutation' ), 'two-phase snapshot boundary is documented in source' );

echo "PASS  Design Rules bulk changes preflight and snapshot the complete selection before mutation\n";
echo "PASS  Design Rules previews enforce the same published/purpose contract as Apply and rollback preserves inheritance semantics\n\n";
echo $checks . " checks, 0 failures\n";
