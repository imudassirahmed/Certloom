<?php
/** DSN-09 Table/Data Table canonical architecture, immutable rowset and render-plan boundary. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);} function get_bloginfo($k=''){return 'Certloom Test Site';} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_attr__($v){return esc_attr($v);} function esc_html__($v){return esc_html($v);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Issuance\MergeSnapshotBuilder;
use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Domain\Rendering\TableDataContract;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;

final class Dsn9NoAssets implements LocalAssetResolverInterface { public function resolve($reference){throw new RuntimeException('No assets expected: '.(string)$reference);} }
$pass=0;$fail=0;function dsn9($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}function dsn9throws($fn,$label,$contains=''){try{$fn();dsn9(false,$label);}catch(Throwable $e){dsn9(''===$contains||false!==strpos($e->getMessage(),$contains),$label);}}
function dsn9base($element){return array('_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>4),'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),'elements'=>array($element));}
function dsn9cols($dynamic=false){$cols=array(array('key'=>'subject','label'=>'Subject','width_pct'=>50,'align'=>'left'),array('key'=>'score','label'=>'Score','width_pct'=>25,'align'=>'center'),array('key'=>'grade','label'=>'Grade','width_pct'=>25,'align'=>'center'));if($dynamic){$cols[0]['field']='subject';$cols[1]['field']='score';$cols[2]['field']='grade';}return $cols;}
function dsn9table(){return dsn9base(array('id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','type'=>'table','x_mm'=>20,'y_mm'=>30,'width_mm'=>160,'height_mm'=>48,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'columns'=>dsn9cols(false),'rows'=>array(array('subject'=>'Mathematics','score'=>'98','grade'=>'A+'),array('subject'=>'Physics','score'=>'92','grade'=>'A'),array('subject'=>'Computer Science','score'=>'96','grade'=>'A+')),'show_header'=>true,'style'=>array('font_family'=>'sans-serif','font_size_pt'=>10,'font_weight'=>400,'italic'=>false,'color'=>'#111827','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink','header_fill'=>'#F3F4F6','header_color'=>'#111827','header_font_weight'=>600,'row_fill'=>'#FFFFFF','alternate_row_fill'=>'#F9FAFB','border_color'=>'#D1D5DB','border_width_mm'=>0.2,'cell_padding_mm'=>1.5)));}
function dsn9data(){return dsn9base(array('id'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','type'=>'data_table','x_mm'=>20,'y_mm'=>90,'width_mm'=>160,'height_mm'=>48,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'source'=>'credential.results','columns'=>dsn9cols(true),'max_rows'=>3,'show_header'=>true,'style'=>array('font_family'=>'sans-serif','font_size_pt'=>10,'font_weight'=>400,'italic'=>false,'color'=>'#111827','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink','header_fill'=>'#F3F4F6','header_color'=>'#111827','header_font_weight'=>600,'row_fill'=>'#FFFFFF','alternate_row_fill'=>'#F9FAFB','border_color'=>'#D1D5DB','border_width_mm'=>0.2,'cell_padding_mm'=>1.5)));}

$v=new LayoutValidator();$c=new CanonicalJson();
$types4=LayoutSchema::element_types(4);dsn9(in_array('table',$types4,true)&&in_array('data_table',$types4,true),'schema 4 registry exposes fixed Table and Data Table only after their canonical contract exists');
dsn9(LayoutSchema::element_types(1)===array('text','merge','image','logo','signature','shape','line','qr'),'schema 1 primitive inventory remains frozen');
dsn9(LayoutSchema::element_types(2)===array('text','merge','image','logo','signature','shape','line','qr','barcode'),'schema 2 primitive inventory remains frozen');
dsn9(LayoutSchema::element_types(3)===array('text','merge','image','logo','signature','shape','line','qr','barcode','list'),'schema 3 primitive inventory remains frozen');
$contract=ElementRegistry::browser_contract();dsn9('table'===$contract['types']['table']['render_operation']&&'table'===$contract['types']['data_table']['render_operation'],'both canonical table types share one renderer-plan operation rather than parallel renderer authorities');

$static=dsn9table();$norm=$v->normalize($static);dsn9('table'===$norm['elements'][0]['type']&&3===count($norm['elements'][0]['rows']),'fixed Table normalizes bounded literal rows');
dsn9(100.0===array_sum(array_column($norm['elements'][0]['columns'],'width_pct')),'Table columns preserve a deterministic 100-percent width contract');
dsn9($norm===$v->normalize($norm),'Table normalization is idempotent on canonical bytes');
foreach(array(1,2,3) as $old){$bad=$static;$bad['_meta']['schema_version']=$old;dsn9throws(function()use($v,$bad){$v->normalize($bad);},'schema '.$old.' rejects Table before the v4 boundary','unsupported type');}
$bad=$static;$bad['elements'][0]['columns'][0]['width_pct']=49;dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Table columns must total exactly 100 percent','total exactly 100');
$bad=$static;$bad['elements'][0]['columns'][1]['key']='subject';dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Table column keys are unique','unique machine keys');
$bad=$static;$bad['elements'][0]['rows'][0]['onclick']='x';dsn9throws(function()use($v,$bad){$v->normalize($bad);},'fixed Table rows reject undeclared/arbitrary cell keys','declared column keys');
$bad=$static;$bad['elements'][0]['rows'][0]['subject']=str_repeat('x',1001);dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Table cell text has a 1,000-byte bound','1,000-byte');
$bad=$static;$bad['elements'][0]['height_mm']=12;dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Table geometry enforces a 4 mm minimum visible-row height','at least 4 mm');

$data=dsn9data();$dn=$v->normalize($data);dsn9('credential.results'===$dn['elements'][0]['source']&&3===$dn['elements'][0]['max_rows'],'Data Table stores only a bounded immutable rowset source and row limit');
dsn9($dn===$v->normalize($dn),'Data Table normalization is idempotent');
$bad=$data;$bad['elements'][0]['source']='recipient.orders';dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Data Table rejects arbitrary/live collection sources','Unsupported immutable Data Table source');
$bad=$data;$bad['elements'][0]['columns'][1]['field']='html';dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Data Table columns bind only to approved row fields','not approved');
$bad=$data;$bad['elements'][0]['max_rows']=51;dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Data Table row count is capped at 50','between 1 and 50');
$bad=$data;$bad['elements'][0]['max_rows']=12;dsn9throws(function()use($v,$bad){$v->normalize($bad);},'Data Table worst-case row density must fit its certificate box','at least 4 mm');

$rows=array(array('subject'=>'Mathematics','score'=>98,'grade'=>'A+'),array('subject'=>'Physics','score'=>'92','grade'=>'A'));
$r=TableDataContract::normalize_rows(TableDataContract::RESULTS,$rows);dsn9('98'===$r[0]['score']&&array_keys($r[0])===TableDataContract::fields_for_source(TableDataContract::RESULTS),'immutable result rows normalize scalars into a stable complete field shape');
dsn9(''===$r[0]['code']&&''===$r[0]['status'],'missing approved result fields normalize to empty strings rather than changing row shape');
dsn9throws(function(){TableDataContract::normalize_rows(TableDataContract::RESULTS,array(array('subject'=>'Math','payload'=>array('x'))));},'repeating rowsets reject unknown/nested payload fields','approved scalar fields');
dsn9throws(function(){TableDataContract::normalize_rows(TableDataContract::SUBJECTS,array_fill(0,51,array('subject'=>'x')));},'repeating rowsets are globally capped at 50 rows','at most 50');

$builder=new MergeSnapshotBuilder();$recipient=array('display_name'=>'Sara Ahmed Khan','first_name'=>'Sara','last_name'=>'Khan','email'=>'sara@example.invalid');$program=array('title'=>'Higher Secondary School Certificate','code'=>'HSSC');$issuer=array('name'=>'Northbridge School and College','settings'=>array());$credential=array('display_number'=>'CERT-009','public_id'=>'PUBLIC-009','valid_from'=>'2026-09-10','expires_at'=>null,'verification_url'=>'https://example.invalid/verify/PUBLIC-009','results'=>$r);
$required=$builder->required_tokens($data);dsn9(in_array('credential.results',$required,true),'Data Table source becomes an explicit immutable issuance requirement');
$merge=$builder->build($recipient,$program,$issuer,$credential,$data,array(),null);dsn9(!is_wp_error($merge)&&isset($merge['credential.results'])&&2===count($merge['credential.results']),'MergeSnapshotBuilder freezes supported repeating results into immutable merge evidence');
$missing=$credential;unset($missing['results']);$missingMerge=$builder->build($recipient,$program,$issuer,$missing,$data,array(),null);dsn9(is_wp_error($missingMerge)&&'certloom_manual_issue_merge_missing'===$missingMerge->get_error_code(),'Data Table issuance fails closed when its required rowset was not supplied');

$resolver=new LayoutResolver();$resolved=$resolver->resolve($data,$merge,'https://example.invalid/verify/PUBLIC-009',new Dsn9NoAssets());$el=$resolved['elements'][0];dsn9('table'===$el['type']&&!isset($el['source'])&&!isset($el['max_rows'])&&3===$el['row_slots'],'Data Table resolves centrally to a concrete Table with reserved row geometry before renderer execution');
dsn9('Mathematics'===$el['rows'][0]['subject']&&'98'===$el['rows'][0]['score'],'resolved Data Table maps immutable row fields into declared canonical columns');
dsn9(!isset($el['columns'][0]['field']),'source-field bindings are consumed before the renderer-plan boundary');
$many=$merge;$many['credential.results']=array_merge($r,array(array('subject'=>'Computer Science','score'=>'96','grade'=>'A+'),array('subject'=>'English','score'=>'91','grade'=>'A')));$resolvedLimited=$resolver->resolve($data,$many,'https://example.invalid/verify/PUBLIC-009',new Dsn9NoAssets());dsn9(3===count($resolvedLimited['elements'][0]['rows']),'Data Table resolver enforces the canonical max_rows slice');
$badSnapshot=$merge;$badSnapshot['credential.results']=array(array('subject'=>'Math','evil'=>'<script>'));dsn9throws(function()use($resolver,$data,$badSnapshot){$resolver->resolve($data,$badSnapshot,'https://example.invalid/verify/PUBLIC-009',new Dsn9NoAssets());},'LayoutResolver revalidates immutable rowsets defensively instead of trusting stored arrays','approved scalar fields');

$fonts=(new BrowserPreviewFontManifest())->for_document($resolved);$plan=(new RenderPlanBuilder())->build($resolved,array('font_manifest'=>$fonts));$op=$plan['operations'][0];dsn9(isset($op['table'])&&3===count($op['table']['columns'])&&3===count($op['table']['rows']),'Render Plan owns one deterministic Table operation with exact columns/rows');
dsn9(12.0===$op['table']['row_height_mm'],'Render Plan centrally derives exact row height from canonical geometry');
dsn9(80.0===$op['table']['columns'][0]['width_mm']&&40.0===$op['table']['columns'][1]['width_mm'],'Render Plan centrally derives exact column geometry from canonical percentages');
dsn9(1.5===$op['table']['cell_padding_mm']&&0.2===$op['table']['grid']['border_width_mm'],'Render Plan freezes cell padding and grid width rather than leaving adapter interpretation open');
dsn9('Subject'===$op['table']['rows'][0]['cells'][0]['text']['value']&&600===$op['table']['rows'][0]['cells'][0]['text']['font']['weight'],'header cell text/font facts are fully resolved in the renderer-independent plan');
dsn9('Mathematics'===$op['table']['rows'][1]['cells'][0]['text']['value'],'body cell text is fully resolved before adapters');
// exact first-column box is 80mm; content box subtracts 3mm total padding.
dsn9(80.0===$op['table']['rows'][0]['cells'][0]['box']['width_mm']&&77.0===$op['table']['rows'][0]['cells'][0]['content_box']['width_mm'],'Render Plan fixes cell and padded content boxes exactly');
$tablePreview=(new RenderPlanHtmlPreview())->present($plan);dsn9(false!==strpos($tablePreview['html'],'certloom-template-preview-op-table')&&false!==strpos($tablePreview['html'],'Mathematics'),'browser Render-Plan preview consumes deterministic Table cells rather than re-resolving row data');

$samples=new TemplatePreviewSamples();$sample=$samples->snapshot_for_layout($data,TemplatePreviewSamples::SCHOOL_RESULT);dsn9(isset($sample['credential.results'])&&4===count($sample['credential.results']),'school-result preview profile supplies deterministic synthetic repeating results without reading real credentials');
$root=dirname(__DIR__);dsn9(!file_exists($root.'/resources/renderer/accepted.json')&&!file_exists($root.'/resources/domain/accepted.json'),'Table architecture manufactures no renderer/domain production authority manifests');

// Candidate adapter implementation may advance before independent production
// acceptance. The accepted authority manifests must remain absent until that
// separate evidence ceremony succeeds.
$gd=file_get_contents($root.'/src/Infrastructure/Rendering/GdRasterRenderer.php');$pdf=file_get_contents($root.'/src/Infrastructure/Rendering/MpdfPdfRenderer.php');$browser=file_get_contents($root.'/src/Infrastructure/Rendering/RenderPlanHtmlPreview.php');dsn9(false!==strpos($gd,"case 'table'")&&false!==strpos($pdf,"case 'table'")&&false!==strpos($browser,"case 'table'"),'PHP browser/PDF/raster candidates consume the shared Table Render Plan operation while authority remains separate');

echo "\n$pass DSN-09 Table/Data Table architecture checks, $fail failures\n";exit($fail?1:0);
