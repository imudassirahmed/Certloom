<?php
/** Alpha 4.42 operational unblocking contract: no ceremony barrier around completed review workflows. */
$root = dirname( __DIR__ );
$gate = file_get_contents( $root . '/src/Infrastructure/Migration/DomainFeatureGate.php' );
$public_gate = file_get_contents( $root . '/src/Infrastructure/Verification/PublicVerificationGate.php' );
$render = file_get_contents( $root . '/includes/class-ecapla-certverify-render.php' );
$rest = file_get_contents( $root . '/includes/class-ecapla-certverify-rest.php' );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$bulk = file_get_contents( $root . '/src/Infrastructure/Admin/BulkIssuanceAdmin.php' );
$domain = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$structure = file_get_contents( $root . '/src/Infrastructure/Admin/DomainStructureAdmin.php' );
$portal = file_get_contents( $root . '/src/Application/Recipient/RecipientPortalService.php' );
$portal_ui = file_get_contents( $root . '/includes/class-ecapla-certverify-recipient-portal.php' );
$bulk_review = file_get_contents( $root . '/admin/views/bulk-review.php' );
function opu($ok,$msg){ if(!$ok){ throw new RuntimeException('FAIL  '.$msg); } echo "PASS  {$msg}\n"; }
opu(false!==strpos($gate,"self::\$scopes['admin_preview'] = true;")&&false!==strpos($gate,"self::\$scopes['write_paths'] = true;"),'review build opens implemented admin and credential-write workflows');
opu(false!==strpos($gate,"self::\$scopes['data_tools'] = true;")&&false!==strpos($gate,"'local', 'development', 'staging'"),'review Data Tools are open only on explicit non-production WordPress environments');
opu(false===strpos($public_gate,'LegacyMigrationState')&&false===strpos($public_gate,'DomainCutoverState'),'public verification no longer depends on legacy migration/cutover ceremony');
opu(false!==strpos($public_gate,'SchemaManager::health_marker_is_fresh()')&&false!==strpos($public_gate,'DomainMaintenanceLock'),'public verification still fails closed for real storage/maintenance problems');
opu(false===strpos($render,'normalized_admin_preview_allowed')&&false===strpos($render,"'Admin preview'")&&false!==strpos($render,"'Verified credential'"),'public profile has no admin-preview barrier');
opu(false!==strpos($rest,'credential store is not ready')&&false===strpos($rest,'in-place data upgrade'),'REST verification reports only genuine storage unavailability');
opu(false===strpos($manual,'credential-management preview')&&false===strpos($manual,'existing registry still powers public verification'),'Manual Issuance contains no stale preview/cutover barrier copy');
opu(false===strpos($bulk,'credential-management preview')&&false!==strpos($bulk,'normalized public verification are operational'),'Bulk Issuance no longer claims public verification is blocked');
opu(false===strpos($domain,'Safety gate active.')&&false===strpos($domain,'Acceptance preview'),'Credentials/Programs admin no longer present accepted workflows as a gated preview');
opu(false===strpos($structure,'Safety gate active.')&&false===strpos($structure,'Acceptance preview'),'structure workspaces no longer show fake release barriers');
opu(false===strpos($portal,'DomainFeatureGate::admin_preview_ready()')&&false!==strpos($portal,'certloom_recipient_portal_store_unavailable'),'Recipient Portal follows real storage readiness instead of an acceptance-preview bypass');
opu(false===strpos($portal_ui,'normalized credential authority passes')&&false===strpos($portal_ui,'acceptance gate'),'Recipient Portal UI contains no stale authority-acceptance blocker');
opu(false!==strpos($domain,'Online verification')&&false!==strpos($domain,'View public credential')&&false===strpos($domain,'Production authority')&&false===strpos($domain,'Authority remains closed.'),'Advanced Credential Management exposes the same operational public verification as Simple mode');
opu(false!==strpos($bulk_review,'reviewed successor-Version workflow')&&false===strpos($bulk_review,'acceptance gate is approved'),'legacy bulk editor routes relationship changes to the real immutable workflow without a fake gate');
opu(false!==strpos($manual,'PDF/PNG/JPEG')&&false!==strpos($bulk,'PDF/PNG/JPEG'),'renderer-dependent downloads remain honestly gated');
echo "\n15 operational-unblocking checks, 0 failures\n";
