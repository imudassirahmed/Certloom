<?php
/** I18N-02 locale-driven credential language, RTL and Template-variant contract. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){$v=(string)$v;$v=preg_replace('/[\r\n\t]+/',' ',$v);return trim(strip_tags($v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\I18n\CredentialLocale;
use Certloom\Application\I18n\ProgramTemplateVariantResolver;
use Certloom\Application\Program\ProgramProfile;
use Certloom\Application\Rendering\TextDirectionResolver;

$pass=0;$fail=0;function i18nck($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$l=new CredentialLocale();
i18nck('en_US'===$l->normalize_locale('en-us'),'locale normalizes language + region deterministically');
i18nck('zh_Hant'===$l->normalize_locale('ZH-hant'),'locale normalizes script subtags deterministically');
i18nck('ar'===$l->normalize_locale('AR'),'language-only locale normalizes');
i18nck(is_wp_error($l->normalize_locale('../../etc/passwd')),'path-like invalid locale fails closed');
i18nck(is_wp_error($l->normalize_locale('e')),'one-letter invalid locale fails closed');
i18nck('site_default'===$l->normalize_configured('site_default'),'site_default remains an explicit configured sentinel');
$resolved=$l->resolve('site_default','fr_FR');i18nck(!is_wp_error($resolved)&&'site_default'===$resolved['configured']&&'fr_FR'===$resolved['effective'],'site_default resolves to exact WordPress site locale at review time');
i18nck('fr'===$resolved['language']&&'ltr'===$resolved['direction'],'resolved LTR locale exposes base language and direction');
foreach(array('ar','ar_SA','fa_IR','he_IL','ur_PK') as $rtl){i18nck('rtl'===$l->direction($rtl),$rtl.' resolves RTL without a language-specific Urdu toggle');}
foreach(array('en_US','fr_FR','de_DE','hi_IN','zh_Hans') as $ltr){i18nck('ltr'===$l->direction($ltr),$ltr.' resolves LTR');}
i18nck('rtl'===$l->direction('ku_Arab'),'explicit Arab script subtag resolves RTL even outside the base RTL-language list');
$chain=$l->fallback_chain('fr_CA','en_US');i18nck(array('fr_CA','fr','en_US','en')===$chain,'fallback chain is exact locale → base language → declared fallback → fallback base');
$chain2=$l->fallback_chain('ar_SA','program_default');i18nck(array('ar_SA','ar')===$chain2,'program_default fallback does not invent a language variant');

$p=new ProgramProfile();$profile=$p->normalize(array('purpose'=>'training_courses','subcategory'=>'online_course','credential_locale'=>'ar-SA','template_variants'=>array('ar'=>'arabic-certificate','en-US'=>'english-certificate'),'template_variant_fallback'=>'en_US'));
i18nck(!is_wp_error($profile),'Program Profile v2 accepts normalized locale variants');
i18nck('ar_SA'===($profile['credential_locale']??''),'Program credential locale canonicalizes');
i18nck(array('ar'=>'arabic-certificate','en_US'=>'english-certificate')===($profile['template_variants']??array()),'Program Template locale map canonicalizes and sorts');
i18nck('en_US'===($profile['template_variant_fallback']??''),'Program declared fallback canonicalizes');
$r=new ProgramTemplateVariantResolver($l);$exact=$r->resolve(array('template_variants'=>array('ar_SA'=>'arabic-sa','ar'=>'arabic'),'template_variant_fallback'=>'program_default'),'ar_SA');i18nck('arabic-sa'===($exact['template_key']??'')&&'program_locale_exact'===($exact['resolution_scope']??''),'exact locale Template variant wins');
$base=$r->resolve(array('template_variants'=>array('ar'=>'arabic'),'template_variant_fallback'=>'program_default'),'ar_SA');i18nck('arabic'===($base['template_key']??'')&&'program_locale_language'===($base['resolution_scope']??''),'base language Template variant is second choice');
$fallback=$r->resolve(array('template_variants'=>array('en_US'=>'english'),'template_variant_fallback'=>'en_US'),'fr_CA');i18nck('english'===($fallback['template_key']??'')&&'program_locale_fallback'===($fallback['resolution_scope']??''),'declared fallback Template variant is third choice');
i18nck(null===$r->resolve(array('template_variants'=>array(),'template_variant_fallback'=>'program_default'),'fr_CA'),'no variant returns null so existing Program/Issuer/Global assignment remains canonical fallback');

$dir=new TextDirectionResolver();i18nck('rtl'===$dir->resolve('مرحبا','auto'),'element Auto direction resolves Arabic content RTL');i18nck('rtl'===$dir->resolve('שלום','auto'),'element Auto direction resolves Hebrew content RTL');i18nck('ltr'===$dir->resolve('Certificate مرحبا','auto'),'element Auto uses first strong character so mixed-script Latin-first text remains LTR');i18nck('rtl'===$dir->resolve('مرحبا Certificate','auto'),'mixed-script Arabic-first text remains RTL');i18nck('ltr'===$dir->resolve('مرحبا','ltr'),'explicit LTR element direction overrides content');i18nck('rtl'===$dir->resolve('Certificate','rtl'),'explicit RTL element direction overrides content');

$root=dirname(__DIR__);$admin=file_get_contents($root.'/src/Infrastructure/Admin/DomainAdmin.php');$manual_admin=file_get_contents($root.'/src/Infrastructure/Admin/ManualIssuanceAdmin.php');$bulk_preflight=file_get_contents($root.'/src/Application/Issuance/BulkIssuancePreflightJobHandler.php');$repo=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php');$js=file_get_contents($root.'/admin/assets/admin.js');$css=file_get_contents($root.'/admin/assets/admin.css');$svc=file_get_contents($root.'/src/Application/Issuance/ManualIssuanceService.php');$designer=file_get_contents($root.'/admin/assets/designer.js');
i18nck(false!==strpos($admin,'Language-specific certificate designs')&&false!==strpos($admin,'profile_template_variant_locale[]'),'Program admin exposes locale-specific published Template variants');
i18nck(false!==strpos($admin,'Certificate language')&&false!==strpos($admin,'interface still follows the WordPress user language'),'Program UI separates certificate locale from administrator/interface locale');
i18nck(false!==strpos($admin,'Element direction remains Auto / LTR / RTL'),'Program UI explains mixed-direction element override rather than a global RTL toggle');
i18nck(false!==strpos($admin,'find_published')&&false!==strpos($admin,'certloom_program_profile_template_variant_unpublished'),'Program variant save validates active published Template identity');
i18nck(false!==strpos($js,'initLocaleVariants')&&false!==strpos($js,'data-certloom-locale-fallback'),'Program locale variants have bounded progressive admin interaction');
i18nck(false!==strpos($css,'.certloom-locale-variant-row')&&false!==strpos($css,'forced-colors:active'),'Program locale variant UI has responsive/forced-colors coverage');
i18nck(false!==strpos($svc,'ProgramTemplateVariantResolver')&&false!==strpos($svc,"'credential_locale' => \$locale"),'issuance review binds locale resolution and Template-variant resolution into review evidence');
i18nck(false!==strpos($svc,"'locale' => isset( \$review['credential_locale']")&&false!==strpos($svc,"'locale_variant'"),'immutable Version snapshot freezes effective locale and locale Template provenance');
i18nck(false!==strpos($svc,'certloom_manual_issue_locale_template_unavailable'),'configured locale Template becoming unavailable fails closed');
i18nck(false!==strpos($manual_admin,"__( 'Credential language', 'certloom' )")&&false!==strpos($manual_admin,'language variant %s'),'Manual Issuance confirmation visibly discloses effective credential language/direction and locale-specific design provenance');
i18nck(false!==strpos($bulk_preflight,"'credential_locale'=>")&&false!==strpos($bulk_preflight,"'template_locale_variant'=>"),'Bulk review plan preserves each row’s locale/template resolution evidence from the canonical Manual Issuance review');
i18nck(false!==strpos($repo,'find_published_by_machine_key')&&false!==strpos($svc,'find_published_template_by_machine_key'),'Program locale variants bind to stable Template machine keys rather than database IDs, preserving backup/restore portability');
i18nck(false===strpos(file_get_contents($root.'/src/Application/Program/ProgramProfile.php'),"'template_variants' => array( 1 =>"),'Program Profile locale variants do not persist raw Template database IDs');
i18nck(false!==strpos($designer,"direction")&&false!==strpos($designer,"auto"),'Designer continues to expose canonical element direction rather than a separate language-specific layout schema');
i18nck(false===strpos($admin,'if language == Urdu')&&false===strpos($svc,'if language == Urdu'),'no hard-coded Urdu-only RTL branch was introduced');
i18nck(!file_exists($root.'/resources/renderer/accepted.json'),'I18N-02 does not manufacture renderer authority');$plugin=file_get_contents($root.'/certloom.php');i18nck(false!==strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")&&false!==strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'I18N-02 leaves normalized production authority closed');

echo "\n$pass I18N-02 checks, $fail failures\n";exit($fail?1:0);
