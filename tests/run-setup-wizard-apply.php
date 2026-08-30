<?php
namespace Certloom\Infrastructure\Backup {
    class LocalBackupStore {
        public function test_storage() {
            $GLOBALS['wiz_apply_storage_checks']++;
            return ! empty( $GLOBALS['wiz_apply_storage_fail'] ) ? new \WP_Error( 'storage_fail', 'Storage is unavailable.' ) : true;
        }
    }
    class ScheduledBackup {
        public static function settings() { return $GLOBALS['wiz_apply_backup']; }
        public static function save_settings( $schedule, $retention ) {
            $GLOBALS['wiz_apply_backup_calls'][] = array( $schedule, $retention );
            if ( ! empty( $GLOBALS['wiz_apply_backup_fail'] ) ) { return new \WP_Error( 'backup_fail', 'Backup settings could not be saved.' ); }
            $GLOBALS['wiz_apply_backup'] = array( 'schedule' => $schedule, 'retention' => (int) $retention );
            return true;
        }
    }
    class GoogleDriveBackup {
        public static function state() { return $GLOBALS['wiz_apply_google']; }
        public static function settings_snapshot() { return array( 'enabled' => ! empty( $GLOBALS['wiz_apply_google']['enabled'] ), 'retention' => (int) ( $GLOBALS['wiz_apply_google']['retention'] ?? 14 ) ); }
        public static function save_settings( $enabled, $retention ) {
            $GLOBALS['wiz_apply_google_calls'][] = array( (bool) $enabled, (int) $retention );
            if ( ! empty( $GLOBALS['wiz_apply_google_fail'] ) ) { return new \WP_Error( 'google_fail', 'Google settings could not be saved.' ); }
            $GLOBALS['wiz_apply_google']['enabled'] = (bool) $enabled;
            $GLOBALS['wiz_apply_google']['retention'] = (int) $retention;
            return true;
        }
        public static function restore_settings_snapshot( array $snapshot ) {
            $GLOBALS['wiz_apply_google_restore_calls'][] = $snapshot;
            $GLOBALS['wiz_apply_google']['enabled'] = ! empty( $snapshot['enabled'] );
            $GLOBALS['wiz_apply_google']['retention'] = (int) ( $snapshot['retention'] ?? 14 );
            return true;
        }
    }
}
namespace Certloom\Infrastructure\Admin {
    class SetupReadinessReminder { public static function clear(){ $GLOBALS['wiz_apply_reminder_clears']++; } }
}
namespace {
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
$GLOBALS['wiz_apply_settings'] = array( 'numbering_mode'=>'manual', 'verification_qr_enabled'=>false, 'verification_barcode_enabled'=>false );
$GLOBALS['wiz_apply_backup'] = array( 'schedule'=>'off', 'retention'=>14 );
$GLOBALS['wiz_apply_google'] = array( 'connected'=>true, 'enabled'=>false, 'retention'=>14 );
$GLOBALS['wiz_apply_storage_checks'] = 0;
$GLOBALS['wiz_apply_storage_fail'] = false;
$GLOBALS['wiz_apply_backup_fail'] = false;
$GLOBALS['wiz_apply_profile_fail'] = false;
$GLOBALS['wiz_apply_complete_fail'] = false;
$GLOBALS['wiz_apply_profile_calls'] = array();
$GLOBALS['wiz_apply_complete_calls'] = 0;
$GLOBALS['wiz_apply_settings_calls'] = array();
$GLOBALS['wiz_apply_backup_calls'] = array();
$GLOBALS['wiz_apply_google_calls'] = array();
$GLOBALS['wiz_apply_google_restore_calls'] = array();
$GLOBALS['wiz_apply_google_fail'] = false;
$GLOBALS['wiz_apply_restore_calls'] = array();
$GLOBALS['wiz_apply_reminder_clears'] = 0;
$GLOBALS['wiz_apply_posts'] = array( 22 => array( 'type'=>'page' ) );
$GLOBALS['wiz_apply_draft'] = array();
function __( $text ){ return $text; }
function is_wp_error( $value ){ return $value instanceof WP_Error; }
function sanitize_key( $value ){ return strtolower( preg_replace('/[^a-z0-9_\-]/','',(string)$value) ); }
function absint( $value ){ return abs((int)$value); }
function get_post( $id ){ return isset($GLOBALS['wiz_apply_posts'][$id]) ? (object)array('ID'=>$id) : null; }
function get_post_type( $id ){ return $GLOBALS['wiz_apply_posts'][$id]['type'] ?? ''; }
class WP_Error { private $code; private $message; public function __construct($code='',$message=''){ $this->code=$code; $this->message=$message; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
class Ecapla_CertVerify_Settings {
    public static function get_all(){ return $GLOBALS['wiz_apply_settings']; }
    public static function save( $settings ) { $GLOBALS['wiz_apply_settings_calls'][]=$settings; if(!empty($GLOBALS['wiz_apply_settings_fail'])){return false;} $GLOBALS['wiz_apply_settings']=$settings; return true; }
}
class Ecapla_CertVerify_Numbering {
    public static function normalize_policy( $settings ) { return in_array($settings['numbering_mode'] ?? '',array('manual','sequential','random','uuid'),true) ? $settings : new WP_Error('numbering_invalid','Invalid numbering.'); }
}
class Ecapla_CertVerify_Onboarding {
    public static function get_state(){ return array('wizard_draft'=>$GLOBALS['wiz_apply_draft']); }
    public static function snapshot(){ return array('wizard_draft'=>$GLOBALS['wiz_apply_draft'],'organization_name'=>'Original Org','verification_page_id'=>0,'appearance_reviewed'=>false,'verification_tested'=>false); }
    public static function restore_snapshot( $snapshot ){ $GLOBALS['wiz_apply_restore_calls'][]=$snapshot; return true; }
    public static function save_profile($org,$page,$appearance=null,$tested=null){ $GLOBALS['wiz_apply_profile_calls'][]=array($org,$page,$appearance,$tested); return !empty($GLOBALS['wiz_apply_profile_fail']) ? new WP_Error('profile_fail','Profile save failed.') : true; }
    public static function complete_wizard(){ $GLOBALS['wiz_apply_complete_calls']++; return !empty($GLOBALS['wiz_apply_complete_fail']) ? new WP_Error('complete_fail','Completion marker failed.') : true; }
}
require dirname( __DIR__ ) . '/admin/class-ecapla-certverify-admin.php';
function apply_reset(){
    $GLOBALS['wiz_apply_settings']=array('numbering_mode'=>'manual','verification_qr_enabled'=>false,'verification_barcode_enabled'=>false);
    $GLOBALS['wiz_apply_backup']=array('schedule'=>'off','retention'=>14);
    $GLOBALS['wiz_apply_google']=array('connected'=>true,'enabled'=>false,'retention'=>14);
    $GLOBALS['wiz_apply_storage_checks']=0; $GLOBALS['wiz_apply_storage_fail']=false; $GLOBALS['wiz_apply_backup_fail']=false; $GLOBALS['wiz_apply_google_fail']=false; $GLOBALS['wiz_apply_profile_fail']=false; $GLOBALS['wiz_apply_complete_fail']=false; $GLOBALS['wiz_apply_settings_fail']=false;
    $GLOBALS['wiz_apply_profile_calls']=array(); $GLOBALS['wiz_apply_complete_calls']=0; $GLOBALS['wiz_apply_settings_calls']=array(); $GLOBALS['wiz_apply_backup_calls']=array(); $GLOBALS['wiz_apply_google_calls']=array(); $GLOBALS['wiz_apply_google_restore_calls']=array(); $GLOBALS['wiz_apply_restore_calls']=array(); $GLOBALS['wiz_apply_reminder_clears']=0;
    $GLOBALS['wiz_apply_draft']=array();
}
function apply_invoke(){ $rc=new ReflectionClass('Ecapla_CertVerify_Admin'); $obj=$rc->newInstanceWithoutConstructor(); $m=$rc->getMethod('apply_setup_wizard_review'); return $m->invoke($obj); }
$checks=0; function ac($ok,$label){global $checks;if(!$ok){throw new RuntimeException($label);} $checks++;}

apply_reset();
$GLOBALS['wiz_apply_draft']=array('organization_name'=>'Acme Academy','verification_page_id'=>22,'numbering_mode'=>'sequential','verification_qr_enabled'=>true,'verification_barcode_enabled'=>true,'appearance_reviewed'=>true,'verification_tested'=>true,'backup_schedule'=>'daily','backup_retention'=>20);
$r=apply_invoke();
ac(true===$r,'Valid reviewed configuration should apply.');
ac('sequential'===$GLOBALS['wiz_apply_settings']['numbering_mode'] && true===$GLOBALS['wiz_apply_settings']['verification_qr_enabled'],'Canonical Settings must receive reviewed numbering/QR choices.');
ac(array('schedule'=>'daily','retention'=>20)===$GLOBALS['wiz_apply_backup'],'Canonical ScheduledBackup must receive reviewed schedule/retention.');
ac(1===$GLOBALS['wiz_apply_storage_checks'],'Enabling automatic backups must test storage exactly once before mutation.');
ac(1===count($GLOBALS['wiz_apply_profile_calls']) && 'Acme Academy'===$GLOBALS['wiz_apply_profile_calls'][0][0] && 22===$GLOBALS['wiz_apply_profile_calls'][0][1],'Organization/verification page must apply through onboarding profile authority.');
ac(1===$GLOBALS['wiz_apply_complete_calls'],'Wizard progress is finalized only after reviewed settings apply.');
ac(1===$GLOBALS['wiz_apply_reminder_clears'],'Successful Apply clears the current administrator setup-reminder snooze state.');

apply_reset();
$GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'invalid','backup_schedule'=>'off');
$r=apply_invoke();
ac(is_wp_error($r)&&'numbering_invalid'===$r->get_error_code(),'Invalid numbering must fail before any write.');
ac(array()===$GLOBALS['wiz_apply_settings_calls'] && array()===$GLOBALS['wiz_apply_backup_calls'],'Invalid numbering must produce zero settings/backup writes.');

apply_reset();
$GLOBALS['wiz_apply_storage_fail']=true; $GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'sequential','backup_schedule'=>'weekly','backup_retention'=>9);
$r=apply_invoke();
ac(is_wp_error($r)&&'storage_fail'===$r->get_error_code(),'Unsafe backup destination must block review application.');
ac(array()===$GLOBALS['wiz_apply_settings_calls'] && array()===$GLOBALS['wiz_apply_backup_calls'],'Storage failure must occur before canonical Settings/Backup mutation.');

apply_reset();
$GLOBALS['wiz_apply_backup_fail']=true; $GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'sequential','verification_qr_enabled'=>true,'backup_schedule'=>'off','backup_retention'=>12);
$r=apply_invoke();
ac(is_wp_error($r)&&'backup_fail'===$r->get_error_code(),'Backup-settings failure must propagate.');
ac('manual'===$GLOBALS['wiz_apply_settings']['numbering_mode'] && false===$GLOBALS['wiz_apply_settings']['verification_qr_enabled'],'Backup-settings failure must roll canonical Settings back to pre-review values.');
ac(2===count($GLOBALS['wiz_apply_settings_calls']),'Backup-settings failure must perform one apply and one Settings rollback.');

