<?php
/** Phase 1E structural acceptance for Batches, Issuers, Signers, history and restore ownership. */
$root = dirname( __DIR__ );
$files = array(
	'schema'      => 'src/Infrastructure/Database/SchemaManager.php',
	'batches'     => 'src/Infrastructure/Repository/BatchRepository.php',
	'signers'     => 'src/Infrastructure/Repository/SignerRepository.php',
	'issuers'     => 'src/Infrastructure/Repository/IssuerRepository.php',
	'programs'    => 'src/Infrastructure/Repository/ProgramRepository.php',
	'recipients'  => 'src/Infrastructure/Repository/RecipientRepository.php',
	'credentials' => 'src/Infrastructure/Repository/CredentialRepository.php',
	'admin'       => 'src/Infrastructure/Admin/DomainStructureAdmin.php',
	'domain_admin'=> 'src/Infrastructure/Admin/DomainAdmin.php',
	'backup_fmt'  => 'src/Application/Backup/DomainBackupFormat.php',
	'backup_repo' => 'src/Infrastructure/Backup/DomainBackupRepository.php',
	'row_valid'   => 'src/Application/Restore/DomainRestoreRowValidator.php',
	'preflight'   => 'src/Application/Restore/DomainRestorePreflightService.php',
	'staging'     => 'src/Application/Restore/DomainRestoreStagingService.php',
	'merge'       => 'src/Infrastructure/Restore/DomainRestoreMergeService.php',
	'workspace'   => 'src/Infrastructure/Restore/DomainRestoreWorkspace.php',
	'proof'       => 'src/Infrastructure/Diagnostics/DomainAcceptanceProof.php',
	'permissions' => 'includes/class-ecapla-certverify-permissions.php',
	'plugin'      => 'src/Plugin.php',
	'bootstrap'   => 'certloom.php',
	'uninstall'   => 'uninstall.php',
	'js'          => 'admin/assets/admin.js',
);
$src = array();
foreach ( $files as $key => $file ) {
	$src[ $key ] = file_get_contents( $root . '/' . $file );
	if ( ! is_string( $src[ $key ] ) || '' === $src[ $key ] ) {
		throw new RuntimeException( 'Required Phase 1E source unavailable: ' . $file );
	}
}
$checks = 0;
function p1e_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$checks++;
}
function p1e_has( $source, $needle, $message ) { p1e_check( false !== strpos( $source, $needle ), $message ); }

p1e_has( $src['schema'], "const SCHEMA_VERSION         = '13'", 'Expanded normalized domain must use schema contract v13.' );
foreach ( array( 'signers', 'batches', 'batch_recipients', 'signer_assignments' ) as $table ) {
	p1e_has( $src['schema'], "'$table'", 'Schema table map missing ' . $table . '.' );
}
p1e_has( $src['schema'], 'UNIQUE KEY code (code)', 'Batch code must be unique.' );
p1e_has( $src['schema'], 'UNIQUE KEY slug (slug)', 'Signer/issuer machine identity must use unique slug indexes.' );
p1e_has( $src['schema'], 'UNIQUE KEY batch_recipient (batch_id,recipient_id)', 'Batch membership must be unique.' );
p1e_has( $src['schema'], 'UNIQUE KEY signer_scope (signer_id,scope_type,scope_id,role_type)', 'Signer assignment identity must be deterministic.' );
p1e_has( $src['schema'], 'batch_id BIGINT UNSIGNED NULL', 'Credential Series must support nullable Batch identity.' );
p1e_check( substr_count( $src['schema'], 'ENGINE=InnoDB' ) >= 4, 'New normalized entity tables must explicitly use InnoDB.' );

