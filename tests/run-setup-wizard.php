<?php
/** UI Phase 3 — Setup Wizard v2 profile/persistence/source contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
$GLOBALS['certloom_wiz_options'] = array();
$GLOBALS['certloom_wiz_generation'] = 0;
$GLOBALS['certloom_wiz_now'] = '2026-09-09 06:15:00';

function __( $text ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
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

require dirname( __DIR__ ) . '/src/Application/Setup/PurposePresetEngine.php';
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
}

$defaults = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 5 === $defaults['version'] && 'welcome' === $defaults['wizard_step'], 'Onboarding state must be v5 and start at Welcome.' );
wiz_check( array() === $defaults['wizard_draft'] && '' === $defaults['wizard_completed_at'], 'New setup must start without authoritative draft/completion.' );
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
wiz_check( true === $r, 'Organization may be explicitly skipped for later without bypassing the saved skip state.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( in_array( 'organization', $state['wizard_skipped_steps'], true ) && 'fields' === $state['wizard_step'], 'Skipped setup steps must be recorded and advance resumably.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'organization', array( 'organization_name'=>'Example Certification Body','organization_logo_id'=>55,'organization_website'=>'https://example.test','organization_contact'=>'info@example.test' ), 'fields', false );
wiz_check( true === $r, 'Organization step must save identity draft.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'fields', array( 'selected_fields'=>array('standard','scope'),'custom_fields'=>array('Accreditation reference','Audit cycle') ), 'appearance', false );
wiz_check( true === $r, 'Field intent must persist.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'profile', array( 'usage_profile'=>'school_results' ), 'organization', false );
wiz_check( true === $r, 'Profile can be revisited.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( array('standard','scope') === $state['wizard_draft']['selected_fields'], 'Changing profile must not overwrite field choices already edited by the administrator.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'image','certificate_image_id'=>0 ), 'issue', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_image_required' === $r->get_error_code(), 'Image appearance must require an uploaded image.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'image','certificate_image_id'=>91,'design_template_id'=>44 ), 'issue', false );
wiz_check( true === $r, 'Image appearance may persist when image exists.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 91 === $state['wizard_draft']['certificate_image_id'] && 0 === $state['wizard_draft']['design_template_id'], 'Image mode must clear competing design authority.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'design','design_template_id'=>44,'certificate_image_id'=>91 ), 'issue', false );
wiz_check( true === $r, 'Design appearance may persist when design exists.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 44 === $state['wizard_draft']['design_template_id'] && 0 === $state['wizard_draft']['certificate_image_id'], 'Design mode must clear competing image authority.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'appearance', array( 'presentation_mode'=>'details_only','design_template_id'=>44,'certificate_image_id'=>91 ), 'issue', false );
wiz_check( true === $r, 'Details-only must remain first-class.' );
$state = Ecapla_CertVerify_Onboarding::get_state();
wiz_check( 0 === $state['wizard_draft']['design_template_id'] && 0 === $state['wizard_draft']['certificate_image_id'], 'Details-only must clear both artifact inputs.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'issue', array( 'first_recipient_name'=>'','credential_title'=>'ISO 9001 Certification','issue_date'=>'2026-09-09' ), 'finish', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_recipient_required' === $r->get_error_code(), 'First credential step must require Recipient when the administrator chooses to issue now.' );
$r = Ecapla_CertVerify_Onboarding::save_wizard_step( 'issue', array( 'first_recipient_name'=>'Acme Ltd','first_recipient_email'=>'not-an-email','credential_title'=>'ISO 9001 Certification','issue_date'=>'2026-09-09' ), 'finish', false );
wiz_check( is_wp_error( $r ) && 'certloom_setup_email_invalid' === $r->get_error_code(), 'Optional email must still validate when supplied.' );
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
wiz_check( false !== strpos($view,'Start setup') && false !== strpos($view,'What are you using Certloom for?') && false !== strpos($view,'Choose credential fields') && false !== strpos($view,'How should credentials appear?') && false !== strpos($view,'Issue your first credential'), 'Setup v2 must expose the approved flow.' );
wiz_check( false === strpos($view,'name="program_title"') && false === strpos($view,'name="numbering_mode"') && false === strpos($view,'backup_schedule'), 'Required Setup v2 path must not ask for Program, numbering or backups.' );
wiz_check( false !== strpos($view,'value="details_only"') && false !== strpos($view,'value="image"') && false !== strpos($view,'value="design"'), 'Appearance must expose exactly the three approved presentation concepts.' );
wiz_check( false !== strpos($view,'name="first_recipient_name"') && false !== strpos($view,'name="credential_title"') && false !== strpos($view,'name="issue_date"'), 'First credential must expose Recipient, title and issue date.' );
wiz_check( false !== strpos($view,'data-certloom-media-control') && false !== strpos($admin,"'certloom-setup'" ) && false !== strpos($admin,'wp_enqueue_media'), 'Organization/image selection must use WordPress media infrastructure.' );
wiz_check( false !== strpos($view,'How Certloom organizes this profile') && false !== strpos($view,'data-certloom-hierarchy="program"'), 'Profile-specific hierarchy help must be available without making hierarchy required.' );
wiz_check( false !== strpos($css,'position: fixed') && false !== strpos($css,'.certloom-setup-v2') && false !== strpos($admin,'certloom-setup-v2-body'), 'Setup v2 must use a focused full-screen shell.' );
wiz_check( false !== strpos($js,'data-certloom-custom-field-add') && false !== strpos($js,'data-certloom-presentation-panel') && false !== strpos($js,'data-hierarchy-'), 'Setup v2 interactions must cover custom fields, presentation exclusivity and live profile help.' );
wiz_check( false !== strpos($js,'data-certloom-dirty') && false !== strpos($js,'beforeunload') && false !== strpos($js,"addEventListener( 'invalid'") , 'Setup v2 must track unsaved changes and focus invalid controls.' );
wiz_check( false !== strpos($js,"aria-haspopup', 'dialog") && false !== strpos($js,"frame.on( 'close'") && false !== strpos($view,'aria-live="polite"'), 'Setup media controls must expose dialog/live-region semantics and restore focus after media closes.' );
wiz_check( false !== strpos($admin,"ensure_starter_publications( get_current_user_id(), 10 )") && false !== strpos($admin,"search_published_candidates( 'certloom-preset-', 50 )") && false !== strpos($view,'Open Design Center'), 'Setup Appearance must seed the ten starter designs for immediate selection and keep Design Center discoverable.' );
wiz_check( false !== strpos($view,'10 starter designs') && false !== strpos($view,'create your own certificate design with Certloom Designer'), 'Appearance helper copy must explain starter designs, Design Center publication and custom design creation.' );
wiz_check( 5 === substr_count($view,'value="skip_step"') && 5 === substr_count($view,'formnovalidate'), 'Each actionable setup step must expose an explicit skip/do-later action without browser validation blocking it.' );
wiz_check( false !== strpos($view,'certloom-brand-mark.png') && false !== strpos($view,'certloom-brand-wordmark.png') && false === strpos($view,'exact supplied full-colour Certloom lockup will replace'), 'Setup branding must use the supplied Certloom mark/wordmark assets with no stale placeholder note.' );
wiz_check( false !== strpos($view,'wizard_skipped_steps') && false !== strpos($view,'is-skipped'), 'Progress must distinguish skipped steps from completed/current steps.' );
wiz_check( false !== strpos($css,'var(--cl-brand-emerald)') && false !== strpos($css,'linear-gradient(90deg, var(--cl-cyan), var(--cl-brand-emerald))'), 'Current/progress states must use the Certloom green and brand gradient.' );
wiz_check( false !== strpos($css,'.certloom-setup-v2-organization-logo') && false !== strpos($css,'.certloom-setup-v2-media-actions'), 'Organization logo control must use the compact aligned media-action layout.' );
wiz_check( false !== strpos($js,'fitWizardTemplatePreview') && false !== strpos($js,"availableHeight / naturalHeight") && false !== strpos(file_get_contents($root.'/admin/assets/admin.css'),'certloom-wizard-template-preview-stage'), 'Setup certificate previews must fit by both width and height inside a bounded no-scroll stage.' );
wiz_check( false === strpos($view,'generalized issuance backend') && false === strpos($admin,'generalized issuance backend') && false === strpos($view,'One required step remains'), 'Setup must not retain obsolete pending-backend or required-first-credential blockers.' );
wiz_check( false !== strpos($admin,'issue_setup_wizard_first_credential') && false !== strpos($admin,'ManualIssuanceService') && false !== strpos($view,'Issue first credential'), 'Setup first issuance must use the canonical Manual Issuance service and expose a real issue action.' );
wiz_check( false !== strpos($view,'Independent credential') && false !== strpos($view,'Program-backed or advanced issuance'), 'Setup first issuance must explicitly harmonize with canonical Independent/Program semantics.' );

printf("PASS  Setup Wizard v2 profile/persistence/source contract\n\n%d checks, 0 failures\n", $checks);