apply_reset();
$GLOBALS['wiz_apply_profile_fail']=true; $GLOBALS['wiz_apply_draft']=array('organization_name'=>'Bad Profile','verification_page_id'=>22,'numbering_mode'=>'uuid','backup_schedule'=>'daily','backup_retention'=>8);
$r=apply_invoke();
ac(is_wp_error($r)&&'profile_fail'===$r->get_error_code(),'Profile failure must propagate.');
ac('manual'===$GLOBALS['wiz_apply_settings']['numbering_mode'],'Profile failure must roll canonical Settings back.');
ac(array('schedule'=>'off','retention'=>14)===$GLOBALS['wiz_apply_backup'],'Profile failure must roll canonical Backup settings back.');
ac(2===count($GLOBALS['wiz_apply_backup_calls']),'Profile failure must perform one canonical backup apply and one canonical backup rollback.');

apply_reset();
$GLOBALS['wiz_apply_draft']=array('organization_name'=>'Acme','verification_page_id'=>999,'numbering_mode'=>'manual','backup_schedule'=>'off');
$r=apply_invoke();
ac(is_wp_error($r)&&'certloom_setup_review_page_invalid'===$r->get_error_code(),'Missing verification page must fail closed.');
ac(array()===$GLOBALS['wiz_apply_settings_calls'] && array()===$GLOBALS['wiz_apply_backup_calls'],'Invalid verification page must be detected before writes.');

