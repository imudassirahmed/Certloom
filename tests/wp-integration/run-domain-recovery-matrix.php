<?php
/** Destructive recovery fault injection on a backed-up disposable Local site only. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' );
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) {
	fwrite( STDERR, "Set CERTLOOM_TEST_WP_ROOT and CERTLOOM_TEST_DB_BACKUP first.\n" ); exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
// Install a fault barrier before plugins bootstrap, using WordPress's preinitialized hook format.
if ( ( $argv[1] ?? '' ) === 'recover-rollback' ) {
	$GLOBALS['wp_filter']['updated_option'][10][] = array( 'accepted_args' => 3, 'function' => static function ( $key, $old, $value ) {
		if ( $key === 'certloom_domain_restore_journal' && ( $value['state'] ?? '' ) === 'rolled_back' ) {
			echo "BARRIER rolled_back\n"; flush(); fgets( STDIN );
		}
	} );
}
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Local test environment required.' ); }

use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

function rm_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rm_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function rm_option( $key ) {
	global $wpdb;
	return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) ) );
}
function rm_hashes() {
	global $wpdb; $hashes = array();
	foreach ( DomainBackupRepository::entity_types() as $type ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( $type ) ), ARRAY_A );
		rm_assert( '' === $wpdb->last_error && is_array( $rows ), 'Cannot hash live table ' . $type );
		$hashes[ $type ] = hash( 'sha256', wp_json_encode( $rows ) );
	}
	return $hashes;
}
function rm_spawn( array $args ) {
	$command = array_merge( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__ ), $args );
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	rm_assert( is_resource( $process ), 'Cannot start independent WordPress process.' );
	$GLOBALS['rm_children'][] = array( $process, $pipes );
	return array( $process, $pipes );
}
function rm_finish( array $child ) {
	list( $process, $pipes ) = $child;
	fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] ); $status = proc_close( $process );
	rm_assert( 0 === $status, 'Child failed: ' . $out . $err );
	return json_decode( trim( $out ), true, 512, JSON_THROW_ON_ERROR );
}
function rm_expire( $owner ) {
	global $wpdb;
	$stored = rm_option( DomainMaintenanceLock::OPTION );
	rm_assert( is_string( $stored ) && strpos( $stored, $owner . '|' ) === 0, 'Refusing to expire another maintenance owner.' );
	$value = $owner . '|' . ( time() - 3601 ) . '|' . ( time() - 1 ) . '|domain_restore';
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, DomainMaintenanceLock::OPTION, $stored ) );
}
function rm_private_count( $owner ) {
	global $wpdb; $count = 0;
	foreach ( array( 'rs', 'rm', 'rb' ) as $kind ) {
		$count += count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' . $kind . '_' . substr( $owner, 0, 12 ) . '_' ) . '%' ) ) );
	}
	return $count;
}

$workspace_service = new DomainRestoreWorkspace();
$action = $argv[1] ?? 'matrix';
if ( in_array( $action, array( 'observe', 'recover-rollback' ), true ) ) {
	echo wp_json_encode( array( 'journal' => rm_option( DomainRestoreWorkspace::JOURNAL_OPTION ), 'hashes' => rm_hashes(), 'active' => ( new DomainMaintenanceLock() )->active() ) ); exit;
}
if ( 'commit' === $action ) {
	$mode = $argv[2]; $barrier = $argv[3]; $owner = $argv[4];
	$workspace = rm_ok( $workspace_service->create( $owner ) );
	if ( 'merge' === $mode ) { rm_ok( $workspace_service->create_merge_target( $workspace ) ); }
	$counts = array();
	foreach ( DomainBackupRepository::entity_types() as $entity ) {
		$table = rm_ok( $workspace_service->workspace_table( $workspace, 'merge' === $mode ? 'merge' : 'stage', $entity ) );
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i SELECT * FROM %i', $table, SchemaManager::table( $entity ) ) );
		rm_assert( false !== $inserted, 'Cannot copy live graph: ' . $entity ); $counts[ $entity ] = $inserted;
	}
	$table = rm_ok( $workspace_service->workspace_table( $workspace, 'merge' === $mode ? 'merge' : 'stage', 'issuers' ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET name = %s ORDER BY id LIMIT 1', $table, '__Certloom recovery matrix__' ) );
	rm_assert( '' === $wpdb->last_error, 'Cannot mark staged issuer.' );
	$wait = static function ( $state ) use ( $barrier ) {
		if ( $state === $barrier ) { echo "BARRIER " . $state . "\n"; flush(); rm_assert( trim( (string) fgets( STDIN ) ) === 'continue', 'Worker interrupted at barrier.' ); }
	};
	add_action( 'added_option', static function ( $key, $value ) use ( $wait ) { if ( DomainRestoreWorkspace::JOURNAL_OPTION === $key ) { $wait( $value['state'] ); } }, 10, 2 );
	add_action( 'updated_option', static function ( $key, $old, $value ) use ( $wait ) { if ( DomainRestoreWorkspace::JOURNAL_OPTION === $key ) { $wait( $value['state'] ); } }, 10, 3 );
	add_filter( 'pre_update_option_' . DomainRestoreWorkspace::JOURNAL_OPTION, static function ( $value ) use ( $wait ) { if ( 'swapped' === ( $value['state'] ?? '' ) ) { $wait( 'renamed' ); } return $value; } );
	$config = rm_ok( ( new DomainBackupRepository() )->configuration_snapshot() );
	$receipt = array( 'records' => array_sum( $counts ), 'counts' => 'merge' === $mode ? array( 'inserted' => 0, 'existing' => array_sum( $counts ), 'skipped' => 0 ) : $counts );
	if ( 'receipt_retry' === $barrier ) {
		$fail_once = true;
		add_filter( 'pre_update_option_' . DomainRestoreWorkspace::RECEIPTS_OPTION, static function ( $value, $old ) use ( &$fail_once ) {
			if ( $fail_once ) { $fail_once = false; return $old; } return $value;
		}, 10, 2 );
	}
	$result = 'merge' === $mode ? $workspace_service->commit_merge( $workspace, $config['values'], $receipt ) : $workspace_service->commit_replace( $workspace, $config['values'], $receipt );
	if ( 'receipt_retry' === $barrier ) {
		rm_assert( is_wp_error( $result ) && 'certloom_domain_restore_receipt_failed' === $result->get_error_code(), 'Receipt failure injection did not trigger.' );
		rm_assert( ( new DomainMaintenanceLock() )->owned_active( $owner ), 'Receipt retry must run before lease expiry.' );
		rm_assert( is_array( rm_ok( $workspace_service->completion_receipt( $owner ) ) ), 'Immediate retry did not repair committed receipt.' );
	} else { rm_ok( $result ); }
	echo wp_json_encode( array( 'committed' => true ) ); exit;
}

foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::JOURNAL_OPTION, DomainRestoreWorkspace::BUILD_OPTION ) as $key ) {
	rm_assert( null === rm_option( $key ), 'Existing maintenance state; refusing matrix.' );
}
if ( 'roundtrip' === $action ) {
	$before = rm_hashes();
	$config = rm_ok( ( new DomainBackupRepository() )->configuration_snapshot() );
	$generation = rm_option( DomainGeneration::OPTION );
	$receipts = rm_option( DomainRestoreWorkspace::RECEIPTS_OPTION );
	$builder = new \Certloom\Application\Backup\DomainBackupBuilder();
	$state = rm_ok( $builder->start( bin2hex( random_bytes( 16 ) ), CERTLOOM_VERSION ) );
	$backup_path = $state['path'];
	for ( $slice = 0; empty( $state['complete'] ) && $slice < 1000; $slice++ ) { $state = rm_ok( $builder->continue_build( $state, 7 ) ); }
	rm_assert( ! empty( $state['complete'] ), 'Backup did not complete.' );
	$analysis = rm_ok( ( new \Certloom\Application\Backup\DomainBackupReader() )->analyze( $backup_path ) );
	echo 'PASS  native backup: ' . $analysis['records'] . ' records, ' . $slice . " slices in one WordPress request\n"; flush();
	foreach ( array( 'replace', 'merge' ) as $mode ) {
		$owner = bin2hex( random_bytes( 16 ) );
		$staging = new \Certloom\Application\Restore\DomainRestoreStagingService();
		$restore = rm_ok( $staging->start( $backup_path, $owner ) );
		for ( $slice = 0; empty( $restore['complete'] ) && $slice < 1000; $slice++ ) { $restore = rm_ok( $staging->continue_stage( $restore, 7 ) ); }
		rm_assert( ! empty( $restore['complete'] ), 'Staging did not complete.' );
		if ( 'replace' === $mode ) { rm_ok( $staging->commit_replace( $restore ) ); }
		else {
			$merger = new \Certloom\Infrastructure\Restore\DomainRestoreMergeService();
			$merged = rm_ok( $merger->start( $restore ) );
			for ( $slice = 0; empty( $merged['complete'] ) && $slice < 1000; $slice++ ) { $merged = rm_ok( $merger->continue_merge( $merged, 7 ) ); }
			rm_assert( ! empty( $merged['complete'] ), 'Merge did not complete.' ); rm_ok( $merger->commit( $merged ) );
		}
		rm_assert( $before === rm_hashes(), 'Native ' . $mode . ' changed the backed-up graph.' );
		rm_assert( is_array( rm_ok( $staging->completion_receipt( $owner ) ) ), 'Native restore lost its receipt.' );
		rm_assert( 0 === rm_private_count( $owner ) && ! ( new DomainMaintenanceLock() )->active(), 'Native restore left maintenance state.' );
		echo 'PASS  native ' . $mode . ": serialized backup restores all 14 table hashes\n"; flush();
	}
	// Negative identity case: the same numeric ID with changed data is a distinct
	// imported recipient. Inspect its private Merge graph, then abandon it.
	$owner = bin2hex( random_bytes( 16 ) );
	$restore = rm_ok( $staging->start( $backup_path, $owner ) );
	for ( $slice = 0; empty( $restore['complete'] ) && $slice < 1000; $slice++ ) { $restore = rm_ok( $staging->continue_stage( $restore, 7 ) ); }
	$recipient = $wpdb->get_row( $wpdb->prepare( 'SELECT r.* FROM %i r LEFT JOIN %i s ON s.recipient_id = r.id WHERE r.wp_user_id IS NULL AND s.id IS NULL ORDER BY r.id LIMIT 1', SchemaManager::table( 'recipients' ), SchemaManager::table( 'credential_series' ) ), ARRAY_A );
	rm_assert( is_array( $recipient ), 'An unissued, unlinked recipient is required for the negative identity check.' );
	$stage_table = rm_ok( $workspace_service->workspace_table( $restore['workspace'], 'stage', 'recipients' ) );
	$wpdb->update( $stage_table, array( 'display_name' => '__Different imported recipient__' ), array( 'id' => $recipient['id'] ) );
	$merger = new \Certloom\Infrastructure\Restore\DomainRestoreMergeService();
	$merged = rm_ok( $merger->start( $restore ) );
	for ( $slice = 0; empty( $merged['complete'] ) && $slice < 1000; $slice++ ) { $merged = rm_ok( $merger->continue_merge( $merged, 7 ) ); }
	rm_assert( ! empty( $merged['complete'] ), 'Negative identity Merge did not complete.' );
	$target = rm_ok( $workspace_service->workspace_table( $restore['workspace'], 'merge', 'recipients' ) );
	$preserved = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $target, $recipient['id'] ), ARRAY_A );
	$added = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE display_name = %s AND id <> %d', $target, '__Different imported recipient__', $recipient['id'] ) );
	rm_assert( $preserved === $recipient && (int) $added > 0, 'Merge conflated different recipients sharing a numeric ID.' );
	rm_ok( $workspace_service->abandon( $restore['workspace'] ) );
	rm_assert( $before === rm_hashes(), 'Abandoning private identity check changed live rows.' );
	echo "PASS  different recipient data at the same numeric ID remains distinct; private test graph discarded\n";
	rm_assert( $config === rm_ok( ( new DomainBackupRepository() )->configuration_snapshot() ), 'Roundtrip changed configuration.' );
	update_option( DomainGeneration::OPTION, $generation, false );
	if ( null === $receipts ) { delete_option( DomainRestoreWorkspace::RECEIPTS_OPTION ); } else { update_option( DomainRestoreWorkspace::RECEIPTS_OPTION, $receipts, false ); }
	rm_ok( ( new \Certloom\Infrastructure\Files\PrivateJobFileStore() )->delete( $backup_path ) );
	exit;
}
$issuer_table = SchemaManager::table( 'issuers' );
$issuer = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM %i ORDER BY id LIMIT 1', $issuer_table ), ARRAY_A );
rm_assert( is_array( $issuer ), 'At least one disposable issuer is required.' );
$baseline = rm_hashes();
$initial_generation = rm_option( DomainGeneration::OPTION );
$initial_receipts = rm_option( DomainRestoreWorkspace::RECEIPTS_OPTION );
$initial_config = rm_ok( ( new DomainBackupRepository() )->configuration_snapshot() );
$checks = 0;
register_shutdown_function( static function () {
	foreach ( $GLOBALS['rm_children'] ?? array() as $child ) {
		if ( is_resource( $child[0] ) ) {
			proc_terminate( $child[0] );
			foreach ( $child[1] as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_close( $child[0] );
		}
	}
} );
foreach ( array( 'replace', 'merge' ) as $mode ) {
	foreach ( array( 'prepared', 'renamed', 'swapped', 'committed', 'receipt_retry' ) as $barrier ) {
		$owner = bin2hex( random_bytes( 16 ) );
		$child = rm_spawn( array( 'commit', $mode, $barrier, $owner ) );
		if ( 'receipt_retry' === $barrier ) {
			rm_assert( rm_finish( $child )['committed'], 'Receipt retry worker failed.' );
			rm_assert( rm_hashes() !== $baseline, 'Receipt failure rolled back a committed graph.' );
			$wpdb->update( $issuer_table, array( 'name' => $issuer['name'] ), array( 'id' => $issuer['id'] ) );
			rm_assert( rm_hashes() === $baseline && 0 === rm_private_count( $owner ), 'Receipt replay did not clean up.' );
			foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::JOURNAL_OPTION, DomainRestoreWorkspace::BUILD_OPTION ) as $key ) { rm_assert( null === rm_option( $key ), 'Receipt replay left maintenance state.' ); }
			$checks++; echo 'PASS  ' . $mode . "/receipt_retry: durable commit survives receipt failure; immediate retry finishes cleanup\n"; flush();
			continue;
		}
		$line = trim( (string) fgets( $child[1][1] ) );
		rm_assert( $line === 'BARRIER ' . $barrier, 'Worker failed to reach ' . $mode . '/' . $barrier . ': ' . $line );
		$before_observer = rm_hashes();
		$observer = rm_finish( rm_spawn( array( 'observe' ) ) );
		rm_assert( $observer['hashes'] === $before_observer, 'Normal bootstrap damaged active ' . $barrier );
		rm_assert( $observer['active'], 'Writes reopened during active ' . $barrier );
		// A slow live worker can cross its lease deadline while holding the DDL mutex.
		rm_expire( $owner );
		$observer = rm_finish( rm_spawn( array( 'observe' ) ) );
		rm_assert( $observer['hashes'] === $before_observer && $observer['active'], 'Expired live worker lost its table graph or write boundary.' );
		proc_terminate( $child[0] );
		foreach ( $child[1] as $pipe ) { fclose( $pipe ); } proc_close( $child[0] );
		if ( 'swapped' === $barrier ) {
			$recovery = rm_spawn( array( 'recover-rollback' ) );
			rm_assert( trim( (string) fgets( $recovery[1][1] ) ) === 'BARRIER rolled_back', 'Recovery failed to checkpoint rollback.' );
			rm_assert( rm_hashes() === $baseline, 'Rollback checkpoint did not restore the graph.' );
			proc_terminate( $recovery[0] );
			foreach ( $recovery[1] as $pipe ) { fclose( $pipe ); } proc_close( $recovery[0] );
		}
		// Both normal WordPress bootstraps attempt recovery independently.
		$first = rm_spawn( array( 'observe' ) ); $second = rm_spawn( array( 'observe' ) );
		rm_finish( $first ); rm_finish( $second );
		rm_ok( $workspace_service->recover_interrupted_commit() );
		$after = rm_hashes();
		if ( 'committed' === $barrier ) {
			rm_assert( $after === $before_observer && $after !== $baseline, 'Committed graph was rolled back.' );
			rm_assert( is_array( $workspace_service->completion_receipt( $owner ) ), 'Committed recovery lost its receipt.' );
			$wpdb->update( $issuer_table, array( 'name' => $issuer['name'] ), array( 'id' => $issuer['id'] ) );
		} else { rm_assert( $after === $baseline, 'Interrupted restore failed to recover the original 14-table graph.' ); }
		rm_assert( rm_hashes() === $baseline, 'Fixture did not return the original graph.' );
		rm_assert( null === rm_option( DomainRestoreWorkspace::JOURNAL_OPTION ) && null === rm_option( DomainRestoreWorkspace::BUILD_OPTION ), 'Recovery markers remained.' );
		rm_assert( 0 === rm_private_count( $owner ), 'Private tables remained after recovery.' );
		rm_assert( null === rm_option( DomainMaintenanceLock::OPTION ), 'Expired maintenance lease remained after recovery.' );
		rm_assert( ! ( new DomainMaintenanceLock() )->active(), 'Credential writes stayed paused after recovery.' );
		$checks++; echo 'PASS  ' . $mode . '/' . $barrier . ": active, expired-live, killed-worker and competing recovery\n"; flush();
	}
}
DomainMaintenanceLock::run_internal( static function () use ( $initial_generation, $initial_receipts ) {
	update_option( DomainGeneration::OPTION, $initial_generation, false );
	if ( null === $initial_receipts ) { delete_option( DomainRestoreWorkspace::RECEIPTS_OPTION ); } else { update_option( DomainRestoreWorkspace::RECEIPTS_OPTION, $initial_receipts, false ); }
} );
$final_config = rm_ok( ( new DomainBackupRepository() )->configuration_snapshot() );
rm_assert( $initial_config === $final_config, 'Configuration changed during recovery matrix.' );
echo $checks . " recovery scenarios passed; all 14 live table hashes and configuration restored.\n";
