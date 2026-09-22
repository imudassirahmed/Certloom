<?php
/** Static safety-boundary guards for the staging-only populated restore rehearsal. */
$root=dirname(__DIR__);
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$view=file_get_contents($root.'/admin/views/settings-manager.php');
$plugin=file_get_contents($root.'/certloom.php');
$status=file_get_contents($root.'/includes/class-ecapla-certverify-system-status.php');
$rehearsal=file_get_contents($root.'/src/Infrastructure/Diagnostics/StagingRestoreRehearsal.php');
$uninstall=file_get_contents($root.'/uninstall.php');
foreach(compact('admin','view','plugin','status','rehearsal','uninstall') as $name=>$source){if(false===$source)throw new RuntimeException('Could not read '.$name.' source.');}
foreach(array('handle_staging_restore_rehearsal','$this->require_options_permission()',"check_admin_referer( 'certverify_run_staging_restore_rehearsal' )") as $needle){if(false===strpos($admin,$needle))throw new RuntimeException('Missing restore rehearsal admin guard: '.$needle);}
if(false===strpos($plugin,"define( 'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL', true )"))throw new RuntimeException('Restore rehearsal review opt-in is not enabled in the review-unlocked build.');
foreach(array("'local', 'development', 'staging'",'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL','StagingMigrationRehearsal::is_current_passed()','DomainBackupBuilder','DomainRestoreStagingService','DomainRestoreMergeService','DomainStateFingerprint','pre_update_option_certverify_fields','Credential authority changed: NO') as $needle){if(false===strpos($rehearsal,$needle))throw new RuntimeException('Missing restore rehearsal safety/acceptance guard: '.$needle);}
if(false!==strpos($rehearsal,'DomainCutoverCoordinator')||false!==strpos($rehearsal,"update_option( DomainCutoverState::OPTION"))throw new RuntimeException('Restore rehearsal must never invoke cutover or change authority.');
if(false===strpos($view,"wp_nonce_field( 'certverify_run_staging_restore_rehearsal'" )||false===strpos($view,'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL'))throw new RuntimeException('Restore rehearsal UI lost nonce/explicit-opt-in guidance.');
if(false!==strpos($status,'StagingRestoreRehearsal::run_slice('))throw new RuntimeException('Read-only System Status must never execute the staging restore rehearsal.');
foreach(array("delete_option( 'certloom_staging_restore_rehearsal' )","delete_option( 'certloom_staging_restore_rehearsal_lock' )") as $needle){if(false===strpos($uninstall,$needle))throw new RuntimeException('Uninstall does not remove staging restore operational evidence: '.$needle);}
if(false===strpos($rehearsal,'DomainFeatureGate::any_ready()'))throw new RuntimeException('Restore rehearsal lost manifest-backed normalized-domain release-authority guard.');
echo "PASS  staging restore rehearsal is non-production, explicit-opt-in, admin/nonce protected, read-only in System Status and cannot cut over authority\n\n1 test, 0 failures\n";
