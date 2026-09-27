<?php
/** Read-only Local WordPress regression for new Program/Batch design preflight. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { exit( 1 ); }

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Admin\DesignHierarchyConfigurationGuard;
use Certloom\Infrastructure\Repository\ProgramRepository;

$guard = new DesignHierarchyConfigurationGuard();
$program = array(
	'id' => 0,
	'issuer_id' => 0,
	'field_contract' => ScopedFieldContract::empty_program(),
	'profile' => array(),
);
$result = $guard->validate_program( $program, 0 );
if ( is_wp_error( $result ) || true !== $result ) {
	throw new RuntimeException( 'New Program inheritance preflight failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown result' ) );
}

$programs = ( new ProgramRepository() )->list_page( '', 1, 1, 'active' );
if ( is_wp_error( $programs ) || empty( $programs['items'][0]['id'] ) ) { throw new RuntimeException( 'An active Local QA Program is required for Batch preflight.' ); }
$existing_program = ( new ProgramRepository() )->find( absint( $programs['items'][0]['id'] ) );
if ( ! is_array( $existing_program ) ) { throw new RuntimeException( 'Active Local QA Program is unavailable.' ); }
$batch = array( 'id' => 0, 'program_id' => absint( $existing_program['id'] ), 'field_contract' => ScopedFieldContract::empty_batch() );
$result = $guard->validate_batch( $existing_program, $batch, 0 );
if ( is_wp_error( $result ) || true !== $result ) {
	throw new RuntimeException( 'New Batch inheritance preflight failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown result' ) );
}
echo "PASS  new Program and Batch design preflights accept inherited designs before a record ID exists\n";
