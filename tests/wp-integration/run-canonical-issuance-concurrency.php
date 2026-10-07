<?php
/** Actual independent canonical issuance, candidate/approval and cancellation workers. No transport. */
if ( PHP_SAPI !== 'cli' ) { exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { exit( 1 ); }
require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No transport.' ); }, PHP_INT_MAX );

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Recipient\RecipientCollectionService;

function canonical_qa_ok( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( ( $GLOBALS['canonical_qa_stage'] ?? 'command' ) . ': ' . $v->get_error_code() ); } return $v; }
function canonical_qa_write( $path, array $data ) { if ( false === file_put_contents( $path, wp_json_encode( $data ) ) ) { throw new RuntimeException( 'Private signal failed.' ); } }
function canonical_qa_wait( $path ) { $deadline = microtime( true ) + 25; do { clearstatcache( true, $path ); if ( is_file( $path ) ) { return json_decode( file_get_contents( $path ), true ); } usleep( 50000 ); } while ( microtime( true ) < $deadline ); throw new RuntimeException( 'Worker synchronization timed out.' ); }
function canonical_qa_operation( array $f, $op ) {
	$requests = new IssuanceRequestRepository(); $collection = new RecipientCollectionService();
	if ( 'issue' === $op ) { return ( new PendingIssuanceService() )->process( $f['request'] ); }
	if ( 'manual_issue' === $op ) { return ( new \Certloom\Application\Issuance\ManualIssuanceService() )->issue( $f['manual_request'], $f['manual_fingerprint'], $f['actor'] ); }
	if ( 'cancel' === $op ) { return ( new \Certloom\Application\Issuance\PendingRequestAdminService() )->command( $f['actor'], $f['request'], $f['request_revision'], 'cancel' ); }
	if ( 'approve' === $op ) { return $collection->decide( $f['submission'], $f['submission_revision'], true, $f['actor'] ); }
	if ( 'change' === $op ) { return $collection->save_for_user( $f['user'], 0, $f['form_revision'], array( $f['field'] => '00099' ), $f['request'] ); }
	if ( 'unqualify' === $op ) {
		$row = $requests->find( $f['request'] );
		if ( is_wp_error( $row ) ) { return $row; }
		$evidence = json_decode( $row['evidence_json'], true ); $evidence['eligibility'] = 'not_eligible';
		return $requests->update_evidence( $f['request'], $f['request_revision'], hash( 'sha256', 'qa-disqualified:' . $row['public_ref'] ), $evidence, $f['actor'] );
	}
	if ( 'disable_program' === $op ) { return ( new ProgramRepository() )->update( $f['program'], array( 'active' => false ) ); }
	if ( 'change_contract' === $op ) { return ( new ProgramRepository() )->update( $f['program'], array( 'metadata' => $f['new_metadata'] ) ); }
	if ( 'tighten_policy' === $op ) {
		$fields = Ecapla_CertVerify_Fields::get_fields();
		foreach ( $fields as &$field ) { if ( $f['field'] === $field['key'] ) { $field['collection_policy']['help'] = 'Updated organization review for request ' . $f['request']; } } unset( $field );
		return Ecapla_CertVerify_Fields::save_fields( $fields ) ? true : new WP_Error( 'qa_policy_save_failed', 'Policy command failed.' );
	}
	if ( 'unlink' === $op ) {
		return ( new \Certloom\Application\Recipient\RecipientAccountLinkService() )->update( $f['recipient'], array( 'wp_user_id' => null ), $f['account_revision'], true, $f['actor'] );
	}
	throw new RuntimeException( 'Unknown operation.' );
}
function canonical_qa_spawn( $mode, $directory ) { $process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, $mode, $directory ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes ); if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Independent worker unavailable.' ); } fclose( $pipes[0] ); return array( $process, $pipes[1] ); }
function canonical_qa_join( array $worker ) { $out = stream_get_contents( $worker[1] ); fclose( $worker[1] ); $exit = proc_close( $worker[0] ); if ( 0 !== $exit ) { throw new RuntimeException( 'Independent worker failed: ' . trim( $out ) ); } }

