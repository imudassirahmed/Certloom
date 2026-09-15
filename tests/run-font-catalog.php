<?php
/** FONT-02A controlled Designer font-family catalog contract. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html__($v,$d=null){return $v;} function esc_attr__($v,$d=null){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function wp_strip_all_tags($v){return strip_tags((string)$v);} function wp_get_global_settings(){return array('typography'=>array('fontFamilies'=>array('custom'=>array(array('name'=>'Inter Local','slug'=>'inter','fontFamily'=>'Inter, Arial, sans-serif','fontFace'=>array(array('src'=>'https://example.test/uploads/fonts/inter.woff2','fontWeight'=>'400')))))));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\DesignerFontCatalog;
use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\FontManifest;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
$root=dirname(__DIR__); $checks=0; $fail=0;
function f02($ok,$label){global $checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
$catalog=new DesignerFontCatalog(); $defs=$catalog->definitions();
f02(count($defs)>=40&&isset($defs['sans-serif'],$defs['arial'],$defs['georgia'],$defs['consolas'],$defs['wp-inter']),'catalog exposes the expanded built-in and WordPress-local font families');
f02($catalog->normalize_key(' Serif ')==='serif','catalog normalizes bounded family keys');
$localKey=$catalog->key_for_local_name('Fixture Local Sans'); f02(is_string($localKey)&&0===strpos($localKey,'local-')&&$catalog->normalize_key($localKey)===$localKey,'catalog creates stable browser-local font keys');
f02($catalog->local_name_for_key($localKey)==='Fixture Local Sans'&&false!==strpos($catalog->browser_css($localKey),'Fixture Local Sans'),'catalog reversibly maps a local-device key back to browser CSS');
f02($catalog->safe_css_stack('"Fixture Local Sans",Arial,sans-serif')!=='','email-safe local font stacks are accepted without remote font sources');
f02($catalog->safe_css_stack('url(https://example.invalid/x.woff2)')==='','remote font source syntax is rejected by the safe stack validator');
$bad=false; try{$catalog->normalize_key('Comic Sans MS');}catch(Throwable $e){$bad=true;} f02($bad,'catalog rejects arbitrary CSS/system family names');
$bad=false; try{$catalog->normalize_key('url(https://example.invalid/font.woff2)');}catch(Throwable $e){$bad=true;} f02($bad,'catalog rejects remote/font-injection values');
f02(strpos($catalog->browser_css('sans-serif'),'ui-sans-serif')!==false,'sans browser role is local/system only');
f02(strpos($catalog->browser_css('serif'),'ui-serif')!==false,'serif browser role is local/system only');
f02(strpos($catalog->browser_css('monospace'),'ui-monospace')!==false,'monospace browser role is local/system only');
foreach($defs as $key=>$def){ f02(false===stripos($def['css'],'http://')&&false===stripos($def['css'],'https://')&&false===stripos($def['css'],'url('),"$key has no remote CSS source"); }
$doc=array('elements'=>array(array('type'=>'text','text'=>'Certificate','style'=>array('font_family'=>'serif','font_weight'=>700,'italic'=>false))));
$previewManifest=(new BrowserPreviewFontManifest($catalog))->for_document($doc);
f02(isset($previewManifest['families']['serif']['700-normal']),'browser preview manifest binds selected controlled family/variant');
$localDoc=array('elements'=>array(array('type'=>'text','text'=>'Local preview','style'=>array('font_family'=>$localKey,'font_weight'=>400,'italic'=>false)))); $localPreview=(new BrowserPreviewFontManifest($catalog))->for_document($localDoc); f02(isset($localPreview['families'][$localKey]['400-normal']),'browser preview manifest accepts a valid local-device font key');
$badDoc=$doc; $badDoc['elements'][0]['style']['font_family']='unknown-family'; $bad=false; try{(new BrowserPreviewFontManifest($catalog))->for_document($badDoc);}catch(Throwable $e){$bad=true;} f02($bad,'browser preview manifest fails closed on non-catalog family');
$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $preview=file_get_contents($root.'/src/Infrastructure/Rendering/RenderPlanHtmlPreview.php');
f02(strpos($admin,"certloomTemplateFontCatalog")!==false,'Designer receives server-owned font catalog contract');
f02(strpos($admin,'wp_print_font_faces')!==false,'Designer prints WordPress/theme local font faces on the Studio route');
f02(strpos($admin,'$font_options_html')!==false&&strpos($admin,'data-certloom-font-select')!==false,'Designer renders the shared expanded font catalog instead of three hard-coded families');
f02(strpos($admin,'type="text" data-certloom-designer-style-field="font_family" readonly')===false,'free-text/read-only legacy font control is removed');
f02(strpos($js,'var FONT_CATALOG')!==false&&strpos($js,'function fontCss')!==false&&strpos($js,'function fontKeyAllowed')!==false,'Designer consumes the controlled catalog and local-device key registry rather than arbitrary CSS names');
f02(strpos($js,'probeLocalFontAvailability')!==false&&strpos($js,'FontFace')!==false,'Designer detects which supported font families are installed locally without remote requests');
f02(strpos($js,'queryLocalFonts')!==false&&strpos($js,'Scan local fonts')!==false&&strpos($js,'base32LocalFontName')!==false,'Designer can permission-scan and enumerate additional device-local font families without uploading font bytes');
f02(strpos($js,"if ( 'font_family' === key )")!==false,'font-family change travels canonical style mutation path');
f02(strpos($js,"proposed.style[ key ] = value")!==false,'font-family selection persists canonical stable key');
f02(strpos($preview,'DesignerFontCatalog')!==false&&strpos($preview,'browser_css')!==false,'canonical browser Preview maps family through the same controlled catalog');
$exact=new FontManifest();
$manifest=array('schema'=>FontManifest::SCHEMA,'version'=>FontManifest::VERSION,'families'=>array('sans-serif'=>array('400-normal'=>array('faces'=>array(array('sha256'=>str_repeat('a',64),'format'=>'ttf','coverage'=>array('U+0000-024F')))))));
$resolved=array('elements'=>array(array('type'=>'text','text'=>'Certificate','style'=>array('font_family'=>'serif','font_weight'=>400,'italic'=>false))));
$blocked=false; try{$exact->assert_document_supported($resolved,$manifest);}catch(Throwable $e){$blocked=true;} f02($blocked,'artifact FontManifest still fails closed when selected family exact bytes are not declared');
f02(strpos($admin,'No remote font URL is accepted')!==false&&strpos($admin,'Installed fonts are detected in the browser')!==false,'administrator copy states the local-only and exact-renderer font boundary');
f02(strpos($js,'https://')===false,'Designer font authoring adds no remote URL dependency');
echo "\n{$checks} FONT-02A checks, {$fail} failures\n"; exit($fail?1:0);
