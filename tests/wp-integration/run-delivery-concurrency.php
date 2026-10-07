<?php
/** Independent delivery workers with owned private journals and exact cleanup. No transport. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || realpath( ABSPATH ) !== realpath( $site ) || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site or database.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'QA' ); }, PHP_INT_MAX );

use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;

function delivery_race_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; }
function delivery_race_directory( $path ) {
 $resolved = realpath( $path ); $temp = realpath( sys_get_temp_dir() );
 if ( ! $resolved || ! preg_match( '/^certloom-delivery-race-[a-f0-9]{16}$/D', basename( $resolved ) ) || realpath( dirname( $resolved ) ) !== $temp ) { throw new RuntimeException( 'Exact owned private directory required.' ); }
 return $resolved;
}
function delivery_race_write( $path, $value ) { if ( false === file_put_contents( $path, wp_json_encode( $value ) ) ) { throw new RuntimeException( 'Private synchronization write failed.' ); } }
function delivery_race_spawn( array $arguments ) {
 $ini = php_ini_loaded_file(); if ( ! $ini ) { throw new RuntimeException( 'CLI configuration required.' ); }
 $process = proc_open( array_merge( array( PHP_BINARY, '-c', $ini, __FILE__ ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
 if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Worker start failed.' ); }
 fclose( $pipes[0] ); return array( $process, $pipes[1] );
}
function delivery_race_join( array $worker ) {
 $text = stream_get_contents( $worker[1] ); fclose( $worker[1] ); $exit = proc_close( $worker[0] );
 if ( 0 !== $exit ) { throw new RuntimeException( 'Worker failed: ' . trim( $text ) ); }
 return $text;
}
function delivery_race_wait_file( $path ) {
 $deadline = microtime( true ) + 30;
 do { clearstatcache( true, $path ); if ( is_file( $path ) ) { return json_decode( file_get_contents( $path ), true ); } usleep( 50000 ); } while ( microtime( true ) < $deadline );
 throw new RuntimeException( 'Worker synchronization timed out.' );
}

function delivery_race_option() {
	global $wpdb; $wpdb->last_error = '';
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name=%s', $wpdb->options, \Certloom\Infrastructure\Data\DomainGeneration::OPTION ), ARRAY_A );
	if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Generation read failed.' ); }
	return $row;
}
function delivery_race_fingerprints( array $owned ) {
	global $wpdb; $out = array();
	$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
	foreach ( $tables as $table ) {
		$wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); }
		if ( in_array( $table, array( SchemaManager::table( 'email_deliveries' ), SchemaManager::table( 'destination_deliveries' ) ), true ) ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $owned ) { return ! in_array( $row['delivery_key'], $owned, true ); } ) ); }
		$out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) );
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,OCTET_LENGTH(option_value) AS bytes,SHA2(option_value,256) AS sha256,autoload FROM %i WHERE option_name<>%s ORDER BY option_name', $wpdb->options, \Certloom\Infrastructure\Data\DomainGeneration::OPTION ), ARRAY_A );
	if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Option preservation read failed.' ); }
	$out['all_other_options'] = hash( 'sha256', wp_json_encode( $rows ) ); return $out;
}
function delivery_race_repository( $type ) {
	if ( 'email_deliveries' === $type ) { return new \Certloom\Infrastructure\Repository\EmailDeliveryRepository(); }
	if ( 'destination_deliveries' === $type ) { return new \Certloom\Infrastructure\Repository\DestinationDeliveryRepository(); }
	throw new RuntimeException( 'Exact delivery store required.' );
}
function delivery_race_raw( $type, $key, $lock = false ) {
	global $wpdb; delivery_race_repository( $type ); $wpdb->last_error = '';
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key=%s' . ( $lock ? ' FOR UPDATE' : '' ), SchemaManager::table( $type ), $key ), ARRAY_A );
	if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned delivery read failed.' ); }
	return $row;
}
function delivery_race_cleanup( $directory ) {
	$directory = delivery_race_directory( $directory ); $journal = json_decode( file_get_contents( $directory . '/journal.json' ), true );
	$receipt = json_decode( file_get_contents( $directory . '/cleanup.json' ), true );
	if ( ! is_array( $journal ) || $journal['directory'] !== $directory || ! is_array( $receipt ) || ! is_array( $receipt['rows'] ) ) { throw new RuntimeException( 'Exact owned cleanup receipt required.' ); }
	delivery_race_ok( ( new Transaction() )->run( static function () use ( $journal, $receipt ) {
		global $wpdb;
		$expected_before = $receipt['before'] ?? $journal['before'];
		$original_business = $journal['before']; $current_business = $expected_before;
		unset( $original_business['all_other_options'], $current_business['all_other_options'] );
		if ( $original_business !== $current_business || $expected_before !== delivery_race_fingerprints( $journal['owned'] ) || $receipt['generation'] !== delivery_race_option() ) { return new WP_Error( 'qa_preservation_conflict', 'Preserve a concurrent change.' ); }
		foreach ( $receipt['rows'] as $entry ) {
			if ( ! in_array( $entry['key'], $journal['owned'], true ) || delivery_race_raw( $entry['type'], $entry['key'], true ) !== $entry['row'] ) { return new WP_Error( 'qa_owned_conflict', 'Preserve a changed delivery.' ); }
			$wpdb->last_error = ''; $removed = $wpdb->delete( SchemaManager::table( $entry['type'] ), array( 'id' => $entry['row']['id'], 'delivery_key' => $entry['key'] ) );
			if ( 1 !== $removed || '' !== $wpdb->last_error ) { return new WP_Error( 'qa_cleanup_failed', 'Owned delivery cleanup failed.' ); }
		}
		$wpdb->last_error = '';
		$locked = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, \Certloom\Infrastructure\Data\DomainGeneration::OPTION ), ARRAY_A );
		if ( '' !== $wpdb->last_error || $locked !== $receipt['generation'] ) { return new WP_Error( 'qa_generation_conflict', 'Preserve a concurrent marker.' ); }
		$original = $journal['generation'];
		if ( ! empty( $receipt['preserve_options'] ) ) {
			$marked = ( new \Certloom\Infrastructure\Data\DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $marked ) ) { return $marked; }
		} elseif ( $locked !== $original ) {
			$restored = null === $original ? $wpdb->delete( $wpdb->options, array( 'option_id' => $locked['option_id'], 'option_value' => $locked['option_value'] ) ) : $wpdb->update( $wpdb->options, array( 'option_value' => $original['option_value'], 'autoload' => $original['autoload'] ), array( 'option_id' => $locked['option_id'], 'option_value' => $locked['option_value'] ) );
			if ( 1 !== $restored || '' !== $wpdb->last_error ) { return new WP_Error( 'qa_generation_restore_failed', 'Exact generation restoration failed.' ); }
		}
		return true;
	} ) );
	\Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( \Certloom\Infrastructure\Data\DomainGeneration::OPTION ) );
	if ( ( empty( $receipt['preserve_options'] ) && $journal['generation'] !== delivery_race_option() ) || ( $receipt['before'] ?? $journal['before'] ) !== delivery_race_fingerprints( array() ) ) { throw new RuntimeException( 'Final restoration comparison failed.' ); }
	// Enumerated files only, in the verified private directory; never recurse.
	foreach ( array( 'journal.json', 'cleanup.json', 'first-ready.json', 'second-entered.json', 'second-result.json', 'release.json' ) as $file ) { if ( is_file( $directory . '/' . $file ) && ! unlink( $directory . '/' . $file ) ) { throw new RuntimeException( 'Private receipt cleanup failed.' ); } }
	if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Owned directory cleanup failed.' ); }
}

$mode = $argv[1] ?? '';
if ( in_array( $mode, array( 'hold', 'compete', 'restore', 'inspect', 'recover-owned-preserve-options' ), true ) ) {
	try {
		$directory = delivery_race_directory( $argv[2] ?? '' );
		if ( 'inspect' === $mode ) {
			$journal = json_decode( file_get_contents( $directory . '/journal.json' ), true );
			echo wp_json_encode( array( 'changed_scopes' => array_keys( array_diff_assoc( delivery_race_fingerprints( $journal['owned'] ), $journal['before'] ) ), 'owned_records' => count( $journal['owned'] ) ) ) . "\n"; exit;
		}
		if ( 'restore' === $mode ) { delivery_race_cleanup( $directory ); echo "Exact owned fixture restored.\n"; exit; }
		$journal = json_decode( file_get_contents( $directory . '/journal.json' ), true );
		if ( 'recover-owned-preserve-options' === $mode ) {
			$rows = array();
			foreach ( $journal['entries'] as $entry ) {
				$row = delivery_race_raw( $entry['type'], $entry['key'] );
				$state = 'claim' === $entry['first'] ? 'sending' : ( 'uncertain' === $entry['first'] ? 'uncertain' : ( 'email_deliveries' === $entry['type'] ? 'sent' : 'succeeded' ) );
				$evidence = array( 'fixture' => 'independent_delivery_repository_only' );
				if ( 'claim' !== $entry['first'] ) { $evidence['attempt_token_sha256'] = hash( 'sha256', $entry['attempt'] ); }
				$tokens = 'claim' === $entry['first'] ? array( $entry['attempt'], $entry['competitor'] ) : array( $entry['attempt'] );
				if ( ! is_array( $row ) || $state !== $row['status'] || ! in_array( $row['attempt_token'], $tokens, true ) || $evidence !== json_decode( $row['evidence_json'], true ) || '1' !== $row['series_id'] || '1' !== $row['version_id'] ) { throw new RuntimeException( 'Changed fixture evidence must be preserved.' ); }
				$rows[] = array( 'type' => $entry['type'], 'key' => $entry['key'], 'row' => $row );
			}
			delivery_race_write( $directory . '/cleanup.json', array( 'rows' => $rows, 'generation' => delivery_race_option(), 'before' => delivery_race_fingerprints( $journal['owned'] ), 'preserve_options' => true ) );
			delivery_race_cleanup( $directory ); echo "Exact owned records removed; current unrelated options preserved; generation advanced.\n"; exit;
		}
		$entry = $journal['current']; $repository = delivery_race_repository( $entry['type'] );
		$operation = 'hold' === $mode ? $entry['first'] : $entry['second'];
		$call = static function () use ( $repository, $entry, $operation ) {
			if ( 'claim' === $operation ) { return $repository->mark_sending( $entry['key'], 'hold' === ( $GLOBALS['argv'][1] ?? '' ) ? $entry['attempt'] : $entry['competitor'] ); }
			$evidence = array( 'attempt_token_sha256' => hash( 'sha256', $entry['attempt'] ) );
			if ( 'uncertain' === $operation ) { return $repository->mark_failed( $entry['key'], 'qa_interrupted', $evidence, true ); }
			return 'email_deliveries' === $entry['type'] ? $repository->mark_sent( $entry['key'], $evidence ) : $repository->mark_succeeded( $entry['key'], 'qa-accepted', $evidence );
		};
		if ( 'compete' === $mode ) {
			delivery_race_write( $directory . '/second-entered.json', array( 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) );
			$result = $call(); delivery_race_write( $directory . '/second-result.json', array( 'success' => true === $result, 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : null ) ); echo "Competitor completed.\n"; exit;
		}
		delivery_race_ok( ( new Transaction() )->run( static function () use ( $call, $directory ) {
			delivery_race_ok( $call() ); global $wpdb;
			delivery_race_write( $directory . '/first-ready.json', array( 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) );
			delivery_race_wait_file( $directory . '/release.json' ); return true;
		} ) ); echo "Owner committed.\n"; exit;
	} catch ( Throwable $error ) { fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" ); exit( 1 ); }
}
if ( '' !== $mode ) { fwrite( STDERR, "Unknown mode.\n" ); exit( 1 ); }

$checks = array(); $directory = null; $workers = array(); $failure = null; $restored = false;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
	$before = delivery_race_fingerprints( array() ); $original = delivery_race_option();
	$directory = sys_get_temp_dir() . '/certloom-delivery-race-' . bin2hex( random_bytes( 8 ) );
	if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Private directory failed.' ); }
	$directory = delivery_race_directory( $directory );
	$journal = array( 'directory' => $directory, 'before' => $before, 'generation' => $original, 'owned' => array(), 'entries' => array() );
	delivery_race_write( $directory . '/journal.json', $journal );
	foreach ( array( 'email_deliveries', 'destination_deliveries' ) as $type ) {
		foreach ( array( array( 'claim', 'claim' ), array( 'success', 'uncertain' ), array( 'uncertain', 'success' ) ) as $operations ) {
			// Persist exact ownership before creating a row; fixture creation uses no credential truth.
			$key = bin2hex( random_bytes( 16 ) ); $token = bin2hex( random_bytes( 16 ) );
			$entry = array( 'type' => $type, 'key' => $key, 'first' => $operations[0], 'second' => $operations[1], 'attempt' => $token, 'competitor' => bin2hex( random_bytes( 16 ) ) );
			$journal['owned'][] = $key; $journal['entries'][] = $entry; $journal['current'] = $entry; delivery_race_write( $directory . '/journal.json', $journal );
			$hash = hash( 'sha256', $key ); $now = current_time( 'mysql', true );
			$row = array( 'delivery_key' => $key, 'series_id' => 1, 'version_id' => 1, 'status' => 'claim' === $operations[0] ? 'queued' : 'sending', 'job_key' => bin2hex( random_bytes( 16 ) ), 'attempt_token' => 'claim' === $operations[0] ? null : $token, 'created_at' => $now, 'updated_at' => $now, 'evidence_json' => '{"fixture":"independent_delivery_repository_only"}' );
			$row = 'email_deliveries' === $type ? array_merge( $row, array( 'delivery_kind' => 'issued', 'destination_mask' => 'qa', 'destination_sha256' => $hash, 'subject_sha256' => $hash, 'html_sha256' => $hash, 'text_sha256' => $hash, 'composition_sha256' => $hash, 'template_version' => 1 ) ) : array_merge( $row, array( 'destination' => 'qa', 'dedupe_key' => $hash, 'mapping_sha256' => $hash, 'configuration_sha256' => $hash, 'recipient_sha256' => $hash ) );
			delivery_race_ok( ( new Transaction() )->run( static function () use ( $row, $type ) { global $wpdb; $wpdb->last_error = ''; return 1 === $wpdb->insert( SchemaManager::table( $type ), $row ) && '' === $wpdb->last_error ? true : new WP_Error( 'qa_stage_failed', 'Exact fixture failed.' ); } ) );
			$workers['first'] = delivery_race_spawn( array( 'hold', $directory ) ); $first = delivery_race_wait_file( $directory . '/first-ready.json' );
			$workers['second'] = delivery_race_spawn( array( 'compete', $directory ) ); $second = delivery_race_wait_file( $directory . '/second-entered.json' );
			$waiting = false; $deadline = microtime( true ) + 6;
			do { $wpdb->last_error = ''; $wait = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d AND trx_state='LOCK WAIT'", $second['connection_id'] ) ); if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Wait inspection failed.' ); } if ( (int) $wait > 0 ) { $waiting = true; break; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
			$check( $first['connection_id'] !== $second['connection_id'] && $waiting && ! is_file( $directory . '/second-result.json' ), $type . ': independent ' . implode( ' versus ', $operations ) . ' sessions actually overlap in an InnoDB lock wait' );
			delivery_race_write( $directory . '/release.json', array( 'release' => true ) );
			delivery_race_join( $workers['first'] ); unset( $workers['first'] ); delivery_race_join( $workers['second'] ); unset( $workers['second'] );
			$result = json_decode( file_get_contents( $directory . '/second-result.json' ), true ); $final = delivery_race_raw( $type, $key );
			$expected = 'claim' === $operations[0] ? 'sending' : ( 'uncertain' === $operations[0] ? 'uncertain' : ( 'email_deliveries' === $type ? 'sent' : 'succeeded' ) );
			$check( false === $result['success'] && is_string( $result['error_code'] ) && $expected === $final['status'] && $token === $final['attempt_token'], $type . ': only the winning operation persists; the competitor cannot overwrite it' );
			foreach ( array( 'first-ready.json', 'second-entered.json', 'second-result.json', 'release.json' ) as $file ) { if ( ! unlink( $directory . '/' . $file ) ) { throw new RuntimeException( 'Owned synchronization cleanup failed.' ); } }
		}
	}
	$cleanup_before = delivery_race_fingerprints( $journal['owned'] ); $original_business = $before; $current_business = $cleanup_before;
	unset( $original_business['all_other_options'], $current_business['all_other_options'] );
	$check( $original_business === $current_business, 'independent workers leave every original business/core row unchanged' );
	$preserve_options = $cleanup_before['all_other_options'] !== $before['all_other_options'];
	$rows = array(); foreach ( $journal['entries'] as $entry ) { $rows[] = array( 'type' => $entry['type'], 'key' => $entry['key'], 'row' => delivery_race_raw( $entry['type'], $entry['key'] ) ); }
	delivery_race_write( $directory . '/cleanup.json', array( 'rows' => $rows, 'generation' => delivery_race_option(), 'before' => $cleanup_before, 'preserve_options' => $preserve_options ) );
	delivery_race_cleanup( $directory ); $directory = null; $restored = true;
	$check( ( $preserve_options || $original === delivery_race_option() ) && $cleanup_before === delivery_race_fingerprints( array() ), 'all six exact owned records are removed and current unrelated options preserved; private journal removed' );
} catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); }
finally {
	if ( $workers ) { if ( $directory && is_dir( $directory ) ) { delivery_race_write( $directory . '/release.json', array( 'release' => true ) ); } foreach ( $workers as $worker ) { try { delivery_race_join( $worker ); } catch ( Throwable $error ) { if ( null === $failure ) { $failure = $error->getMessage(); } } } }
}
echo wp_json_encode( array( 'success' => null === $failure && $restored && null === $directory, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_state_restored' => $restored, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && $restored && null === $directory ? 0 : 1 );