$mode = $argv[1] ?? 'matrix'; $directory = $argv[2] ?? null;
if ( in_array( $mode, array( 'hold', 'compete' ), true ) ) {
	try {
		$real = realpath( $directory ); if ( ! $real || realpath( dirname( $real ) ) !== realpath( sys_get_temp_dir() ) || ! preg_match( '/^certloom-isolated-[a-f0-9]{16}$/D', basename( $real ) ) ) { throw new RuntimeException( 'Exact private worker directory required.' ); }
		$sandbox = new CertloomIsolatedLocalSandbox( $real . '/journal.json' ); $sandbox->enter(); $f = json_decode( file_get_contents( $real . '/fixture.json' ), true ); wp_set_current_user( 'change' === ( 'hold' === $mode ? $f['first'] : $f['second'] ) ? $f['user'] : $f['actor'] );
		if ( 'hold' === $mode ) {
			$result = ( new Transaction() )->run( static function () use ( $f, $real ) {
				global $wpdb;
				// Complete the real command but hold its owning transaction before COMMIT.
				// The competitor sees the previous state, then waits on the actual locks.
				$result = canonical_qa_operation( $f, $f['first'] );
				if ( is_wp_error( $result ) ) { return $result; }
				canonical_qa_write( $real . '/first-ready.json', array( 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) ); canonical_qa_wait( $real . '/release.json' );
				return $result;
			} );
		} else { canonical_qa_write( $real . '/second-entered.json', array( 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) ); $result = canonical_qa_operation( $f, $f['second'] ); }
		canonical_qa_write( $real . '/' . ( 'hold' === $mode ? 'first-result' : 'second-result' ) . '.json', is_wp_error( $result ) ? array( 'success' => false, 'error_code' => $result->get_error_code() ) : array( 'success' => true, 'status' => $result['status'] ?? null, 'version_id' => $result['version_id'] ?? null ) );
		$sandbox->leave(); exit;
	} catch ( Throwable $e ) { fwrite( STDERR, $e->getMessage() ); exit( 1 ); }
}

