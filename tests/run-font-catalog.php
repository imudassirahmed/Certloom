<?php
/** FONT-02A controlled Designer font-family catalog contract. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html__($v,$d=null){return $v;} function esc_attr__($v,$d=null){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\DesignerFontCatalog;
use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\FontManifest;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
$root=dirname(__DIR__); $checks=0; $fail=0;
function f02($ok,$label){global $checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
$catalog=new DesignerFontCatalog(); $defs=$catalog->definitions();
f02(array_keys($defs)===array('sans-serif','serif','monospace'),'catalog exposes exactly three stable authoring keys');
f02($catalog->normalize_key(' Serif ')==='serif','catalog normalizes bounded family keys');
$bad=false; try{$catalog->normalize_key('Comic Sans MS');}catch(Throwable $e){$bad=true;} f02($bad,'catalog rejects arbitrary CSS/system family names');
$bad=false; try{$catalog->normalize_key('url(https://example.invalid/font.woff2)');}catch(Throwable $e){$bad=true;} f02($bad,'catalog rejects remote/font-injection values');
f02(strpos($catalog->browser_css('sans-serif'),'ui-sans-serif')!==false,'sans browser role is local/system only');
f02(strpos($catalog->browser_css('serif'),'ui-serif')!==false,'serif browser role is local/system only');
f02(strpos($catalog->browser_css('monospace'),'ui-monospace')!==false,'monospace browser role is local/system only');
foreach($defs as $key=>$def){ f02(false===stripos($def['css'],'http://')&&false===stripos($def['css'],'https://')&&false===stripos($def['css'],'url('),"$key has no remote CSS source"); }
$doc=array('elements'=>array(array('type'=>'text','text'=>'Certificate','style'=>array('font_family'=>'serif','font_weight'=>700,'italic'=>false))));
$previewManifest=(new BrowserPreviewFontManifest($catalog))->for_document($doc);
f02(isset($previewManifest['families']['serif']['700-normal']),'browser preview manifest binds selected controlled family/variant');
$badDoc=$doc; $badDoc['elements'][0]['style']['font_family']='unknown-family'; $bad=false; try{(new BrowserPreviewFontManifest($catalog))->for_document($badDoc);}catch(Throwable $e){$bad=true;} f02($bad,'browser preview manifest fails closed on non-catalog family');
$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $preview=file_get_contents($root.'/src/Infrastructure/Rendering/RenderPlanHtmlPreview.php');
f02(strpos($admin,"certloomTemplateFontCatalog")!==false,'Designer receives server-owned font catalog contract');
f02(strpos($admin,'<option value="sans-serif">')!==false&&strpos($admin,'<option value="serif">')!==false&&strpos($admin,'<option value="monospace">')!==false,'Designer renders bounded font picker');
f02(strpos($admin,'type="text" data-certloom-designer-style-field="font_family" readonly')===false,'free-text/read-only legacy font control is removed');
f02(strpos($js,'var FONT_CATALOG')!==false&&strpos($js,'function fontCss')!==false&&strpos($js,'function fontKeyAllowed')!==false,'Designer consumes frozen catalog rather than arbitrary CSS names');
f02(strpos($js,"if ( 'font_family' === key )")!==false,'font-family change travels canonical style mutation path');
f02(strpos($js,"proposed.style[ key ] = value")!==false,'font-family selection persists canonical stable key');
f02(strpos($preview,'DesignerFontCatalog')!==false&&strpos($preview,'browser_css')!==false,'canonical browser Preview maps family through the same controlled catalog');
$exact=new FontManifest();
$manifest=array('schema'=>FontManifest::SCHEMA,'version'=>FontManifest::VERSION,'families'=>array('sans-serif'=>array('400-normal'=>array('faces'=>array(array('sha256'=>str_repeat('a',64),'format'=>'ttf','coverage'=>array('U+0000-024F')))))));
$resolved=array('elements'=>array(array('type'=>'text','text'=>'Certificate','style'=>array('font_family'=>'serif','font_weight'=>400,'italic'=>false))));
$blocked=false; try{$exact->assert_document_supported($resolved,$manifest);}catch(Throwable $e){$blocked=true;} f02($blocked,'artifact FontManifest still fails closed when selected family exact bytes are not declared');
f02(strpos($admin,'No remote font URL or arbitrary CSS family is accepted')!==false,'administrator copy states the remote/arbitrary-font boundary');
f02(strpos($js,'https://')===false,'Designer font authoring adds no remote URL dependency');
echo "\n{$checks} FONT-02A checks, {$fail} failures\n"; exit($fail?1:0);
