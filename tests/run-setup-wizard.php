<?php
/** UI Phase 3 — Setup Wizard v2 profile/persistence/source contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
$GLOBALS['certloom_wiz_options'] = array();
$GLOBALS['certloom_wiz_generation'] = 0;
$GLOBALS['certloom_wiz_now'] = '2026-09-09 06:15:00';
$GLOBALS['certloom_wiz_user_meta'] = array();
$GLOBALS['certloom_wiz_user_id'] = 17;
$GLOBALS['certloom_wiz_uuid'] = 0;

function __( $text ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['certloom_wiz_options'] ) ? $GLOBALS['certloom_wiz_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['certloom_wiz_options'][ $key ] = $value; return true; }
function current_time( $type ) { return $GLOBALS['certloom_wiz_now']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
class WP_Error { private $code; private $message; public function __construct( $code = '', $message = '' ) { $this->code=$code; $this->message=$message; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
class Ecapla_CertVerify_Backup { public static function assert_writes_allowed(){ return true; } public static function reserve_mutation_generation(){ $GLOBALS['certloom_wiz_generation']++; return $GLOBALS['certloom_wiz_generation']; } }
class Ecapla_CertVerify_Database { public static function get_registry_count(){ return 0; } public static function get_list(){ return array( 'items' => array() ); } }
class Ecapla_CertVerify_Fields { public static function get_manageable_fields(){ return array(); } }
function get_post( $id ){ return null; }
function get_post_type( $id ){ return ''; }
function get_current_user_id(){ return (int) $GLOBALS['certloom_wiz_user_id']; }
function get_user_meta( $user_id, $key, $single = false ){ return $GLOBALS['certloom_wiz_user_meta'][ $user_id ][ $key ] ?? ( $single ? '' : array() ); }
function update_user_meta( $user_id, $key, $value ){ $GLOBALS['certloom_wiz_user_meta'][ $user_id ][ $key ] = $value; return 1; }
function delete_user_meta( $user_id, $key ){ unset( $GLOBALS['certloom_wiz_user_meta'][ $user_id ][ $key ] ); return true; }
function wp_generate_uuid4(){ $GLOBALS['certloom_wiz_uuid']++; return '00000000-0000-4000-8000-' . str_pad( (string) $GLOBALS['certloom_wiz_uuid'], 12, '0', STR_PAD_LEFT ); }

require __DIR__ . '/support/draft-database.php';
require dirname( __DIR__ ) . '/src/Application/Setup/PurposePresetEngine.php';
require dirname( __DIR__ ) . '/src/Application/Admin/DraftWorkspace.php';
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-onboarding.php';

$checks=0;
function wiz_check($ok,$label){ global $checks; if(!$ok){ throw new RuntimeException($label); } $checks++; }
$engine = new \Certloom\Application\Setup\PurposePresetEngine();
$profiles = $engine->profiles();
wiz_check( 9 === count( $profiles ), 'Setup v2 must expose nine approved usage profiles.' );
foreach ( array( 'training_lms','iso_management','government_licensing','school_results','corporate_compliance','membership_association','events_awards','product_inspection','general_other' ) as $key ) { wiz_check( isset( $profiles[$key] ), 'Missing approved usage profile: '.$key ); }
wiz_check( array() === $engine->subcategories(), 'Setup v2 must remove the old required subcategory step.' );
$iso = $engine->recommend( 'iso_management' );
wiz_check( ! is_wp_error( $iso ) && 'ISO / Management Systems' === $iso['profile_label'], 'ISO profile recommendation must resolve.' );
$iso_fields = array_column( $iso['recommended_fields'], 'key' );
wiz_check( in_array( 'standard', $iso_fields, true ) && in_array( 'scope', $iso_fields, true ) && in_array( 'accreditation_reference', $iso_fields, true ), 'ISO profile must recommend Standard, Scope and accreditation reference.' );
wiz_check( false !== stripos( $iso['hierarchy']['program'], 'certification scheme' ) && false !== stripos( $iso['hierarchy']['batch'], 'audit cycle' ), 'ISO hierarchy help must explain Program and Batch context.' );
$government = $engine->recommend( 'government_licensing' );
wiz_check( false !== stripos( $government['hierarchy']['program'], 'license' ) && false !== stripos( $government['hierarchy']['issuer'], 'ministry' ), 'Government hierarchy must be explicit.' );
$school = $engine->recommend( 'school_results' );
wiz_check( in_array( 'roll_number', array_column( $school['recommended_fields'], 'key' ), true ) && in_array( 'marks_percentage', array_column( $school['recommended_fields'], 'key' ), true ), 'School profile fields must cover result use cases.' );
wiz_check( 'training_lms' === $engine->normalize_profile( 'training_courses' ) && 'iso_management' === $engine->normalize_profile( 'certification_standards' ), 'Legacy setup purpose keys must migrate to v2 profiles.' );
wiz_check( is_wp_error( $engine->recommend( 'unknown_profile' ) ), 'Unknown profile must fail closed.' );
foreach ( array_keys( $profiles ) as $profile_key ) {
    $recommendation = $engine->recommend( $profile_key );
    wiz_check( ! is_wp_error( $recommendation ) && count( (array) $recommendation['recommended_fields'] ) >= 4, 'Every profile must provide useful field recommendations: '.$profile_key );
    wiz_check( 4 === count( (array) $recommendation['hierarchy'] ), 'Every profile must explain Issuer/Program/Batch/Signer: '.$profile_key );
    wiz_check( 4 === count( (array) $recommendation['checklist'] ), 'Every profile must provide four next-step recommendations: '.$profile_key );
    wiz_check( $recommendation['checklist'] === $engine->completion_checklist( $profile_key, array() ), 'Unfinished setup preserves useful profile-specific next steps: '.$profile_key );
    $finished_checklist = $engine->completion_checklist( $profile_key, array( 'setup_program_id' => 42 ) );
    wiz_check( 4 === count( $finished_checklist ) && ( 'general_other' === $profile_key || in_array( 'Review Program requirements and customer fields', $finished_checklist, true ) ), 'Saved Program replaces its creation task while preserving other profile advice: '.$profile_key );
}
wiz_check( array() === $engine->completion_checklist( 'unknown_profile', array( 'setup_program_id' => 42 ) ), 'Completion advice does not invent a profile for invalid saved input.' );

$defaults = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 9 === $defaults['version'] && 'welcome' === $defaults['wizard_step'], 'Onboarding state must be v9 and start at Welcome.' );
wiz_check( array( 'welcome', 'profile', 'organization', 'context', 'fields', 'appearance', 'email', 'configuration', 'issue', 'finish' ) === Ecapla_CertVerify_Onboarding::wizard_steps(), 'Guided setup places Program before fields and design while preserving every setup stage.' );
wiz_check( array() === $defaults['wizard_draft'] && '' === $defaults['wizard_completed_at'], 'New setup must start without authoritative draft/completion.' );
$autosaved = Ecapla_CertVerify_Onboarding::autosave_wizard_draft( 'organization', array( 'organization_name'=>'Unsaved Organization','organization_contact'=>'draft@example.test' ), 1000 );
wiz_check( is_array( $autosaved ) && ! empty( $autosaved['run_id'] ), 'Lightweight Setup autosave must create a per-administrator resumable run.' );
wiz_check( 1000 === $autosaved['client_revision'], 'Autosave acknowledgement must bind the actual persisted client revision.' );
// An out-of-order save acknowledges the retained revision and step, so the
// browser cannot mistake discarded older intent for its latest saved choices.
$older_autosave = Ecapla_CertVerify_Onboarding::autosave_wizard_draft( 'appearance', array( 'organization_name' => 'Older discarded draft' ), 999 );
wiz_check( is_array( $older_autosave ) && 1000 === $older_autosave['client_revision'] && 'organization' === $older_autosave['step'], 'A stale autosave must acknowledge the retained workspace rather than the requested revision or step.' );
$autosaved_state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 'organization' === $autosaved_state['wizard_step'] && 'Unsaved Organization' === $autosaved_state['wizard_draft']['organization_name'], 'Reloading Setup state must restore the latest autosaved administrator draft.' );
$GLOBALS['certloom_wiz_user_id'] = 18;
$other_admin_state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( empty( $other_admin_state['wizard_draft']['organization_name'] ), 'One administrator must not inherit another administrator’s unfinished Setup fields.' );
$GLOBALS['certloom_wiz_user_id'] = 17;
$r = Ecapla_CertVerify_Onboarding::restart_wizard();
wiz_check( true === $r && 'welcome' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Explicit Start new setup must clear the autosaved run before the normal wizard journey begins.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'welcome', array(), 'profile', false );
wiz_check( true === $r && 'profile' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Welcome must advance to Profile.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'profile', array( 'usage_profile'=>'iso_management' ), 'organization', false );
wiz_check( true === $r, 'Valid usage profile must persist.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 'organization' === $state['wizard_step'] && 'iso_management' === $state['wizard_draft']['usage_profile'], 'Profile must advance resumably.' );
wiz_check( in_array( 'standard', $state['wizard_draft']['selected_fields'], true ) && in_array( 'scope', $state['wizard_draft']['selected_fields'], true ), 'Profile save must seed recommended field keys into draft only.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'organization', array( 'organization_name'=>'' ), 'fields', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_organization_required' === $r->get_error_code(), 'Organization name must be server-required.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'organization', array(), 'fields', true );
wiz_check( is_wp_error( $r ) && 'certloom_setup_organization_required' === $r->get_error_code(), 'Required organization cannot be skipped through a crafted request.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( ! in_array( 'organization', $state['wizard_skipped_steps'], true ) && 'organization' === $state['wizard_step'], 'Rejected organization skip leaves setup at the required step.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'organization', array( 'organization_name'=>'Example Certification Body','organization_logo_id'=>55,'organization_website'=>'https://example.test','organization_contact'=>'info@example.test' ), 'fields', false );
wiz_check( true === $r, 'Organization step must save identity draft.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'fields', array( 'selected_fields'=>array('standard','scope'),'custom_fields'=>array('Accreditation reference','Audit cycle') ), 'appearance', false );
wiz_check( true === $r, 'Field intent must persist.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'profile', array( 'usage_profile'=>'school_results' ), 'organization', false );
wiz_check( true === $r, 'Profile can be revisited.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( array('standard','scope') === $state['wizard_draft']['selected_fields'], 'Changing profile must not overwrite field choices already edited by the administrator.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'image','certificate_image_id'=>0 ), 'context', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_image_required' === $r->get_error_code(), 'Image appearance must require an uploaded image.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'image','certificate_image_id'=>91,'design_template_id'=>44 ), 'context', false );
wiz_check( true === $r, 'Image appearance may persist when image exists.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 91 === $state['wizard_draft']['certificate_image_id'] && 0 === $state['wizard_draft']['design_template_id'], 'Image mode must clear competing design authority.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'design','design_template_id'=>44,'certificate_image_id'=>91 ), 'context', false );
wiz_check( true === $r, 'Design appearance may persist when design exists.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 44 === $state['wizard_draft']['design_template_id'] && 0 === $state['wizard_draft']['certificate_image_id'], 'Design mode must clear competing image authority.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'details_only','design_template_id'=>44,'certificate_image_id'=>91 ), 'context', false );
wiz_check( true === $r, 'Details-only must remain first-class.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 0 === $state['wizard_draft']['design_template_id'] && 0 === $state['wizard_draft']['certificate_image_id'], 'Details-only must clear both artifact inputs.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'context', array( 'setup_new_batch_name'=>'September cycle','setup_new_batch_code'=>'SEP-26' ), 'issue', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_batch_program_required' === $r->get_error_code(), 'A new Batch/cycle must have Program context without introducing Recipient membership semantics.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'context', array( 'setup_new_issuer_name'=>'Example Authority','setup_new_program_title'=>'ISO 9001','setup_new_program_code'=>'ISO-9001','setup_new_batch_name'=>'September cycle','setup_new_batch_code'=>'SEP-26','setup_new_signer_name'=>'A. Signer','setup_new_signer_email'=>'signer@example.test','setup_credential_values'=>array('standard'=>'ISO 9001') ), 'issue', false );
wiz_check( true === $r && 'issue' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Context & data step must preserve compact hierarchy and credential values for first issuance.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'issue', array( 'first_recipient_name'=>'','credential_title'=>'ISO 9001 Certification','issue_date'=>'2026-09-09' ), 'finish', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_recipient_required' === $r->get_error_code(), 'First credential step must require Recipient when the administrator chooses to issue now.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'issue', array( 'first_recipient_name'=>'Acme Ltd','first_recipient_email'=>'not-an-email','credential_title'=>'ISO 9001 Certification','issue_date'=>'2026-09-09' ), 'finish', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_email_invalid' === $r->get_error_code(), 'Optional email must still validate when supplied.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'email', array( 'setup_email_mode'=>'selected', 'setup_email_design_id'=>'' ), 'configuration' );
wiz_check( is_wp_error( $r ) && 'certloom_setup_email_design_required' === $r->get_error_code(), 'Selecting an email design requires an identifier.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'email', array( 'setup_email_mode'=>'selected', 'setup_email_design_id'=>'credential-issued' ), 'configuration' );
wiz_check( true === $r && 'configuration' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Email intent must advance to review without sending or creating a credential.' );
wiz_check( in_array( 'email', Ecapla_CertVerify_Onboarding::wizard_steps(), true ) && in_array( 'configuration', Ecapla_CertVerify_Onboarding::wizard_steps(), true ), 'Guided setup includes explicit Email and Review steps.' );
$first_patch = array( 'first_recipient_name'=>'Acme Ltd','first_recipient_email'=>'qa@example.test','credential_title'=>'ISO 9001 Certification','issue_date'=>'2026-09-09' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_draft_only( 'issue', $first_patch );
wiz_check( true === $r && 'issue' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Save & finish later must preserve the first-credential draft without advancing or issuing.' );
$r = Ecapla_CertVerify_Onboarding::record_first_credential( $first_patch, array( 'version_id'=>101, 'series_id'=>55, 'verification_url'=>'https://example.test/credential/abc' ) );
wiz_check( true === $r, 'A real first-credential receipt must complete Setup.' );
$issued_state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 101 === $issued_state['wizard_draft']['first_credential_id'] && 55 === $issued_state['wizard_draft']['first_credential_series_id'], 'Setup must freeze the real first Credential Version and Series identifiers.' );
wiz_check( 'https://example.test/credential/abc' === $issued_state['wizard_draft']['first_credential_url'] && Ecapla_CertVerify_Onboarding::is_wizard_complete(), 'Setup must retain the verification URL and complete after real issuance.' );
$r = Ecapla_CertVerify_Onboarding::restart_wizard();
wiz_check( true === $r && 'welcome' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Setup v2 must be replayable from Welcome.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'issue', array(), 'finish', true );
wiz_check( true === $r && Ecapla_CertVerify_Onboarding::is_wizard_complete(), 'Doing the first credential later must complete Setup without fabricating a credential.' );
$skipped_state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( empty( $skipped_state['wizard_draft']['first_credential_id'] ) && in_array( 'issue', $skipped_state['wizard_skipped_steps'], true ), 'Skipped first issuance must remain explicitly recorded as deferred, not fake-issued.' );
$r = Ecapla_CertVerify_Onboarding::restart_wizard();
wiz_check( true === $r && 'welcome' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Setup v2 restart must remain available after a deferred first credential.' );

// Legacy migration: old walkthrough completion must not count as v2 completion.
$GLOBALS['certloom_wiz_user_meta'] = array();
$GLOBALS['certloom_wiz_options'][ Ecapla_CertVerify_Onboarding::OPTION_KEY ] = array( 'version'=>3,'wizard_step'=>'purpose','wizard_completed_steps'=>array('purpose','organization','design'),'wizard_completed_at'=>'2026-08-29 12:00:00','wizard_draft'=>array('purpose'=>'training_courses','subcategory'=>'online_course') );
$legacy = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 'profile' === $legacy['wizard_step'] && 'training_lms' === $legacy['wizard_draft']['usage_profile'], 'Legacy purpose state must migrate to Profile semantics.' );
wiz_check( '' === $legacy['wizard_completed_at'], 'Retired pre-v4 walkthrough completion must be cleared because it predates the current Setup contract.' );

$root = dirname(__DIR__);
$admin = file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$view = file_get_contents($root.'/admin/views/setup-wizard.php');
$css = file_get_contents($root.'/admin/assets/certloom-foundation.css');
$js = file_get_contents($root.'/admin/assets/admin.js');
foreach(array($admin,$view,$css,$js) as $src){ wiz_check(is_string($src)&&''!==$src,'Required Setup v2 source unavailable.'); }
wiz_check( false !== strpos($view,'Start setup') && false !== strpos($view,'Quick issue instead') && false !== strpos($view,'What are you using Certloom for?') && false !== strpos($view,'Choose credential fields') && false !== strpos($view,'How should credentials appear?') && false !== strpos($view,'Set up your Program') && false !== strpos($view,'Issue your first credential'), 'Setup must expose guided setup as the primary path and an optional quick issue path.' );
wiz_check( false === strpos($view,'name="numbering_mode"') && false === strpos($view,'backup_schedule'), 'Required Setup v2 path must not ask for numbering or backup policy during onboarding.' );
wiz_check( false !== strpos($view,'value="details_only"') && false !== strpos($view,'value="image"') && false !== strpos($view,'value="design"'), 'Appearance must expose exactly the three approved presentation concepts.' );
wiz_check( false !== strpos($view,'name="first_recipient_name"') && false !== strpos($view,'name="credential_title"') && false !== strpos($view,'name="issue_date"'), 'First credential must expose Recipient, Independent title fallback and effective date.' );
wiz_check( false !== strpos($view,'data-certloom-media-control') && false !== strpos($admin,"'certloom-setup'" ) && false !== strpos($admin,'wp_enqueue_media'), 'Organization/image selection must use WordPress media infrastructure.' );
wiz_check( false !== strpos($view,'How Certloom organizes this profile') && false !== strpos($view,'data-certloom-hierarchy="program"'), 'Profile-specific hierarchy help must be available without making hierarchy required.' );
wiz_check( false !== strpos($css,'position: fixed') && false !== strpos($css,'.certloom-setup-v2') && false !== strpos($admin,'certloom-setup-v2-body'), 'Setup v2 must use a focused full-screen shell.' );
wiz_check( false !== strpos($js,'data-certloom-setup-field-add') && false !== strpos($js,'data-certloom-presentation-panel') && false !== strpos($js,'data-hierarchy-'), 'Setup v2 interactions must cover custom fields, presentation exclusivity and live profile help.' );
wiz_check( false !== strpos($js,'certloom_setup_draft_autosave') && false !== strpos($js,'data-certloom-dirty') && false !== strpos($js,"addEventListener( 'invalid'") , 'Setup v2 must autosave unfinished changes and focus invalid controls.' );
wiz_check( false !== strpos($js,"aria-haspopup', 'dialog") && false !== strpos($js,"frame.on( 'close'") && false !== strpos($view,'aria-live="polite"'), 'Setup media controls must expose dialog/live-region semantics and restore focus after media closes.' );
wiz_check( false !== strpos($admin,"ensure_starter_publications( get_current_user_id(), 10 )") && false !== strpos($admin,"search_published_candidates( 'certloom-preset-', 50 )") && false !== strpos($view,'Open Design Center'), 'Setup Appearance must seed the ten starter designs for immediate selection and keep Design Center discoverable.' );
wiz_check( false !== strpos($view,'10 starter designs') && false !== strpos($view,'create your own certificate design with Certloom Designer'), 'Appearance helper copy must explain starter designs, Design Center publication and custom design creation.' );
wiz_check( 6 === substr_count($view,'value="skip_step"') && 6 === substr_count($view,'formnovalidate') && false !== strpos($view,'Add a logo or contact details (optional)'), 'Optional setup steps remain skippable while required organization is clear.' );
wiz_check( false !== strpos($view,'certloom-brand-mark.png') && false !== strpos($view,'certloom-brand-wordmark.png') && false === strpos($view,'exact supplied full-colour Certloom lockup will replace'), 'Setup branding must use the supplied Certloom mark/wordmark assets with no stale placeholder note.' );
wiz_check( false !== strpos($view,'wizard_skipped_steps') && false !== strpos($view,'is-skipped'), 'Progress must distinguish skipped steps from completed/current steps.' );
wiz_check( false !== strpos($css,'var(--cl-brand-emerald)') && false !== strpos($css,'linear-gradient(90deg, var(--cl-cyan), var(--cl-brand-emerald))'), 'Current/progress states must use the Certloom green and brand gradient.' );
wiz_check( false !== strpos($css,'.certloom-setup-v2-organization-logo') && false !== strpos($css,'.certloom-setup-v2-media-actions'), 'Organization logo control must use the compact aligned media-action layout.' );
wiz_check( false !== strpos($js,'fitWizardTemplatePreview') && false !== strpos($js,"availableHeight / naturalHeight") && false !== strpos(file_get_contents($root.'/admin/assets/admin.css'),'certloom-wizard-template-preview-stage'), 'Setup certificate previews must fit by both width and height inside a bounded no-scroll stage.' );
wiz_check( false === strpos($view,'generalized issuance backend') && false === strpos($admin,'generalized issuance backend') && false === strpos($view,'One required step remains'), 'Setup must not retain obsolete pending-backend or required-first-credential blockers.' );
wiz_check( false !== strpos($admin,'issue_setup_wizard_first_credential') && false !== strpos($admin,'ManualIssuanceService') && false !== strpos($view,'Issue first credential'), 'Setup first issuance must use the canonical Manual Issuance service and expose a real issue action.' );
wiz_check( false !== strpos($view,'Independent credential') && false !== strpos($view,'Inherited from') && false !== strpos($view,'Review Program'), 'Setup first issuance must harmonize with canonical Independent/Program title semantics and the new context step.' );

wiz_check( false !== strpos($view,'data-certloom-setup-context') && false !== strpos($view,'setup_issuer_id') && false !== strpos($view,'setup_program_id') && false !== strpos($view,'setup_batch_id') && false !== strpos($view,'setup_signer_id'), 'Context step must allow profile-aware Issuer/Program/Batch/Signer selection without a separate roster system.' );
wiz_check( false !== strpos($view,'Required by design') && false !== strpos($view,'setup_credential_values[') && false !== strpos($view,'Details for this first certificate'), 'Selected design requirements remain real fields on the first-issuance step.' );
wiz_check( false !== strpos($admin,'FirstCredentialSetupPlan') && false !== strpos($admin,'validate_setup_wizard_context_requirements') && false !== strpos($admin,'materialize_setup_wizard_context'), 'Setup backend must plan, validate and materialize the selected design context through canonical repositories.' );
wiz_check( false !== strpos($js,'data-certloom-setup-context') && false !== strpos($js,'data-certloom-setup-batch'), 'Setup JavaScript must keep Program/Batch context coherent while the administrator fills the compact context step.' );

// Resume a real per-administrator legacy workspace through the public reader.
$GLOBALS['certloom_wiz_options'][ Ecapla_CertVerify_Onboarding::OPTION_KEY ] = array( 'version' => 8, 'wizard_completed_at' => '' );
$GLOBALS['certloom_wiz_user_meta'] = array();
$resume_payload = array( 'wizard_draft' => array( 'usage_profile' => 'training_lms', 'organization_name' => 'Preserved Academy', 'selected_fields' => array( 'student_id' ), 'design_template_id' => 44, 'presentation_mode' => 'design', 'setup_credential_values' => array( 'training_hours' => '0' ), 'setup_repeating_data' => array( 'results' => array( array( 'subject' => 'Math', 'score' => '0' ) ) ) ), 'wizard_completed_steps' => array( 'welcome', 'profile', 'organization', 'fields' ), 'wizard_skipped_steps' => array() );
$saved_workspace = \Certloom\Application\Admin\DraftWorkspace::save( 'setup', $resume_payload, array( 'step' => 'appearance', 'status' => 'active' ) );
wiz_check( ! is_wp_error( $saved_workspace ), 'A legacy administrator workspace can be resumed.' );
$before_reader_options = $GLOBALS['certloom_wiz_options']; $before_reader_meta = $GLOBALS['certloom_wiz_user_meta'];
$resumed = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 'context' === $resumed['wizard_step'] && 'Preserved Academy' === $resumed['wizard_draft']['organization_name'] && array( 'student_id' ) === $resumed['wizard_draft']['selected_fields'] && 44 === $resumed['wizard_draft']['design_template_id'] && 'design' === $resumed['wizard_draft']['presentation_mode'] && array( 'training_hours' => '0' ) === $resumed['wizard_draft']['setup_credential_values'] && 'Math' === $resumed['wizard_draft']['setup_repeating_data']['results'][0]['subject'] && '0' === $resumed['wizard_draft']['setup_repeating_data']['results'][0]['score'], 'An unfinished design draft resumes at Program while preserving fields, design and individual values.' );
wiz_check( $before_reader_options === $GLOBALS['certloom_wiz_options'] && $before_reader_meta === $GLOBALS['certloom_wiz_user_meta'], 'Reading migrated setup does not persist settings or overwrite the administrator workspace.' );
$resume_payload['wizard_completed_steps'][] = 'context';
\Certloom\Application\Admin\DraftWorkspace::save( 'setup', $resume_payload, array( 'step' => 'appearance', 'status' => 'active' ) );
wiz_check( 'appearance' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'A draft with a reviewed Program keeps its current design step.' );
$resume_payload['wizard_completed_steps'] = array( 'organization' ); $resume_payload['wizard_draft']['quick_setup'] = true;
\Certloom\Application\Admin\DraftWorkspace::save( 'setup', $resume_payload, array( 'step' => 'issue', 'status' => 'active' ) );
wiz_check( 'issue' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Quick issuance resumes without a new Program prerequisite.' );
unset( $resume_payload['wizard_draft']['quick_setup'] ); $resume_payload['wizard_skipped_steps'] = array( 'context' );
\Certloom\Application\Admin\DraftWorkspace::save( 'setup', $resume_payload, array( 'step' => 'appearance', 'status' => 'active' ) );
wiz_check( 'appearance' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'An explicitly deferred Program preserves an independent-credential draft.' );
$GLOBALS['certloom_wiz_options'][ Ecapla_CertVerify_Onboarding::OPTION_KEY ]['wizard_completed_at'] = '2026-10-01 10:00:00';
\Certloom\Application\Admin\DraftWorkspace::save( 'setup', $resume_payload, array( 'step' => 'finish', 'status' => 'complete' ) );
wiz_check( 'finish' === Ecapla_CertVerify_Onboarding::get_state()['wizard_step'], 'Completed setup is not reopened by the changed sequence.' );

// Exercise the actual request translator: moved controls cannot erase older drafts.
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value ); }
require_once $root . '/admin/class-ecapla-certverify-admin.php';
$admin_instance = ( new ReflectionClass( Ecapla_CertVerify_Admin::class ) )->newInstanceWithoutConstructor();
$request_translator = new ReflectionMethod( Ecapla_CertVerify_Admin::class, 'setup_wizard_draft_request' );
$_POST = array( 'setup_new_program_title' => 'New Course' );
$program_patch = $request_translator->invoke( $admin_instance, 'context' );
wiz_check( 'New Course' === $program_patch['setup_new_program_title'] && ! array_key_exists( 'setup_credential_values', $program_patch ) && ! array_key_exists( 'setup_repeating_data', $program_patch ) && ! array_key_exists( 'first_grade', $program_patch ), 'The Program form preserves omitted first-certificate values.' );
$_POST = array( 'setup_credential_values' => array( 'training_hours' => '0' ), 'first_grade' => '0' );
$old_context_patch = $request_translator->invoke( $admin_instance, 'context' );
wiz_check( array( 'training_hours' => '0' ) === $old_context_patch['setup_credential_values'] && '0' === $old_context_patch['first_grade'] && ! array_key_exists( 'setup_repeating_data', $old_context_patch ), 'An older context form can still save its supplied values without clearing omitted rowsets.' );
$_POST = array( 'first_recipient_name' => 'Recipient', 'setup_values_present' => '1', 'setup_credential_values' => array( 'training_hours' => '0' ), 'setup_repeating_data' => array( 'results' => array( array( 'subject' => 'Math', 'score' => '0' ) ) ), 'first_grade' => '0' );
$issue_patch = $request_translator->invoke( $admin_instance, 'issue' );
wiz_check( '0' === $issue_patch['setup_credential_values']['training_hours'] && '0' === $issue_patch['setup_repeating_data']['results'][0]['score'] && '0' === $issue_patch['first_grade'], 'The first-issuance form receives moved credential values and results, preserving zeroes.' );
$_POST = array( 'first_recipient_name' => 'Recipient' );
$reduced_issue = $request_translator->invoke( $admin_instance, 'issue' );
wiz_check( ! array_key_exists( 'setup_credential_values', $reduced_issue ) && ! array_key_exists( 'first_grade', $reduced_issue ), 'Older quick/reduced issuance forms preserve omitted values.' );
$_POST = array( 'setup_values_present' => '1' );
$empty_issue = $request_translator->invoke( $admin_instance, 'issue' );
wiz_check( array() === $empty_issue['setup_credential_values'] && array() === $empty_issue['setup_repeating_data'] && '' === $empty_issue['first_grade'], 'A complete first-issuance values section explicitly clears stale values.' );

printf("PASS  Setup Wizard profile, Program-first resume and request translation\n\n%d checks, 0 failures\n", $checks);
