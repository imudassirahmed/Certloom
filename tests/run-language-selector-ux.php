<?php
/** UX-C08 certificate-language selector contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\I18n\CredentialLocale;
$pass=0;$fail=0;function ux8($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$l=new CredentialLocale();$options=$l->selection_options(true);$variants=$l->selection_options(false);
ux8(isset($options['site_default'])&&isset($options['en_US'])&&isset($options['ar'])&&isset($options['ur'])&&isset($options['fa_IR'])&&isset($options['zh_CN']),'selector includes site default plus representative LTR/RTL certificate locales');
ux8(!isset($variants['site_default']),'language-specific Template variants require an explicit locale rather than site-default sentinel');
ux8(false!==strpos($options['ar'],'Arabic')&&false!==strpos($options['ur'],'Urdu')&&false!==strpos($options['fr_FR'],'French'),'selector labels are human-readable rather than raw locale codes only');
ux8('zh_Hant'===$l->normalize_locale('zh-Hant'),'valid custom locale remains accepted outside curated selector');
ux8('ku_Arab'===$l->normalize_locale('ku-Arab'),'server validation remains a locale validator, not a selector allow-list');
$root=dirname(__DIR__);$admin=file_get_contents($root.'/src/Infrastructure/Admin/DomainAdmin.php');$js=file_get_contents($root.'/admin/assets/admin.js');
ux8(false!==strpos($admin,'data-certloom-credential-locale')&&false!==strpos($admin,"credential_locale_field( 'profile[credential_locale]'"),'Program credential locale is rendered as a select');
ux8(false===strpos($admin,"text_field( 'profile[credential_locale]'") ,'raw credential-locale text input is removed from normal Program UI');
ux8(false!==strpos($admin,'profile_template_variant_locale[]')&&false!==strpos($admin,'data-certloom-locale-select'),'language-specific Template locale rows use the same bounded selector experience');
ux8(false!==strpos($admin,'current custom locale'),'existing valid non-curated locale is retained visibly instead of being discarded on edit');
ux8(false!==strpos($admin,'interface still follows the WordPress user language')&&false!==strpos($admin,'translations that are actually installed'),'copy separates certificate language from interface translation availability');
ux8(false!==strpos($js,"[name=\"profile_template_variant_locale[]\"]")&&false!==strpos($js,"list.addEventListener( 'change'"),'variant fallback interaction is wired for select controls');
ux8(false===strpos($admin,'Interface language — available')&&false===strpos($admin,'reviewed translation available'),'selector does not fabricate reviewed interface-language claims');
ux8(!file_exists($root.'/resources/renderer/accepted.json'),'language selector does not open renderer authority');
$plugin=file_get_contents($root.'/certloom.php');ux8(false!==strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")&&false!==strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'language UX leaves normalized production authority closed');
echo "\n$pass UX-C08 checks, $fail failures\n"; exit($fail?1:0);
