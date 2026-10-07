<?php
/** Static regression tests for the administrator-triggered populated migration preflight surface. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$view=file_get_contents($root.'/admin/views/settings-manager.php');
$status=file_get_contents($root.'/includes/class-ecapla-certverify-system-status.php');
$uninstall=file_get_contents($root.'/uninstall.php');
function lmpas_assert($c,$m){if(!$c)throw new RuntimeException($m);}
lmpas_assert(false!==strpos($admin,'handle_legacy_migration_preflight_proof'),'Preflight handler must be registered.');
lmpas_assert(false!==strpos($admin,'require_options_permission()'),'Preflight action must require Certloom settings permission.');
lmpas_assert(false!==strpos($admin,"check_admin_referer( 'certverify_run_legacy_migration_preflight_proof' )"),'Preflight action must be nonce protected.');
lmpas_assert(false!==strpos($admin,'LegacyMigrationPreflightProof::run_slice()'),'Explicit administrator action must delegate to the resumable proof.');
lmpas_assert(false!==strpos($view,"wp_nonce_field( 'certverify_run_legacy_migration_preflight_proof' )"),'Settings form must emit the proof nonce.');
lmpas_assert(false!==strpos($view,'DomainAcceptanceProof::is_current_passed()'),'Preflight button must require fresh gated-domain acceptance evidence.');
lmpas_assert(false!==strpos($view,"'disabled' => 'disabled'"),'Preflight button must remain disabled until its prerequisite passes.');
lmpas_assert(false===strpos($status,'LegacyMigrationPreflightProof::run_slice()'),'Read-only System Status must never execute the preflight.');
lmpas_assert(false===strpos($view,'LegacyMigrationPreflightProof::run_slice()'),'Rendering Settings must never execute the preflight implicitly.');
lmpas_assert(false!==strpos($uninstall,"delete_option( 'certloom_legacy_migration_preflight_proof' )"),'Uninstall must clean proof evidence.');
lmpas_assert(false!==strpos($uninstall,"delete_option( 'certloom_legacy_migration_preflight_proof_lock' )"),'Uninstall must clean proof execution state.');
echo "PASS  Populated migration preflight is explicit, permissioned, nonce-protected and uninstall-owned\n\n11 checks, 0 failures\n";
