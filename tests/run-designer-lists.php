<?php
/** DSN-06 canonical list/bullet element and v3 schema compatibility gate. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_attr__($v){return esc_attr($v);} function esc_html__($v){return esc_html($v);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} 
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutSchemaUpgrader;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Domain\Rendering\LayoutSchema;

final class Dsn6NoAssets implements LocalAssetResolverInterface { public function resolve( $reference ){ throw new RuntimeException('No assets expected.'); } }
$pass=0;$fail=0; function dsn6($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}} function dsn6throws($fn,$label){try{$fn();dsn6(false,$label);}catch(Throwable $e){dsn6(true,$label);}}
function dsn6manifest(){ return array('schema'=>'certloom-font-manifest','version'=>2,'families'=>array('sans-serif'=>array('400-normal'=>array('faces'=>array(array('sha256'=>str_repeat('a',64),'format'=>'ttf','coverage'=>array('U+0000-024F','U+2000-206F'))))))); }

$validator=new LayoutValidator(); $upgrader=new LayoutSchemaUpgrader($validator); $canonical=new CanonicalJson();
dsn6(LayoutSchema::VERSION===3,'latest canonical authoring schema is v3');
dsn6(LayoutSchema::supported_versions()===array(1,2,3),'v1 and v2 remain permanently readable beside v3');
dsn6(LayoutSchema::element_types(2)===array('text','merge','image','logo','signature','shape','line','qr','barcode'),'v2 primitive inventory remains frozen');
dsn6(LayoutSchema::element_types(3)===array('text','merge','image','logo','signature','shape','line','qr','barcode','list'),'v3 adds list without mutating v2 semantics');
$v1=(new PresetCatalog())->get('classic-a4-landscape')['layout']; $v2=$upgrader->upgrade($v1,2); $v3=$upgrader->upgrade($v2,3); $v3direct=$upgrader->upgrade($v1,3);
dsn6(3===$v3['_meta']['schema_version']&&3===$v3direct['_meta']['schema_version'],'v1/v2 can deterministically upgrade to v3');
dsn6($canonical->hash($v3)===$canonical->hash($v3direct),'direct v1→v3 equals stepwise v1→v2→v3');
dsn6($v3===$upgrader->upgrade($v3,3),'v3 upgrading is idempotent');
dsn6throws(function()use($upgrader,$v3){$upgrader->upgrade($v3,2);},'v3 downgrade is rejected');
dsn6(3===TemplateDefaults::blank_a4_landscape()['_meta']['schema_version'],'new blank Templates author at v3');

$list=array('id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','type'=>'list','x_mm'=>40,'y_mm'=>80,'width_mm'=>160,'height_mm'=>50,'z'=>99,'rotation_deg'=>0,'opacity'=>1,'items'=>array('Accredited training completed','Identity verified','Credential issued locally'),'list_style'=>'bullet','style'=>array('font_family'=>'sans-serif','font_size_pt'=>16,'font_weight'=>400,'italic'=>false,'color'=>'#111111','align'=>'start','vertical_align'=>'top','direction'=>'auto','line_height'=>1.25,'letter_spacing_pt'=>0,'overflow'=>'wrap'));
$layout=$v3; $layout['elements']=array($list); $normalized=$validator->normalize($layout);
dsn6('list'===$normalized['elements'][0]['type']&&3===count($normalized['elements'][0]['items']),'canonical v3 list validates with ordered item array');
$decimal=$layout; $decimal['elements'][0]['list_style']='decimal'; dsn6('decimal'===$validator->normalize($decimal)['elements'][0]['list_style'],'numbered list marker style is canonical');
$v2list=$v2; $v2list['elements']=array($list); dsn6throws(function()use($validator,$v2list){$validator->normalize($v2list);},'v2 refuses the v3 list primitive');
$bad=$layout; $bad['elements'][0]['items']=array(); dsn6throws(function()use($validator,$bad){$validator->normalize($bad);},'empty list is rejected');
$bad=$layout; $bad['elements'][0]['items']=array_fill(0,101,'x'); dsn6throws(function()use($validator,$bad){$validator->normalize($bad);},'list item count is bounded at 100');
$bad=$layout; $bad['elements'][0]['items']=array('One','   '); dsn6throws(function()use($validator,$bad){$validator->normalize($bad);},'blank list items are rejected');
$bad=$layout; $bad['elements'][0]['list_style']='roman'; dsn6throws(function()use($validator,$bad){$validator->normalize($bad);},'unknown list marker style is rejected');

$resolved=(new LayoutResolver())->resolve($layout,array(),'https://example.test/verify/CERT-001',new Dsn6NoAssets());
dsn6('text'===$resolved['elements'][0]['type'],'list resolves into shared text pipeline before adapters');
dsn6("• Accredited training completed\n• Identity verified\n• Credential issued locally"===$resolved['elements'][0]['text'],'bullet list resolution is deterministic');
dsn6('ltr'===$resolved['elements'][0]['style']['direction'],'list text direction is resolved centrally');
$resolvedDecimal=(new LayoutResolver())->resolve($decimal,array(),'https://example.test/verify/CERT-001',new Dsn6NoAssets()); dsn6(0===strpos($resolvedDecimal['elements'][0]['text'],"1. Accredited"),'decimal list resolution uses stable one-based markers');
$plan=(new RenderPlanBuilder())->build($resolved,array('font_manifest'=>dsn6manifest()));
dsn6(1===count($plan['operations'])&&'text'===$plan['operations'][0]['type'],'Render Plan reuses canonical text operation rather than inventing a second list renderer');
dsn6(false!==strpos($plan['operations'][0]['text']['value'],'• Identity verified'),'Render Plan preserves canonical list marker content');

$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $resolver=file_get_contents($root.'/src/Application/Rendering/LayoutResolver.php');
dsn6(false!==strpos($admin,'data-certloom-designer-add="list"')&&false!==strpos($admin,'certloom-layout v3'),'admin exposes list only behind v3 authoring boundary');
dsn6(false!==strpos($js,"'list' === kind")&&false!==strpos($js,'schemaVersion < 3'),'browser refuses list authoring before v3');
dsn6(false!==strpos($js,"raw.split( /\\r?\\n/ )")&&false!==strpos($js,"list_style"),'Designer stores list items as canonical lines and controlled marker style');
dsn6(false!==strpos($resolver,"case 'list'")&&false!==strpos($resolver,'implode( "\\n", $lines )'),'list resolution is centralized before renderer adapters');
dsn6(!file_exists($root.'/resources/renderer/accepted.json'),'DSN-06 does not manufacture production renderer authority');

echo "\n$pass DSN-06 checks, $fail failures\n"; exit($fail?1:0);
