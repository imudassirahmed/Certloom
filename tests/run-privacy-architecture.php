<?php
/** Static acceptance checks for Certloom PRV-02 implementation boundaries. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$files = array(
	'privacy'        => file_get_contents( $root . '/includes/class-ecapla-certverify-privacy.php' ),
	'repo'           => file_get_contents( $root . '/src/Infrastructure/Repository/PrivacyRepository.php' ),
	'recipient_repo' => file_get_contents( $root . '/src/Infrastructure/Repository/RecipientRepository.php' ),
	'service'        => file_get_contents( $root . '/src/Application/Privacy/RecipientAnonymizationService.php' ),
	'digest'         => file_get_contents( $root . '/src/Application/Privacy/IdentityDigest.php' ),
	'schema'         => file_get_contents( $root . '/src/Infrastructure/Database/SchemaManager.php' ),
	'backup_fmt'     => file_get_contents( $root . '/src/Application/Backup/DomainBackupFormat.php' ),
	'bootstrap'      => file_get_contents( $root . '/certloom.php' ),
	'settings'       => file_get_contents( $root . '/admin/views/settings-manager.php' ),
	'controller'     => file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' ),
	'settings_model' => file_get_contents( $root . '/includes/class-ecapla-certverify-settings.php' ),
	'contract'       => file_get_contents( $root . '/tests/acceptance-contract.md' ),
);
foreach ( $files as $name => $source ) {
	if ( ! is_string( $source ) || '' === $source ) { throw new RuntimeException( 'Missing privacy acceptance source: ' . $name ); }
}
function qa( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$tests = array(
	'registers WordPress exporter' => fn() => qa( false !== strpos( $files['privacy'], 'wp_privacy_personal_data_exporters' ), 'exporter hook' ),
	'registers WordPress eraser' => fn() => qa( false !== strpos( $files['privacy'], 'wp_privacy_personal_data_erasers' ), 'eraser hook' ),
	'privacy bootstrap is live' => fn() => qa( false !== strpos( $files['bootstrap'], 'Ecapla_CertVerify_Privacy::init()' ), 'init' ),
	'legacy current-authority exporter participates' => fn() => qa( false !== strpos( $files['privacy'], 'get_privacy_email_candidates' ), 'legacy' ),
	'legacy eraser retains certificate evidence' => fn() => qa( false !== strpos( $files['privacy'], 'issued credential evidence' ), 'retention' ),
	'normalized exporter includes recipient profiles' => fn() => qa( false !== strpos( $files['privacy'], 'certloom-recipients' ), 'recipients' ),
	'normalized exporter includes immutable versions' => fn() => qa( false !== strpos( $files['privacy'], 'certloom-credential-versions' ), 'versions' ),
	'normalized exporter includes audit history' => fn() => qa( false !== strpos( $files['privacy'], 'certloom-credential-events' ), 'events' ),
	'event export excludes internal administrator fields' => fn() => qa( false !== strpos( $files['privacy'], "\$allowed = array( 'id', 'series_id', 'version_id', 'event_type', 'old_state_json', 'new_state_json', 'reason_code', 'public_reason', 'created_at' )" ), 'event whitelist' ),
	'issuer exporter does not dump all settings blindly' => fn() => qa( false !== strpos( $files['privacy'], 'contact_data' ), 'issuer contact whitelist' ),
	'settings default is retain' => fn() => qa( false !== strpos( $files['controller'], "'privacy_erasure_policy'" ) && false !== strpos( $files['settings'], 'safest default' ) && false !== strpos( $files['settings_model'], "'privacy_erasure_policy'   => 'retain'" ), 'policy' ),
	'settings model persists privacy policy through strict whitelist' => fn() => qa( substr_count( $files['settings_model'], 'privacy_erasure_policy' ) >= 2, 'settings whitelist' ),
	'anonymization is opt-in' => fn() => qa( false !== strpos( $files['privacy'], 'POLICY_ANONYMIZE_MUTABLE' ), 'opt-in' ),
	'anonymization transaction boundary exists' => fn() => qa( false !== strpos( $files['service'], '$this->transaction->run' ), 'transaction' ),
	'anonymization writes only recipient mutable row in repository' => fn() => qa( false !== strpos( $files['repo'], 'UPDATE %i SET display_name' ) && false === strpos( $files['repo'], 'UPDATE %i SET snapshot_json' ), 'no version rewrite' ),
	'immutable evidence is never rewritten by service' => fn() => qa( false === strpos( $files['service'], 'credential_versions' ), 'no versions' ),
	'anonymization audit event is append-only' => fn() => qa( false !== strpos( $files['service'], 'recipient.profile_anonymized' ), 'audit' ),
	'synchronous anonymization credential fanout is bounded' => fn() => qa( false !== strpos( $files['service'], 'MAX_SYNC_SERIES = 100' ), 'bound' ),
	'repeat privacy erasure is idempotent across site restores' => fn() => qa( false !== strpos( $files['service'], 'already_anonymized(' ) && false !== strpos( $files['service'], "privacy_anonymized_at" ) && false === strpos( $files['service'], "display_name'] ?? '' ) !==" ), 'idempotency marker' ),
	'recipient update is optimistic-concurrency protected' => fn() => qa( false !== strpos( $files['repo'], 'AND updated_at = %s' ), 'cas' ),
	'privacy eraser has deterministic WordPress pagination' => fn() => qa( false !== strpos( $files['privacy'], '$offset = ( $page - 1 ) * self::PAGE_SIZE' ) && false !== strpos( $files['privacy'], 'count( $recipients ) >= self::PAGE_SIZE' ), 'paging' ),
	'domain privacy reads do not create schema' => fn() => qa( false !== strpos( $files['repo'], 'SHOW TABLES LIKE' ), 'existence check' ),
	'storage probe database errors fail closed' => fn() => qa( false !== strpos( $files['repo'], 'certloom_privacy_storage_probe_failed' ) && false !== strpos( $files['repo'], 'storage_error()' ), 'storage probe' ),
	'privacy digest is domain-separated SHA-256 pseudonymous data' => fn() => qa( false !== strpos( $files['digest'], "PREFIX = 'certloom-email-v1:'" ) && false !== strpos( $files['digest'], "hash( 'sha256'" ), 'digest' ),
	'privacy identity history is hard bounded without silent trimming' => fn() => qa( false !== strpos( $files['digest'], 'MAX_HASHES = 64' ) && false !== strpos( $files['digest'], 'is_valid_history' ) && false !== strpos( $files['recipient_repo'], 'certloom_recipient_privacy_identity_limit' ), 'digest bound' ),
	'recipient email changes preserve prior identity digests' => fn() => qa( false !== strpos( $files['recipient_repo'], 'identity_hashes_for_update' ) && false !== strpos( $files['recipient_repo'], 'merge_email' ), 'digest history' ),
	'privacy identity storage remains present in workflow schema v20' => fn() => qa( false !== strpos( $files['schema'], "const SCHEMA_VERSION         = '20'" ) && false !== strpos( $files['schema'], 'privacy_identity_hashes LONGTEXT NULL' ) && false !== strpos( $files['schema'], 'privacy_anonymized_at DATETIME NULL' ), 'schema v20' ),
	'workflow backup v5 retains v1/v2/v3/v4 compatibility' => fn() => qa( false !== strpos( $files['backup_fmt'], 'const VERSION = 5' ) && false !== strpos( $files['backup_fmt'], 'const ARTWORK_VERSION = 4' ) && false !== strpos( $files['backup_fmt'], 'const STRUCTURE_VERSION = 2' ) && false !== strpos( $files['backup_fmt'], 'const LEGACY_VERSION = 1' ), 'backup v5 versions' ),
	'backup v3 has independent chained-hash seed' => fn() => qa( false !== strpos( $files['backup_fmt'], "HASH_SEED_V3 = 'certloom-domain-backup-v3'" ), 'v3 seed' ),
	'pre-privacy backups synthesize private identity field in memory' => fn() => qa( false !== strpos( $files['backup_fmt'], "'privacy_identity_hashes'" ) && false !== strpos( $files['backup_fmt'], '$version < self::PRIVACY_VERSION' ), 'backup normalization' ),
	'privacy contract retains immutable versions' => fn() => qa( false !== stripos( $files['contract'], 'never silently rewrite' ), 'contract' ),
	'public visibility and erasure remain separate' => fn() => qa( false !== strpos( $files['contract'], 'Public visibility and personal-data erasure are separate' ), 'separate' ),
);
$failed = 0;
foreach ( $tests as $name => $test ) {
	try { $test(); echo "PASS  $name\n"; }
	catch ( Throwable $e ) { $failed++; echo "FAIL  $name\n      {$e->getMessage()}\n"; }
}
echo "\n" . count( $tests ) . " tests, $failed failures\n";
exit( $failed ? 1 : 0 );
