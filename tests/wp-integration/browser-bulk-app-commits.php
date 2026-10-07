<?php
/** Owned native bulk/app/settings acceptance on the one authorized Local site. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
$snapshot = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/output/program-recipient-20261003/private-snapshot.json' ), true );
if ( ! is_array( $snapshot ) || ! is_file( $snapshot['path'] ) || ! hash_equals( $snapshot['sha256'], hash_file( 'sha256', $snapshot['path'] ) ) ) { exit( 1 ); }
require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'local' !== DB_NAME || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport.' ); }, PHP_INT_MAX );
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository;
$mode = $argv[1] ?? ''; $pointer = dirname( __DIR__, 2 ) . '/output/program-recipient-20261004/native-bulk-app-journal.json';
$names = array_merge( \Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types(), array( 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map' ) );
wp_set_current_user( 1 );
function bn_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function bn_ok( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() . ( 'qa_cleanup_failed' === $v->get_error_code() ? ': ' . $v->get_error_message() : '' ) ); } return $v; }
function bn_rows( $table ) { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); bn_check( is_array( $rows ) && ! $wpdb->last_error, 'Preservation read failed.' ); return $rows; }
function bn_options() { global $wpdb; $rows = bn_rows( $wpdb->options ); $out = array(); foreach ( $rows as $row ) { $key = $row['option_name']; if ( 0 === strpos( $key, 'certloom_' ) || 0 === strpos( $key, 'certverify_' ) || 'cron' === $key || preg_match( '/^_transient_(?:timeout_)?certloom_(?:bulk_notice|webhook_v2_setup_secret)_1$/D', $key ) ) { $out[$key] = $row; } } ksort( $out ); return $out; }
try {
 if ( 'stage' === $mode ) {
  bn_check( ! is_file( $pointer ), 'Restore the existing native acceptance journal first.' );
  bn_check( ! bn_rows( SchemaManager::table( 'issuance_requests' ) ), 'Existing recipient requests must be isolated before native commits.' );
  foreach ( bn_rows( SchemaManager::table( 'jobs' ) ) as $job ) { bn_check( ! in_array( $job['status'], array( 'queued', 'running' ), true ), 'Existing running work must finish first.' ); }
  bn_check( false === get_transient( \Certloom\Infrastructure\Admin\WebhookV2IntegrationAdmin::SECRET_PREFIX . '1' ), 'Preserve the existing one-time app secret.' );
  $dir = sys_get_temp_dir() . '/certloom-native-commits-' . bin2hex( random_bytes( 8 ) ); bn_check( mkdir( $dir, 0700 ), 'Private acceptance directory failed.' );
  $path = $dir . '/journal.json'; $nonce = bin2hex( random_bytes( 6 ) );
  $journal = array( 'nonce' => $nonce, 'before_options' => bn_options(), 'before_rows' => array(), 'core' => array() );
  foreach ( $names as $name ) { $journal['before_rows'][$name] = bn_rows( SchemaManager::table( $name ) ); }
  foreach ( array( $wpdb->users, $wpdb->posts, $wpdb->postmeta ) as $table ) { $journal['core'][$table] = hash( 'sha256', wp_json_encode( bn_rows( $table ) ) ); }
  $save = static function ( $j ) use ( $path, $pointer ) { bn_check( false !== file_put_contents( $path, wp_json_encode( $j ) ), 'Private journal failed.' ); bn_check( false !== file_put_contents( $pointer, wp_json_encode( array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ) ) ), 'Private journal pointer failed.' ); };
  $save( $journal );
  $journal['owned'] = bn_ok( ( new Transaction() )->run( static function () use ( $nonce ) {
   try {
    $settings = Ecapla_CertVerify_Settings::get_all(); $settings['numbering_mode'] = 'manual'; $settings['certificate_name_source'] = 'profile'; $settings['certificate_name_field_key'] = '';
    bn_check( Ecapla_CertVerify_Settings::save( $settings ), 'Owned numbering settings failed.' );
    $key = 'qa_native_result_' . $nonce; $fields = Ecapla_CertVerify_Fields::get_fields();
    $fields[] = array( 'key' => $key, 'label' => 'QA native assessment score', 'type' => 'number', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'source', 'lifetime' => 'achievement', 'collect_when' => 'never', 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) );
    bn_check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'Owned source result failed.' );
    $bulk = bn_ok( ( new ProgramRepository() )->create( array( 'title' => 'QA Native Bulk ' . $nonce, 'code' => 'QA-NB-' . $nonce ) ) );
    $meta = bn_ok( ( new ScopedFieldContract() )->with_program_metadata( array(), array( $key => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ) );
    $app = bn_ok( ( new ProgramRepository() )->create( array( 'title' => 'QA Native App ' . $nonce, 'code' => 'QA-NA-' . $nonce, 'metadata' => $meta ) ) );
    $templates = new TemplateRepository(); $template = bn_ok( $templates->create( array( 'name' => 'QA Native Design ' . $nonce, 'machine_key' => 'qa-native-design-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
    $row = bn_ok( $templates->find( $template ) ); bn_ok( $templates->publish( $template, $row['current_revision_id'], $row['current_revision']['layout_sha256'] ) );
    bn_ok( $templates->assign( $template, 'program', $bulk ) ); bn_ok( $templates->assign( $template, 'program', $app ) );
    $recipients = array(); foreach ( array( 1, 2 ) as $i ) { $recipients[] = bn_ok( ( new RecipientRepository() )->create( array( 'display_name' => 'QA Native Recipient ' . $nonce . ' ' . $i, 'external_id' => 'qa-native-' . $nonce . '-' . $i ) ) ); }
    return array( 'bulk_program' => $bulk, 'app_program' => $app, 'template' => $template, 'revision' => $row['current_revision_id'], 'recipients' => $recipients, 'field' => $key, 'connection_name' => 'QA Native Connection ' . $nonce );
   } catch ( Throwable $e ) { return new WP_Error( 'qa_stage_failed', $e->getMessage() ); }
  } ) );
  $save( $journal );
  $csv = $dir . '/native-bulk.csv'; $handle = fopen( $csv, 'xb' ); bn_check( is_resource( $handle ), 'Owned CSV failed.' ); fputcsv( $handle, array( 'recipient_id', 'certificate_number' ), ',', '"', '' ); foreach ( $journal['owned']['recipients'] as $i => $id ) { fputcsv( $handle, array( $id, 'QA-NATIVE-' . $nonce . '-' . $i ), ',', '"', '' ); } fclose( $handle );
  file_put_contents( dirname( __DIR__, 2 ) . '/output/program-recipient-20261004/native-bulk-app-public.json', wp_json_encode( $journal['owned'] + array( 'csv' => $csv ), JSON_PRETTY_PRINT ) );
  echo wp_json_encode( array( 'staged' => true, 'owned_programs' => 2, 'owned_recipients_without_email' => 2, 'existing_state_journaled' => true ) ) . "\n"; exit;
 }
 bn_check( is_file( $pointer ), 'Native acceptance journal required.' ); $record = json_decode( file_get_contents( $pointer ), true ); $path = realpath( $record['path'] );
 bn_check( $path && basename( $path ) === 'journal.json' && realpath( dirname( dirname( $path ) ) ) === realpath( sys_get_temp_dir() ) && preg_match( '/^certloom-native-commits-[a-f0-9]{16}$/D', basename( dirname( $path ) ) ) && hash_equals( $record['sha256'], hash_file( 'sha256', $path ) ), 'Exact native journal required.' );
 $j = json_decode( file_get_contents( $path ), true ); $own = $j['owned']; $current = array(); $added = array();
 foreach ( $names as $name ) {
  $current[$name] = bn_rows( SchemaManager::table( $name ) ); $prior = array_column( $j['before_rows'][$name], null, 'id' );
  foreach ( $current[$name] as $row ) {
   if ( isset( $prior[$row['id']] ) ) {
    $original = $prior[$row['id']];
    // Account-provider refresh can touch its timestamp while every recipient
    // fact remains identical. Preserve that current timestamp, never reset it.
    if ( 'recipients' === $name && array_diff_assoc( $row, $original ) === array( 'updated_at' => $row['updated_at'] ) ) {
     foreach ( $j['before_rows'][$name] as &$baseline_row ) { if ( $baseline_row['id'] === $row['id'] ) { $baseline_row['updated_at'] = $row['updated_at']; break; } } unset( $baseline_row ); $original['updated_at'] = $row['updated_at'];
    }
    bn_check( $original === $row, 'Preserve changed original ' . $name . ' row.' ); unset( $prior[$row['id']] );
   } else { $added[$name][] = $row; }
  }
  bn_check( ! $prior, 'Preserve missing original rows.' );
 }
 $series = array_column( $added['credential_series'] ?? array(), 'id' ); $versions = array_column( $added['credential_versions'] ?? array(), 'id' ); $job_keys = array(); $file_paths = array(); $files = new \Certloom\Infrastructure\Files\PrivateJobFileStore();
 foreach ( $added as $name => $rows ) { foreach ( $rows as $row ) {
  if ( 'programs' === $name ) { bn_check( in_array( (int) $row['id'], array( $own['bulk_program'], $own['app_program'] ), true ), 'Unowned Program.' ); }
  elseif ( 'recipients' === $name ) { bn_check( in_array( (int) $row['id'], $own['recipients'], true ) && ! $row['email'] && ! $row['wp_user_id'], 'Unowned Recipient.' ); }
  elseif ( 'templates' === $name ) { bn_check( (int) $row['id'] === $own['template'], 'Unowned design.' ); }
  elseif ( 'template_revisions' === $name ) { bn_check( (int) $row['template_id'] === $own['template'], 'Unowned design revision.' ); }
  elseif ( 'template_assignments' === $name ) { bn_check( (int) $row['template_id'] === $own['template'] && 'program' === $row['scope_type'] && in_array( (int) $row['scope_id'], array( $own['bulk_program'], $own['app_program'] ), true ), 'Unowned assignment.' ); }
  elseif ( 'credential_series' === $name ) { bn_check( (int) $row['program_id'] === $own['bulk_program'] && in_array( (int) $row['recipient_id'], $own['recipients'], true ), 'Unowned credential.' ); }
  elseif ( 'credential_versions' === $name ) { bn_check( in_array( $row['series_id'], $series, true ) && 'bulk_admin' === $row['source_type'], 'Unowned Version.' ); }
  elseif ( in_array( $name, array( 'credential_numbers', 'credential_events' ), true ) ) { bn_check( in_array( $row['series_id'], $series, true ), 'Unowned credential history.' ); }
  elseif ( 'jobs' === $name ) { $payload = json_decode( $row['payload_json'], true ); bn_check( in_array( $row['job_type'], array( 'bulk_issuance_preflight', 'bulk_issuance_commit' ), true ) && 1 === (int) $row['owner_user_id'], 'Unowned job.' ); $job_keys[] = $row['job_key']; foreach ( array( $payload, json_decode( $row['result_json'], true ) ) as $data ) { $scan = static function ( $v ) use ( &$scan, &$file_paths, $files ) { if ( is_array( $v ) ) { foreach ( $v as $item ) { $scan( $item ); } } elseif ( is_string( $v ) && $files->owns( $v ) ) { $file_paths[$v] = true; } }; $scan( $data ); } }
  else { bn_check( false, 'Unreviewed new business data: ' . $name ); }
 } }
 foreach ( $j['core'] as $table => $hash ) { bn_check( hash_equals( $hash, hash( 'sha256', wp_json_encode( bn_rows( $table ) ) ) ), 'Preserve changed ordinary site account/page.' ); }
 if ( in_array( $mode, array( 'rotation_before', 'rotation_after' ), true ) ) {
  $repo = new WebhookV2ConnectionRepository(); $connections = bn_ok( $repo->read() );
  $matches = array_filter( $connections, static function ( $row ) use ( $own ) { return $row['name'] === $own['connection_name'] && $row['program_id'] === $own['app_program']; } );
  bn_check( 1 === count( $matches ), 'One exact owned app connection is required.' ); $connection = reset( $matches );
  $proof = array( 'key' => $connection['key'], 'program_id' => $connection['program_id'], 'fields' => $connection['fields'], 'mapping_revision' => $connection['mapping_revision'], 'revision' => $connection['revision'], 'secret_sha256' => hash( 'sha256', bn_ok( $repo->secret( $connection ) ) ) );
  if ( 'rotation_before' === $mode ) { $j['rotation_before'] = $proof; bn_check( false !== file_put_contents( $path, wp_json_encode( $j ) ) && false !== file_put_contents( $pointer, wp_json_encode( array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ) ) ), 'Private rotation proof save failed.' ); echo "{\"before_native_rotation_journaled\":true,\"secret_values_exported\":false}\n"; exit; }
  $before = $j['rotation_before']; bn_check( $proof['key'] === $before['key'] && $proof['program_id'] === $before['program_id'] && $proof['fields'] === $before['fields'] && $proof['mapping_revision'] === $before['mapping_revision'] && $proof['revision'] === $before['revision'] + 1 && ! hash_equals( $proof['secret_sha256'], $before['secret_sha256'] ), 'Native rotation must replace the signing secret once and retain its exact result ownership.' );
  echo "{\"actual_native_rotation_verified\":true,\"exact_program_and_mapping_preserved\":true,\"signing_secret_changed\":true,\"secret_values_exported\":false}\n"; exit;
 }
 if ( 'drain' === $mode ) {
  $runtime = new \Certloom\Infrastructure\Job\JobRuntime(); $finished = array();
  foreach ( $job_keys as $key ) { for ( $slice = 0; $slice < 12; ++$slice ) { $job = bn_ok( $runtime->repository()->find( $key ) ); if ( ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) { break; } $runtime->dispatcher()->run_scheduled( $key ); } $job = bn_ok( $runtime->repository()->find( $key ) ); bn_check( 'succeeded' === $job['status'], 'Owned native job did not succeed: ' . ( $job['last_error_code'] ?? $job['status'] ) ); $finished[] = array( 'type' => $job['job_type'], 'status' => $job['status'], 'summary' => $job['result']['summary'] ?? array() ); }
  echo wp_json_encode( array( 'actual_scheduler_jobs' => $finished ), JSON_PRETTY_PRINT ) . "\n"; exit;
 }
 $options = bn_options(); $target = $j['before_options'];
 // The nested public-layout fixture restores exact values/autoloads. Retain
 // current surrogate option IDs if that fixture recreated these two rows.
 foreach ( array( 'certverify_settings', 'certloom_domain_data_generation' ) as $key ) { if ( isset( $target[$key], $options[$key] ) ) { $target[$key]['option_id'] = $options[$key]['option_id']; } }
 if ( isset( $options['cron'] ) ) { $cron = maybe_unserialize( $options['cron']['option_value'] ); bn_check( is_array( $cron ), 'Current cron required.' ); foreach ( $cron as &$hooks ) { if ( ! is_array( $hooks ) || ! isset( $hooks['certloom_run_job'] ) ) { continue; } foreach ( $hooks['certloom_run_job'] as $key => $event ) { if ( 1 === count( $event['args'] ?? array() ) && in_array( $event['args'][0], $job_keys, true ) ) { unset( $hooks['certloom_run_job'][$key] ); } } if ( ! $hooks['certloom_run_job'] ) { unset( $hooks['certloom_run_job'] ); } } unset( $hooks ); foreach ( $cron as $stamp => $hooks ) { if ( is_array( $hooks ) && ! $hooks ) { unset( $cron[$stamp] ); } } $target['cron'] = $options['cron']; $target['cron']['option_value'] = maybe_serialize( $cron ); } else { unset( $target['cron'] ); }
 // Preserve disposable watchdog progress produced by ordinary browser traffic.
 // Existing jobs are compared byte-for-byte above; no lifecycle fixture job is owned here.
 foreach ( array( 'certloom_job_retention_last_run', 'certloom_pending_lifecycle_job', 'certloom_whole_upload_prune_cursor', 'certverify_schema_health', 'certloom_domain_schema_health' ) as $key ) { if ( isset( $options[$key] ) ) { $target[$key] = $options[$key]; } else { unset( $target[$key] ); } }
 foreach ( array_unique( array_merge( array_keys( $options ), array_keys( $target ) ) ) as $key ) { if ( preg_match( '/^certloom_rl_(?:credential_verify|legacy_verify)_[0-9]+_[a-f0-9]{40}$/D', $key ) ) { if ( isset( $options[$key] ) ) { $target[$key] = $options[$key]; } else { unset( $target[$key] ); } } }
 $allowed = array( 'certverify_fields', 'certverify_settings', 'certverify_number_sequence_next', 'certloom_domain_data_generation', 'certverify_data_generation', 'certloom_pending_configuration', 'certloom_webhook_sources_v2', 'cron', 'certloom_template_recommendations_' . $own['template'] );
 foreach ( array_unique( array_merge( array_keys( $options ), array_keys( $target ) ) ) as $key ) { if ( ( $options[$key] ?? null ) !== ( $target[$key] ?? null ) ) { bn_check( in_array( $key, $allowed, true ) || preg_match( '/^_transient_(?:timeout_)?certloom_(?:bulk_notice|webhook_v2_setup_secret)_1$/D', $key ), 'Preserve unreviewed setting: ' . $key ); } }
 if ( 'inspect' === $mode ) { echo wp_json_encode( array( 'owned_counts' => array_map( 'count', $added ), 'existing_rows_preserved' => true, 'no_email_delivery' => ! isset( $added['email_deliveries'] ), 'app_connection_count' => count( ( new WebhookV2ConnectionRepository() )->read() ) ) ) . "\n"; exit; }
 bn_check( 'restore' === $mode, 'Choose stage, inspect or restore.' );
 bn_ok( ( new Transaction() )->run( static function () use ( $names, $current, $added, $options, $target, $j ) { global $wpdb; try { foreach ( $names as $name ) { bn_check( $current[$name] === bn_rows( SchemaManager::table( $name ) ), 'Business data changed during cleanup.' ); } bn_check( $options === bn_options(), 'Settings changed during cleanup.' ); foreach ( array_reverse( $names ) as $name ) { foreach ( $added[$name] ?? array() as $row ) { bn_check( 1 === $wpdb->delete( SchemaManager::table( $name ), array( 'id' => $row['id'] ) ) && ! $wpdb->last_error, 'Owned row cleanup failed.' ); } } foreach ( array_unique( array_merge( array_keys( $options ), array_keys( $target ) ) ) as $key ) { if ( ( $options[$key] ?? null ) === ( $target[$key] ?? null ) ) { continue; } $old = $target[$key] ?? null; if ( null === $old ) { $count = $wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $options[$key]['option_value'] ) ); } elseif ( isset( $options[$key] ) ) { $count = $wpdb->update( $wpdb->options, array( 'option_value' => $old['option_value'], 'autoload' => $old['autoload'] ), array( 'option_name' => $key, 'option_value' => $options[$key]['option_value'] ) ); } else { $count = $wpdb->insert( $wpdb->options, $old ); } bn_check( 1 === $count && ! $wpdb->last_error, 'Owned setting cleanup failed.' ); } foreach ( $names as $name ) { bn_check( $j['before_rows'][$name] === bn_rows( SchemaManager::table( $name ) ), 'Original business preservation failed.' ); } ksort( $target ); bn_check( $target === bn_options(), 'Settings preservation failed.' ); return true; } catch ( Throwable $e ) { return new WP_Error( 'qa_cleanup_failed', $e->getMessage() ); } } ) );
 foreach ( array_keys( $file_paths ) as $file ) { bn_check( true === bn_ok( $files->delete( $file ) ), 'Owned job file cleanup failed.' ); }
 \Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array_unique( array_merge( array_keys( $options ), array_keys( $target ) ) ) ); Ecapla_CertVerify_Fields::reset_runtime_cache();
 $csv = dirname( $path ) . '/native-bulk.csv'; bn_check( unlink( $csv ) && unlink( $path ) && rmdir( dirname( $path ) ) && unlink( $pointer ), 'Exact native journal cleanup failed.' );
 echo wp_json_encode( array( 'restored' => true, 'original_business_configuration_and_core_preserved' => true, 'unrelated_cron_and_user_metadata_preserved' => true, 'owned_records_removed' => array_map( 'count', $added ), 'owned_private_files_removed' => count( $file_paths ), 'private_journal_removed' => true ) ) . "\n";
} catch ( Throwable $e ) { fwrite( STDERR, $e->getMessage() . "\n" ); exit( 1 ); }