apply_reset();
$GLOBALS['wiz_apply_complete_fail']=true; $GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'sequential','backup_schedule'=>'off','backup_retention'=>14);
$r=apply_invoke();
ac(is_wp_error($r)&&'certloom_setup_progress_finalize_failed'===$r->get_error_code(),'Progress-finalization failure must be reported distinctly.');
ac('manual'===$GLOBALS['wiz_apply_settings']['numbering_mode'],'Progress-finalization failure must restore canonical Settings to the exact pre-review state.');
ac(array('schedule'=>'off','retention'=>14)===$GLOBALS['wiz_apply_backup'],'Progress-finalization failure must restore canonical Backup settings.');
ac(1===count($GLOBALS['wiz_apply_google_restore_calls']) && 1===count($GLOBALS['wiz_apply_restore_calls']),'Progress-finalization failure must restore Google and onboarding snapshots without inventing a partial setup.');
ac(false!==stripos($r->get_error_message(),'restored the previous configuration'),'Progress-finalization failure message must accurately describe transactional compensation.');


apply_reset();
$GLOBALS['wiz_apply_google']=array('connected'=>true,'enabled'=>false,'retention'=>14);
$GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'manual','backup_schedule'=>'off','google_drive_enabled'=>true,'google_drive_retention'=>21);
$r=apply_invoke();
ac(true===$r && true===$GLOBALS['wiz_apply_google']['enabled'] && 21===$GLOBALS['wiz_apply_google']['retention'],'Connected Google Drive choices must apply through the canonical Google backup authority.');

