<?php
/** Regressions for the Local template audit: immutable history, borders, rails and contrast. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require __DIR__.'/bootstrap-autoload.php';

use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\CuratedPresetCatalog;

$checks=0;
function tq($ok,$label){global $checks;if(!$ok){throw new RuntimeException($label);}$checks++;}
function tq_intersects($a,$b){return min($a['x_mm']+$a['width_mm'],$b['x_mm']+$b['width_mm'])-max($a['x_mm'],$b['x_mm'])>0.01 && min($a['y_mm']+$a['height_mm'],$b['y_mm']+$b['height_mm'])-max($a['y_mm'],$b['y_mm'])>0.01;}
$historic=json_decode(file_get_contents(__DIR__.'/fixtures/templates/historical-layout-hashes.json'),true);
$catalogs=[new PresetCatalog(),new CuratedPresetCatalog()];
foreach($catalogs as $catalog){
 foreach($catalog->all() as $d){
  $key=$d['preset_key'];$layout=$d['layout'];$page=$layout['page'];
  foreach($historic[$key] as $version=>$hash){tq(hash_equals($hash,$catalog->get($key,$version)['layout_sha256']),'Historical layout changed: '.$key.' v'.$version);}
  tq($d['preset_version']>max(array_keys($historic[$key])),'Correction needs a new immutable version: '.$key);
  $content=array_values(array_filter($layout['elements'],fn($e)=>in_array($e['type'],['text','merge','qr'],true)));
  foreach($content as $e){
   tq($e['x_mm']>=0 && $e['y_mm']>=0 && $e['x_mm']+$e['width_mm']<=$page['width_mm'] && $e['y_mm']+$e['height_mm']<=$page['height_mm'],'Content outside page: '.$key);
   foreach($layout['elements'] as $frame){
    if($frame['type']!=='shape' || $frame['shape']!=='rectangle' || substr($frame['fill'],-2)!=='00' || $frame['width_mm']<0.8*$page['width_mm'] || $frame['height_mm']<0.8*$page['height_mm']){continue;}
    tq($e['x_mm']>=$frame['x_mm'] && $e['y_mm']>=$frame['y_mm'] && $e['x_mm']+$e['width_mm']<=$frame['x_mm']+$frame['width_mm'] && $e['y_mm']+$e['height_mm']<=$frame['y_mm']+$frame['height_mm'],'Content crosses certificate frame: '.$key);
   }
  }
  for($i=0;$i<count($content);$i++){for($j=$i+1;$j<count($content);$j++){tq(!tq_intersects($content[$i],$content[$j]),'Content boxes overlap: '.$key);}}
  if(in_array($key,['curated-executive','curated-folio','curated-signal'],true)){
   $rail=$layout['elements'][0];
   foreach($content as $e){if('merge'===$e['type'] && in_array($e['token'],['credential.display_number','credential.issue_date'],true)){tq(!tq_intersects($e,$rail),'Credential facts overlap decorative rail: '.$key);}}
  }
  if('curated-horizon'===$key){
   $band=$layout['elements'][0];
   foreach($content as $e){if('text'===$e['type'] && in_array($e['text'],['CREDENTIAL ID','ISSUED','SCAN TO VERIFY'],true) || 'merge'===$e['type'] && in_array($e['token'],['credential.display_number','credential.issue_date'],true)){
    tq($e['style']['color']==='#FFFFFF','Horizon footer requires contrasting ink');
    tq($e['y_mm']>=$band['y_mm'] && $e['y_mm']+$e['height_mm']<=$band['y_mm']+$band['height_mm'],'Horizon footer crosses band boundary');
   }}
  }
  if(strpos($key,'minimal-')===0){foreach($layout['elements'] as $line){if('line'===$line['type']){foreach($content as $e){tq(!tq_intersects($line,$e),'Minimal underline crosses content box: '.$key);}}}}
  if(strpos($key,'academic-')===0 && $page['orientation']==='landscape'){
   $seal=array_values(array_filter($layout['elements'],fn($e)=>$e['type']==='shape' && $e['shape']==='ellipse'))[0];
   foreach($content as $e){if($e['type']==='text' && $e['text']==='ACADEMIC CERTIFICATE'){tq(!tq_intersects($seal,$e),'Academic seal crosses eyebrow');}}
  }
 }
}
echo $checks." template design checks, 0 failures\n";
