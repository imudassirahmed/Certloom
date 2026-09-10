<?php
/** DSN-08 closed Typed Element Registry architecture/behavior gate. */
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;

$pass=0;$fail=0; function dsn8($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
function dsn8has($s,$n,$l){dsn8(false!==strpos($s,$n),$l);}

$all=ElementRegistry::all();
dsn8(1===ElementRegistry::CONTRACT_VERSION,'registry has an explicit portable contract version');
dsn8(13===count($all),'registry contains the historical types plus additive schema-4 rich_text, Table and Data Table');
dsn8(array('text','merge','rich_text','image','logo','signature','shape','line','qr','barcode','list','table','data_table')===array_keys($all),'registry order and canonical machine types are deterministic');
dsn8(array('text','merge','image','logo','signature','shape','line','qr')===LayoutSchema::element_types(1),'layout v1 type inventory is derived from the registry without drift');
dsn8(array('text','merge','image','logo','signature','shape','line','qr','barcode')===LayoutSchema::element_types(2),'layout v2 registry adds Code 128 only');
dsn8(array('text','merge','image','logo','signature','shape','line','qr','barcode','list')===LayoutSchema::element_types(3),'layout v3 registry adds canonical lists only');
dsn8(array('text','merge','rich_text','image','logo','signature','shape','line','qr','barcode','list','table','data_table')===LayoutSchema::element_types(4),'layout v4 registry adds rich_text, Table and Data Table without removing historical read types');
dsn8(array()===LayoutSchema::element_types(999),'unsupported future schema cannot acquire registry types');

$expected_min=array('text'=>1,'merge'=>1,'rich_text'=>4,'image'=>1,'logo'=>1,'signature'=>1,'shape'=>1,'line'=>1,'qr'=>1,'barcode'=>2,'list'=>3,'table'=>4,'data_table'=>4);
$expected_resolver=array('text'=>'text','merge'=>'merge','rich_text'=>'rich_text','image'=>'asset','logo'=>'asset','signature'=>'signature_asset','shape'=>'passthrough','line'=>'passthrough','qr'=>'qr','barcode'=>'barcode','list'=>'list','table'=>'table','data_table'=>'data_table');
$expected_render=array('text'=>'text','merge'=>'text','rich_text'=>'rich_text','image'=>'image','logo'=>'image','signature'=>'image','shape'=>'shape','line'=>'line','qr'=>'qr','barcode'=>'barcode','list'=>'text','table'=>'table','data_table'=>'table');
foreach($all as $type=>$definition){
 dsn8($type===($definition['type']??null),"$type registry key matches machine type");
 dsn8($expected_min[$type]===($definition['min_schema_version']??null),"$type declares the correct minimum schema version");
 dsn8($expected_resolver[$type]===($definition['resolver']??null),"$type declares one central resolver mapping");
 dsn8($expected_render[$type]===($definition['render_operation']??null),"$type declares one central renderer-operation mapping");
 dsn8(is_array($definition['allowed_properties']??null)&&is_array($definition['required_properties']??null),"$type declares allowed/required property contracts");
 dsn8(!empty($definition['validation'])&&!empty($definition['preview'])&&isset($definition['inspector']),"$type declares validation, Preview and inspector metadata");
 foreach((array)($definition['required_properties']??array()) as $required){ dsn8(in_array($required,$definition['allowed_properties'],true),"$type required property $required is allowed"); }
}

dsn8(array('dynamic_token')===$all['merge']['merge_requirements'],'merge element declares dynamic immutable snapshot requirement');
dsn8(array('verification_url')===$all['qr']['merge_requirements'],'QR declares canonical verification URL requirement');
dsn8(array('credential.display_number')===$all['barcode']['merge_requirements'],'Code 128 declares only the existing human certificate-number requirement');
dsn8('text'===$all['list']['render_operation']&&'list'===$all['list']['resolver'],'lists resolve centrally into the existing text renderer path');
dsn8('text'===$all['merge']['render_operation']&&'merge'===$all['merge']['resolver'],'merge elements resolve centrally into the existing text renderer path');

$palette=ElementRegistry::palette(); $kinds=array_column($palette,'kind');
dsn8(16===count($palette)&&16===count(array_unique($kinds)),'cross-schema registry owns sixteen unique palette definitions including legacy Merge plus schema-4 Rich text, Table and Data Table');
dsn8(in_array('seal',$kinds,true)&&in_array('background',$kinds,true),'seal/background remain typed palette presets rather than new persisted element types');
$seal=null;$background=null;$barcode=null;$list=null;$merge=null;$rich=null;foreach($palette as $item){if('seal'===$item['kind'])$seal=$item;if('background'===$item['kind'])$background=$item;if('barcode'===$item['kind'])$barcode=$item;if('list'===$item['kind'])$list=$item;if('merge'===$item['kind'])$merge=$item;if('rich_text'===$item['kind'])$rich=$item;}
dsn8('image'===($seal['type']??null),'Seal palette tool maps explicitly to canonical image type');
dsn8('shape'===($background['type']??null)&&!empty($background['defaults']['full_page']),'Background palette tool maps explicitly to canonical full-page shape');
dsn8(2===($barcode['min_schema_version']??null),'Barcode palette availability comes from v2 registry metadata');
dsn8(3===($list['min_schema_version']??null),'List palette availability comes from v3 registry metadata');
dsn8(3===($merge['max_schema_version']??null)&&4===($rich['min_schema_version']??null),'palette metadata retires normal standalone Merge after schema 3 and introduces Rich text at schema 4');

$browser=ElementRegistry::browser_contract();
dsn8(1===($browser['contract_version']??0)&&13===count($browser['types']??array())&&16===count($browser['palette']??array()),'browser registry is a bounded portable cross-schema projection of the server contract');
$encoded=json_encode($browser); dsn8(is_string($encoded)&&strlen($encoded)<20000,'browser registry remains compact and JSON-only');
dsn8(false===stripos($encoded,'<script')&&false===stripos($encoded,'javascript:')&&false===stripos($encoded,'callback'),'browser contract carries no executable callback/HTML channel');

// Behavioral server checks prove registry availability never replaces authoritative validation.
$base=array('_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>1),'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),'elements'=>array());
$uuid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$text=array('id'=>$uuid,'type'=>'text','x_mm'=>10,'y_mm'=>10,'width_mm'=>80,'height_mm'=>15,'text'=>'Registry');
$layout=$base;$layout['elements'][]=$text; try{$normalized=(new LayoutValidator())->normalize($layout);dsn8('text'===$normalized['elements'][0]['type'],'registry-backed text validation succeeds');}catch(Throwable $e){dsn8(false,'registry-backed text validation succeeds');}
$bad=$layout;$bad['elements'][0]['rogue_css']='position:fixed';try{(new LayoutValidator())->normalize($bad);dsn8(false,'registry rejects undeclared arbitrary element properties');}catch(Throwable $e){dsn8(true,'registry rejects undeclared arbitrary element properties');}
$v1bar=$base;$v1bar['elements'][]=array('id'=>$uuid,'type'=>'barcode','x_mm'=>10,'y_mm'=>10,'width_mm'=>110,'height_mm'=>18,'source'=>'credential.display_number','symbology'=>'code128','quiet_zone_modules'=>10);try{(new LayoutValidator())->normalize($v1bar);dsn8(false,'v1 cannot gain v2 barcode through registry metadata alone');}catch(Throwable $e){dsn8(true,'v1 cannot gain v2 barcode through registry metadata alone');}
$v2bar=$v1bar;$v2bar['_meta']['schema_version']=2;try{$n=(new LayoutValidator())->normalize($v2bar);dsn8('barcode'===$n['elements'][0]['type'],'v2 barcode validates through registry-backed schema boundary');}catch(Throwable $e){dsn8(false,'v2 barcode validates through registry-backed schema boundary');}
$v2list=$base;$v2list['_meta']['schema_version']=2;$v2list['elements'][]=array('id'=>$uuid,'type'=>'list','x_mm'=>10,'y_mm'=>10,'width_mm'=>100,'height_mm'=>40,'items'=>array('One'));try{(new LayoutValidator())->normalize($v2list);dsn8(false,'v2 cannot gain v3 list through registry metadata alone');}catch(Throwable $e){dsn8(true,'v2 cannot gain v3 list through registry metadata alone');}
$v3list=$v2list;$v3list['_meta']['schema_version']=3;try{$n=(new LayoutValidator())->normalize($v3list);dsn8('list'===$n['elements'][0]['type'],'v3 list validates through registry-backed schema boundary');}catch(Throwable $e){dsn8(false,'v3 list validates through registry-backed schema boundary');}

