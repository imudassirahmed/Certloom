<?php
/** Real SQL failures/zero writes across canonical issuance. Exact disposable tables only. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); } if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Infrastructure\Database\SchemaManager;
function cf_ok( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() ); } return $v; }
function cf_graph() { global $wpdb; $out = array(); foreach ( array( 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries' ) as $entity ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( $entity ) ), ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Graph evidence unavailable.' ); } $out[$entity] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; }
$checks = array(); $failure = null; $sandbox = null; $directory = null; $cleanup = null;
$check = static function ( $v, $label ) use ( &$checks ) { if ( ! $v ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
 $directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Private fixture directory unavailable.' ); }
 $sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter();
 $actor = cf_ok( wp_insert_user( array( 'user_login' => 'qa-fault-organization', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
 $program = cf_ok( ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->create( array( 'title' => 'QA SQL Faults', 'code' => 'QA-SQL-FAULTS' ) ) );
 $manual = new \Certloom\Application\Issuance\ManualIssuanceService(); $pending = new \Certloom\Application\Issuance\PendingIssuanceService(); $requests = new \Certloom\Infrastructure\Repository\IssuanceRequestRepository(); $i = 0;
 foreach ( array( 'manual', 'pending' ) as $flow ) { foreach ( array( 'sql_error', 'zero_write' ) as $fault ) { foreach ( array( 'series', 'number', 'version', 'number_link', 'current_pointer', 'credential_event', 'request_receipt', 'request_event' ) as $point ) {
  if ( 'manual' === $flow && in_array( $point, array( 'request_receipt', 'request_event' ), true ) ) { continue; }
  ++$i; $label = $flow . ' ' . $point . ' ' . $fault;
  $recipient = cf_ok( ( new \Certloom\Infrastructure\Repository\RecipientRepository() )->create( array( 'display_name' => 'QA Fault Recipient ' . $i ) ) );
  $intake = array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QA-FAULT-' . $i );
  if ( 'manual' === $flow ) { $input = array_merge( $intake, array( 'recipient_mode' => 'existing', 'recipient_id' => $recipient, 'program_id' => $program ) ); $review = cf_ok( $manual->review( $input ) ); $operation = static function () use ( $manual, $input, $review, $actor ) { return $manual->issue( $input, $review['review_fingerprint'], $actor ); }; }
  else { $request = cf_ok( $requests->receive( array( 'recipient_id' => $recipient, 'program_id' => $program, 'source_type' => 'manual', 'intake_key' => hash( 'sha256', 'qa-fault-' . $i ) ), $intake, array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) ); $operation = static function () use ( $pending, $request ) { return $pending->process( $request['id'] ); }; }
  $before = cf_graph(); $fired = false;
  $tables = array( 'series' => 'credential_series', 'number' => 'credential_numbers', 'version' => 'credential_versions', 'number_link' => 'credential_numbers', 'current_pointer' => 'credential_series', 'credential_event' => 'credential_events', 'request_receipt' => 'issuance_requests', 'request_event' => 'issuance_request_events' ); $table = SchemaManager::table( $tables[$point] );
  $filter = static function ( $sql ) use ( &$fired, $point, $fault, $table ) {
   if ( $fired ) { return $sql; }
   $insert = preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( $table, '/' ) . '`?\s/i', $sql ); $update = preg_match( '/^UPDATE\s+`?' . preg_quote( $table, '/' ) . '`?\s/i', $sql );
   $match = in_array( $point, array( 'series', 'number', 'version', 'credential_event' ), true ) ? $insert : ( 'number_link' === $point ? $update && strpos( $sql, 'first_version_id' ) !== false : ( 'current_pointer' === $point ? $update && strpos( $sql, 'current_version_id' ) !== false : ( 'request_receipt' === $point ? $update && strpos( $sql, "'issued'" ) !== false : $insert && strpos( $sql, 'issuance_completed' ) !== false ) ) );
   if ( ! $match ) { return $sql; } $fired = true;
   return 'sql_error' === $fault ? 'SELECT * FROM certloom_intentional_missing_canonical_fault_table' : ( $update ? $sql . ' AND 1=0' : 'UPDATE `' . $table . '` SET id=id WHERE 1=0' );
  };
  $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $filter, PHP_INT_MAX );
  try { $failed = $operation(); } finally { remove_filter( 'query', $filter, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
  $check( $fired && ( is_wp_error( $failed ) || 'issued' !== ( $failed['status'] ?? null ) ), $label . ': actual database fault cannot return an issued receipt' );
  $check( $before === cf_graph(), $label . ': every Series, number, Version, event, job and delivery remains unchanged' );
  $issued = cf_ok( $operation() );
  $check( (int) $issued['version_id'] > 0 && ( 'manual' === $flow || 'issued' === $issued['status'] ), $label . ': retry after fault creates one canonical issuance' );
  if ( 'pending' === $flow ) { $again = cf_ok( $operation() ); $check( $issued['version_id'] === $again['version_id'], $label . ': repeated worker returns the same durable receipt' ); }
  $number = $wpdb->get_row( $wpdb->prepare( 'SELECT n.*,s.current_version_id FROM %i n INNER JOIN %i s ON s.id=n.series_id WHERE n.display_number=%s', SchemaManager::table( 'credential_numbers' ), SchemaManager::table( 'credential_series' ), $intake['display_number'] ), ARRAY_A );
  $check( is_array( $number ) && (int) $number['first_version_id'] === (int) $issued['version_id'] && (int) $number['current_version_id'] === (int) $issued['version_id'], $label . ': actual first-Version and current-Version pointers agree' );
 } } }
 // Re-selecting an already persisted pointer is a legitimate zero-row update.
 $check( true === cf_ok( ( new \Certloom\Infrastructure\Repository\CredentialRepository() )->set_current_version( $issued['series_id'], $issued['version_id'] ) ), 'verified unchanged current-Version selection remains idempotent' );
} catch ( Throwable $e ) { $failure = get_class( $e ) . ': ' . $e->getMessage(); }
finally { if ( $sandbox ) { try { $cleanup = $sandbox->cleanup(); if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Private fixture cleanup failed.' ); } $directory = null; } catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } } }
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