$checks = array(); $failure = null; $workers = array(); $sandbox = null; $cleanup = null;
$check = static function ( $v, $label ) use ( &$checks ) { if ( ! $v ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
	$directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Private QA directory unavailable.' ); }
	$sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter();
	$actor = canonical_qa_ok( wp_insert_user( array( 'user_login' => 'qa-organization', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
	$user = canonical_qa_ok( wp_insert_user( array( 'user_login' => 'qa-recipient', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Recipient' ) ) );
	$recipient = canonical_qa_ok( ( new RecipientRepository() )->find_by_wp_user_id( $user ) ); $check( is_array( $recipient ), 'isolated real WordPress registration links the recipient' );
	$key = 'qa_canonical_id'; $fields = Ecapla_CertVerify_Fields::get_fields();
	$fields[] = array( 'key' => $key, 'label' => 'QA Personal ID', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => \Certloom\Application\Fields\FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'recipient', 'lifetime' => 'recipient', 'collect_when' => 'before_issue', 'approval_required' => true, 'show_on_certificate' => false, 'help' => '' ) );
	$result_key = 'qa_canonical_result';
	$fields[] = array( 'key' => $result_key, 'label' => 'QA Organization Result', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => \Certloom\Application\Fields\FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'staff', 'lifetime' => 'achievement', 'collect_when' => 'never', 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) );
	$check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'isolated organization configures a required private approved field' );
	$metadata = canonical_qa_ok( ( new \Certloom\Application\Fields\ScopedFieldContract() )->with_program_metadata( array(), array( $key => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ) );
	$new_metadata = canonical_qa_ok( ( new \Certloom\Application\Fields\ScopedFieldContract() )->with_program_metadata( array(), array( $key => 'required', $result_key => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ) );
	$program = canonical_qa_ok( ( new ProgramRepository() )->create( array( 'title' => 'QA Concurrency', 'code' => 'QA-RACE', 'metadata' => $metadata ) ) );
	$requests = new IssuanceRequestRepository(); $collection = new RecipientCollectionService(); $i = 0;
	foreach ( array( array( 'issue', 'issue' ), array( 'cancel', 'issue' ), array( 'issue', 'cancel' ), array( 'change', 'approve' ), array( 'approve', 'change' ), array( 'change', 'issue' ), array( 'issue', 'change' ), array( 'unqualify', 'issue' ), array( 'issue', 'unqualify' ), array( 'disable_program', 'issue' ), array( 'issue', 'disable_program' ), array( 'unlink', 'issue' ), array( 'issue', 'unlink' ), array( 'tighten_policy', 'issue' ), array( 'issue', 'tighten_policy' ), array( 'change_contract', 'issue' ), array( 'issue', 'change_contract' ), array( 'change', 'manual_issue' ), array( 'manual_issue', 'change' ), array( 'tighten_policy', 'manual_issue' ), array( 'change_contract', 'manual_issue' ) ) as $ops ) {
		++$i; wp_set_current_user( $actor );
		$check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'organization restores the next isolated policy baseline' );
		canonical_qa_ok( ( new ProgramRepository() )->update( $program, array( 'active' => true, 'metadata' => $metadata ) ) );
		$user = canonical_qa_ok( wp_insert_user( array( 'user_login' => 'qa-case-' . $i, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Case Recipient' ) ) );
		$recipient = canonical_qa_ok( ( new RecipientRepository() )->find_by_wp_user_id( $user ) );
		$row = canonical_qa_ok( $requests->receive( array( 'recipient_id' => $recipient['id'], 'program_id' => $program, 'source_type' => 'manual', 'intake_key' => hash( 'sha256', 'qa-race-' . $i ) ), array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QA-RACE-' . $i ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
		$GLOBALS['canonical_qa_stage'] = 'initial_assessment_' . $i;
		canonical_qa_ok( ( new PendingIssuanceService() )->process( $row['id'] ) );
		$GLOBALS['canonical_qa_stage'] = 'form_read_' . $i;
		// Seed WordPress's installation authentication material before reading the
		// form. The source constants may intentionally delegate to local options.
		wp_salt( 'auth' );
		wp_set_current_user( $user ); $view = canonical_qa_ok( $collection->read_for_user( $user, 0, 0, $row['id'] ) );
		$GLOBALS['canonical_qa_stage'] = 'candidate_save_' . $i;
		canonical_qa_ok( $collection->save_for_user( $user, 0, $view['revision'], array( $key => '00123' ), $row['id'] ) );
		$proposal = canonical_qa_ok( ( new \Certloom\Infrastructure\Repository\FieldSubmissionRepository() )->latest( $recipient['id'], 'recipient', $recipient['id'], $key ) );
		wp_set_current_user( $actor );
		$GLOBALS['canonical_qa_stage'] = 'approval_setup_' . $i;
		if ( ! in_array( 'approve', $ops, true ) && 'approved' !== $proposal['status'] ) { canonical_qa_ok( $collection->decide( $proposal['id'], $proposal['revision'], true, $actor ) ); }
		$row = canonical_qa_ok( $requests->find( $row['id'] ) ); $view = canonical_qa_ok( $collection->read_for_user( $user, 0, 0, $row['id'] ) );
		$recipient = canonical_qa_ok( ( new RecipientRepository() )->find( $recipient['id'] ) );
		$f = array( 'actor' => $actor, 'user' => $user, 'recipient' => $recipient['id'], 'account_revision' => ( new \Certloom\Application\Recipient\RecipientAccountLink() )->revision( $recipient ), 'program' => $program, 'new_metadata' => $new_metadata, 'request' => $row['id'], 'registration' => $row['registration_id'], 'request_revision' => $row['revision'], 'submission' => $proposal['id'], 'submission_revision' => $proposal['revision'], 'field' => $key, 'form_revision' => $view['revision'], 'first' => $ops[0], 'second' => $ops[1] );
		if ( in_array( 'manual_issue', $ops, true ) ) {
			$f['manual_request'] = array( 'recipient_mode' => 'existing', 'recipient_id' => $recipient['id'], 'program_id' => $program, 'registration_id' => $row['registration_id'], 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QA-RACE-' . $i );
			$review = canonical_qa_ok( ( new \Certloom\Application\Issuance\ManualIssuanceService() )->review( $f['manual_request'] ) );
			$f['manual_fingerprint'] = $review['review_fingerprint'];
		}
		canonical_qa_write( $directory . '/fixture.json', $f );
		// New empty cloned tables have no optimizer statistics. Warm their current
		// committed inventory before measuring application row-lock contention.
		foreach ( array_keys( $sandbox->read()['tables'] ) as $table ) {
			$wpdb->last_error = '';
			$wpdb->get_results( $wpdb->prepare( 'ANALYZE TABLE %i', $table ), ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Disposable optimizer preparation failed.' ); }
		}
		$workers['first'] = canonical_qa_spawn( 'hold', $directory ); $first = canonical_qa_wait( $directory . '/first-ready.json' );
		$workers['second'] = canonical_qa_spawn( 'compete', $directory ); $second = canonical_qa_wait( $directory . '/second-entered.json' );
		$waited = false; $deadline = microtime( true ) + 12;
		do {
			$wpdb->last_error = '';
			$waiting = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d AND trx_state='LOCK WAIT'", $second['connection_id'] ) );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Real lock-wait inspection failed.' ); }
			if ( (int) $waiting > 0 ) { $waited = true; break; }
			// InnoDB's information-schema snapshot needs a gap between observations;
			// tight polling can keep returning the pre-wait cached snapshot.
			usleep( 300000 );
		} while ( microtime( true ) < $deadline );
		if ( ! $waited ) {
			$early = is_file( $directory . '/second-result.json' ) ? json_decode( file_get_contents( $directory . '/second-result.json' ), true ) : null;
			$states = $wpdb->get_results( $wpdb->prepare( 'SELECT ID,COMMAND,STATE,LEFT(INFO,200) AS query_prefix FROM information_schema.PROCESSLIST WHERE ID IN (%d,%d)', $first['connection_id'], $second['connection_id'] ), ARRAY_A );
			$transactions = $wpdb->get_results( $wpdb->prepare( 'SELECT trx_mysql_thread_id,trx_state,trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN (%d,%d)', $first['connection_id'], $second['connection_id'] ), ARRAY_A );
			throw new RuntimeException( implode( ' versus ', $ops ) . ': no lock wait; early receipt=' . wp_json_encode( $early ) . '; states=' . wp_json_encode( $states ) . '; transactions=' . wp_json_encode( $transactions ) );
		}
		$check( $first['connection_id'] !== $second['connection_id'] && ! is_file( $directory . '/second-result.json' ), implode( ' versus ', $ops ) . ': independent canonical workers overlap in a real InnoDB lock wait' );
		canonical_qa_write( $directory . '/release.json', array( 'release' => true ) ); canonical_qa_join( $workers['first'] ); unset( $workers['first'] ); canonical_qa_join( $workers['second'] ); unset( $workers['second'] );
		$a = json_decode( file_get_contents( $directory . '/first-result.json' ), true ); $b = json_decode( file_get_contents( $directory . '/second-result.json' ), true ); $row = canonical_qa_ok( $requests->find( $row['id'] ) );
		$versions = $wpdb->get_results( $wpdb->prepare( 'SELECT v.* FROM %i v INNER JOIN %i s ON s.id=v.series_id WHERE s.recipient_id=%d AND s.program_id=%d AND v.display_number=%s', SchemaManager::table( 'credential_versions' ), SchemaManager::table( 'credential_series' ), $recipient['id'], $program, 'QA-RACE-' . $i ), ARRAY_A );
		$check( $a['success'], implode( ' versus ', $ops ) . ': the lock-owning command commits successfully' );
		if ( 'manual_issue' === $ops[1] ) { $check( ! $b['success'] && ! $versions, 'ordinary reviewed manual issuance rejects changed customer details or configuration after a real lock wait' ); }
		elseif ( 'manual_issue' === $ops[0] ) { $check( $b['success'] && 1 === count( $versions ), 'subsequent customer updates preserve the ordinary manual issued snapshot' ); }
		elseif ( array( 'issue', 'issue' ) === $ops ) { $check( $b['success'] && $a['version_id'] === $b['version_id'] && 1 === count( $versions ) && 'issued' === $row['status'], 'simultaneous canonical issuance returns one immutable Version and one request receipt' ); }
		elseif ( 'cancel' === $ops[0] ) { $check( $b['success'] && 'cancelled' === $row['status'] && ! $versions, 'cancellation wins before issuance without reserving a credential number' ); }
		elseif ( 'cancel' === $ops[1] ) { $check( ! $b['success'] && 'issued' === $row['status'] && 1 === count( $versions ), 'late cancellation cannot override the committed canonical receipt' ); }
		elseif ( 'approve' === $ops[1] ) { $check( ! $b['success'] && ! $versions, 'changed candidate invalidates a concurrent stale approval' ); }
		elseif ( 'approve' === $ops[0] ) { $check( ! $b['success'] && ! $versions, 'approval changes the revision and rejects a stale customer form' ); }
		elseif ( 'unqualify' === $ops[0] ) { $check( $b['success'] && 'not_eligible' === $row['status'] && ! $versions, 'new source qualification is current after an issuance lock wait' ); }
		elseif ( 'unqualify' === $ops[1] ) { $check( ! $b['success'] && 'issued' === $row['status'] && 1 === count( $versions ), 'late source disqualification cannot replace an immutable issued receipt' ); }
		elseif ( 'disable_program' === $ops[0] ) { $check( $b['success'] && 'blocked' === $row['status'] && ! $versions, 'a concurrently disabled Program blocks issuance under current context' ); }
		elseif ( 'disable_program' === $ops[1] ) { $check( $b['success'] && 'issued' === $row['status'] && 1 === count( $versions ), 'later Program deactivation preserves the issued Version' ); }
		elseif ( 'unlink' === $ops[0] ) { $check( $b['success'] && 'cancelled' === $row['status'] && ! $versions, 'reviewed account unlink closes unissued work before a waiting issuer proceeds' ); }
		elseif ( 'unlink' === $ops[1] ) { $check( $b['success'] && 'issued' === $row['status'] && 1 === count( $versions ), 'reviewed account unlink preserves concurrent issued history' ); }
		elseif ( in_array( $ops[0], array( 'tighten_policy', 'change_contract' ), true ) ) { $check( $b['success'] && 'waiting' === $row['status'] && ! $versions, 'changed field policy or Program requirements are current after an issuance lock wait' ); }
		elseif ( in_array( $ops[1], array( 'tighten_policy', 'change_contract' ), true ) ) { $check( $b['success'] && 'issued' === $row['status'] && 1 === count( $versions ), 'subsequent field policy or Program requirements preserve issued history' ); }
		elseif ( 'change' === $ops[0] ) { $check( $b['success'] && 'waiting' === $row['status'] && ! $versions, 'changed approved details block concurrent issuance pending a new approval; competitor=' . wp_json_encode( $b ) . '; status=' . $row['status'] . '; versions=' . count( $versions ) ); }
		else { $check( ! $b['success'] && 'issued' === $row['status'] && 1 === count( $versions ), 'issued request rejects a late customer edit without changing history' ); }
		if ( $versions ) { $version = canonical_qa_ok( ( new \Certloom\Infrastructure\Repository\CredentialRepository() )->find_version( (int) $versions[0]['id'] ) ); $check( '00123' === $version['snapshot']['fields']['values'][ $key ], 'immutable issuance preserves the exact approved leading-zero answer' ); }
		foreach ( array( 'fixture.json', 'first-ready.json', 'second-entered.json', 'first-result.json', 'second-result.json', 'release.json' ) as $file ) { if ( ! unlink( $directory . '/' . $file ) ) { throw new RuntimeException( 'Signal cleanup failed.' ); } }
	}
	$check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', SchemaManager::table( 'email_deliveries' ) ) ), 'no customer transport is queued by blank-destination canonical fixtures' );
} catch ( Throwable $e ) { $failure = get_class( $e ) . ': ' . $e->getMessage(); }
finally {
	if ( $workers ) { canonical_qa_write( $directory . '/release.json', array( 'release' => true ) ); foreach ( $workers as $worker ) { try { canonical_qa_join( $worker ); } catch ( Throwable $e ) { if ( null === $failure ) { $failure = $e->getMessage(); } } } }
	if ( $sandbox ) { try { $cleanup = $sandbox->cleanup(); foreach ( array( 'fixture.json', 'first-ready.json', 'second-entered.json', 'first-result.json', 'second-result.json', 'release.json' ) as $file ) { if ( is_file( $directory . '/' . $file ) && ! unlink( $directory . '/' . $file ) ) { throw new RuntimeException( 'Private signal cleanup failed.' ); } } if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Private directory cleanup failed.' ); } $directory = null; } catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
