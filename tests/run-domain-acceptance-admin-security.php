<?php
/** Static regression tests for the administrator-triggered gated-domain acceptance proof surface. */
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$view=file_get_contents($root.'/admin/views/settings-manager.php');
$status=file_get_contents($root.'/includes/class-ecapla-certverify-system-status.php');
function daas_assert($c,$m){if(!$c)throw new RuntimeException($m);}
daas_assert(false!==strpos($admin, 'handle_domain_acceptance_proof' ),'Admin handler must be registered.');
daas_assert(false!==strpos($admin,'require_options_permission()'),'Acceptance action must require Certloom settings permission.');
daas_assert(false!==strpos($admin,"check_admin_referer( 'certverify_run_domain_acceptance_proof' )"),'Acceptance action must be nonce protected.');
daas_assert(false!==strpos($admin,'DomainAcceptanceProof::run()'),'Explicit administrator action must delegate to the proof service.');
daas_assert(false!==strpos($view,"wp_nonce_field( 'certverify_run_domain_acceptance_proof' )"),'Settings form must emit the proof nonce.');
daas_assert(false!==strpos($view,'Ecapla_CertVerify_Database_Proof::is_current_passed()'),'Proof button must require a fresh passing isolated database proof.');
daas_assert(false!==strpos($view,"'disabled' => 'disabled'"),'Proof button must be disabled until its prerequisite passes.');
daas_assert(false===strpos($status,'DomainAcceptanceProof::run()'),'Read-only System Status must never execute the acceptance proof.');
daas_assert(false===strpos($view,'DomainAcceptanceProof::run()'),'Rendering Settings must never execute the acceptance proof.');
echo "PASS  Gated-domain acceptance proof is explicit, permissioned and nonce-protected\n\n9 checks, 0 failures\n";
