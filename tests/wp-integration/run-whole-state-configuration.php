<?php
/** Exact Local/MySQL raw configuration capture. All option fixtures roll back; no archive publication. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $backup_sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $backup_sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $backup_sha ) || ! hash_equals( $backup_sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || realpath( ABSPATH ) !== realpath( $site ) || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site or database.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport in fixture acceptance.' ); }, PHP_INT_MAX );

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateConfigurationFormat;
use Certloom\Infrastructure\Backup\WholeStateOptionRepository;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;

$checks = array(); $failure = null;
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
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprint, &$failure ) {
	try {
		global $wpdb;
		$repository = new WholeStateOptionRepository(); $format = new WholeStateConfigurationFormat();
		$pre = $fingerprint(); $queries = array();
		$capture = static function ( $sql ) use ( &$queries, $wpdb ) { if ( false !== strpos( $sql, $wpdb->options ) && ( false !== strpos( $sql, 'SHA2(option_value,256)' ) || false !== strpos( $sql, 'CASE WHEN OCTET_LENGTH(option_value)' ) ) ) { $queries[] = $sql; } return $sql; };
		add_filter( 'query', $capture, PHP_INT_MAX );
		try { $snapshot = $unwrap( $repository->snapshot() ); $verify = $unwrap( $repository->verify( $snapshot['fingerprint'] ) ); } finally { remove_filter( 'query', $capture, PHP_INT_MAX ); }
		$check( true === $verify && 31 === count( $snapshot['options'] ) && 3 === count( $queries ), 'real MySQL captures thirty-one explicit options in two reads and verifies with one descriptor read' );
		$check( $pre === $fingerprint(), 'capture and verification leave tracked business, account, configuration and cron state unchanged' );
		$component = $unwrap( $format->from_snapshot( $snapshot ) ); $encoded = $unwrap( $format->encode( $component ) );
		$check( $unwrap( $format->decode( $encoded ) ) == $component && 21 === count( $component['options'] ), 'actual saved configuration transports its complete scope through canonical component bytes' );
		foreach ( $queries as $i => $sql ) {
			$wpdb->last_error = ''; $plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
			$check( '' === $wpdb->last_error && 1 === count( $plan ) && 'option_name' === $plan[0]['key'] && in_array( $plan[0]['type'], array( 'range', 'ref', 'const' ), true ), 'actual configuration query uses the option-name index, case ' . $i );
		}
		$constants = array(
			\Ecapla_CertVerify_Fields::OPTION_KEY, \Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, \Ecapla_CertVerify_Settings::OPTION_KEY, \Ecapla_CertVerify_Numbering::STATE_OPTION, \Ecapla_CertVerify_Onboarding::OPTION_KEY,
			\Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository::OPTION,
			\Certloom\Infrastructure\Integration\TutorLmsMappingStore::OPTION, \Certloom\Infrastructure\Integration\TutorPendingMappingRepository::OPTION,
			\Certloom\Infrastructure\Integration\WooCommerceMappingStore::OPTION, \Certloom\Infrastructure\Integration\WooCommercePendingMappingRepository::OPTION,
			\Certloom\Infrastructure\Integration\LearnDashMappingStore::OPTION, \Certloom\Infrastructure\Integration\CourseraMappingStore::OPTION, \Certloom\Infrastructure\Integration\CourseraXapiCredentials::OPTION,
			\Certloom\Infrastructure\Integration\CredlyMappingStore::OPTION, \Certloom\Infrastructure\Integration\CredlyConnectionRepository::OPTION,
			\Certloom\Infrastructure\Integration\WebhookSourceRepository::OPTION, \Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository::OPTION,
			\Certloom\Infrastructure\Email\EmailDesignAssignmentRepository::OPTION, \Certloom\Infrastructure\Email\EmailAutomationRuleRepository::OPTION,
			\Certloom\Infrastructure\Backup\ScheduledBackup::OPTION_SCHEDULE, \Certloom\Infrastructure\Backup\ScheduledBackup::OPTION_RETENTION, \Certloom\Infrastructure\Backup\SafetySnapshot::OPTION_RETENTION,
			\Certloom\Infrastructure\Backup\GoogleDriveOAuth::CONFIG_OPTION, \Certloom\Infrastructure\Backup\GoogleDriveOAuth::SECRETS_OPTION, \Certloom\Infrastructure\Backup\GoogleDriveBackup::RETENTION_OPTION,
			\Certloom\Infrastructure\Email\EmailTemplateCatalogRepository::OPTION, \Certloom\Infrastructure\Email\EmailTemplateSettingsRepository::OPTION, \Certloom\Infrastructure\Email\EmailAutomationRunRepository::OPTION,
			\Certloom\Application\Rendering\CustomFontLibrary::OPTION, \Certloom\Infrastructure\Backup\LocalBackupStore::CATALOG_OPTION, \Certloom\Infrastructure\Backup\RemoteBackupCatalog::OPTION,
		); sort( $constants, SORT_STRING );
		$check( $constants === WholeStateOptionInventory::keys(), 'fixed inventory names agree exactly with all thirty-one production store constants' );
		$key = \Ecapla_CertVerify_Onboarding::OPTION_KEY;
		$write = static function ( $key, $bytes ) use ( $check ) { global $wpdb; $wpdb->last_error = ''; $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $key ) ); $count = null === $exists ? $wpdb->insert( $wpdb->options, array( 'option_name' => $key, 'option_value' => $bytes, 'autoload' => 'no' ) ) : $wpdb->update( $wpdb->options, array( 'option_value' => $bytes ), array( 'option_name' => $key ) ); $check( false !== $count && '' === $wpdb->last_error, 'owned raw fixture option saved inside rollback transaction' ); };
		foreach ( array( '', '0', '0000123', 'b:0;', serialize( array( 'registration' => '00123', 'score' => 0, 'name' => "\u{0627}\u{0645}\u{06cc}\u{062f}", 'notices' => array( false, null, 0.0 ) ) ) ) as $i => $storage ) {
			$write( $key, $storage ); $current = $unwrap( $repository->snapshot() ); $record = $current['options'][ $key ];
			$check( $record['exists'] && $storage === base64_decode( $record['storage_base64'], true ) && hash( 'sha256', $storage ) === $record['sha256'] && strlen( $storage ) === $record['bytes'], 'MySQL/PHP agree on exact empty, numeric, Unicode and serialized bytes, case ' . $i );
		}
		$write( $key, 'old' ); DatabaseOptions::invalidate( array( $key ) ); get_option( $key );
		$filter = static function () { return 'filtered fake value'; }; add_filter( 'pre_option_' . $key, $filter, PHP_INT_MAX );
		try { $write( $key, 'new' ); $raw = $unwrap( $repository->snapshot() ); $check( 'filtered fake value' === get_option( $key ) && 'new' === base64_decode( $raw['options'][ $key ]['storage_base64'], true ), 'raw capture bypasses both a warm option cache and a value-spoofing option filter' ); } finally { remove_filter( 'pre_option_' . $key, $filter, PHP_INT_MAX ); }
		$write( 'certloom_whole_qa_excluded', 'before' ); $excluded = $unwrap( $repository->snapshot() ); $write( 'certloom_whole_qa_excluded', 'after' );
		$check( true === $unwrap( $repository->verify( $excluded['fingerprint'] ) ) && ! isset( $excluded['options']['certloom_whole_qa_excluded'] ), 'an unlisted option is never copied through a wildcard scope' );
		foreach ( array( 'change', 'delete', 'insert' ) as $mode ) {
			$write( $key, 'old' );
			if ( 'insert' === $mode ) { $wpdb->last_error = ''; $count = $wpdb->delete( $wpdb->options, array( 'option_name' => 'certloom_backup_schedule' ) ); $check( false !== $count && '' === $wpdb->last_error, 'owned fixture makes one explicit option absent before race test' ); }
			$did = false;
			$mutate = static function ( $sql ) use ( &$did, $mode, $key, $write, $check, $wpdb ) {
				if ( ! $did && false !== strpos( $sql, 'CASE WHEN OCTET_LENGTH(option_value)' ) ) {
					$did = true;
					if ( 'delete' === $mode ) { $wpdb->last_error = ''; $count = $wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ); $check( 1 === $count && '' === $wpdb->last_error, 'owned fixture removes captured option between reads' ); }
					else { $write( 'insert' === $mode ? 'certloom_backup_schedule' : $key, 'insert' === $mode ? 'off' : 'new' ); }
				} return $sql;
			};
			add_filter( 'query', $mutate, PHP_INT_MAX ); try { $rejected = $repository->snapshot(); } finally { remove_filter( 'query', $mutate, PHP_INT_MAX ); }
			$check( $did && is_wp_error( $rejected ) && 'certloom_whole_configuration_changed' === $rejected->get_error_code(), 'actual MySQL detects ' . $mode . ' between descriptor and value reads' );
		}
		$final = $unwrap( $repository->snapshot() ); $write( $key, 'later' );
		$check( is_wp_error( $repository->verify( $final['fingerprint'] ) ), 'final verification detects an edit after all storage records were captured' );
		foreach ( array( 'SHA2(option_value,256)', 'CASE WHEN OCTET_LENGTH(option_value)' ) as $signature ) {
			$inject = static function ( $sql ) use ( $signature ) { return false !== strpos( $sql, $signature ) ? 'SELECT * FROM certloom_nonexistent_whole_configuration_qa' : $sql; };
			$pre = $fingerprint(); $old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
			try { $rejected = $repository->snapshot(); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
			$check( is_wp_error( $rejected ) && 'certloom_whole_configuration_read_failed' === $rejected->get_error_code() && $pre === $fingerprint(), 'actual checked SQL failure refuses capture and changes no state: ' . $signature );
		}
		$dynamic_repository = new \Certloom\Infrastructure\Backup\WholeStateTemplateRecommendationRepository();
		$dynamic_prefix = \Certloom\Application\Backup\WholeStateTemplateRecommendationInventory::PREFIX;
		$check( $dynamic_prefix === \Certloom\Infrastructure\Repository\TemplateRecommendationRepository::OPTION_PREFIX, 'dynamic authoring namespace agrees with its actual production writer' );
		$dynamic_key = $dynamic_prefix . '2147000001'; $dynamic_other = $dynamic_prefix . '2147000002';
		$pre = $fingerprint(); $dynamic_original = $unwrap( $dynamic_repository->snapshot() );
		$check( true === $unwrap( $dynamic_repository->verify( $dynamic_original['fingerprint'] ) ) && $pre === $fingerprint(), 'actual dynamic capture and verification are read-only across all current options and business rows' );
		$write( $dynamic_key, serialize( array( 'training_lms' ) ) );
		DatabaseOptions::invalidate( array( $dynamic_key ) ); get_option( $dynamic_key ); $fake_profiles = static function () { return array( 'fake filtered profiles' ); };
		add_filter( 'pre_option_' . $dynamic_key, $fake_profiles, PHP_INT_MAX );
		try { $dynamic = $unwrap( $dynamic_repository->snapshot() ); $check( serialize( array( 'training_lms' ) ) === base64_decode( $dynamic['options'][ $dynamic_key ]['storage_base64'], true ) && array( 'fake filtered profiles' ) === get_option( $dynamic_key ), 'dynamic raw capture ignores warmed caches and profile-spoofing option filters' ); } finally { remove_filter( 'pre_option_' . $dynamic_key, $fake_profiles, PHP_INT_MAX ); }
		// More than one value page, without requiring these metadata references to be live designs.
		for ( $i = 0; $i < 201; ++$i ) {
			$wpdb->last_error = ''; $name = $dynamic_prefix . ( 2147000100 + $i );
			$count = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => '0', 'autoload' => 'no' ) );
			if ( 1 !== $count || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned paged authoring fixture insertion failed.' ); }
		}
		$dynamic_queries = array(); $capture_dynamic = static function ( $sql ) use ( &$dynamic_queries ) { if ( false !== strpos( $sql, 'FORCE INDEX (option_name)' ) ) { $dynamic_queries[] = $sql; } return $sql; };
		add_filter( 'query', $capture_dynamic, PHP_INT_MAX ); try { $dynamic = $unwrap( $dynamic_repository->snapshot() ); } finally { remove_filter( 'query', $capture_dynamic, PHP_INT_MAX ); }
		$value_pages = array_filter( $dynamic_queries, static function ( $sql ) { return false !== strpos( $sql, 'CASE WHEN' ); } );
		$check( count( $dynamic['options'] ) >= 202 && count( $value_pages ) >= 2 && true === $unwrap( $dynamic_repository->verify( $dynamic['fingerprint'] ) ), 'actual dynamic inventory includes orphan metadata, uses multiple bounded length-guarded pages and verifies its complete namespace' );
		foreach ( $dynamic_queries as $i => $sql ) {
			$wpdb->last_error = ''; $plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
			$check( '' === $wpdb->last_error && 1 === count( $plan ) && 'option_name' === $plan[0]['key'] && 'range' === $plan[0]['type'], 'actual dynamic authoring query uses an option-name range, case ' . $i );
		}
		$authoring_format = new \Certloom\Application\Backup\WholeStateAuthoringFormat();
		$authoring = $unwrap( $authoring_format->from_snapshots( $unwrap( $repository->snapshot() ), $dynamic ) );
		$stream = $unwrap( $authoring_format->encode( $authoring ) );
		$check( $unwrap( $authoring_format->decode( $stream ) ) == $authoring && count( $authoring ) === count( $dynamic['options'] ) + 2 && ! isset( $authoring['certverify_settings'] ), 'actual authoring stream preserves exact fixed email and dynamic metadata storage without duplicating native domain options' );
		foreach ( array( 'change', 'grow', 'delete', 'insert', 'huge' ) as $mode ) {
			$write( $dynamic_key, 'old' );
			$wpdb->last_error = ''; $removed = $wpdb->delete( $wpdb->options, array( 'option_name' => $dynamic_other ) ); $check( false !== $removed && '' === $wpdb->last_error, 'owned phantom reference reset is checked' );
			$did = false; $mutate = static function ( $sql ) use ( &$did, $mode, $dynamic_key, $dynamic_other, $write, $wpdb, $check ) {
				if ( ! $did && false !== strpos( $sql, 'CASE WHEN OCTET_LENGTH(option_value) = CASE option_name' ) ) {
					$did = true;
					if ( 'delete' === $mode ) { $wpdb->last_error = ''; $count = $wpdb->delete( $wpdb->options, array( 'option_name' => $dynamic_key ) ); $check( 1 === $count && '' === $wpdb->last_error, 'owned dynamic row deletion between pages is checked' ); }
					else { $write( 'insert' === $mode ? $dynamic_other : $dynamic_key, 'huge' === $mode ? str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) : ( 'grow' === $mode ? 'longer' : 'new' ) ); }
				} return $sql;
			};
			add_filter( 'query', $mutate, PHP_INT_MAX ); try { $rejected = $dynamic_repository->snapshot(); } finally { remove_filter( 'query', $mutate, PHP_INT_MAX ); }
			$check( $did && is_wp_error( $rejected ) && 'certloom_whole_recommendations_changed' === $rejected->get_error_code(), 'actual dynamic ' . $mode . ' race refuses capture, including namespace phantoms and size growth' );
		}
		$write( $dynamic_key, 'old' ); $dynamic_final = $unwrap( $dynamic_repository->snapshot() ); $write( $dynamic_key, 'new' );
		$check( is_wp_error( $dynamic_repository->verify( $dynamic_final['fingerprint'] ) ), 'final dynamic verification detects a later same-length edit' );
		foreach ( array( 'LIKE', 'CASE WHEN' ) as $signature ) {
			$inject = static function ( $sql ) use ( $signature ) { return false !== strpos( $sql, 'FORCE INDEX (option_name)' ) && false !== strpos( $sql, $signature ) ? 'SELECT * FROM certloom_missing_authoring_qa' : $sql; };
			$pre = $fingerprint(); $old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
			try { $rejected = $dynamic_repository->snapshot(); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
			$check( is_wp_error( $rejected ) && 'certloom_whole_recommendations_read_failed' === $rejected->get_error_code() && $pre === $fingerprint(), 'actual checked dynamic SQL failure changes no state: ' . $signature );
		}
		$malformed_key = $dynamic_prefix . '01'; $write( $malformed_key, 'bad' );
		$check( is_wp_error( $dynamic_repository->snapshot() ), 'malformed actual namespace member cannot be silently omitted from authoring capture' );
		$wpdb->last_error = ''; $check( 1 === $wpdb->delete( $wpdb->options, array( 'option_name' => $malformed_key ) ) && '' === $wpdb->last_error, 'owned malformed authoring fixture cleanup is checked' );
		$write( $dynamic_key, str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) );
		$check( is_wp_error( $dynamic_repository->snapshot() ), 'oversized actual dynamic storage fails before value pages' );
		$write( $dynamic_key, 'old' );
		$root_repository = new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository(); $root_key = \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository::OPTION;
		$actor = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0]; wp_set_current_user( $actor );
		$pre = $fingerprint();
		$check( is_wp_error( $root_repository->ensure( 0 ) ) && is_wp_error( $root_repository->ensure( $actor + 1 ) ) && $pre === $fingerprint(), 'root provisioning requires the current permitted data-tools actor and otherwise changes no state' );
		$identity = $unwrap( $root_repository->ensure( $actor ) );
		$root_storage = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $root_key ) );
		$check( $identity === $unwrap( $root_repository->ensure( $actor ) ) && $root_storage === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $root_key ) ), 'repeated root provisioning preserves the original installation identity and exact sealed key bytes' );
		$root_record = json_decode( $root_storage, true ); $opened = $unwrap( \Certloom\Infrastructure\Backup\SecretVault::open( $root_record['sealed_key'] ) );
		$check( 64 === strlen( $opened['key_hex'] ) && false === strpos( $root_storage, $opened['key_hex'] ) && 'certloom-recovery-root-v1' === $opened['purpose'] && 'no' === $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name=%s', $wpdb->options, $root_key ) ), 'actual recovery key uses authenticated sealing with purpose binding and is not autoloaded or stored in plaintext' ); unset( $opened );
		$after_root = $unwrap( $repository->snapshot() );
		$check( ! isset( $after_root['options'][ $root_key ] ) && ! isset( $unwrap( $format->from_snapshot( $after_root ) )['options'][ $root_key ] ), 'local root is never exported through the option or configuration transport' );
		$manifest = array( 'format' => \Certloom\Application\Backup\WholeStateBackupFormat::FORMAT, 'format_version' => 1, 'created_at' => '2026-10-03T10:00:00Z', 'plugin_version' => CERTLOOM_VERSION, 'scope' => \Certloom\Application\Backup\WholeStateBackupFormat::scope(), 'source' => array( 'installation_id' => $identity['installation_id'], 'export_id' => bin2hex( random_bytes( 16 ) ), 'blog_id' => $identity['blog_id'], 'home_url' => 'http://ecapla.local', 'generation' => '0' ), 'provenance' => array( 'algorithm' => 'site-hmac-sha256', 'tag' => str_repeat( '0', 64 ) ), 'members' => array() );
		foreach ( \Certloom\Application\Backup\WholeStateBackupFormat::required_members() as $member ) { $manifest['members'][ $member ] = array( 'bytes' => 3, 'sha256' => hash( 'sha256', "{}\n" ) ); }
		$pre = $fingerprint(); $signed = $unwrap( $root_repository->sign( $manifest ) ); $verified = $unwrap( $root_repository->verify( $signed ) );
		$check( $verified['provenance_verified'] && ! $verified['ownership_verified'] && ! $verified['restore_ready'] && $pre === $fingerprint(), 'actual origin signing and verification are read-only and grant neither account ownership nor restore readiness' );
		$spoof_root = static function () { return array( 'installation_id' => str_repeat( 'f', 32 ), 'key' => 'forged filter key' ); };
		add_filter( 'pre_option_' . $root_key, $spoof_root, PHP_INT_MAX );
		try { $check( $identity === $unwrap( $root_repository->identity() ) && $verified === $unwrap( $root_repository->verify( $signed ) ), 'local root ignores a spoofing WordPress option filter and uses checked raw JSON storage' ); } finally { remove_filter( 'pre_option_' . $root_key, $spoof_root, PHP_INT_MAX ); }
		foreach ( array( 'member', 'installation', 'blog', 'tag' ) as $mode ) {
			$forged = $signed;
			if ( 'member' === $mode ) { $forged['members']['data/configuration.json']['sha256'] = str_repeat( 'f', 64 ); }
			elseif ( 'installation' === $mode ) { $forged['source']['installation_id'] = str_repeat( 'f', 32 ); }
			elseif ( 'blog' === $mode ) { ++$forged['source']['blog_id']; }
			else { $forged['provenance']['tag'] = str_repeat( '0', 64 ); }
			$check( is_wp_error( $root_repository->verify( $forged ) ) && $pre === $fingerprint(), 'forged ' . $mode . ' claims cannot acquire local provenance or mutate state' );
		}
		$root_record['sealed_key'][ strpos( $root_record['sealed_key'], ':', 3 ) + 1 ] = 'a' === $root_record['sealed_key'][ strpos( $root_record['sealed_key'], ':', 3 ) + 1 ] ? 'b' : 'a';
		$corrupt_storage = ( new \Certloom\Application\Rendering\CanonicalJson() )->encode( $root_record ); $write( $root_key, $corrupt_storage ); $pre = $fingerprint();
		$check( is_wp_error( $root_repository->verify( $signed ) ) && is_wp_error( $root_repository->ensure( $actor ) ) && $pre === $fingerprint(), 'corrupt local sealed root fails authentication and is never silently replaced by setup' );
		$wpdb->last_error = ''; $removed_root = $wpdb->delete( $wpdb->options, array( 'option_name' => $root_key ) ); $check( 1 === $removed_root && '' === $wpdb->last_error, 'owned rollback fixture can simulate an installation without its root' ); $pre = $fingerprint();
		$check( is_wp_error( $root_repository->verify( $signed ) ) && $pre === $fingerprint(), 'fresh-installation verification cannot import a claimed key or generate replacement local authority' );
		foreach ( array( 'error', 'zero' ) as $mode ) {
			$inject = static function ( $sql ) use ( $mode, $root_key ) { return 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, $root_key ) ? ( 'error' === $mode ? 'INSERT INTO certloom_missing_recovery_root_qa (id) VALUES (1)' : 'SELECT 1 WHERE 1=0' ) : $sql; };
			$old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
			try { $rejected = $root_repository->ensure( $actor ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
			$check( is_wp_error( $rejected ) && $pre === $fingerprint(), 'checked root ' . $mode . ' write failure rolls back and does not invent provisioned authority' );
		}
		$write( $root_key, $root_storage ); $check( $identity === $unwrap( $root_repository->identity() ), 'owned fixture restores its exact sealed root for final preservation checks' );
		foreach ( array( 'purpose', 'identity', 'oversized' ) as $mode ) {
			$bad_root = json_decode( $root_storage, true );
			if ( 'purpose' === $mode ) { $bad_root['sealed_key'] = $unwrap( \Certloom\Infrastructure\Backup\SecretVault::seal( array( 'purpose' => 'other', 'installation_id' => $identity['installation_id'], 'blog_id' => $identity['blog_id'], 'key_hex' => str_repeat( 'a', 64 ) ) ) ); }
			elseif ( 'identity' === $mode ) { $bad_root['installation_id'] = str_repeat( 'f', 32 ); }
			$bad_storage = 'oversized' === $mode ? str_repeat( 'x', 4097 ) : ( new \Certloom\Application\Rendering\CanonicalJson() )->encode( $bad_root ); $write( $root_key, $bad_storage ); $pre = $fingerprint();
			$check( is_wp_error( $root_repository->identity() ) && is_wp_error( $root_repository->ensure( $actor ) ) && $pre === $fingerprint(), 'local root refuses ' . $mode . ' mismatch without replacing saved storage' );
		}
		$write( $root_key, $root_storage );
		$write( $key, str_repeat( 'x', WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) );
		$check( is_wp_error( $repository->snapshot() ), 'oversized actual storage fails the descriptor pass before fetching its value' );
		return new WP_Error( 'qa_rollback', 'Successful fixture; roll back all state.' );
	} catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Failed fixture; roll back all state.' ); }
} );
DatabaseOptions::invalidate( array_merge( WholeStateOptionInventory::keys(), array( 'certloom_whole_qa_excluded' ) ) );
DatabaseOptions::invalidate( array( \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository::OPTION ) ); wp_set_current_user( 0 );
$preserved = $before === $fingerprint();
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_rows_options_cron_preserved' => $preserved, 'error_code' => $success ? null : ( is_wp_error( $result ) ? $result->get_error_code() : 'unexpected_result' ), 'environment' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'database' => $wpdb->db_version() ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $success ? 0 : 1 );
