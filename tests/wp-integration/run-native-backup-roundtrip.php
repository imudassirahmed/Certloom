<?php
/** Streaming native backup and actual Replace/Merge of the identical backed-up Local graph. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
use Certloom\Application\Backup\DomainBackupBuilder;
use Certloom\Application\Restore\DomainRestorePreflightService;
use Certloom\Application\Restore\DomainRestoreStagingService;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Infrastructure\Restore\DomainRestoreMergeService;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
function round_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function round_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function round_hashes() { global $wpdb; $hashes = array(); foreach ( DomainBackupRepository::entity_types() as $type ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( $type ) ), ARRAY_A ); round_assert( is_array( $rows ) && '' === $wpdb->last_error, 'Cannot hash ' . $type ); $hashes[ $type ] = hash( 'sha256', wp_json_encode( $rows ) ); } return $hashes; }
function round_finish( $state, callable $continue ) { for ( $slice = 0; $slice < 5000 && empty( $state['complete'] ); $slice++ ) { $state = round_ok( $continue( $state ) ); } round_assert( ! empty( $state['complete'] ), 'Bounded operation did not finish.' ); return $state; }
foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { round_assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $key ) ), 'Existing maintenance state; refusing probe.' ); }
$before = round_hashes(); $configuration = round_ok( ( new DomainBackupRepository() )->configuration_snapshot() ); $path = '';
try {
	$builder = new DomainBackupBuilder(); $state = round_ok( $builder->start( bin2hex( random_bytes( 16 ) ), CERTLOOM_VERSION ) ); $path = $state['path'];
	$state = round_finish( $state, static function ( $state ) use ( $builder ) { return $builder->continue_build( $state, 7 ); } );
	round_ok( ( new DomainRestorePreflightService() )->analyze( $path ) );
	echo "PASS  real streaming backup passes integrity and semantic preflight\n";
	foreach ( array( 'replace', 'merge' ) as $mode ) {
		$staging = new DomainRestoreStagingService(); $restore = round_ok( $staging->start( $path, bin2hex( random_bytes( 16 ) ) ) );
		$restore = round_finish( $restore, static function ( $state ) use ( $staging ) { return $staging->continue_stage( $state, 7 ); } );
		if ( 'replace' === $mode ) { round_ok( $staging->commit_replace( $restore ) ); }
		else { $merger = new DomainRestoreMergeService(); $merged = round_finish( round_ok( $merger->start( $restore ) ), static function ( $state ) use ( $merger ) { return $merger->continue_merge( $state, 7 ); } ); round_ok( $merger->commit( $merged ) ); }
		round_assert( $before === round_hashes() && $configuration === round_ok( ( new DomainBackupRepository() )->configuration_snapshot() ), $mode . ' changed the identical live graph/configuration.' );
		$receipt = $staging->completion_receipt( $restore['workspace']['owner_token'] );
		round_assert( is_array( $receipt ) && $mode === ( $receipt['mode'] ?? '' ), 'Missing completion receipt.' );
		echo 'PASS  real ' . $mode . " preserves all fourteen table hashes and current configuration\n";
	}
	foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { round_assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $key ) ), 'Roundtrip left maintenance state.' ); }
	echo "3 live groups, 0 failures\n";
} finally {
	if ( $path && is_file( $path ) ) { round_ok( ( new PrivateJobFileStore() )->delete( $path ) ); }
}