p1e_has( $src['batches'], 'certloom_batch_code_duplicate', 'Batch writes need actionable duplicate-code failures.' );
p1e_has( $src['batches'], 'members_page(', 'Batch memberships must support bounded pagination.' );
p1e_has( $src['batches'], 'recipient_count', 'Batch list must expose bounded membership counts.' );
p1e_has( $src['batches'], 'credential_count', 'Batch list must expose credential activity counts.' );
p1e_has( $src['batches'], 'certloom_batch_list_failed', 'Batch list reads must fail closed.' );
p1e_has( $src['batches'], 'membership_exists', 'Batch membership must prove idempotency.' );
p1e_has( $src['batches'], "array( 'batches' => \$batch_id, 'recipients' => \$recipient_id )", 'Batch membership must validate both related entities.' );
p1e_has( $src['batches'], 'instructor_signer_id', 'Batch model must support an instructor signer profile.' );
p1e_has( $src['batches'], 'start_date', 'Batch model must carry cohort dates.' );
p1e_has( $src['batches'], 'metadata', 'Batch model must preserve structured metadata.' );

p1e_has( $src['signers'], 'certloom_signer_slug_duplicate', 'Signer machine key must have actionable collision handling.' );
p1e_has( $src['signers'], "array( 'signer', 'instructor' )", 'Signer roles must include signer and instructor.' );
p1e_has( $src['signers'], "array( 'global', 'issuer', 'program', 'batch' )", 'Signer assignments must support all planned hierarchy scopes.' );
p1e_has( $src['signers'], 'resolve_hierarchy(', 'Signer hierarchy resolver must exist.' );
p1e_has( $src['signers'], 'normalize_context(', 'Signer hierarchy context must be normalized before lookup.' );
p1e_has( $src['signers'], 'The selected Batch does not belong to the supplied Program.', 'Contradictory Batch/Program hierarchy must fail closed.' );
p1e_has( $src['signers'], 'The selected Program does not belong to the supplied Issuer.', 'Contradictory Program/Issuer hierarchy must fail closed.' );
p1e_has( $src['signers'], 'scope_label(', 'Signer assignment presentation must resolve real scopes.' );
p1e_has( $src['signers'], 'certloom_signer_scope_missing', 'Missing signer scopes must be surfaced as corruption/errors.' );

p1e_has( $src['issuers'], 'Issuer name is required and must be 255 characters or fewer.', 'Issuer repository must enforce DB-safe name bounds.' );
p1e_has( $src['issuers'], 'Issuer slug is required and must be 191 characters or fewer.', 'Issuer repository must enforce DB-safe slug bounds.' );
p1e_has( $src['issuers'], 'certloom_issuer_list_failed', 'Issuer list reads must fail closed.' );
p1e_has( $src['domain_admin'], 'certloom_issuer_website_invalid', 'Issuer website must reject malformed non-empty values.' );
p1e_has( $src['domain_admin'], "array( 'http', 'https' )", 'Issuer website must remain HTTP/HTTPS only.' );
p1e_has( $src['domain_admin'], 'certificate_prefix', 'Issuer brand UI must expose certificate prefix.' );
p1e_has( $src['domain_admin'], 'logo_attachment_id', 'Issuer brand UI must expose local logo attachment identity.' );
p1e_has( $src['domain_admin'], 'seal_attachment_id', 'Issuer brand UI must expose local seal attachment identity.' );

p1e_has( $src['programs'], 'certloom_program_issuer_check_failed', 'Program repository must fail closed when issuer validation cannot run.' );
p1e_has( $src['programs'], 'certloom_program_issuer_missing', 'Program repository must reject missing issuer relationships.' );
p1e_has( $src['programs'], "SELECT COUNT(*) FROM %i p", 'Program list must use a bounded count query.' );
p1e_has( $src['recipients'], "SELECT COUNT(*) FROM %i r", 'Recipient list must use a bounded count query.' );
p1e_check( substr_count( $src['programs'], 'certloom_program_list_failed' ) >= 2, 'Program count and list query errors must each fail closed.' );
p1e_check( substr_count( $src['recipients'], 'certloom_recipient_list_failed' ) >= 2, 'Recipient count and list query errors must each fail closed.' );

