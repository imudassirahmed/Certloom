<?php
/** Guided choices must preserve authority, concurrent changes and unrelated Program data. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $v ) { return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( str_replace( ' ', '-', $v ) ); }
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
use Certloom\Application\Setup\WizardFieldSetup;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Application\Fields\ScopedFieldContract;
$planner = new WizardFieldSetup(); $checks = 0;
function check_setup( $ok, $name ) { global $checks; if ( ! $ok ) { throw new RuntimeException( $name ); } ++$checks; echo "PASS  $name\n"; }
$catalog = array();
foreach ( array( 'score' => 'number', 'existing_detail' => 'text', 'first_name' => 'text', 'cert_number' => 'text' ) as $key => $type ) { $catalog[ $key ] = $planner->new_definition( $key, $key, $type, count( $catalog ) ); }
$program = array( 'id' => 42, 'title' => 'Course', 'code' => 'C', 'active' => true, 'metadata' => array( 'nested_integration' => array( 'untouched' => true ) ), 'field_contract' => ( new ScopedFieldContract() )->normalize_program( array( 'existing_detail' => 'required' ), $catalog ) );
$collection = array( 'writer' => 'recipient', 'lifetime' => 'recipient', 'collect_when' => 'profile', 'approval_required' => '1', 'show_on_certificate' => '0', 'help' => 'Enter your legal ID.' );
$personal = array( 'key' => 'national_id', 'label' => 'National ID', 'type' => 'text', 'requirement' => 'required', 'collection' => $collection, 'default_value' => '' );
$shared = array( 'key' => 'training_hours', 'label' => 'Training hours', 'type' => 'number', 'requirement' => 'optional', 'collection' => array_replace( $collection, array( 'writer' => 'staff', 'lifetime' => 'program', 'collect_when' => 'never', 'approval_required' => '0' ) ), 'default_value' => '0' );
$intent = array( 'schema' => WizardFieldSetup::SCHEMA, 'version' => 1, 'catalog_revision' => $planner->catalog_revision( $catalog, array() ), 'program_revision' => $planner->program_revision( $program ), 'program_id' => '42', 'rows' => array( $personal, $shared ) );
$before = serialize( array( $intent, $catalog, $program ) );
$plan = $planner->build( $intent, $catalog, array(), $program );
check_setup( ! is_wp_error( $plan ), 'valid customer and shared Program choices produce a plan' );
check_setup( $before === serialize( array( $intent, $catalog, $program ) ), 'planning is mutation free' );
check_setup( 'private' === $plan['catalog']['national_id']['public_exposure'], 'a new ID is private' );
check_setup( 'text' === $plan['catalog']['national_id']['type'], 'ID fields retain text semantics and leading zeros' );
check_setup( true === $plan['catalog']['national_id'][FieldCollectionPolicy::KEY]['approval_required'], 'approval is explicit and survives into the canonical policy' );
check_setup( '0' === $plan['metadata'][ProgramFieldDefaults::KEY]['values']['training_hours'], 'zero is a supplied shared value' );
check_setup( 'required' === $plan['contract']['fields']['existing_detail'], 'unchecked existing Program requirements survive' );
check_setup( $program['metadata']['nested_integration'] === $plan['metadata']['nested_integration'], 'unrelated nested metadata survives' );
check_setup( 'optional' === $plan['contract']['fields']['training_hours'], 'profile suggestions do not become required' );
$form = array( 'setup_fields_version' => '1', 'setup_fields_complete' => '1', 'setup_catalog_revision' => $intent['catalog_revision'], 'setup_program_revision' => $intent['program_revision'], 'setup_fields_program_id' => '42', 'setup_field' => array( array_merge( $personal, array( 'enabled' => '1' ) ), $shared ) );
check_setup( array( $personal ) === $planner->from_form( $form )['rows'], 'only explicitly selected rows enter intent' );
$empty_form = $form; unset( $empty_form['setup_field'] );
check_setup( array() === $planner->from_form( $empty_form )['rows'], 'a complete form can deliberately select no extra fields without submitting hidden settings' );
$bad = $empty_form; $bad['setup_field'] = null;
check_setup( is_wp_error( $planner->from_form( $bad ) ), 'an explicitly malformed row transport is not treated as an empty selection' );
foreach ( array( 'setup_fields_complete', 'setup_fields_version', 'setup_catalog_revision', 'setup_fields_program_id' ) as $key ) { $bad = $form; unset( $bad[$key] ); check_setup( is_wp_error( $planner->from_form( $bad ) ), 'incomplete transport cannot clear or grant settings: ' . $key ); }
foreach ( array( 'version' => 2, 'program_id' => 42, 'rows' => 'forged', 'catalog_revision' => str_repeat( 'x', 64 ) ) as $key => $value ) { $bad = $intent; $bad[$key] = $value; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'malformed stored intent fails closed: ' . $key ); }
$changed = $catalog; $changed['existing_detail']['label'] = 'Changed'; check_setup( is_wp_error( $planner->build( $intent, $changed, array(), $program ) ), 'catalog edits invalidate the old review' );
$changed = $program; $changed['metadata']['concurrent'] = 'keep'; check_setup( is_wp_error( $planner->build( $intent, $catalog, array(), $changed ) ), 'Program edits invalidate the old review' );
check_setup( is_wp_error( $planner->build( $intent, $catalog, array( 'old_id' => array() ), $program ) ), 'retirement edits invalidate the old review' );
check_setup( is_wp_error( $planner->build( $intent, $catalog, array(), null ) ), 'a draft cannot change Programs silently' );
foreach ( array( 'score', 'result', 'grade', 'marks_percentage', 'cert_number', 'first_name' ) as $key ) { $bad = $intent; $bad['rows'] = array( array_replace( $personal, array( 'key' => $key, 'label' => $catalog[$key]['label'] ?? 'Result', 'type' => $catalog[$key]['type'] ?? 'text' ) ) ); check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'customer cannot replace trusted results or protected identity: ' . $key ); }
$bad = $intent; $bad['rows'][0]['collection']['show_on_certificate'] = '1'; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'certificate expectation never grants private artwork exposure' );
$bad = $intent; $bad['rows'][0]['default_value'] = 'ID'; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'personal information cannot become a shared Program default' );
$bad = $intent; $bad['rows'][0]['collection']['writer'] = 'source'; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'unsupported app-owned personal lifetimes cannot masquerade as connected results' );
foreach ( array( 'bad number', '1e309' ) as $value ) { $bad = $intent; $bad['rows'][1]['default_value'] = $value; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'malformed shared numbers are rejected' ); }
$bad = $intent; $bad['rows'][] = $personal; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'duplicate semantic keys are rejected' );
$bad = $intent; $bad['rows'][0]['key'] = 'credential_title'; check_setup( is_wp_error( $planner->build( $bad, $catalog, array(), $program ) ), 'native certificate facts cannot be recreated as custom fields' );
$legacy = $intent; $legacy['rows'][0]['collection']['writer'] = 'legacy'; $legacy_plan = $planner->build( $legacy, $catalog, array(), $program ); check_setup( ! isset( $legacy_plan['catalog']['national_id'][FieldCollectionPolicy::KEY] ), 'legacy choice does not grant customer editing' );
$rows = $planner->candidates( array( 'selected_fields' => array( 'score' ) ), array( 'recommended_fields' => array( array( 'key' => 'score', 'label' => 'Score' ), array( 'key' => 'expiry', 'label' => 'Expiry' ) ) ), $catalog, array(), $program );
check_setup( 'score' === $rows[0]['key'] && 'optional' === $rows[0]['requirement'] && 'legacy' === $rows[0]['collection']['writer'], 'profile suggestions are optional and never authorize recipient edits' );
check_setup( ! in_array( 'expiry', array_column( $rows, 'key' ), true ), 'built-in expiry is not duplicated as a profile field' );
$coverage = new \Certloom\Application\Setup\WizardDesignCoverage();
$artwork_catalog = $plan['catalog']; $artwork_catalog['training_hours']['public_exposure'] = 'both';
$template = array( 'published_revision' => array( 'layout' => array( 'elements' => array( array( 'type' => 'merge', 'token' => 'recipient.custom.national_id' ) ) ) ) );
$report = $coverage->inspect( $template, array( 'national_id', 'training_hours' ), $artwork_catalog );
check_setup( 1 === $report['privacy_count'] && 1 === $report['missing_count'], 'artwork guidance separates a private ID reference from a missing public detail' );
check_setup( 'private_on_design' === $report['rows'][0]['status'], 'a design token never grants exposure to a private field' );
$template['published_revision']['layout']['elements'][] = array( 'type' => 'rich_text', 'runs' => array( array( 'kind' => 'token', 'token' => 'credential.custom.training_hours' ) ) );
$report = $coverage->inspect( $template, array( 'training_hours' ), $artwork_catalog );
check_setup( 'shown' === $report['rows'][0]['status'] && 0 === $report['missing_count'], 'inline design tokens satisfy coverage without changing a template' );
check_setup( 'private' === $coverage->inspect( null, array( 'national_id' ), $artwork_catalog )['rows'][0]['status'], 'a private field is not presented as an artwork requirement' );
foreach ( array( 'national_id', 'recipient.national_id', 'credential.national_id', 'custom.national_id', 'credential:national_id' ) as $alias ) {
	$alias_template = array( 'published_revision' => array( 'layout' => array( 'elements' => array( array( 'type' => 'merge', 'token' => $alias ) ) ) ) );
	$alias_report = $coverage->inspect( $alias_template, array(), $artwork_catalog );
	check_setup( 1 === $alias_report['privacy_count'] && 'private_on_design' === $alias_report['rows'][0]['status'], 'unselected private design references are visible through historical alias: ' . $alias );
}
$design_only = array( 'published_revision' => array( 'layout' => array( 'elements' => array( array( 'type' => 'merge', 'token' => 'credential.custom.training_hours' ), array( 'type' => 'merge', 'token' => 'credential.custom.undefined_detail' ), array( 'type' => 'rich_text', 'runs' => array( array( 'kind' => 'token', 'token' => 'custom:undefined_detail' ) ) ) ) ) ) );
$report = $coverage->inspect( $design_only, array(), $artwork_catalog );
check_setup( 2 === $report['unreviewed_count'] && array( 'design_only', 'undefined' ) === array_column( $report['rows'], 'status' ), 'design-only and undefined custom references are distinct and deduplicated' );
check_setup( 0 === $report['missing_count'] && 0 === $report['privacy_count'], 'a design reference does not silently become a selected artwork requirement' );
$artwork_before = serialize( array( $artwork_catalog, $design_only ) ); $coverage->inspect( $design_only, array(), $artwork_catalog );
check_setup( $artwork_before === serialize( array( $artwork_catalog, $design_only ) ), 'two-way coverage does not change a field policy or template' );
echo "$checks checks, 0 failures\n";