apply_reset();
$GLOBALS['wiz_apply_google']=array('connected'=>false,'enabled'=>false,'retention'=>14);
$GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'manual','backup_schedule'=>'off','google_drive_enabled'=>true,'google_drive_retention'=>21);
$r=apply_invoke();
ac(is_wp_error($r)&&'certloom_setup_google_not_connected'===$r->get_error_code(),'Wizard must fail closed when automatic Drive copies are requested without a connected Google account.');
ac(array()===$GLOBALS['wiz_apply_settings_calls'] && array()===$GLOBALS['wiz_apply_google_calls'],'Disconnected Google Drive must be detected before any reviewed settings are mutated.');

apply_reset();
$GLOBALS['wiz_apply_draft']=array('numbering_mode'=>'manual','backup_schedule'=>'off','backup_retention'=>14);
$r=apply_invoke();
ac(true===$r && 0===$GLOBALS['wiz_apply_storage_checks'],'Off schedule must not require a storage probe.');
ac(array()===$GLOBALS['wiz_apply_profile_calls'],'Blank organization draft must not overwrite an existing onboarding profile with blank data.');

echo "PASS  WIZ-01 review/apply uses canonical settings with fail-closed validation and bounded rollback\n";
echo "PASS  storage, backup, profile, verification-page and progress-finalization failure semantics are explicit\n\n{$checks} checks, 0 failures\n";
}
