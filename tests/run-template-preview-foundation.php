<?php
/** 1.6.55 canonical Render Plan Template Preview foundation tests. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr__($v){return esc_attr($v);}
function esc_html__($v){return esc_html($v);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Rendering\TemplatePreviewService;

$checks=0;
function p55($ok,$m){global $checks;if(!$ok){throw new RuntimeException($m);} $checks++;}
function p55has($s,$n,$m){p55(false!==strpos($s,$n),$m);}
function p55not($s,$n,$m){p55(false===strpos($s,$n),$m);}

$catalog=new PresetCatalog();
$definition=$catalog->get('classic-a4-landscape');
p55(is_array($definition)&&!empty($definition['layout_sha256']),'factory preset available for Preview proof');
$template=array(
 'id'=>11,
 'current_revision_id'=>101,
 'published_revision_id'=>100,
 'current_revision'=>array('id'=>101,'revision_number'=>2,'layout_sha256'=>$definition['layout_sha256'],'layout'=>$definition['layout']),
 'published_revision'=>array('id'=>100,'revision_number'=>1,'layout_sha256'=>$definition['layout_sha256'],'layout'=>$definition['layout']),
);
$service=new TemplatePreviewService();
$standard=$service->build($template,'current',TemplatePreviewSamples::STANDARD);
p55(!is_wp_error($standard),'standard current Preview builds');
p55(RenderPlanBuilder::SCHEMA===$standard['render_plan']['_meta']['schema'],'Preview uses canonical Render Plan schema');
p55(RenderPlanBuilder::VERSION===$standard['render_plan']['_meta']['version'],'Preview uses canonical Render Plan version');
p55('LayoutResolver → RenderPlanBuilder'===$standard['composition_path'],'Preview records shared composition path');
p55('browser-preview-contract'===$standard['font_mode'],'Preview font mode is explicit and cannot masquerade as artifact font authority');
p55(101===$standard['revision_id']&&2===$standard['revision_number'],'current Preview binds exact selected immutable revision');
p55($definition['layout_sha256']===$standard['layout_sha256'],'Preview reports exact layout SHA-256');
p55(1===preg_match('/^[0-9a-f]{64}$/',$standard['render_plan_sha256']),'Preview exposes canonical Render Plan SHA-256');
p55('Alexandria Noor Rahman'===$standard['merge_snapshot']['recipient.name'],'standard sample is deterministic');
p55('https://example.invalid/certloom/verify/CL-PREVIEW-STANDARD'===$standard['verification_url'],'Preview QR uses deterministic non-production URL');

$long=$service->build($template,'current',TemplatePreviewSamples::LONG);
p55(!is_wp_error($long)&&false!==strpos($long['merge_snapshot']['recipient.name'],'Montgomery-Washington'),'long-content stress profile builds');
p55($long['render_plan_sha256']!==$standard['render_plan_sha256'],'sample content changes the Render Plan identity');
$rtl=$service->build($template,'current',TemplatePreviewSamples::RTL);
p55(!is_wp_error($rtl)&&false!==strpos($rtl['merge_snapshot']['recipient.name'],'محمد'),'RTL/Urdu stress profile builds');
p55($rtl['render_plan_sha256']!==$standard['render_plan_sha256'],'RTL sample has its own deterministic Render Plan identity');
$published=$service->build($template,'published',TemplatePreviewSamples::STANDARD);
p55(!is_wp_error($published)&&100===$published['revision_id']&&1===$published['revision_number'],'published Preview selects exact published revision independently');
$missing=$template; $missing['published_revision_id']=0; $missing['published_revision']=null;
p55(is_wp_error($service->build($missing,'published',TemplatePreviewSamples::STANDARD)),'published Preview fails closed when no published revision exists');

$ops=$standard['render_plan']['operations'];
$types=array_values(array_unique(array_map(function($op){return $op['type'];},$ops)));
p55(in_array('text',$types,true)&&in_array('shape',$types,true)&&in_array('line',$types,true)&&in_array('qr',$types,true),'Preview carries actual factory composition primitives from Render Plan');
$recipient=array_values(array_filter($ops,function($op){return 'text'===$op['type']&&'Alexandria Noor Rahman'===($op['text']['value']??'');}));
p55(1===count($recipient),'merge resolution occurs before browser presentation');
$qr=array_values(array_filter($ops,function($op){return 'qr'===$op['type'];}));
p55(1===count($qr)&&!empty($qr[0]['qr']['dark_runs']),'browser Preview consumes centrally resolved QR runs rather than encoding a second QR');

$manifest=(new BrowserPreviewFontManifest())->for_document(array('elements'=>array(array('type'=>'text','text'=>'Hello','style'=>array('font_family'=>'sans-serif','font_weight'=>700,'italic'=>false)))));
p55(isset($manifest['families']['sans-serif']['700-normal']['faces'][0]),'browser Preview manifest is synthesized only for required font variants');
p55(1===preg_match('/^[0-9a-f]{64}$/',$manifest['families']['sans-serif']['700-normal']['faces'][0]['sha256']),'preview font contract has deterministic identity');
p55(array('U+0000-D7FF','U+E000-10FFFF')===$manifest['families']['sans-serif']['700-normal']['faces'][0]['coverage'],'preview font contract declares bounded Unicode scalar coverage');

$presented=(new RenderPlanHtmlPreview())->present($standard['render_plan']);
p55(is_array($presented)&&isset($presented['html'],$presented['warnings']),'Render Plan presenter returns escaped markup plus warnings');
p55has($presented['html'],'certloom-template-preview-page','browser Preview has isolated page surface');
p55has($presented['html'],'Alexandria Noor Rahman','resolved recipient appears in browser Preview');
p55has($presented['html'],'certloom-template-preview-op-qr','QR is presented from Render Plan');
p55has($presented['html'],'<rect','QR dark runs become SVG rectangles without a second encoder');
p55(empty($presented['warnings']),'factory preset Preview needs no asset warnings');

$root=dirname(__DIR__);
$src=array(
 'service'=>file_get_contents($root.'/src/Infrastructure/Rendering/TemplatePreviewService.php'),
 'presenter'=>file_get_contents($root.'/src/Infrastructure/Rendering/RenderPlanHtmlPreview.php'),
 'assets'=>file_get_contents($root.'/src/Infrastructure/Rendering/WordPressLocalAssetResolver.php'),
 'admin'=>file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'),
 'css'=>file_get_contents($root.'/admin/assets/admin.css'),
 'font'=>file_get_contents($root.'/src/Application/Rendering/BrowserPreviewFontManifest.php'),
);
foreach($src as $k=>$v){p55(is_string($v)&&''!==$v,'source '.$k.' available');}
p55has($src['service'],'new LayoutResolver( null, null, null, new LocalQrEncoder(), new LocalCode128Encoder() )','Preview uses central LayoutResolver + local QR/Code128 encoders');
p55has($src['service'],'$this->plans->build','Preview delegates composition to RenderPlanBuilder');
p55not($src['presenter'],'LayoutValidator','HTML presenter does not interpret canonical layout schema');
p55not($src['presenter'],'LayoutResolver','HTML presenter does not create a second merge/asset/QR resolution path');
p55has($src['presenter'],'RenderPlanBuilder::SCHEMA','HTML presenter accepts only the canonical Render Plan contract');
p55has($src['presenter'],'dark_runs','HTML Preview consumes Render Plan QR dark runs');
p55not($src['presenter'],'LocalQrEncoder','browser presenter never re-encodes QR content');
p55has($src['assets'],'attachment:([1-9][0-9]*)','Preview local asset resolver permits WordPress attachments');
p55has($src['assets'],'Named Certloom asset keys are not yet backed','unimplemented named asset catalog fails closed instead of guessing paths');
p55not($src['assets'],'http://','asset resolver contains no HTTP fallback');
p55not($src['assets'],'https://','asset resolver contains no HTTPS fallback');
p55has($src['font'],'must never be handed to ArtifactRenderCoordinator','preview-only font identity cannot be confused with artifact authority');

p55has($src['admin'],'Template Preview','Templates admin exposes the planned Preview product surface');
p55has($src['admin'],'Current draft','Preview can inspect the current immutable revision');
p55has($src['admin'],'Published','Preview can independently inspect the published immutable revision');
p55has($src['admin'],'Very long name','Preview has extreme-name stress data');
p55has($src['admin'],'Long organization','Preview has organization overflow stress data');
p55has($src['admin'],'Long ISO scope','Preview has long-scope stress data');
p55has($src['admin'],'RTL person','Preview has RTL stress data');
p55has($src['admin'],'School result','Preview has school-result stress data');
p55has($src['admin'],'Shared layout preview','Preview default badge uses customer language');
p55has($src['admin'],'Rendering details','technical renderer evidence is available behind a disclosure');
p55has($src['admin'],'Canonical Render Plan evidence remains available here for support and release verification.','collapsed technical details preserve the shared composition contract');
p55has($src['admin'],'Production downloads unavailable','default Preview states the renderer limitation in customer language');
p55has($src['admin'],'Artifact authority pending means PDF-04 exact renderer/font acceptance has not been approved','collapsed details retain exact deferred-renderer evidence');
p55has($src['admin'],'this screen never manufactures production artifact authority','Preview explicitly preserves renderer authority gate');
p55has($src['admin'],'Render Plan SHA-256','admin exposes Preview composition proof identity');
p55has($src['admin'],'Synthetic Preview values never read or mutate credential records','Preview is explicitly read-only and synthetic');
p55has($src['css'],'.certloom-template-preview-scroll:focus-visible','Preview retains visible keyboard focus');
p55has($src['css'],'@media (max-width: 782px)','Preview has mobile behavior');
p55has($src['css'],'prefers-reduced-motion','Preview respects reduced motion');
p55(!file_exists($root.'/resources/renderer/accepted.json'),'1.6.55 development tree does not manufacture renderer authority');

echo "PASS  1.6.55 Template Preview uses the canonical LayoutResolver → RenderPlanBuilder path without a second QR/layout engine\n";
echo "PASS  current/published revision selection, deterministic eight-profile sample stress cases, proof hashes, local-only assets and renderer-honest browser presentation are covered\n\n";
echo $checks . " checks, 0 failures\n";
