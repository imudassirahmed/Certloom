<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message = '', $data = null ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
$checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
$entry = static function ( $value ) { return array( 'exists' => true, 'value' => $value ); };
$source = array(
	'certloom_tutor_lms_mappings' => $entry( array( 'mappings' => array( array( 'program_id' => 10, 'batch_id' => 20, 'template_id' => 999 ) ) ) ),
	'certloom_email_template_catalog_v1' => $entry( array( 'templates' => array( array( 'id' => 'qa-email', 'purpose' => 'credential_issued', 'active' => 0 ) ) ) ),
	'certloom_email_design_assignments_v1' => $entry( array( array( 'scope_type' => 'program', 'scope_id' => 10, 'design_id' => 'qa-email', 'purpose' => 'credential_issued' ) ) ),
	'certloom_email_automation_rules_v1' => $entry( array( array( 'issuer_id' => 30, 'program_id' => 10, 'batch_id' => 20 ) ) ),
	'certloom_webhook_sources_v2' => $entry( array( 'connections' => array( array( 'program_id' => 10, 'batch_id' => 20, 'fields' => array( 'remoteScore' => 'score' ) ) ) ) ),
);
$recommendations = array( 'certloom_template_recommendations_40' => array() );
$rows = array( 'issuers' => array( 'id' => '30' ), 'programs' => array( 'id' => '10', 'issuer_id' => '30', 'metadata_json' => json_encode( array( 'certloom_profile_v1' => array( 'template_variants' => array( 'en-US' => 'qa-design' ) ) ) ) ), 'batches' => array( 'id' => '20', 'program_id' => '10' ), 'templates' => array( 'id' => '40', 'machine_key' => 'qa-design' ) );
$build = static function ( $options = null ) use ( $source, $recommendations ) { return \Certloom\Application\Restore\WholeStateConfigurationGraph::build( $options ?? $source, $recommendations, array( 'score' => array() ) ); };
$graph = $build(); foreach ( $rows as $entity => $row ) { $check( true === $graph->visit( $entity, $row ), 'source graph row' ); }
$check( true === $graph->finish(), 'same-source relationships, inactive designs and historical app template IDs retain their intended semantics' );
foreach ( array_keys( $rows ) as $missing ) { $graph = $build(); foreach ( $rows as $entity => $row ) { if ( $entity !== $missing ) { $graph->visit( $entity, $row ); } } $check( is_wp_error( $graph->finish() ), 'missing source ' . $missing . ' cannot be substituted by target records' ); }
foreach ( array( array( 'batches', 'program_id' ), array( 'programs', 'issuer_id' ) ) as $mismatch ) { $graph = $build(); foreach ( $rows as $entity => $row ) { if ( $entity === $mismatch[0] ) { $row[$mismatch[1]] = '999'; } $graph->visit( $entity, $row ); } $check( is_wp_error( $graph->finish() ), 'mismatched hierarchy cannot restore' ); }
$graph = $build(); foreach ( $rows as $entity => $row ) { if ( 'templates' === $entity ) { $row['machine_key'] = 'other-key'; } $graph->visit( $entity, $row ); } $check( is_wp_error( $graph->finish() ), 'Program locale variants require their actual stable design key' );
$wrong = $source; $wrong['certloom_email_design_assignments_v1']['value'][0]['purpose'] = 'other'; $check( is_wp_error( $build( $wrong ) ), 'email design purpose must match its assignment' );
$wrong = $source; $wrong['certloom_email_design_assignments_v1']['value'][0]['design_id'] = 'missing'; $check( is_wp_error( $build( $wrong ) ), 'email design assignments cannot silently inherit from missing designs' );
$check( is_wp_error( \Certloom\Application\Restore\WholeStateConfigurationGraph::build( $source, $recommendations, array( 'remoteScore' => array() ) ) ), 'Webhook slot names cannot substitute for missing target fields' );
$graph = $build(); $check( true === $graph->validate_batches( static function ( $entity, $ids ) use ( $rows ) { return array( $rows[$entity] ); } ), 'staged connections resolve through bounded actual database batches' );
$default = array( 'certloom_email_design_assignments_v1' => $entry( array( array( 'scope_type' => 'global', 'scope_id' => 0, 'design_id' => 'default', 'purpose' => 'credential_issued' ) ) ) );
$default_graph = \Certloom\Application\Restore\WholeStateConfigurationGraph::build( $default, array(), array() );
$check( ! is_wp_error( $default_graph ) && true === $default_graph->finish(), 'native synthesized default email design remains valid without a saved catalog' );
$default['certloom_email_design_assignments_v1']['value'][0]['purpose'] = 'credential_expired';
$check( is_wp_error( \Certloom\Application\Restore\WholeStateConfigurationGraph::build( $default, array(), array() ) ), 'factory default does not grant another email purpose' );
echo $checks . " configuration graph checks, 0 failures\n";
