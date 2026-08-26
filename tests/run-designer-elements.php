<?php
/** DSN-03 canonical element/content creation architecture and regression gate. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function is_wp_error($v){return false;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Domain\Rendering\LayoutSchema;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css');
$pass=0;$fail=0; function dsn3($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}

dsn3(is_string($admin)&&strpos($admin,'DSN-07 · Recovery autosave & concurrency')!==false,'Template admin advances through DSN-07 while retaining the DSN-03 element/content surface');
dsn3(strpos($admin,'wp_enqueue_media()')!==false,'WordPress Media Library is explicitly enqueued for local asset selection');
foreach(['text','merge','image','logo','signature','seal','qr','rectangle','ellipse','line','background'] as $type){ dsn3(strpos($admin,'data-certloom-designer-add="\' . esc_attr( $type )')!==false || strpos($admin,"'$type' => __(")!==false,"palette exposes $type creation"); }
dsn3(strpos($js,"'seal' === kind")!==false&&strpos($js,"element.type = 'image'")!==false,'seal palette maps to the existing canonical image primitive without schema mutation');
dsn3(strpos($admin,'data-certloom-designer-add="barcode"')!==false&&strpos($admin,'Upgrade this draft to certloom-layout v2')!==false,'barcode is exposed only behind the explicit v2 upgrade boundary rather than mutating schema v1');
dsn3(strpos($admin,'data-certloom-designer-remove')!==false,'selected elements can be removed through explicit Designer UI');
dsn3(strpos($admin,'data-certloom-designer-content-field="text"')!==false&&strpos($admin,'data-certloom-designer-content-field="token"')!==false,'text and merge content editors are present');
dsn3(strpos($admin,'data-certloom-designer-media')!==false&&strpos($admin,'attachment:123')!==false,'asset editor keeps WordPress-local attachment references visible');
dsn3(strpos($admin,'error_correction')!==false&&strpos($admin,'quiet_zone_modules')!==false,'QR content controls preserve the canonical v1 QR contract');
dsn3(strpos($admin,'data-certloom-designer-style-field="font_size_pt"')!==false&&strpos($admin,'data-certloom-designer-style-field="opacity"')!==false,'DSN-03 primitives are now styled only through canonical DSN-04 fields');
dsn3(strpos($admin,'DSN-08 remains the Typed Element Registry')!==false,'DSN-08 remains explicit after DSN-07 without collapsing later registry work');

dsn3(is_string($js)&&strpos($js,'function secureUuidV4')!==false&&strpos($js,'getRandomValues')!==false,'new element IDs use browser cryptographic randomness');
dsn3(strpos($js,'Math.random')===false,'element IDs never fall back to Math.random');
dsn3(strpos($js,'elements().length >= 500')!==false,'client creation honors the server 500-element ceiling');
dsn3(strpos($js,'function buildNewElement')!==false&&strpos($js,'function appendElement')!==false&&strpos($js,'function removeSelected')!==false,'creation/removal have explicit bounded operations');
dsn3(strpos($js,"element.source = 'verification_url'")!==false,'new QR elements use verification_url only');
dsn3(strpos($js,"element.asset = assetReference")!==false&&strpos($js,"'attachment:' + Number( data.id )")!==false,'Media Library selection becomes an attachment:ID reference');
dsn3(strpos($js,'window.wp.media')!==false&&strpos($js,"library: { type: 'image' }")!==false,'asset picker is WordPress-local image media');
dsn3(strpos($js,"[ 'image/png', 'image/jpeg' ]")!==false&&strpos($admin,'unsupportedMediaType')!==false,'Designer rejects Media Library types outside the renderer-supported PNG/JPEG contract before persistence');
dsn3(strpos($js,"/^[a-z][a-z0-9_.:-]{0,127}$/")!==false,'merge token editing mirrors server machine-key syntax');
dsn3(strpos($js,"/^attachment:[1-9][0-9]*$/")!==false&&strpos($js,"asset:[a-z0-9]")===false,'Designer creates/edits only immediately resolvable attachment:ID assets while schema-reserved asset:key remains future-facing');
dsn3(strpos($js,'stateLayout = layout')!==false,'authoring-state normalization recognizes newly created element IDs during undo/redo');
dsn3(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'DSN-03 content remains free of browser-local persistence; DSN-07 recovery is server-side user meta');
dsn3(strpos($js,'fetch(')!==false&&strpos($admin,"admin_url( 'admin-ajax.php' )")!==false&&strpos($js,'XMLHttpRequest')===false&&strpos($js,'https://')===false&&substr_count($js,'http://')===1,'Designer network activity remains restricted to same-origin WordPress recovery autosave');
dsn3(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Designer adds no dynamic code execution');
$resolver=file_get_contents($root.'/src/Infrastructure/Rendering/WordPressLocalAssetResolver.php');
dsn3(strpos($resolver,"array( 'image/png', 'image/jpeg' )")!==false&&strpos($resolver,'supports only local PNG and JPEG')!==false,'production WordPress asset resolver independently enforces the same PNG/JPEG media boundary');
dsn3(is_string($css)&&strpos($css,'.certloom-template-designer-palette')!==false&&strpos($css,'.certloom-template-designer-content-editor')!==false,'DSN-03 palette/content editor have dedicated responsive styling');

$types=LayoutSchema::element_types(LayoutSchema::VERSION_V1);
dsn3($types===array('text','merge','image','logo','signature','shape','line','qr'),'certloom-layout v1 element type contract is unchanged');
dsn3(!in_array('barcode',$types,true),'barcode is not smuggled into immutable layout schema v1');

$validator=new LayoutValidator(); $base=(new PresetCatalog())->get('classic-a4-landscape')['layout']; $base['elements']=array();
$style=array('font_family'=>'sans-serif','font_size_pt'=>18,'font_weight'=>400,'italic'=>false,'color'=>'#000000','align'=>'center','vertical_align'=>'middle','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink');
$uuid=function($n){return sprintf('00000000-0000-4000-8000-%012d',$n);};
$elements=array(
 array('id'=>$uuid(1),'type'=>'text','x_mm'=>10,'y_mm'=>10,'width_mm'=>80,'height_mm'=>15,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'text'=>'Certificate text','style'=>$style),
 array('id'=>$uuid(2),'type'=>'merge','x_mm'=>10,'y_mm'=>30,'width_mm'=>80,'height_mm'=>15,'z'=>1,'rotation_deg'=>0,'opacity'=>1,'token'=>'recipient.name','style'=>$style),
 array('id'=>$uuid(3),'type'=>'image','x_mm'=>10,'y_mm'=>50,'width_mm'=>60,'height_mm'=>40,'z'=>2,'rotation_deg'=>0,'opacity'=>1,'asset'=>'attachment:321','fit'=>'contain'),
 array('id'=>$uuid(4),'type'=>'logo','x_mm'=>80,'y_mm'=>50,'width_mm'=>60,'height_mm'=>40,'z'=>3,'rotation_deg'=>0,'opacity'=>1,'asset'=>'attachment:322','fit'=>'cover'),
 array('id'=>$uuid(5),'type'=>'signature','x_mm'=>150,'y_mm'=>50,'width_mm'=>70,'height_mm'=>25,'z'=>4,'rotation_deg'=>0,'opacity'=>1,'asset'=>'attachment:323','fit'=>'fill'),
 array('id'=>$uuid(6),'type'=>'qr','x_mm'=>240,'y_mm'=>10,'width_mm'=>35,'height_mm'=>35,'z'=>5,'rotation_deg'=>0,'opacity'=>1,'source'=>'verification_url','error_correction'=>'H','quiet_zone_modules'=>4),
 array('id'=>$uuid(7),'type'=>'shape','x_mm'=>10,'y_mm'=>100,'width_mm'=>60,'height_mm'=>30,'z'=>6,'rotation_deg'=>0,'opacity'=>1,'shape'=>'rectangle','fill'=>'#FFFFFF00','stroke'=>'#000000','stroke_width_mm'=>0.3),
 array('id'=>$uuid(8),'type'=>'shape','x_mm'=>80,'y_mm'=>100,'width_mm'=>60,'height_mm'=>30,'z'=>7,'rotation_deg'=>0,'opacity'=>1,'shape'=>'ellipse','fill'=>'#FFFFFF00','stroke'=>'#000000','stroke_width_mm'=>0.3),
 array('id'=>$uuid(9),'type'=>'line','x_mm'=>150,'y_mm'=>110,'width_mm'=>60,'height_mm'=>1,'z'=>8,'rotation_deg'=>0,'opacity'=>1,'stroke'=>'#000000','stroke_width_mm'=>0.3),
);
$base['elements']=$elements; try{$normalized=$validator->normalize($base);$valid=true;}catch(Throwable $e){$valid=false;echo "INFO  validator: {$e->getMessage()}\n";}
dsn3($valid&&count($normalized['elements']??array())===9,'all DSN-03 schema-v1 creation primitives validate through the authoritative server contract');
$catalog=new PresetCatalog();$all=$catalog->all();$validPresets=0;foreach($all as $preset){try{$validator->normalize($preset['layout']);$validPresets++;}catch(Throwable $e){}}
dsn3(count($all)===24&&$validPresets===24,'all 24 factory layouts remain valid after DSN-03');

echo "\n$pass DSN-03 checks, $fail failures\n"; exit($fail?1:0);
