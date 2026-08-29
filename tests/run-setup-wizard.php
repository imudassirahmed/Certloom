<?php
/** WIZ-01/02 adaptive setup contract and authority-boundary regression. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
$GLOBALS['certloom_wiz_options'] = array();
$GLOBALS['certloom_wiz_generation'] = 0;
$GLOBALS['certloom_wiz_now'] = '2026-08-29 22:40:00';

function __( $text ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['certloom_wiz_options'] ) ? $GLOBALS['certloom_wiz_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['certloom_wiz_options'][ $key ] = $value; return true; }
function current_time( $type ) { return $GLOBALS['certloom_wiz_now']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error { private $code; private $message; public function __construct( $code = '', $message = '' ) { $this->code=$code; $this->message=$message; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
class Ecapla_CertVerify_Backup { public static function assert_writes_allowed(){ return true; } public static function reserve_mutation_generation(){ $GLOBALS['certloom_wiz_generation']++; return $GLOBALS['certloom_wiz_generation']; } }
class Ecapla_CertVerify_Database { public static function get_registry_count(){ return 0; } public static function find_sample_cert_number(){ return ''; } }
class Ecapla_CertVerify_Fields { public static function get_manageable_fields(){ return array(); } }
function get_post( $id ){ return null; }
function get_post_type( $id ){ return ''; }

require dirname( __DIR__ ) . '/src/Application/Program/ProgramProfile.php';
require dirname( __DIR__ ) . '/src/Application/Setup/PurposePresetEngine.php';
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-onboarding.php';

$checks=0; function wiz_check($ok,$label){global $checks;if(!$ok){throw new RuntimeException($label);} $checks++;}
$engine = new \Certloom\Application\Setup\PurposePresetEngine();
$purposes=$engine->purposes(); $subs=$engine->subcategories();
wiz_check(7===count($purposes),'Wizard must reuse seven canonical Program purpose families.');
wiz_check(isset($subs['training_courses']['online_course']) && isset($subs['certification_standards']['management_systems']),'Wizard subcategories must come from ProgramProfile taxonomy.');
$cert=$engine->recommend('certification_standards','management_systems');
wiz_check(!is_wp_error($cert) && 'sequential'===$cert['numbering_mode'],'Certification preset must recommend canonical sequential numbering, never a Wizard-only automatic value.');
wiz_check(true===$cert['recommendation_only'] && false!==stripos($cert['notice'],'not legal'),'Preset must identify recommendations as non-authoritative advice.');
$academic=$engine->recommend('academic_credentials','degree');
wiz_check('degree'===$academic['credential_type'] && 'permanent'===$academic['validity'],'Academic degree preset must remain bounded and purpose-specific.');
$training=$engine->recommend('training_courses','online_course');
wiz_check(true===$training['integration_focus'] && 'enabled'===$training['portal'],'Training preset should emphasize existing Portal/integration capabilities without enabling them itself.');
wiz_check(is_wp_error($engine->recommend('training_courses','degree')),'Cross-purpose subcategory mismatch must fail closed.');
wiz_check(isset($engine->reference_examples('certification_standards')['iso_9001']) && array()===$engine->reference_examples('academic_credentials'),'Standard references must be examples only for certification/standards use.' );

$defaults=Ecapla_CertVerify_Onboarding::get_state();
wiz_check(2===$defaults['version'] && 'purpose'===$defaults['wizard_step'],'Onboarding state must upgrade in memory to version 2 and begin at Purpose.');
wiz_check(array()===$defaults['wizard_draft'] && ''===$defaults['wizard_completed_at'],'Wizard draft/completion must start non-authoritative and incomplete.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('purpose',array('purpose'=>'certification_standards','subcategory'=>'management_systems','scheme_reference'=>'ISO 9001'),'organization',false);
wiz_check(true===$r,'Valid Purpose step must persist.');
$state=Ecapla_CertVerify_Onboarding::get_state();
wiz_check('organization'===$state['wizard_step'] && in_array('purpose',$state['wizard_completed_steps'],true),'Purpose save must advance resumable progress.');
wiz_check('sequential'===$state['wizard_draft']['numbering_mode'] && 'weekly'===$state['wizard_draft']['backup_schedule'],'Purpose save may seed untouched canonical recommendation defaults into draft only.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('delivery',array('numbering_mode'=>'uuid'),'backup',false); wiz_check(true===$r,'Explicit delivery choice must persist.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('purpose',array('purpose'=>'academic_credentials','subcategory'=>'degree'),'organization',false); wiz_check(true===$r,'Purpose may be revisited.');
$state=Ecapla_CertVerify_Onboarding::get_state();
wiz_check('uuid'===$state['wizard_draft']['numbering_mode'],'Changing purpose must not overwrite an administrator\'s explicit draft choice.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('backup',array('backup_schedule'=>'daily','backup_retention'=>999),'review',false); wiz_check(true===$r,'Backup draft should persist through canonical normalization.');
$state=Ecapla_CertVerify_Onboarding::get_state(); wiz_check(60===$state['wizard_draft']['backup_retention'],'Wizard retention must share the canonical 1–60 bound.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('bogus',array(),'purpose',false); wiz_check(is_wp_error($r) && 'certloom_setup_step_invalid'===$r->get_error_code(),'Unknown Wizard step must fail closed.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('purpose',array('purpose'=>'custom_other','subcategory'=>'custom'),'bogus',false); wiz_check(is_wp_error($r) && 'certloom_setup_next_step_invalid'===$r->get_error_code(),'Unknown next step must fail closed.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('purpose',array('purpose'=>'training_courses','subcategory'=>'degree'),'organization',false); wiz_check(is_wp_error($r),'Invalid purpose/subcategory pair must not persist.');
$r=Ecapla_CertVerify_Onboarding::save_wizard_step('design',array(),'delivery',true); wiz_check(true===$r,'Skippable Wizard step must persist without fabricating completion of the underlying product setting.');
$state=Ecapla_CertVerify_Onboarding::get_state(); wiz_check(in_array('design',$state['wizard_skipped_steps'],true),'Skipped step must be separately recorded.');
$r=Ecapla_CertVerify_Onboarding::complete_wizard(); wiz_check(true===$r && Ecapla_CertVerify_Onboarding::is_wizard_complete(),'Wizard walkthrough completion must be separate and explicit.');
wiz_check(!Ecapla_CertVerify_Onboarding::is_complete(),'Wizard completion must not fabricate legacy/full setup readiness.');
$r=Ecapla_CertVerify_Onboarding::restart_wizard(); wiz_check(true===$r,'Completed Wizard must be rerunnable.');
$state=Ecapla_CertVerify_Onboarding::get_state(); wiz_check('purpose'===$state['wizard_step'] && array()===$state['wizard_draft'] && 1===$state['wizard_rerun_count'],'Rerun must start a fresh draft without reusing previous intent.');

$root=dirname(__DIR__);
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$view=file_get_contents($root.'/admin/views/setup-wizard.php');
$css=file_get_contents($root.'/admin/assets/admin.css');
$js=file_get_contents($root.'/admin/assets/admin.js');
$bootstrap=file_get_contents($root.'/certloom.php');
$dashboard=file_get_contents($root.'/admin/views/dashboard.php');
$ux_program=file_get_contents($root.'/docs/ux-product-simplification-program.md');
foreach(array($admin,$view,$css,$js,$bootstrap,$dashboard,$ux_program) as $src){wiz_check(is_string($src)&&''!==$src,'Required Wizard source unavailable.');}
wiz_check(false!==strpos($admin,'handle_setup_wizard_action') && false!==strpos($admin,"check_admin_referer( 'certloom_setup_wizard' )"),'Wizard mutations must be capability/nonce controlled.');
wiz_check(false!==strpos($admin,'AdminUiMode::save_for_current_user') && false===strpos($admin,"update_option( 'wizard_mode'") && false===strpos($admin,"update_option( 'certloom_wizard_mode'"),'Wizard Simple/Advanced must reuse canonical per-admin UI mode.');
wiz_check(false!==strpos($admin,'ScheduledBackup::save_settings') && false===strpos($admin,'wizard_backup_schedule'),'Wizard backup review must apply through ScheduledBackup authority, not a second runtime option.');
wiz_check(false!==strpos($admin,'LocalBackupStore() )->test_storage()'),'Wizard must verify local backup storage before enabling automatic backups.');
wiz_check(false!==strpos($admin,'Ecapla_CertVerify_Settings::save') && false!==strpos($admin,'Ecapla_CertVerify_Numbering::normalize_policy'),'Wizard review must use canonical settings/numbering validation.');
wiz_check(false===strpos($admin,'ProgramRepository::create') && false===strpos($admin,'TemplateRepository::create'),'Wizard controller must not bypass gated Program/Template creation authority.');
wiz_check(false!==strpos($view,'Program creation remains protected') && false!==strpos($view,'Authority stays fail-closed'),'Wizard must disclose protected Program/Template boundaries in normal review UX.');
wiz_check(false!==strpos($view,'Apply reviewed settings') && false!==strpos($view,'Apply the reviewed setup changes now?'),'Review application must be explicit and confirmation-protected.');
wiz_check(false!==strpos($view,'form="certloom-wizard-step-form"') && false!==strpos($admin,"wizard_finish_later"),'Save & finish later must submit and save the current draft rather than discard unsaved fields.');
wiz_check(false!==strpos($js,'initAdaptiveSetupWizard') && false!==strpos($js,'data-certloom-wizard-summary-purpose'),'Purpose/subcategory changes must have an accessible live adaptive interaction layer.');
wiz_check(false!==strpos($css,'1.6.94 WIZ-01/02') && false!==strpos($css,'@media(max-width:782px)') && false!==strpos($css,'forced-colors:active'),'Wizard must have dedicated responsive, reduced-motion and forced-colors styling.');
wiz_check(false!==strpos($dashboard,"wizard_completed") && false!==strpos($dashboard,'Setup review complete') && false!==strpos($dashboard,'certloom-setup-checklist'),'Completed Wizard must transition Dashboard to a compact readiness checklist rather than pretending skipped readiness work is complete.');
wiz_check(false!==strpos($dashboard,'Finish first-run setup'),'Dashboard must retain the pre-Wizard first-run invitation for administrators who have not completed the walkthrough.');
wiz_check(false!==strpos($ux_program,'professional license / membership') && false!==strpos($ux_program,'award / recognition') && false!==strpos($ux_program,'participation / attendance / speaker'),'Wizard planning taxonomy must stay synchronized to canonical ProgramProfile subcategories.');
wiz_check(false!==strpos($bootstrap,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false") && false!==strpos($bootstrap,"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false") && false!==strpos($bootstrap,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'WIZ-01/02 must leave all normalized production gates closed.');
wiz_check(!file_exists($root.'/resources/renderer/accepted.json'),'Wizard must not create renderer release authority.');

echo "PASS  WIZ-01/02 adaptive setup remains resumable, recommendation-only and canonical-authority safe\n";
echo "PASS  purpose presets, strict step validation, rerun isolation, backup bounds and fail-closed Program/Template boundaries are enforced\n\n{$checks} checks, 0 failures\n";
