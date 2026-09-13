<?php
/** DSN-07 Schema 4 rich inline text/token-run architecture and v1-v3 immutability gate. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_attr__($v){return esc_attr($v);} function esc_html__($v){return esc_html($v);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} 
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutSchemaUpgrader;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;

final class Dsn7NoAssets implements LocalAssetResolverInterface { public function resolve( $reference ){ throw new RuntimeException('No assets expected.'); } }
$pass=0;$fail=0; function dsn7($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}} function dsn7throws($fn,$label,$contains=''){try{$fn();dsn7(false,$label);}catch(Throwable $e){dsn7(''===$contains||false!==strpos($e->getMessage(),$contains),$label);}}
function dsn7rich($version=4){return array('_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>$version),'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),'elements'=>array(array('id'=>'77777777-7777-4777-8777-777777777777','type'=>'rich_text','x_mm'=>30,'y_mm'=>60,'width_mm'=>180,'height_mm'=>28,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'runs'=>array(array('kind'=>'text','text'=>'This certifies that '),array('kind'=>'token','token'=>'recipient.name','style'=>array('font_weight'=>700,'color'=>'#1450E0')),array('kind'=>'text','text'=>' completed the program.')),'style'=>array('font_family'=>'sans-serif','font_size_pt'=>18,'font_weight'=>400,'italic'=>false,'color'=>'#111111','align'=>'center','vertical_align'=>'middle','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink'))));}

$v=new LayoutValidator(); $u=new LayoutSchemaUpgrader($v); $c=new CanonicalJson();
dsn7(LayoutSchema::VERSION===4,'latest authoring schema is v4');
dsn7(LayoutSchema::supported_versions()===array(1,2,3,4),'read compatibility permanently retains schemas 1 through 4');
dsn7(LayoutSchema::element_types(1)===array('text','merge','image','logo','signature','shape','line','qr'),'schema 1 primitive inventory is frozen');
dsn7(LayoutSchema::element_types(2)===array('text','merge','image','logo','signature','shape','line','qr','barcode'),'schema 2 primitive inventory is frozen');
dsn7(LayoutSchema::element_types(3)===array('text','merge','image','logo','signature','shape','line','qr','barcode','list'),'schema 3 primitive inventory is frozen');
dsn7(LayoutSchema::element_types(4)===array('text','merge','rich_text','image','logo','signature','shape','line','qr','barcode','list','table','data_table'),'schema 4 retains rich_text and now adds the separately certified Table/Data Table architecture without changing schemas 1-3');

$v1=(new PresetCatalog())->get('classic-a4-landscape')['layout']; $v2=$u->upgrade($v1,2); $v3=$u->upgrade($v1,3); $v4=$u->upgrade($v1,4);
dsn7('163509ccdba3fe4ab754479dbb28ff3bb46029c2729d4c08699dd270eeb4ebf8'===$c->hash($v->normalize($v1)),'schema 1 historical canonical hash is unchanged');
dsn7('117460445b920b2fee63e018b3c0a0b55442a04395895d148fd0cc252090bc34'===$c->hash($v2),'schema 2 historical canonical hash is unchanged');
dsn7('8e9c71e7903cb427f19cdb71454db47ca03000c847d0ff32d261142070137596'===$c->hash($v3),'schema 3 historical canonical hash is unchanged');
dsn7(4===$v4['_meta']['schema_version'],'v3 upgrades deterministically to v4');
$v4as3=$v4; $v4as3['_meta']['schema_version']=3; dsn7($c->hash($v->normalize($v4as3))===$c->hash($v3),'v4 upgrade is additive and leaves historical element bytes/semantics unchanged');
dsn7($v4===$u->upgrade($v4,4),'v4 upgrade is idempotent');
dsn7throws(function()use($u,$v4){$u->upgrade($v4,3);},'schema downgrade remains rejected');

$rich=dsn7rich(4); $norm=$v->normalize($rich); dsn7('rich_text'===$norm['elements'][0]['type']&&3===count($norm['elements'][0]['runs']),'schema 4 rich text validates ordered structured runs');
dsn7('token'===$norm['elements'][0]['runs'][1]['kind']&&'recipient.name'===$norm['elements'][0]['runs'][1]['token'],'dynamic field stays a machine token in canonical authoring bytes');
dsn7(700===$norm['elements'][0]['runs'][1]['style']['font_weight']&&'#1450E0'===$norm['elements'][0]['runs'][1]['style']['color'],'sparse inline overrides normalize without duplicating the base style');
foreach(array(1,2,3) as $old){$bad=dsn7rich($old); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'rich_text is rejected before schema '.$old);}
$bad=$rich; $bad['elements'][0]['runs']=array(); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'rich text requires at least one run');
$bad=$rich; $bad['elements'][0]['runs']=array_fill(0,257,array('kind'=>'text','text'=>'x')); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'rich text run count is bounded at 256');
$bad=$rich; $bad['elements'][0]['runs']=array(array('kind'=>'html','text'=>'<b>x</b>')); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'HTML run kinds are not part of the canonical contract');
$bad=$rich; $bad['elements'][0]['runs']=array(array('kind'=>'text','text'=>'x','onclick'=>'alert(1)')); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'unknown/executable run properties are rejected');
$literal=$rich; $literal['elements'][0]['runs']=array(array('kind'=>'text','text'=>'<b>literal text only</b>')); $literalNorm=$v->normalize($literal); dsn7('<b>literal text only</b>'===$literalNorm['elements'][0]['runs'][0]['text'],'markup-looking text remains literal text rather than executable HTML');
$bad=$rich; $bad['elements'][0]['runs']=array(array('kind'=>'text','text'=>'')); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'empty literal runs are rejected instead of storing no-op structure');
$bad=$rich; $bad['elements'][0]['runs']=array(array('kind'=>'token','token'=>'9bad')); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'inline token syntax uses the existing bounded machine-key contract');
$bad=$rich; $bad['elements'][0]['runs']=array(array('kind'=>'text','text'=>str_repeat('x',20001))); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'rich source text stays inside the 20k canonical budget');
$bad=$rich; $bad['elements'][0]['runs'][0]['style']=array('font_size_pt'=>0); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'inline font size is bounded');
$bad=$rich; $bad['elements'][0]['runs'][0]['style']=array('font_weight'=>650); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'inline font weight is bounded to canonical 100-point steps');
$bad=$rich; $bad['elements'][0]['runs'][0]['style']=array('position'=>'absolute'); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'arbitrary CSS properties cannot enter inline run styles');
$bad=$rich; $bad['elements'][0]['runs'][0]['style']=array('letter_spacing_pt'=>51); dsn7throws(function()use($v,$bad){$v->normalize($bad);},'inline letter spacing uses canonical bounds');

$resolver=new LayoutResolver(); $resolved=$resolver->resolve($rich,array('recipient.name'=>'Alexandria Noor Rahman'),'https://example.test/verify/CERT-001',new Dsn7NoAssets()); $rr=$resolved['elements'][0]['runs'];
dsn7('rich_text'===$resolved['elements'][0]['type'],'resolver preserves rich_text structure instead of flattening it prematurely');
dsn7(array('text','text','text')===array_column($rr,'kind'),'resolver removes unresolved token run kinds before any renderer boundary');
dsn7('Alexandria Noor Rahman'===$rr[1]['text']&&!isset($rr[1]['token']),'immutable snapshot value replaces inline token centrally');
dsn7(700===$rr[1]['style']['font_weight'],'resolver preserves sparse inline style on resolved token text');
dsn7('ltr'===$resolved['elements'][0]['style']['direction'],'base text direction resolves from the complete resolved rich text');
dsn7throws(function()use($resolver,$rich){$resolver->resolve($rich,array(),'https://example.test/verify/CERT-001',new Dsn7NoAssets());},'missing inline merge value fails closed','Missing immutable rich-text merge value');
dsn7throws(function()use($resolver,$rich){$resolver->resolve($rich,array('recipient.name'=>array('bad')),'https://example.test/verify/CERT-001',new Dsn7NoAssets());},'non-scalar inline merge value fails closed');
$richPlan=(new RenderPlanBuilder())->build($resolved,array('font_manifest'=>(new BrowserPreviewFontManifest())->for_document($resolved)));dsn7(isset($richPlan['operations'][0]['rich_text'])&&3===count($richPlan['operations'][0]['rich_text']['runs']),'Schema 4 Render Plan freezes resolved rich-text runs for shared adapter consumption');
$richPreview=(new RenderPlanHtmlPreview())->present($richPlan);dsn7(false!==strpos($richPreview['html'],'certloom-template-preview-op-rich_text')&&false!==strpos($richPreview['html'],'Alexandria Noor Rahman'),'browser Render-Plan preview consumes resolved rich-text runs without reading canonical token structure');

$legacy=$v4; $legacy['elements']=array(array('id'=>'88888888-8888-4888-8888-888888888888','type'=>'merge','x_mm'=>30,'y_mm'=>50,'width_mm'=>100,'height_mm'=>20,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'token'=>'recipient.name','style'=>array('font_family'=>'sans-serif','font_size_pt'=>18,'font_weight'=>400,'italic'=>false,'color'=>'#000000','align'=>'center','vertical_align'=>'middle','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink'))); $legacyNorm=$v->normalize($legacy); dsn7('merge'===$legacyNorm['elements'][0]['type'],'legacy standalone merge boxes remain readable in schema 4'); $legacyResolved=$resolver->resolve($legacy,array('recipient.name'=>'Legacy Recipient'),'https://example.test/verify/CERT-001',new Dsn7NoAssets()); dsn7('text'===$legacyResolved['elements'][0]['type']&&'Legacy Recipient'===$legacyResolved['elements'][0]['text'],'legacy merge resolution remains unchanged under schema 4');

$palette=ElementRegistry::palette(); $mergePalette=null;$richPalette=null; foreach($palette as $item){if('merge'===$item['kind']){$mergePalette=$item;}if('rich_text'===$item['kind']){$richPalette=$item;}}
dsn7(3===(int)$mergePalette['max_schema_version'],'normal standalone merge insertion retires after schema 3 without deleting legacy read support');
dsn7(4===(int)$richPalette['min_schema_version'],'rich text insertion begins explicitly at schema 4');
$contract=ElementRegistry::browser_contract(); dsn7('rich_text'===$contract['types']['rich_text']['content_group']&&'text'===$contract['types']['rich_text']['style_group'],'browser registry exposes rich content editor plus existing text-style authority');
dsn7('rich_text'===$contract['types']['rich_text']['render_operation'],'registry maps rich text to the shared Schema 4 render operation');

$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $plan=file_get_contents($root.'/src/Application/Rendering/RenderPlanBuilder.php');
dsn7(false!==strpos($admin,'$maximum = absint')&&false!==strpos($admin,'$schema_version > $maximum'),'admin palette hides retired merge authoring on schema 4');
dsn7(false!==strpos($admin,'data-certloom-designer-rich-runs')&&false!==strpos($admin,'without HTML')&&false===strpos($admin,'data-certloom-designer-context-rich-text'),'Studio Inspector exposes structured inline-run editing without restoring the retired horizontal rich-text toolbar or raw HTML editor');
dsn7(false!==strpos($js,'insertRichTokenAtCaret')&&false!==strpos($js,"textContent = richRunText"),'browser authoring supports caret token insertion and textContent-only preview');
dsn7(false===strpos($js,'content.innerHTML = richTextPlain')&&false===strpos($js,'runSpan.innerHTML'),'rich preview never injects run content through innerHTML');
dsn7(false!==strpos($plan,"case 'rich_text'")&&false!==strpos($plan,'rich_text_operation'),'Render Plan source implements the shared rich-text operation without adapter-specific layout authority');
dsn7(!file_exists($root.'/resources/renderer/accepted.json')&&!file_exists($root.'/resources/domain/accepted.json'),'Schema 4 architecture manufactures no production authority manifests');

echo "\n$pass DSN-07 checks, $fail failures\n"; exit($fail?1:0);
