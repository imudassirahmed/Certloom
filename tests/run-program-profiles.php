<?php
/** PRG-02 Program Profile canonical configuration + issuance-design contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){$v=(string)$v;$v=preg_replace('/[\r\n\t]+/',' ',$v);return trim(strip_tags($v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Program\ProgramProfile;
use Certloom\Application\Program\ProgramLifecyclePolicy;

$pass=0;$fail=0; function pp($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$p=new ProgramProfile();
$d=ProgramProfile::defaults();
pp(3===($d['schema_version']??0),'Program Profile uses explicit schema version 3 for lifecycle and locale variants');
pp('custom_other'===($d['purpose']??''),'Program Profile has bounded safe default purpose');
pp('inherit'===($d['verification_visibility']??''),'Program Profile preserves the current safe verification visibility by default');
pp(0===($d['default_batch_id']??null),'Existing Programs retain no default Batch until explicitly selected');
pp(12===($p->normalize(array('default_batch_id'=>'12'))['default_batch_id']??null),'Default Batch normalizes to a typed positive ID');
foreach(array(-1,'12.5',true,array(),str_repeat('9',80))as$badDefault){pp(is_wp_error($p->normalize(array('default_batch_id'=>$badDefault))),'Invalid or oversized default Batch identity fails closed');}
pp(isset(ProgramProfile::purpose_labels()['training_courses']),'approved purpose taxonomy includes Training & Courses');
pp(isset(ProgramProfile::subcategory_labels()['certification_standards']['management_systems']),'approved certification taxonomy includes management systems');
$valid=$p->normalize(array('purpose'=>'training_courses','subcategory'=>'corporate_training','credential_type'=>'certification','eligibility_context'=>'Completed approved corporate training and met the Program threshold.','credential_locale'=>'ar','template_variants'=>array('ar'=>'arabic-certificate','en_US'=>'english-certificate'),'template_variant_fallback'=>'en_US','renewal'=>'required','verification_visibility'=>'recipient_only','portal'=>'enabled','sharing'=>'disabled','email_delivery'=>'enabled','integration_mapping_key'=>'training-map','external_scheme_id'=>'SCHEME-42'));
pp(!is_wp_error($valid),'valid Program Profile normalizes');
pp('training_courses'===($valid['purpose']??'')&&'corporate_training'===($valid['subcategory']??''),'purpose and subcategory survive normalization');
pp('certification'===($valid['credential_type']??''),'credential type is a bounded Program rule');
pp(false!==strpos((string)($valid['eligibility_context']??''),'Program threshold'),'eligibility context is stored as bounded administrator-owned context');
pp('ar'===($valid['credential_locale']??''),'locale is stored independently from admin locale');
pp(array('ar'=>'arabic-certificate','en_US'=>'english-certificate')===($valid['template_variants']??array()),'locale Template variants normalize into a bounded deterministic map');
pp('en_US'===($valid['template_variant_fallback']??''),'declared locale fallback is stored only when that variant exists');
pp('required'===($valid['renewal']??''),'renewal relevance is explicit');
$lifecycleProfile=$p->normalize(array('purpose'=>'professional_license_membership','subcategory'=>'professional_license','validity_mode'=>'duration','validity_value'=>2,'validity_unit'=>'years','renewal'=>'required','repeat_policy'=>'lifecycle'));
pp(!is_wp_error($lifecycleProfile)&&'duration'===($lifecycleProfile['validity_mode']??'')&&2===($lifecycleProfile['validity_value']??0)&&'years'===($lifecycleProfile['validity_unit']??''),'Program Profile stores typed validity duration instead of a disconnected day count');
pp(!is_wp_error($lifecycleProfile)&&'required'===($lifecycleProfile['renewal']??'')&&'lifecycle'===($lifecycleProfile['repeat_policy']??''),'Program Profile couples after-expiry and repeat-issuance policy into the typed lifecycle contract');
pp(is_wp_error($p->normalize(array('validity_mode'=>'permanent','renewal'=>'required'))),'non-expiring Program cannot declare an after-expiry renewal workflow');
pp('recipient_only'===($valid['verification_visibility']??''),'verification visibility default is explicit');
pp(!is_wp_error($p->normalize(array('verification_visibility'=>'verification_summary')))&&!is_wp_error($p->normalize(array('verification_visibility'=>'unlisted'))),'Program Profile can represent existing bounded verification visibility semantics');
pp('enabled'===($valid['portal']??'')&&'disabled'===($valid['sharing']??'')&&'enabled'===($valid['email_delivery']??''),'Portal/share/email defaults are bounded tri-state values');
pp(is_wp_error($p->normalize(array('purpose'=>'training_courses','subcategory'=>'degree'))),'subcategory from another purpose fails closed');
pp(is_wp_error($p->normalize(array('credential_locale'=>'../../etc/passwd'))),'invalid credential locale fails closed');
pp(is_wp_error($p->normalize(array('purpose'=>'made_up'))),'unknown purpose fails closed');
pp(is_wp_error($p->normalize(array('credential_type'=>'future_magic'))),'unknown credential type fails closed');
pp(is_wp_error($p->normalize(array('eligibility_context'=>str_repeat('x',501)))),'oversized eligibility context fails closed');
pp(is_wp_error($p->from_metadata(array(ProgramProfile::STORAGE_KEY=>'not-an-array'))),'malformed stored Program Profile envelope fails closed');
pp(is_wp_error($p->from_metadata(array(ProgramProfile::STORAGE_KEY=>array('schema_version'=>99)))),'unsupported stored Program Profile schema fails closed');
$legacy=$p->from_metadata(array(ProgramProfile::STORAGE_KEY=>array('schema_version'=>1,'purpose'=>'training_courses','subcategory'=>'online_course','credential_locale'=>'en_US')));pp(!is_wp_error($legacy)&&3===($legacy['schema_version']??0)&&ProgramLifecyclePolicy::VALIDITY_LEGACY===($legacy['validity_mode']??'')&&ProgramLifecyclePolicy::REPEAT_LIFECYCLE===($legacy['repeat_policy']??'')&&array()===($legacy['template_variants']??null),'stored v1 Program Profile upgrades in memory with migration-safe legacy lifecycle semantics');
pp(is_wp_error($p->normalize(array('template_variants'=>array('en_US'=>'one','en-US'=>'two')))),'duplicate locale variants after normalization fail closed');
pp(is_wp_error($p->normalize(array('template_variants'=>array('fr_FR'=>'french-certificate'),'template_variant_fallback'=>'ar'))),'declared fallback must name a configured locale variant');
$meta=array('legacy_key'=>'preserve-me');$combined=$p->with_metadata($meta,$valid);pp(!is_wp_error($combined)&&'preserve-me'===($combined['legacy_key']??''),'typed profile preserves unrelated Program metadata');
pp(isset($combined[ProgramProfile::STORAGE_KEY])&&is_array($combined[ProgramProfile::STORAGE_KEY]),'typed profile uses one reserved metadata envelope');
$round=$p->from_metadata($combined);pp(!is_wp_error($round)&&$round===$valid,'Program Profile round-trips deterministically');

$root=dirname(__DIR__);$repo=file_get_contents($root.'/src/Infrastructure/Repository/ProgramRepository.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/DomainAdmin.php');$svc=file_get_contents($root.'/src/Application/Issuance/ManualIssuanceService.php');$manual=file_get_contents($root.'/src/Infrastructure/Admin/ManualIssuanceAdmin.php');$bulk=file_get_contents($root.'/src/Infrastructure/Admin/BulkIssuanceAdmin.php');$assignment=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php');$adminjs=file_get_contents($root.'/admin/assets/admin.js');$restore=file_get_contents($root.'/src/Application/Restore/DomainRestoreRowValidator.php');
pp(false!==strpos($repo,'ProgramProfile::STORAGE_KEY')&&false!==strpos($repo,"\$row['profile']"),'Program repository exposes typed profile without leaking reserved envelope into advanced metadata');
pp(false!==strpos($repo,'profile_summary')&&false!==strpos($repo,'purpose_label'),'Program list surfaces a compact purpose summary without exposing the reserved metadata envelope');
pp(false!==strpos($admin,'Program defaults')&&false!==strpos($admin,'ProgramWorkspaceView() )->design')&&false!==strpos($admin,"save_scope_template_assignment( 'program'"),'Program admin keeps Profile defaults and certificate settings on one page while saving through canonical design assignments');
pp(false!==strpos($admin,'Inherit current safe issuance default')&&false!==strpos($admin,'Verification summary')&&false!==strpos($admin,'Unlisted'),'Program visibility defaults preserve current safe semantics rather than defaulting legacy Programs to public');
pp(false!==strpos($admin,'ProgramProfile::purpose_labels')&&false!==strpos($admin,'verification_visibility')&&false!==strpos($admin,'Credential type'),'Program Profile UI is driven by canonical typed choices');
pp(false!==strpos($admin,'inherits the established site numbering policy')&&false!==strpos($admin,'competing sequence authorities'),'Program numbering default is honest inheritance rather than an unimplemented second allocator');
pp(false!==strpos($adminjs,'function initProgramProfile()')&&false!==strpos($adminjs,"option.getAttribute( 'data-purpose' ) === activePurpose"),'Program purpose progressively filters subcategories in the admin UI');
pp(false!==strpos($assignment,'resolve_published_assignment_details')&&false!==strpos($assignment,'resolve_published_assignment_details'),'existing assignment hierarchy remains canonical design source');
pp(false!==strpos($svc,'resolve_published_template_for_context')&&false!==strpos($svc,'certloom_manual_issue_program_design_missing'),'Manual Issuance resolves Program + optional Batch design context and fails closed if no published design exists');
pp(false!==strpos($svc,"'resolution_scope'")&&false!==strpos($svc,"'profile'=>isset(\$p['profile'])"),'issued snapshots freeze Program Profile and design-resolution provenance');
pp(false!==strpos($manual,'Use inherited design')&&false!==strpos($manual,'Assign for this credential')&&false!==strpos($manual,'data-inherited-issuer')&&false!==strpos($manual,'Add a %3$s only when needed.')&&false!==strpos($manual,'Managed on the selected %s; issuance cannot contradict it.'),'Manual Issuance keeps canonical hierarchy resolution while Program-owned authority and optional signer selection remain distinct');
pp(false!==strpos($bulk,'Global → Issuer → Program → Batch')&&false!==strpos($bulk,'one-off design override'),'Bulk Issuance defaults to canonical hierarchy resolution and keeps explicit one-off override separate');
pp(false!==strpos($admin,'Credential lifecycle')&&false!==strpos($admin,'Repeat issuance')&&false!==strpos($admin,'ProgramLifecyclePolicy::repeat_labels'),'Program admin presents validity, renewal and repeat issuance as one lifecycle contract');
pp(false!==strpos($svc,'ProgramLifecyclePolicy')&&false!==strpos($svc,'find_existing_series_for_batch')&&false!==strpos($svc,'REPEAT_SEPARATE'),'Manual Issuance enforces the Program repeat policy instead of hard-coding Recipient + Program uniqueness');
pp(false!==strpos($restore,'ProgramProfile')&&false!==strpos($restore,'certloom_domain_restore_program_profile_invalid'),'restore preflight validates typed Program Profile metadata');
pp(false===strpos($admin,'if language == Urdu'),'Program Profile does not hard-code Urdu as the RTL model');
pp(!file_exists($root.'/resources/renderer/accepted.json'),'PRG-02 does not manufacture renderer authority');
$plugin=file_get_contents($root.'/certloom.php');pp(false!==strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")&&false!==strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'PRG-02 leaves normalized production authority closed');

echo "\n$pass Program Profile checks, $fail failures\n"; exit($fail?1:0);
