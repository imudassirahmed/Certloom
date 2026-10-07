<?php
/** Static safety-boundary guards for the staging-only final authority-cutover rehearsal. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$view=file_get_contents($root.'/admin/views/settings-manager.php');
$plugin=file_get_contents($root.'/certloom.php');
$status=file_get_contents($root.'/includes/class-ecapla-certverify-system-status.php');
$rehearsal=file_get_contents($root.'/src/Infrastructure/Diagnostics/StagingCutoverRehearsal.php');
$coordinator=file_get_contents($root.'/src/Infrastructure/Migration/DomainCutoverCoordinator.php');
$uninstall=file_get_contents($root.'/uninstall.php');
foreach(compact('admin','view','plugin','status','rehearsal','coordinator','uninstall') as $name=>$source){if(false===$source)throw new RuntimeException('Could not read '.$name.' source.');}
foreach(array('handle_staging_cutover_rehearsal','$this->require_options_permission()',"check_admin_referer( 'certverify_run_staging_cutover_rehearsal' )") as $needle){if(false===strpos($admin,$needle))throw new RuntimeException('Missing cutover rehearsal admin guard: '.$needle);}
if(false===strpos($plugin,"define( 'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL', true )"))throw new RuntimeException('Cutover rehearsal review opt-in is not enabled in the review-unlocked build.');
foreach(array("'local', 'development', 'staging'",'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL','StagingRestoreRehearsal::is_current_passed()','DomainCutoverCoordinator','PublicVerificationGate','LegacyRegistryWriteGuard','DomainStateFingerprint','Normalized-domain release authority changed: NO','Final credential authority after rehearsal: LEGACY') as $needle){if(false===strpos($rehearsal,$needle))throw new RuntimeException('Missing staging cutover safety/acceptance guard: '.$needle);}
if(false!==strpos($rehearsal,"update_option( DomainCutoverState::OPTION"))throw new RuntimeException('Staging cutover must use the production coordinator, not write authority directly.');
if(false===strpos($coordinator,'$readiness_verifier')||false===strpos($coordinator,'array( $this, \'assert_feature_flags\' )'))throw new RuntimeException('Production cutover readiness policy is not defaulting to the real feature flags.');
if(false===strpos($view,"wp_nonce_field( 'certverify_run_staging_cutover_rehearsal'")||false===strpos($view,'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL'))throw new RuntimeException('Cutover rehearsal UI lost nonce/explicit-opt-in guidance.');
if(false!==strpos($status,'StagingCutoverRehearsal::run_slice('))throw new RuntimeException('Read-only System Status must never execute the cutover rehearsal.');
foreach(array("delete_option( 'certloom_staging_cutover_rehearsal' )","delete_option( 'certloom_staging_cutover_rehearsal_lock' )") as $needle){if(false===strpos($uninstall,$needle))throw new RuntimeException('Uninstall does not remove staging cutover operational evidence: '.$needle);}
if(false===strpos($rehearsal,'DomainFeatureGate::any_ready()'))throw new RuntimeException('Cutover rehearsal lost manifest-backed normalized-domain release-authority guard.');
echo "PASS  staging final cutover rehearsal is non-production, explicit-opt-in, admin/nonce protected, uses the production coordinator and restores legacy authority\n\n1 test, 0 failures\n";