$root=dirname(__DIR__);$registrySource=file_get_contents($root.'/src/Domain/Rendering/ElementRegistry.php');$validator=file_get_contents($root.'/src/Application/Rendering/LayoutValidator.php');$resolver=file_get_contents($root.'/src/Application/Rendering/LayoutResolver.php');$plan=file_get_contents($root.'/src/Application/Rendering/RenderPlanBuilder.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');$js=file_get_contents($root.'/admin/assets/designer.js');
dsn8(false===strpos($registrySource,'apply_filters')&&false===strpos($registrySource,'do_action'),'registry is closed to unversioned WordPress filter/action mutation');
dsn8has($validator,'ElementRegistry::get','authoritative LayoutValidator consumes registry property/type metadata');
dsn8has($resolver,'ElementRegistry::require_type','LayoutResolver consumes registry resolution mapping');
dsn8has($plan,'ElementRegistry::get','RenderPlanBuilder consumes registry renderer-operation mapping');
dsn8has($admin,'ElementRegistry::palette()','production Designer palette is generated from the typed registry');
dsn8has($admin,'certloomTemplateElementRegistry','production browser receives only the portable registry contract');
dsn8has($js,'registrySnapshot','browser snapshots the server-projected registry instead of sharing a mutable global reference');
dsn8has($js,'deepFreeze','browser freezes its registry snapshot against later runtime mutation');
dsn8has($js,'paletteDefinition','element creation is driven by registered palette definitions');
dsn8has($js,'content_group','inspector content grouping is registry-driven');
dsn8has($js,'style_group','inspector styling grouping is registry-driven');
dsn8(false===strpos($registrySource,'<style')&&false===strpos($registrySource,'<script'),'registry contains no arbitrary HTML/CSS executable surface');
dsn8(!file_exists($root.'/resources/renderer/accepted.json'),'DSN-08 does not manufacture renderer authority');

echo "\n$pass DSN-08 checks, $fail failures\n"; exit($fail?1:0);
