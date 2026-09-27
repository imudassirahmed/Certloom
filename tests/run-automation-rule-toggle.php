<?php
/** Check that an existing automation can be paused and resumed without losing its scope. */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/fake-wp/' ); }
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $text, $domain = '' ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['automation_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['automation_options'][ $key ] = $value; return true; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $format, $gmt = false ) { return '2026-09-24 00:00:00'; }
function wp_generate_password( $length, $special = true, $extra = false ) { return str_repeat( 'a', $length ); }
require_once dirname( __DIR__ ) . '/src/Infrastructure/Email/EmailAutomationRuleRepository.php';

$repo = new Certloom\Infrastructure\Email\EmailAutomationRuleRepository();
$checks = 0;
$fails = 0;
$check = static function ( $condition, $label ) use ( &$checks, &$fails ) {
	++$checks;
	if ( ! $condition ) { ++$fails; }
	echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
};
$id = $repo->save( array( 'id' => 'test-rule', 'name' => 'QA delivery', 'active' => false, 'program_id' => 42, 'source_type' => 'manual_admin', 'legacy_template_id' => 'historical-design' ) );
$check( 'test-rule' === $id, 'inactive scoped rule is saved' );
$check( array() === $repo->matching( 0, 42, 0, 'manual_admin' ), 'inactive rule does not send' );
$check( 'test-rule' === $repo->set_active( $id, true ), 'existing rule can be turned on' );
$active = $repo->all()[0];
$check( 1 === $active['active'] && 42 === $active['program_id'] && 'historical-design' === $active['legacy_template_id'], 'turning on preserves targeting and historical design fallback' );
$check( 1 === count( $repo->matching( 0, 42, 0, 'manual_admin' ) ), 'active matching rule is eligible' );
$check( 'test-rule' === $repo->set_active( $id, false ) && array() === $repo->matching( 0, 42, 0, 'manual_admin' ), 'pausing immediately removes delivery eligibility' );
$missing = $repo->set_active( 'missing-rule', true );
$check( is_wp_error( $missing ) && 'certloom_email_rule_missing' === $missing->get_error_code() && 1 === count( $repo->all() ), 'unknown rule cannot be created by toggle' );
echo "\n{$checks} automation toggle checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
