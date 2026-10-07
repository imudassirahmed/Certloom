<?php
/** Strict opt-in configuration and source identity contracts without a database. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { private $code; public function __construct( $code,$message = '',$data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value,$domain = null ) { return $value; }
function get_option( $key,$default = false ) { return array_key_exists( $key,$GLOBALS['qa_options'] ) ? $GLOBALS['qa_options'][ $key ] : $default; }
function wp_json_encode( $value,$flags = 0 ) { return json_encode( $value,$flags ); }
$GLOBALS['qa_options'] = array(); require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Integration\TutorPendingMappingRepository;
use Certloom\Application\Integration\TutorPendingContext;
use Certloom\Application\Issuance\PendingRequestContext;
$passed = 0; $failed = 0;
function tp_check( $ok,$message ) { global $passed,$failed; if ( $ok ) { $passed++; echo 'PASS  ' . $message . "\n"; } else { $failed++; echo 'FAIL  ' . $message . "\n"; } }
$repo = new TutorPendingMappingRepository(); tp_check( array() === $repo->read(), 'upgraded sites have no automatic pending source until explicitly configured' );
$row = array( 'key' => 'tp-abcdef123456','course_id' => 12,'program_id' => 8,'batch_id' => 0,'active' => true,'revision' => 1,'updated_at' => '2026-10-03 01:02:03' );
$good = array( 'schema_version' => 1,'mappings' => array( $row ) ); $GLOBALS['qa_options'][ $repo::OPTION ] = $good;
tp_check( $repo->find( $row['key'] ) === $row && $repo->for_course( 12 ) === $row, 'strict typed configuration resolves exact source and course' );
foreach ( array( false, array(), array( 'schema_version' => '1','mappings' => array( $row ) ), array( 'schema_version' => 1,'mappings' => array( $row,$row ) ), array( 'schema_version' => 1,'mappings' => array( 1 => $row ) ) ) as $index => $invalid ) { $GLOBALS['qa_options'][ $repo::OPTION ] = $invalid; tp_check( is_wp_error( $repo->read() ), 'malformed whole configuration fails closed (' . $index . ')' ); }
foreach ( array( 'active' => 1,'course_id' => '12','program_id' => 0,'batch_id' => -1,'revision' => 0,'updated_at' => '2026-02-30 01:02:03','key' => 'tp-Abcdef123456' ) as $key => $value ) { $bad = $row; $bad[ $key ] = $value; $GLOBALS['qa_options'][ $repo::OPTION ] = array( 'schema_version' => 1,'mappings' => array( $bad ) ); tp_check( is_wp_error( $repo->read() ), 'configuration rejects invalid ' . $key ); }
$GLOBALS['qa_options'][ $repo::OPTION ] = $good;
$later = $row; $later['revision'] = 2; $later['updated_at'] = '2026-10-04 01:02:03'; tp_check( $repo->mapping_sha256( $row ) === $repo->mapping_sha256( $later ) && $repo->revision( $row ) !== $repo->revision( $later ), 'command revision is distinct from immutable mapping authority' );
$later['active'] = false; tp_check( $repo->mapping_sha256( $row ) !== $repo->mapping_sha256( $later ), 'paused source cannot match active request authority' );
$proof = str_repeat( 'a',64 ); $ref = $row['key'] . ':12:34:56:' . $proof;
tp_check( TutorPendingContext::parse( $ref ) === array( 'key' => $row['key'],'course' => 12,'user' => 34,'comment' => 56,'proof' => $proof ), 'source reference binds exact course, account, completion and private proof digest' );
foreach ( array( str_replace( ':12:',':0:',$ref ),str_replace( ':34:',':034:',$ref ),str_replace( ':56:',':9223372036854775808:',$ref ),$ref . ':extra',str_replace( $proof,strtoupper( $proof ),$ref ) ) as $index => $bad ) { tp_check( null === TutorPendingContext::parse( $bad ), 'source reference rejects ambiguous or overflowing identity (' . $index . ')' ); }
tp_check( TutorPendingContext::intake_key( 12,34 ) === TutorPendingContext::intake_key( 12,34 ) && TutorPendingContext::intake_key( 12,34 ) !== TutorPendingContext::intake_key( 12,35 ), 'intake identity stays stable for one course/account and separates other learners' );
tp_check( PendingRequestContext::supports_source( array( 'source_type' => TutorPendingContext::SOURCE,'mapping_sha256' => $repo->mapping_sha256( $row ) ) ) && ! PendingRequestContext::supports_source( array( 'source_type' => TutorPendingContext::SOURCE,'mapping_sha256' => null ) ), 'pending executor requires a mapped source contract' );
echo "\n$passed Tutor pending contract checks, $failed failures\n"; exit( $failed ? 1 : 0 );
