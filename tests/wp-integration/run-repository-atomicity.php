<?php
/** Real native commands inside one rollback-only outer transaction; no synthetic rows persist. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\PrivacyRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Application\Templates\TemplateDefaults;
function atomic_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function atomic_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function atomic_hashes() { global $wpdb; $hashes = array(); foreach ( DomainBackupRepository::entity_types() as $type ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( $type ) ), ARRAY_A ); atomic_assert( is_array( $rows ) && '' === $wpdb->last_error, 'Cannot hash ' . $type ); $hashes[ $type ] = hash( 'sha256', wp_json_encode( $rows ) ); } return $hashes; }
foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { atomic_assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $key ) ), 'Existing maintenance state; refusing probe.' ); }
$before = atomic_hashes(); $generation = atomic_ok( ( new DomainGeneration() )->read_current() ); $nonce = 'atomic-' . bin2hex( random_bytes( 12 ) ); $failure = '';
$result = ( new Transaction() )->run( function () use ( $nonce, &$failure ) {
	global $wpdb;
	try {
		$issuers = new IssuerRepository(); $issuer = atomic_ok( $issuers->create( array( 'name' => $nonce, 'slug' => $nonce ) ) ); atomic_ok( $issuers->update( $issuer, array( 'name' => $nonce . ' edited' ) ) );
		$programs = new ProgramRepository(); $program = atomic_ok( $programs->create( array( 'title' => $nonce, 'code' => $nonce, 'issuer_id' => $issuer ) ) ); atomic_ok( $programs->update( $program, array( 'description' => 'Rollback-only probe' ) ) );
		$recipients = new RecipientRepository(); $recipient = atomic_ok( $recipients->create( array( 'display_name' => $nonce ) ) ); atomic_ok( $recipients->update( $recipient, array( 'display_name' => $nonce . ' edited' ) ) );
		$batches = new BatchRepository(); $batch = atomic_ok( $batches->create( array( 'name' => $nonce, 'code' => $nonce, 'program_id' => $program ) ) ); atomic_ok( $batches->update( $batch, array( 'name' => $nonce . ' edited', 'code' => $nonce, 'program_id' => $program ) ) ); atomic_ok( $batches->add_recipient( $batch, $recipient ) ); atomic_ok( $batches->remove_recipient( $batch, $recipient ) );
		$signers = new SignerRepository(); $signer = atomic_ok( $signers->create( array( 'display_name' => $nonce, 'slug' => $nonce ) ) ); atomic_ok( $signers->update( $signer, array( 'title' => 'Probe' ) ) ); $assignment = atomic_ok( $signers->add_assignment( $signer, 'program', $program ) ); atomic_ok( $signers->remove_assignment( $signer, $assignment ) ); atomic_ok( $signers->sync_primary_assignment( $signer, 'program', $program ) );
		echo "PASS  Issuer, Program, Recipient, Batch and Signer commands join the outer transaction\n";
		$credentials = new CredentialRepository(); $series = atomic_ok( $credentials->create_series( array( 'recipient_id' => $recipient, 'program_id' => $program, 'batch_id' => $batch, 'issuer_id' => $issuer ) ) );
		$version = atomic_ok( $credentials->create_version( $series, array( 'display_number' => $nonce . '-1' ) ) ); atomic_ok( $credentials->set_current_version( $series, $version ) ); atomic_ok( ( new EventRepository() )->append( array( 'series_id' => $series, 'version_id' => $version, 'event_type' => 'atomicity.probe' ) ) );
		echo "PASS  credential numbers, versions, current pointers and events share the outer transaction\n";
		$number = $nonce . '-failed'; $before_failure = atomic_ok( ( new DomainGeneration() )->read_current() ); $fired = false;
		$filter = static function ( $sql ) use ( &$fired ) {
			if ( ! $fired && preg_match( '/^INSERT\s+INTO\s+`?' . preg_quote( SchemaManager::table( 'credential_versions' ), '/' ) . '`?\s/i', $sql ) ) { $fired = true; return 'SELECT * FROM certloom_intentional_missing_atomicity_table'; }
			return $sql;
		};
		add_filter( 'query', $filter ); $old_suppress = $wpdb->suppress_errors( true );
		try { $failed = $credentials->create_version( $series, array( 'display_number' => $number ) ); } finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $old_suppress ); }
		atomic_assert( $fired && is_wp_error( $failed ) && 'certloom_version_create_failed' === $failed->get_error_code(), 'Version insertion failure not exercised.' );
		atomic_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE display_number=%s', SchemaManager::table( 'credential_numbers' ), $number ) ), 'Failed version stranded a number reservation.' );
		atomic_assert( $before_failure === atomic_ok( ( new DomainGeneration() )->read_current() ), 'Failed nested command leaked a generation marker.' );
		atomic_ok( $credentials->create_version( $series, array( 'display_number' => $number ) ) );
		echo "PASS  failed version insertion rolls back its number and generation before an outer retry\n";
		$record = atomic_ok( $recipients->find( $recipient ) ); atomic_ok( ( new PrivacyRepository() )->anonymize_recipient( $recipient, 'Anonymous probe', $record['updated_at'] ) );
		$templates = new TemplateRepository(); $template = atomic_ok( $templates->create( array( 'name' => $nonce, 'machine_key' => $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) ); atomic_ok( $templates->update_metadata( $template, array( 'name' => $nonce . ' edited' ) ) );
		echo "PASS  privacy changes and template/revision creation stay inside the outer transaction\n";
	} catch ( Throwable $error ) { $failure = $error->getMessage(); }
	return new WP_Error( 'intentional_atomicity_rollback', 'Always roll back all synthetic writes.' );
} );
atomic_assert( is_wp_error( $result ) && 'intentional_atomicity_rollback' === $result->get_error_code(), 'Outer rollback was not confirmed.' );
atomic_assert( $before === atomic_hashes() && $generation === atomic_ok( ( new DomainGeneration() )->read_current() ), 'Rollback-only commands changed the live graph or generation.' );
\Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( DomainGeneration::OPTION ) );
atomic_assert( '' === $failure, $failure );
echo "PASS  all fourteen live table hashes and the generation marker are preserved after rollback\n5 live groups, 0 failures\n";
