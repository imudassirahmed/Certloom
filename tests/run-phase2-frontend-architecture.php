<?php
/** Static premium acceptance for Phase 2 frontend architecture. */
$root=dirname(__DIR__);
$files=array(
 'bootstrap'=>file_get_contents($root.'/certloom.php'),
 'router'=>file_get_contents($root.'/includes/class-ecapla-certverify-public-router.php'),
 'presenter'=>file_get_contents($root.'/includes/class-ecapla-certverify-public-presentation.php'),
 'render'=>file_get_contents($root.'/includes/class-ecapla-certverify-render.php'),
 'rest'=>file_get_contents($root.'/includes/class-ecapla-certverify-rest.php'),
 'shortcode'=>file_get_contents($root.'/includes/class-ecapla-certverify-shortcode.php'),
 'block'=>file_get_contents($root.'/includes/class-ecapla-certverify-block.php'),
 'elementor'=>file_get_contents($root.'/elementor/class-ecapla-certverify-credential-widget.php'),
 'js'=>file_get_contents($root.'/public/assets/certloom-public.js'),
 'css'=>file_get_contents($root.'/public/assets/certloom-public.css'),
 'inventory'=>file_get_contents($root.'/docs/public-surface-inventory.md'),
);
foreach($files as $n=>$v){if(!is_string($v)||$v==='')throw new RuntimeException('missing '.$n);}
function qa2($c,$m){if(!$c)throw new RuntimeException($m);}
$checks=array(
 'public router booted'=>fn()=>qa2(false!==strpos($files['bootstrap'],'Ecapla_CertVerify_Public_Router::init()'),'router init'),
 'presentation layer booted before renderer'=>fn()=>qa2(strpos($files['bootstrap'],'class-ecapla-certverify-public-presentation.php')<strpos($files['bootstrap'],'class-ecapla-certverify-render.php'),'include order'),
 'clean verification route exists'=>fn()=>qa2(false!==strpos($files['router'],"^certloom/verify/"),'verify rewrite'),
 'clean credential route exists'=>fn()=>qa2(false!==strpos($files['router'],"^certloom/credential/"),'profile rewrite'),
 'clean routes noindex'=>fn()=>qa2(false!==strpos($files['router'],"\$robots['noindex']")&&false!==strpos($files['router'],'X-Robots-Tag: noindex, nofollow'),'robots'),
 'clean routes no-cache'=>fn()=>qa2(false!==strpos($files['router'],'nocache_headers()'),'cache'),
 'clean routes referrer protected'=>fn()=>qa2(false!==strpos($files['router'],'Referrer-Policy: no-referrer'),'referrer'),
 'complex certificate tokens base64url'=>fn()=>qa2(false!==strpos($files['router'],'base64_encode')&&false!==strpos($files['router'],"'+/', '-_"),'transport token'),
 'transport token not new credential identity'=>fn()=>qa2(false!==stripos($files['inventory'],'routing transport only'),'identity docs'),
 'legacy presenter uses exact authoritative database'=>fn()=>qa2(false!==strpos($files['presenter'],'Ecapla_CertVerify_Database::get_by_cert_number'),'legacy authority'),
 'legacy presenter uses visible fields only'=>fn()=>qa2(false!==strpos($files['presenter'],'Ecapla_CertVerify_Fields::get_visible_fields()'),'visibility'),
 'legacy presenter derives effective status'=>fn()=>qa2(false!==strpos($files['presenter'],'effective_status'),'status'),
 'legacy presenter includes issuer context'=>fn()=>qa2(false!==strpos($files['presenter'],"organization_name"),'issuer'),
 'legacy presenter includes verification timestamp'=>fn()=>qa2(false!==strpos($files['presenter'],"verified_at"),'timestamp'),
 'legacy presenter owns actions'=>fn()=>qa2(false!==strpos($files['presenter'],"'actions'")&&false!==strpos($files['presenter'],"'copy'")&&false!==strpos($files['presenter'],"'print'"),'actions'),
 'REST delegates presentation'=>fn()=>qa2(false!==strpos($files['rest'],'Ecapla_CertVerify_Public_Presentation::verify'),'REST presenter'),
 'REST preserves fields compatibility'=>fn()=>qa2(false!==strpos($files['rest'],"'fields'       => \$presentation['fields']"),'REST fields'),
 'REST presentation envelope additive'=>fn()=>qa2(false!==strpos($files['rest'],"'presentation' => array("),'REST additive'),
 'server no-JS renderer delegates presenter'=>fn()=>qa2(false!==strpos($files['render'],'Ecapla_CertVerify_Public_Presentation::verify'),'server presenter'),
 'unavailable server render has real helper'=>fn()=>qa2(false!==strpos($files['render'],'private static function message_html'),'message helper'),
 'profile renderer exists'=>fn()=>qa2(false!==strpos($files['render'],'public static function credential_profile'),'profile render'),
 'copy action server rendered'=>fn()=>qa2(false!==strpos($files['render'],'certloom-copy-link'),'copy'),
 'result action namespace avoids legacy cvx-action collision'=>fn()=>qa2(false!==strpos($files['render'],'certloom-result-action')&&false!==strpos($files['js'],'certloom-result-action')&&false!==strpos($files['css'],'.certloom-result-action{')&&false===strpos($files['css'],'.cv-action,.cvx-action,.cvp-action{appearance:none;'),'action namespace'),
 'print action server rendered'=>fn()=>qa2(false!==strpos($files['render'],'certloom-print'),'print'),
 'shortcode profile parity'=>fn()=>qa2(false!==strpos($files['shortcode'],"add_shortcode( 'certloom_credential'")&&false!==strpos($files['shortcode'],'credential_profile'),'shortcode'),
 'Gutenberg profile block registered'=>fn()=>qa2(false!==strpos($files['block'],"blocks/credential")&&false!==strpos($files['block'],'render_credential'),'block'),
 'Elementor profile uses shared renderer'=>fn()=>qa2(false!==strpos($files['elementor'],'Ecapla_CertVerify_Render::credential_profile'),'elementor'),
 'Elementor profile semantic widget name stable'=>fn()=>qa2(false!==strpos($files['elementor'],"return 'certloom-credential'"),'widget name'),
 'JS consumes additive presentation'=>fn()=>qa2(false!==strpos($files['js'],'result.data.presentation'),'JS presentation'),
 'JS has copy fallback'=>fn()=>qa2(false!==strpos($files['js'],'fallbackCopy')&&false!==strpos($files['js'],'navigator.clipboard'),'copy fallback'),
 'JS print is user initiated'=>fn()=>qa2(false!==strpos($files['js'],'window.print()'),'print'),
 'profile responsive CSS exists'=>fn()=>qa2(false!==strpos($files['css'],'.certloom-credential-profile.cvp-widget')&&false!==strpos($files['css'],'@media print'),'profile css'),
 'public surface inventory exists'=>fn()=>qa2(false!==strpos($files['inventory'],'SURF-01')&&false!==strpos($files['inventory'],'/certloom/v1/verify'),'inventory'),
 'inventory protects internal notes'=>fn()=>qa2(false!==strpos($files['inventory'],'internal_notes'),'privacy inventory'),
 'future normalized endpoint stays gated'=>fn()=>qa2(false!==strpos($files['rest'],'PublicVerificationGate'),'future gate'),
 'production authority flags remain false'=>fn()=>qa2(substr_count($files['bootstrap'],"define( 'CERTLOOM_DOMAIN_")>=3&&false!==strpos($files['bootstrap'],"define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )"),'flags'),
);
$failed=0;foreach($checks as $n=>$f){try{$f();echo "PASS  $n\n";}catch(Throwable $e){$failed++;echo "FAIL  $n\n      {$e->getMessage()}\n";}}
echo "\n".count($checks)." tests, $failed failures\n";exit($failed?1:0);
