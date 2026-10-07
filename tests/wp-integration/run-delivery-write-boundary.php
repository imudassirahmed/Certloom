<?php
/** Guarded real MySQL delivery transitions and stale leases. No external transport; all fixtures roll back. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $backup_sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $backup_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $backup_sha ) || ! hash_equals( $backup_sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || realpath( ABSPATH ) !== realpath( $site ) || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site or database.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport in fixture acceptance.' ); }, PHP_INT_MAX );

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateConfigurationFormat;
use Certloom\Infrastructure\Backup\WholeStateOptionRepository;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;

$checks = array(); $failure = null; $owned_jobs = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; };
$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
$fingerprint = static function () use ( $tables ) {
	global $wpdb; $out = array();
	foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Business preservation read failed.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); }
	// All core/plugin options, including authentication fallbacks; hash raw bytes without printing values.
	$wpdb->last_error = ''; $rows = $wpdb->get_results( "SELECT option_name,OCTET_LENGTH(option_value) AS bytes,SHA2(option_value,256) AS sha256,autoload FROM {$wpdb->options} ORDER BY option_name", ARRAY_A );
	if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Option preservation read failed.' ); }
	$out['options'] = hash( 'sha256', wp_json_encode( $rows ) ); return $out;
};
$before = $fingerprint();
$option_descriptors = static function () { global $wpdb; $out = array(); foreach ( $wpdb->get_results( "SELECT option_name,OCTET_LENGTH(option_value) AS bytes,SHA2(option_value,256) AS sha256,autoload FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ) as $row ) { $out[ $row['option_name'] ] = hash( 'sha256', wp_json_encode( $row ) ); } return $out; };
$options_before = $option_descriptors();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprint, &$failure, &$owned_jobs ) {
	try {
		global $wpdb;
		$generation = new \Certloom\Infrastructure\Data\DomainGeneration();
		$jobs = new \Certloom\Infrastructure\Job\JobRepository();
		$create = static function ( $repository, $email ) use ( $unwrap ) {
			$hash = hash( 'sha256', random_bytes( 16 ) );
			return $unwrap( $repository->create_delivery( array( 'series_id' => 1, 'version_id' => 1, 'destination' => 'qa', 'dedupe_key' => $hash, 'mapping_sha256' => $hash, 'configuration_sha256' => $hash, 'recipient_sha256' => $hash, 'destination_sha256' => $hash, 'subject_sha256' => $hash, 'html_sha256' => $hash, 'text_sha256' => $hash, 'composition_sha256' => $hash, 'evidence' => array( 'fixture' => 'delivery_repository_only' ) ) ) );
		};
		foreach ( array( true, false ) as $email ) {
			$repository = $email ? new \Certloom\Infrastructure\Repository\EmailDeliveryRepository() : new \Certloom\Infrastructure\Repository\DestinationDeliveryRepository();
			$type = $email ? 'email_deliveries' : 'destination_deliveries'; $label = $email ? 'email: ' : 'destination: ';
			$finish = static function ( $key, $proof, $extra = array() ) use ( $repository, $email ) { $evidence = array_merge( array( 'attempt_token_sha256' => $proof ), $extra ); return $email ? $repository->mark_sent( $key, $evidence ) : $repository->mark_succeeded( $key, 'qa-reference', $evidence ); };
			$start = $generation->read_current(); $key = $create( $repository, $email );
			$check( is_string( $key ) && $start !== $generation->read_current(), $label . 'create advances the recovery marker and inserts one preparing record' );
			$pre = $fingerprint(); $check( is_wp_error( $repository->attach_job( $key, 'invalid' ) ) && $pre === $fingerprint(), $label . 'malformed job identity does not mutate state' );
			$job_key = bin2hex( random_bytes( 16 ) ); $unwrap( $repository->attach_job( $key, $job_key ) );
			$a = bin2hex( random_bytes( 16 ) ); $b = bin2hex( random_bytes( 16 ) ); $unwrap( $repository->mark_sending( $key, $a ) );
			$pre = $fingerprint(); $check( is_wp_error( $repository->mark_sending( $key, $b ) ) && $pre === $fingerprint(), $label . 'second claim cannot replace the sending attempt' );
			$check( is_wp_error( $repository->mark_failed( $key, 'old_preflight' ) ) && $pre === $fingerprint(), $label . 'late preflight failure cannot overwrite an active transport' );
			foreach ( array( hash( 'sha256', $b ), '', array(), 123, null ) as $proof ) {
				$check( is_wp_error( $finish( $key, $proof ) ) && $pre === $fingerprint(), $label . 'invalid or foreign transport proof leaves evidence unchanged' );
			}
			$unwrap( $repository->mark_failed( $key, 'rejected', array( 'attempt_token_sha256' => hash( 'sha256', $a ), 'attempt_a' => 'rejected' ) ) );
			$unwrap( $repository->mark_sending( $key, $b ) ); $pre = $fingerprint();
			foreach ( array( 'success', 'failure', 'uncertain' ) as $operation ) {
				$stale = 'success' === $operation ? $finish( $key, hash( 'sha256', $a ) ) : $repository->mark_failed( $key, 'stale', array( 'attempt_token_sha256' => hash( 'sha256', $a ) ), 'uncertain' === $operation );
				$check( is_wp_error( $stale ) && $pre === $fingerprint(), $label . 'old attempt cannot record ' . $operation . ' after retry' );
			}
			$unwrap( $finish( $key, hash( 'sha256', $b ), array( 'attempt_b' => 'accepted' ) ) ); $row = $unwrap( $repository->find_delivery( $key ) );
			$check( ( $email ? 'sent' : 'succeeded' ) === $row['status'] && 'rejected' === $row['evidence']['attempt_a'] && 'accepted' === $row['evidence']['attempt_b'], $label . 'current result merges locked evidence and records acceptance' );
			$pre = $fingerprint(); $check( true === $finish( $key, hash( 'sha256', $b ), array( 'unreviewed' => 'ignored' ) ) && $pre === $fingerprint(), $label . 'terminal replay is a confirmed no-op' );
			$check( is_wp_error( $repository->mark_failed( $key, 'late', array( 'attempt_token_sha256' => hash( 'sha256', $b ) ), true ) ) && $pre === $fingerprint(), $label . 'accepted delivery never moves backwards' );
			$check( is_wp_error( $repository->mark_sending( $key, $a ) ) && $pre === $fingerprint(), $label . 'accepted record cannot be claimed again' );

			// Each fault is scoped to an owned delivery and removed before preservation reads.
			foreach ( array( 'zero', 'error', 'read', 'generation' ) as $mode ) {
				$fault_key = $create( $repository, $email ); $pre = $fingerprint();
				$inject = static function ( $sql ) use ( $mode, $fault_key, $type, $wpdb ) {
					$target = false !== strpos( $sql, $fault_key ) && false !== strpos( $sql, SchemaManager::table( $type ) );
					if ( 'generation' === $mode && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, $wpdb->options ) && false !== strpos( $sql, 'certloom_domain_data_generation' ) ) { return 'SELECT 1 WHERE 1=0'; }
					if ( $target && ( ( 'read' === $mode && 0 === strpos( $sql, 'SELECT *' ) ) || ( 'read' !== $mode && 'generation' !== $mode && 0 === strpos( $sql, 'UPDATE' ) ) ) ) { return 'zero' === $mode ? 'SELECT 1 WHERE 1=0' : 'SELECT * FROM certloom_missing_delivery_qa'; }
					return $sql;
				};
				$old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
				try { $fault = $repository->attach_job( $fault_key, bin2hex( random_bytes( 16 ) ) ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
				$check( is_wp_error( $fault ) && $pre === $fingerprint(), $label . $mode . ' fault rolls back record and generation' );
			}
			foreach ( array( 'zero', 'error' ) as $mode ) {
				$pre = $fingerprint(); $inject = static function ( $sql ) use ( $mode, $type ) { return 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, SchemaManager::table( $type ) ) ? ( 'zero' === $mode ? 'SELECT 1 WHERE 1=0' : 'SELECT * FROM certloom_missing_delivery_qa' ) : $sql; };
				$old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
				try { $hash = hash( 'sha256', random_bytes( 16 ) ); $fault = $repository->create_delivery( array( 'series_id' => 1, 'version_id' => 1, 'destination' => 'qa', 'dedupe_key' => $hash, 'mapping_sha256' => $hash, 'configuration_sha256' => $hash, 'recipient_sha256' => $hash, 'destination_sha256' => $hash, 'subject_sha256' => $hash, 'html_sha256' => $hash, 'text_sha256' => $hash, 'composition_sha256' => $hash ) ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
				$check( is_wp_error( $fault ) && $pre === $fingerprint(), $label . 'create ' . $mode . ' cannot leave a false-success record or marker' );
			}

			foreach ( array( 'sending', 'accepted', 'queued', 'heartbeat', 'job_failure' ) as $case ) {
				$delivery_key = $create( $repository, $email );
				$job = $unwrap( $jobs->create( array( 'job_type' => $email ? \Certloom\Domain\Job\JobTypes::EMAIL_DELIVERY : \Certloom\Domain\Job\JobTypes::CREDLY_PUBLISH, 'payload' => array( 'delivery_key' => $delivery_key ) ) ) );
				$owned_jobs[] = $job;
				$unwrap( $repository->attach_job( $delivery_key, $job ) ); $token = bin2hex( random_bytes( 16 ) );
				if ( 'queued' !== $case ) { $unwrap( $repository->mark_sending( $delivery_key, $token ) ); }
				if ( 'accepted' === $case ) { $unwrap( $finish( $delivery_key, hash( 'sha256', $token ) ) ); }
				$wpdb->last_error = ''; $written = $wpdb->update( SchemaManager::table( 'jobs' ), array( 'status' => 'running', 'locked_by' => $token, 'locked_until' => '2000-01-01 00:00:00' ), array( 'job_key' => $job ) ); $check( 1 === $written && '' === $wpdb->last_error, $label . 'owned expired job fixture staged exactly' );
				$pre = $fingerprint(); $did = false;
				$inject = static function ( $sql ) use ( $case, &$did, $job, $wpdb ) {
					if ( 'heartbeat' === $case && ! $did && 0 === strpos( $sql, 'SELECT *' ) && false !== strpos( $sql, $job ) && false !== strpos( $sql, 'FOR UPDATE' ) ) { $did = true; $wpdb->update( SchemaManager::table( 'jobs' ), array( 'locked_until' => '2099-01-01 00:00:00' ), array( 'job_key' => $job ) ); }
					if ( 'job_failure' === $case && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, $job ) && false !== strpos( $sql, SchemaManager::table( 'jobs' ) ) ) { return 'SELECT 1 WHERE 1=0'; }
					return $sql;
				};
				add_filter( 'query', $inject, PHP_INT_MAX ); try { $recovered = $jobs->recover_stale_running( 20 ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); }
				$row = $unwrap( $repository->find_delivery( $delivery_key ) ); $job_row = $unwrap( $jobs->find( $job ) );
				if ( 'job_failure' === $case ) {
					$check( is_wp_error( $recovered ) && $pre === $fingerprint(), $label . 'failed terminal job write rolls back uncertainty and generation together' );
					$wpdb->update( SchemaManager::table( 'jobs' ), array( 'locked_until' => '2099-01-01 00:00:00' ), array( 'job_key' => $job ) );
				} elseif ( 'heartbeat' === $case ) { $check( 0 === $recovered && 'sending' === $row['status'] && 'running' === $job_row['status'] && '2099-01-01 00:00:00' === $job_row['locked_until'] && $did, $label . 'renewal after candidate selection is respected before recovery' ); }
				elseif ( 'accepted' === $case ) { $check( 1 === $recovered && 'succeeded' === $job_row['status'] && ( $email ? 'sent' : 'succeeded' ) === $row['status'], $label . 'persisted acceptance closes the expired job without replay' ); }
				else { $check( 1 === $recovered && 'failed' === $job_row['status'] && 'uncertain' === $row['status'], $label . $case . ' expired worker becomes uncertain and terminal atomically' ); }
			}
		}
		$lease_key = \Certloom\Infrastructure\Data\DomainMaintenanceLock::OPTION;
		$original_lease = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name=%s', $wpdb->options, $lease_key ), ARRAY_A );
		foreach ( array( true, false ) as $email ) {
			$repository = $email ? new \Certloom\Infrastructure\Repository\EmailDeliveryRepository() : new \Certloom\Infrastructure\Repository\DestinationDeliveryRepository();
			$prepared = $create( $repository, $email ); $queued = $create( $repository, $email ); $sending = $create( $repository, $email );
			$unwrap( $repository->attach_job( $queued, bin2hex( random_bytes( 16 ) ) ) );
			$unwrap( $repository->attach_job( $sending, bin2hex( random_bytes( 16 ) ) ) ); $attempt = bin2hex( random_bytes( 16 ) ); $unwrap( $repository->mark_sending( $sending, $attempt ) );
			$lease = bin2hex( random_bytes( 16 ) ) . '|' . time() . '|' . ( time() + 120 ) . '|qa';
			$wpdb->last_error = ''; $staged = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $wpdb->options, $lease_key, $lease, 'no' ) );
			$check( false !== $staged && '' === $wpdb->last_error, 'owned maintenance lease staged inside rollback boundary' );
			$pre = $fingerprint();
			$operations = array(
				static function () use ( $create, $repository, $email ) { return $create( $repository, $email ); },
				static function () use ( $repository, $prepared ) { return $repository->attach_job( $prepared, bin2hex( random_bytes( 16 ) ) ); },
				static function () use ( $repository, $queued ) { return $repository->mark_sending( $queued, bin2hex( random_bytes( 16 ) ) ); },
				static function () use ( $repository, $email, $sending, $attempt ) { $proof = array( 'attempt_token_sha256' => hash( 'sha256', $attempt ) ); return $email ? $repository->mark_sent( $sending, $proof ) : $repository->mark_succeeded( $sending, 'qa', $proof ); },
			);
			foreach ( $operations as $operation ) {
				try { $blocked = $operation(); } catch ( RuntimeException $error ) { $blocked = new WP_Error( $error->getMessage(), 'Unwrapped create refusal.' ); }
				$check( is_wp_error( $blocked ) && 'certloom_domain_maintenance_write_locked' === $blocked->get_error_code() && $pre === $fingerprint(), ( $email ? 'email' : 'destination' ) . ': create/attach/claim/finish respect active maintenance without altering records or generation' );
			}
			$wpdb->last_error = ''; $restored_lease = null === $original_lease ? $wpdb->delete( $wpdb->options, array( 'option_name' => $lease_key, 'option_value' => $lease ) ) : $wpdb->update( $wpdb->options, array( 'option_value' => $original_lease['option_value'] ), array( 'option_name' => $lease_key, 'option_value' => $lease ) );
			$check( 1 === $restored_lease && '' === $wpdb->last_error, 'original maintenance option restored exactly inside fixture' );
		}
		$capture = new \Certloom\Infrastructure\Backup\WholeStateDeliveryRepository();
		$codec = new \Certloom\Application\Backup\WholeStateDeliveryRecord();
		$owned = array();
		foreach ( array( true, false ) as $email ) {
			$repository = $email ? new \Certloom\Infrastructure\Repository\EmailDeliveryRepository() : new \Certloom\Infrastructure\Repository\DestinationDeliveryRepository();
			$type = $email ? 'email_deliveries' : 'destination_deliveries';
			for ( $i = 0; $i < 17; ++$i ) { $owned[ $type ][] = $create( $repository, $email ); }
			$wpdb->last_error = ''; $columns = array_column( $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', SchemaManager::table( $type ) ), ARRAY_A ), 'Field' );
			$check( count( $columns ) === count( $codec::columns( $type ) ) && ! array_diff( $columns, $codec::columns( $type ) ), $type . ': closed recovery storage inventory matches the real schema contract' );
		}
		$pre = $fingerprint(); $snapshot = $unwrap( $capture->snapshot() );
		$check( true === $unwrap( $capture->verify( $snapshot ) ) && $pre === $fingerprint(), 'delivery snapshot/final verification are read-only and stable' );
		foreach ( array( 'email_deliveries', 'destination_deliveries' ) as $type ) {
			$cursor = '0'; $count = 0; $bytes = 0; $pages = 0; $queries = array();
			$watch = static function ( $sql ) use ( &$queries ) { if ( false !== strpos( $sql, 'AS storage_bytes FROM' ) ) { $queries[] = $sql; } return $sql; };
			add_filter( 'query', $watch, PHP_INT_MAX );
			try {
				do {
					$records = $unwrap( $capture->read_batch( $snapshot, $type, $cursor ) ); ++$pages;
					$check( count( $records ) <= $capture::PAGE_SIZE, $type . ': actual keyset page respects its sixteen-row bound' );
					foreach ( $records as $record ) {
						$raw = $unwrap( $codec->to_row( $record ) );
						$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( $type ), (int) $record['id'] ), ARRAY_A );
						$check( $raw == $stored, $type . ': opaque storage round trip retains all columns, SQL nulls and exact bytes' );
						$cursor = $record['id']; ++$count; $bytes += $record['bytes'];
					}
				} while ( $records );
			} finally { remove_filter( 'query', $watch, PHP_INT_MAX ); }
			$check( $pages >= 3 && $snapshot['entities'][ $type ]['count'] === $count && $snapshot['entities'][ $type ]['bytes'] === $bytes && $snapshot['entities'][ $type ]['max_id'] === $cursor, $type . ': complete multi-page capture matches exact count, byte sum and final identity' );
			foreach ( $queries as $sql ) {
				$wpdb->last_error = ''; $plans = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
				$check( '' === $wpdb->last_error && 1 === count( $plans ) && 'PRIMARY' === $plans[0]['key'] && 'range' === $plans[0]['type'], $type . ': real keyset EXPLAIN uses the primary index range' );
			}
			$repository = 'email_deliveries' === $type ? new \Certloom\Infrastructure\Repository\EmailDeliveryRepository() : new \Certloom\Infrastructure\Repository\DestinationDeliveryRepository();
			$did = false; $mutate = static function ( $sql ) use ( &$did, $repository, $type, $owned, $unwrap ) {
				if ( ! $did && false !== strpos( $sql, 'AS storage_bytes FROM' ) ) { $did = true; $unwrap( $repository->attach_job( $owned[ $type ][0], bin2hex( random_bytes( 16 ) ) ) ); } return $sql;
			};
			$snapshot = $unwrap( $capture->snapshot() ); add_filter( 'query', $mutate, PHP_INT_MAX );
			try { $changed = $capture->read_batch( $snapshot, $type, '0' ); } finally { remove_filter( 'query', $mutate, PHP_INT_MAX ); }
			$check( $did && is_wp_error( $changed ) && is_wp_error( $capture->verify( $snapshot ) ), $type . ': a delivery writer racing the value page rejects the stale capture and final verification' );
			$snapshot = $unwrap( $capture->snapshot() );
			foreach ( array( 'snapshot', 'page' ) as $mode ) {
				$inject = static function ( $sql ) use ( $mode ) { return ( 'snapshot' === $mode ? false !== strpos( $sql, 'COALESCE(MAX(id),0) AS max_id,COALESCE(MAX(' ) : false !== strpos( $sql, 'AS storage_bytes FROM' ) ) ? 'SELECT * FROM certloom_missing_delivery_capture_qa' : $sql; };
				$old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
				try { $bad = 'snapshot' === $mode ? $capture->snapshot() : $capture->read_batch( $snapshot, $type, '0' ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
				$check( is_wp_error( $bad ), $type . ': failed ' . $mode . ' SQL cannot be an empty successful capture' );
			}
			// Grow an owned evidence value after a valid descriptor; SQL bounds its return.
			$key = $owned[ $type ][1]; $id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE delivery_key=%s', SchemaManager::table( $type ), $key ) );
			$wpdb->last_error = ''; $written = $wpdb->update( SchemaManager::table( $type ), array( 'evidence_json' => str_repeat( 'x', $codec::MAX_ROW_BYTES + 1 ) ), array( 'delivery_key' => $key ) );
			$check( 1 === $written && '' === $wpdb->last_error, $type . ': owned oversized race fixture staged exactly' );
			$check( is_wp_error( $capture->snapshot() ) && is_wp_error( $capture->read_batch( $snapshot, $type, (string) ( (int) $id - 1 ) ) ), $type . ': oversized evidence fails both descriptor and guarded value page rather than being omitted' );
			$wpdb->last_error = ''; $restored = $wpdb->update( SchemaManager::table( $type ), array( 'evidence_json' => '{"fixture":"restored_owned_capture_test"}' ), array( 'delivery_key' => $key ) );
			$check( 1 === $restored && '' === $wpdb->last_error, $type . ': exact owned evidence restoration is checked' );
			$snapshot = $unwrap( $capture->snapshot() );
		}
		return new WP_Error( 'qa_rollback', 'Successful fixture; roll back all state.' );
	} catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Failed fixture; roll back all state.' ); }
} );
$after = $fingerprint(); $preserved = $before === $after;
$options_after = $option_descriptors();
$changed_options = array_values( array_unique( array_merge( array_keys( array_diff_assoc( $options_before, $options_after ) ), array_keys( array_diff_assoc( $options_after, $options_before ) ) ) ) );
$changed_scopes = array_keys( array_diff_assoc( $after, $before ) );
$cron_only = array( 'options' ) === $changed_scopes && array( 'cron' ) === $changed_options;
$scheduler_only = array( 'options' ) === $changed_scopes && ! array_diff( $changed_options, array( 'cron', 'action_scheduler_lock_async-request-runner' ) );
$wpdb->last_error = ''; $current_cron = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'cron' ) );
$no_owned_cron = '' === $wpdb->last_error && is_string( $current_cron );
foreach ( $owned_jobs as $job_key ) { if ( is_string( $current_cron ) && false !== strpos( $current_cron, $job_key ) ) { $no_owned_cron = false; } }
// Another request may advance cron during this probe. We never restore it or
// create scheduled events here; permit only that exact unrelated scope change.
$safe_preservation = $preserved || ( $scheduler_only && $no_owned_cron );
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $safe_preservation;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'exact_original_state_unchanged' => $preserved, 'original_rows_and_noncron_options_preserved' => $preserved || ( $cron_only && $no_owned_cron ), 'original_rows_and_durable_options_preserved' => $safe_preservation, 'current_cron_progress_preserved' => $no_owned_cron, 'external_scheduler_progress_observed' => $scheduler_only, 'changed_scopes' => $changed_scopes, 'changed_option_names' => $changed_options, 'error_code' => $success ? null : ( is_wp_error( $result ) ? $result->get_error_code() : 'unexpected_result' ), 'environment' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'database' => $wpdb->db_version() ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $success ? 0 : 1 );