p1e_has( $src['credentials'], 'batch_program_id(', 'Credential Series creation must resolve Batch→Program authoritatively.' );
p1e_has( $src['credentials'], 'certloom_series_batch_program_mismatch', 'Credential Series must reject contradictory Program/Batch assignment.' );
p1e_has( $src['credentials'], 'certloom_series_recipient_missing', 'Credential Series must validate related normalized entities before mutation.' );
p1e_has( $src['credentials'], 'list_versions_for_series(', 'Immutable Version history must have a bounded read path.' );
p1e_has( $src['domain_admin'], 'list_for_series( $series_id, 100 )', 'Append-only Event history must use a bounded read path.' );

p1e_has( $src['admin'], "const BATCHES_PAGE = 'certloom-domain-batches'", 'Batches need a dedicated normalized admin workspace.' );
p1e_has( $src['admin'], "const SIGNERS_PAGE = 'certloom-domain-signers'", 'Signers need a dedicated normalized admin workspace.' );
p1e_has( $src['admin'], 'CAP_MANAGE_BATCHES', 'Batch workspace must use granular capability checks.' );
p1e_has( $src['admin'], 'CAP_MANAGE_SIGNERS', 'Signer workspace must use granular capability checks.' );
p1e_has( $src['admin'], 'check_admin_referer', 'New entity writes must be nonce protected.' );
p1e_has( $src['admin'], "array( 'response' => 403 )", 'Permission failures must set a real 403 response, not misuse wp_die title.' );
p1e_has( $src['admin'], 'data-certloom-add-kv', 'Structured metadata add controls must be wired to admin JavaScript.' );
p1e_has( $src['admin'], 'data-certloom-kv-list', 'Structured metadata list controls must be wired to admin JavaScript.' );
p1e_has( $src['js'], 'certloom-add-kv', 'Admin JS must support the structured metadata add action.' );
p1e_has( $src['admin'], 'members_page(', 'Batch membership UI must use paginated repository reads.' );
p1e_has( $src['admin'], 'recipient_s', 'Batch membership UI must support bounded recipient search.' );
p1e_has( $src['admin'], 'structured metadata that the simple editor cannot round-trip exactly', 'Reduced metadata editor must preserve unrepresentable structured data.' );

p1e_has( $src['domain_admin'], 'Immutable Version lineage', 'Credential detail must present immutable Version lineage.' );
p1e_has( $src['domain_admin'], 'Append-only activity history', 'Credential detail must present append-only activity history.' );
p1e_has( $src['domain_admin'], "__( 'Batch', 'certloom' )", 'Normalized credential detail must surface Batch relationship.' );

p1e_has( $src['backup_fmt'], 'const VERSION = 2', 'Expanded entity backup must use format v2.' );
p1e_has( $src['backup_fmt'], 'const LEGACY_VERSION = 1', 'Valid pre-1.6.4 domain backups must remain supported.' );
p1e_has( $src['backup_fmt'], "HASH_SEED_V1 = 'certloom-domain-backup-v1'", 'Legacy backup hash chain must preserve v1 seed.' );
p1e_has( $src['backup_fmt'], "HASH_SEED_V2 = 'certloom-domain-backup-v2'", 'New backup hash chain needs versioned seed.' );
p1e_has( $src['backup_fmt'], 'normalize_manifest(', 'Backup compatibility must normalize older manifests explicitly.' );
p1e_has( $src['backup_fmt'], 'normalize_entity_row(', 'Backup compatibility must normalize older entity rows explicitly.' );
p1e_has( $src['backup_fmt'], "'batch_id'", 'Legacy Credential Series rows must gain a null Batch relationship in memory.' );
foreach ( array( 'signers', 'batches', 'batch_recipients', 'signer_assignments' ) as $entity ) {
	p1e_has( $src['backup_repo'], "'$entity'", 'Complete-domain backup missing ' . $entity . '.' );
	p1e_has( $src['row_valid'], "case '$entity'", 'Restore row validator missing ' . $entity . '.' );
	p1e_has( $src['preflight'], "'$entity'", 'Restore semantic preflight missing ' . $entity . '.' );
	p1e_has( $src['merge'], "'$entity'", 'Merge restore missing ' . $entity . '.' );
}
p1e_has( $src['row_valid'], 'valid_issuer_slug( $issuer_slug )', 'Restore must reject non-canonical issuer machine identity.' );
p1e_has( $src['row_valid'], '$this->length( $program_title ) > 255', 'Restore must mirror Program DB bounds.' );
p1e_has( $src['row_valid'], '$this->length( $recipient_name ) > 255', 'Restore must mirror Recipient DB bounds.' );
p1e_has( $src['preflight'], 'batch_signer_missing', 'Restore must validate Batch→instructor Signer relationship.' );
p1e_has( $src['preflight'], 'signer_assignment_scope_missing', 'Restore must validate signer assignment scopes.' );
p1e_has( $src['preflight'], 'series_batch_missing', 'Restore must validate Credential Series→Batch relationship.' );
p1e_has( $src['merge'], "case 'signers'", 'Merge restore must deterministically map Signers.' );
p1e_has( $src['merge'], "case 'batches'", 'Merge restore must deterministically map Batches.' );
p1e_has( $src['merge'], "array( 'batch_id', 'recipient_id', 'signer_id', 'scope_type', 'scope_id', 'role_type' )", 'Merge identity/composite columns must include new relationships.' );

