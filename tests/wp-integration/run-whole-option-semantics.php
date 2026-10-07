<?php
/** Complete fixed policy dispatch against actual WP stores, in exact disposable tables. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Restore\WholeStateOptionSemantics;
function os_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
$checks = array(); $failure = null; $cleanup = null; $sandbox = null; $directory = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
 $directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); $check( mkdir( $directory, 0700 ), 'exact owned private fixture created' );
 $sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter();
 $actor = os_ok( wp_insert_user( array( 'user_login' => 'qa-options-owner', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
 $semantics = new WholeStateOptionSemantics(); $repo = new \Certloom\Infrastructure\Backup\WholeStateOptionRepository(); $format = new WholeStateOptionRecord();
 $missing = os_ok( $repo->snapshot() ); $check( count( os_ok( $semantics->validate_set( $missing['options'] ) ) ) === 31, 'complete explicit absence is accepted without generating runtime settings' );
 $check( Ecapla_CertVerify_Fields::save_fields( Ecapla_CertVerify_Fields::get_default_fields() ), 'actual field store persists its native current catalog' );
 $check( Ecapla_CertVerify_Settings::save( Ecapla_CertVerify_Settings::defaults() ), 'actual settings store persists its current policy and appearance' );
 $email = new \Certloom\Infrastructure\Email\EmailTemplateSettingsRepository(); os_ok( $email->save( \Certloom\Application\Email\EmailTemplateService::defaults() ) );
 $check( count( ( new \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository() )->all() ) > 0, 'actual email save establishes both catalog and compatibility authorities' );
 $values = array(
  'certverify_retired_fields' => array(), 'certverify_number_sequence_next' => 1,
  'certverify_onboarding' => Ecapla_CertVerify_Onboarding::defaults(),
  'certloom_global_scoped_fields_v1' => \Certloom\Application\Fields\ScopedFieldContract::empty_global( true ),
  'certloom_tutor_lms_mappings' => array( 'schema_version' => 1, 'mappings' => array() ), 'certloom_tutor_pending_mappings_v1' => array( 'schema_version' => 1, 'mappings' => array() ),
  'certloom_woocommerce_mappings' => array( 'schema_version' => 1, 'mappings' => array() ), 'certloom_woocommerce_pending_mappings_v1' => array( 'schema_version' => 1, 'mappings' => array() ),
  'certloom_learndash_mappings' => array( 'schema_version' => 1, 'mappings' => array() ), 'certloom_coursera_mappings_v1' => array( 'schema_version' => 1, 'mappings' => array() ), 'certloom_credly_mappings_v1' => array( 'schema_version' => 1, 'mappings' => array() ),
  'certloom_webhook_sources_v1' => array( 'schema_version' => 1, 'sources' => array() ), 'certloom_webhook_sources_v2' => array( 'schema_version' => 2, 'connections' => array() ),
  'certloom_email_design_assignments_v1' => array(), 'certloom_email_automation_rules_v1' => array(), 'certloom_email_automation_runs_v1' => array(),
  'certloom_backup_schedule' => 'daily', 'certloom_backup_retention' => 60, 'certloom_safety_backup_retention' => 30,
  'certloom_google_drive_retention' => 60, 'certloom_google_drive_config' => array( 'client_id' => '123-abc.apps.googleusercontent.com', 'connection_mode' => 'direct', 'enabled' => 0, 'folder_id' => '', 'connected_at' => '', 'connected_ts' => 0 ),
  'certloom_google_drive_secrets' => os_ok( \Certloom\Infrastructure\Backup\SecretVault::seal( array( 'client_secret' => 'qa-only' ) ) ),
  'certloom_custom_font_library_v1' => array(), 'certloom_local_backup_catalog' => array(), 'certloom_remote_backup_catalog' => array(),
 );
 foreach ( $values as $key => $value ) { os_ok( ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( $key, $value ) ); }
 os_ok( \Certloom\Infrastructure\Integration\CourseraXapiCredentials::rotate() ); os_ok( ( new \Certloom\Infrastructure\Integration\CredlyConnectionRepository() )->save( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', str_repeat( 'q', 32 ), 'sandbox' ) );
 foreach ( array(
  new \Certloom\Infrastructure\Integration\TutorLmsMappingStore(), new \Certloom\Infrastructure\Integration\WooCommerceMappingStore(), new \Certloom\Infrastructure\Integration\LearnDashMappingStore(), new \Certloom\Infrastructure\Integration\CourseraMappingStore(), new \Certloom\Infrastructure\Integration\CredlyMappingStore(),
 ) as $mapping_store ) {
  $row = array( 'course_id' => $mapping_store instanceof \Certloom\Infrastructure\Integration\CourseraMappingStore ? 'qa-indexed-course' : 73, 'product_id' => 74, 'program_id' => 17, 'batch_id' => 0, 'template_id' => 0, 'badge_template_id' => 'qa-indexed-badge', 'active' => true );
  os_ok( $mapping_store->save( $row ) ); $stored = get_option( $mapping_store::OPTION );
  $check( $stored === os_ok( $mapping_store->validate_recovery_value( $stored ) ), $mapping_store::OPTION . ': actual native indexed mapping round trips without changing its keys or values' );
  $bad_index = $stored; $bad_index['mappings'] = array( 'wrong-index' => reset( $stored['mappings'] ) );
  $check( is_wp_error( $mapping_store->validate_recovery_value( $bad_index ) ), $mapping_store::OPTION . ': mismatched storage identity cannot silently rekey on recovery' );
 }
 $source = os_ok( $repo->snapshot() ); $decoded = os_ok( $semantics->validate_set( $source['options'] ) );
 $check( count( array_filter( $decoded, static function ( $row ) { return $row['exists']; } ) ) === 31, 'every fixed policy validates native current storage without dropping a setting' );
 $check( '60' === $decoded['certloom_backup_retention']['value'], 'raw SQL scalar option storage retains canonical text without pretending it is serialized integer storage' );
 foreach ( WholeStateOptionInventory::keys() as $key ) {
  $bad = $source['options']; $bad[$key] = os_ok( $format->from_storage( serialize( array( 'unreviewed_scope' => true ) ) ) );
  $check( is_wp_error( $semantics->validate_set( $bad ) ), $key . ': malformed value cannot enter recovery through a blanket option allowlist' );
 }
 $bad = $source['options']; unset( $bad['certverify_onboarding'] ); $check( is_wp_error( $semantics->validate_set( $bad ) ), 'partial fixed inventory is rejected before semantic review' );
 $settings = $decoded['certverify_settings']['value']; $settings['pending_request_notice_since']['completion'] = "2026-10-04 12:00:0\0";
 $check( is_wp_error( ( new \Certloom\Application\Issuance\PendingRequestPolicy() )->normalize_notice_since( $settings['pending_request_notice_since'] ) ), 'hostile notice date returns a policy error without throwing or changing state' );
 $check( is_wp_error( $semantics->validate_value( 'certverify_settings', array_merge( $settings, array( 'verification_brand_name' => array( 'invalid' ) ) ) ) ), 'nested settings cannot be cast into display text' );
 $check( is_wp_error( $semantics->validate_value( 'certloom_backup_retention', '060' ) ) && is_wp_error( $semantics->validate_value( 'certloom_safety_backup_retention', '31' ) ), 'retention validation uses exact current store limits and canonical storage' );
 $recommendations = array( 'certloom_template_recommendations_17' => os_ok( $format->from_storage( serialize( array( 'training_lms' ) ) ) ) );
 $check( $recommendations === $semantics->validate_recommendations( $recommendations ), 'dynamic metadata is validated against current recommendation profiles' );
 $bad = $recommendations; $bad['certloom_template_recommendations_17'] = os_ok( $format->from_storage( serialize( array( 'training_lms', 'training_lms' ) ) ) ); $check( is_wp_error( $semantics->validate_recommendations( $bad ) ), 'duplicate dynamic metadata cannot silently normalize on restore' );
 $check( $source['fingerprint'] === os_ok( $repo->snapshot() )['fingerprint'], 'all policy and hostile-source checks leave exact source bytes unchanged' );
 // Exact raw publication is a short checked transaction under the owned fence.
 $publication = new \Certloom\Infrastructure\Restore\WholeStateOptionPublication();
 $before = os_ok( $publication->snapshot() ); $owner = bin2hex( random_bytes( 16 ) ); $maintenance = new \Certloom\Infrastructure\Data\DomainMaintenanceLock(); os_ok( $maintenance->acquire( $owner, 'whole_option_qa', 120 ) );
 $proposed = $before; $settings = $decoded['certverify_settings']['value']; $settings['credit_text'] = 'QA raw settings';
 $proposed['fixed']['certverify_settings'] = os_ok( $format->from_storage( serialize( $settings ) ) );
 $proposed['fixed']['certloom_backup_schedule'] = os_ok( $format->from_storage( 'off' ) );
 $proposed['fixed']['certloom_email_template_issued_v1'] = os_ok( $format->from_storage( null ) ); unset( $proposed['autoloads']['certloom_email_template_issued_v1'] );
 $proposed['recommendations'] = $recommendations; $proposed['autoloads']['certloom_template_recommendations_17'] = 'off'; $proposed['autoloads']['certverify_settings'] = 'yes'; ksort( $proposed['autoloads'] );
 $check( is_array( $publication->validate( $proposed ) ), 'raw publication scope supports exact absence, changed bytes and one dynamic namespace' );
 foreach ( array( 'settings', 'schedule', 'delete', 'insert', 'commit' ) as $point ) {
  foreach ( array( 'sql_error', 'zero_write' ) as $mode ) {
   if ( 'commit' === $point && 'zero_write' === $mode ) { continue; }
   $fired = false;
   $fault = static function ( $query ) use ( $point, $mode, &$fired, $wpdb ) {
    $wanted = array( 'settings' => 'certverify_settings', 'schedule' => 'certloom_backup_schedule', 'delete' => 'certloom_email_template_issued_v1', 'insert' => 'certloom_template_recommendations_17' );
    if ( $fired || ( 'commit' === $point ? 'COMMIT' !== trim( $query ) : ( strpos( $query, $wpdb->options ) === false || strpos( $query, $wanted[$point] ) === false || ! preg_match( '/^(?:UPDATE|INSERT|DELETE) /', $query ) ) ) ) { return $query; }
    $fired = true;
    if ( 'sql_error' === $mode ) { return 'SELECT * FROM `' . $wpdb->prefix . 'certloom_qa_missing_option_fault`'; }
    return 'insert' === $point ? 'UPDATE `' . $wpdb->options . '` SET option_id=option_id WHERE 1=0' : $query . ' AND 1=0';
   };
   $suppressed = $wpdb->suppress_errors( true ); add_filter( 'query', $fault, PHP_INT_MAX ); $failed = $publication->apply( $proposed, $owner, $before ); remove_filter( 'query', $fault, PHP_INT_MAX ); $wpdb->suppress_errors( $suppressed );
   $check( $fired && is_wp_error( $failed ), $point . ' ' . $mode . ': actual option mutation cannot return successful publication' );
   $check( $publication->fingerprint( os_ok( $publication->snapshot() ) ) === $publication->fingerprint( $before ), $point . ' ' . $mode . ': all raw values, presence, dynamic metadata and autoloads roll back together' );
  }
 }
 os_ok( $publication->apply( $proposed, $owner, $before ) ); $after = os_ok( $publication->snapshot() );
 $check( $publication->fingerprint( $after ) === $publication->fingerprint( $proposed ), 'actual checked COMMIT publishes every exact raw option and autoload flag' );
 $check( is_wp_error( $publication->apply( $before, $owner, $before ) ), 'stale raw target baseline cannot replace newer published configuration' );
 $bad = $proposed; $bad['fixed']['certloom_domain_write_authority'] = os_ok( $format->from_storage( 'domain' ) );
 $check( is_wp_error( $publication->apply( $bad, $owner ) ), 'archive-selected entitlement, authority and extra option names are never publication scope' );
 $bad = $proposed; $bad['recommendations']['certloom_template_recommendations_017'] = $recommendations['certloom_template_recommendations_17']; $bad['autoloads']['certloom_template_recommendations_017'] = 'off';
 $check( is_wp_error( $publication->apply( $bad, $owner ) ), 'noncanonical dynamic IDs cannot alias another template during publication' );
 os_ok( $publication->apply( $before, $owner, $proposed ) ); os_ok( $maintenance->release( $owner ) );
 $check( $publication->fingerprint( os_ok( $publication->snapshot() ) ) === $publication->fingerprint( $before ), 'exact pre-publication bytes and autoload values restore without touching other options' );
} catch ( Throwable $e ) { $failure = get_class( $e ) . ': ' . $e->getMessage(); }
finally {
 if ( $sandbox ) { try { $cleanup = $sandbox->cleanup(); if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Owned directory cleanup failed.' ); } $directory = null; } catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