p1e_has( $src['proof'], 'const RESULT_VERSION = 2', 'Old acceptance proof receipts must not approve the expanded schema.' );
foreach ( array( "'signers'", "'batches'", "'batch_recipients'", "'signer_assignments'" ) as $entity ) {
	p1e_has( $src['proof'], $entity, 'Acceptance proof must exercise ' . $entity . '.' );
}
p1e_has( $src['proof'], 'resolve_hierarchy(', 'Acceptance proof must exercise signer hierarchy resolution.' );
p1e_has( $src['proof'], 'append-only event history', 'Acceptance proof must exercise lifecycle/event history.' );

p1e_has( $src['permissions'], "const CAP_MANAGE_BATCHES       = 'certloom_manage_batches'", 'Batch management needs a granular capability.' );
p1e_has( $src['permissions'], "const CAP_MANAGE_SIGNERS       = 'certloom_manage_signers'", 'Signer management needs a granular capability.' );
p1e_has( $src['permissions'], "const CAPABILITY_SCHEMA_VERSION = '1.6.4'", 'Capability schema must advance for new capabilities.' );
p1e_has( $src['uninstall'], "'certloom_manage_batches'", 'Uninstall role cleanup must know Batch capability.' );
p1e_has( $src['uninstall'], "'certloom_manage_signers'", 'Uninstall role cleanup must know Signer capability.' );
foreach ( array( 'certloom_signer_assignments', 'certloom_batch_recipients', 'certloom_batches', 'certloom_signers' ) as $table ) {
	p1e_has( $src['uninstall'], $table, 'Explicit data deletion must own/drop ' . $table . '.' );
}

p1e_has( $src['plugin'], 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', 'New normalized admin surfaces must remain behind the acceptance gate.' );
p1e_has( $src['bootstrap'], "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false", '1.6.5 must not enable normalized write authority.' );
p1e_has( $src['bootstrap'], "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false", '1.6.5 must not enable normalized admin preview in production.' );
p1e_has( $src['bootstrap'], "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false", '1.6.5 must not enable normalized data tools in production.' );

foreach ( array( 'admin', 'batches', 'signers', 'issuers', 'programs', 'recipients' ) as $key ) {
	p1e_check( false === strpos( $src[ $key ], 'wp_remote_' ), 'Phase 1E normalized administration must remain local-only: ' . $key );
	p1e_check( false === strpos( $src[ $key ], 'shell_exec(' ) && false === strpos( $src[ $key ], 'exec(' ) && false === strpos( $src[ $key ], 'system(' ), 'Phase 1E production code must not execute shell processes: ' . $key );
}

echo "PASS  Phase 1E Batch/Issuer/Signer/history implementation is structurally complete behind the domain gate\n";
echo "PASS  backup compatibility, restore ownership, capability isolation and fail-closed relationships remain enforced\n\n{$checks} checks, 0 failures\n";
