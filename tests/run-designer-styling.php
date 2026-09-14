<?php
/** DSN-04 canonical typography/visual styling architecture and regression gate. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function is_wp_error($v){return false;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Domain\Rendering\LayoutSchema;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css');
$pass=0;$fail=0; function dsn4($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}

dsn4(is_string($admin)&&strpos($admin,'certloom-template-designer-v2')!==false&&strpos($admin,'Controlled styles')!==false&&strpos($admin,'DSN-08 is a closed built-in registry')!==false,'Designer v2 keeps customer-facing styling controls while retaining DSN-08 truth behind the technical boundary');
dsn4(strpos($admin,'data-certloom-designer-style-field="opacity"')!==false,'all canonical elements expose common opacity styling');
foreach(['font_family','font_size_pt','font_weight','italic','color','align','vertical_align','direction','line_height','letter_spacing_pt','overflow'] as $key){ dsn4(strpos($admin,'data-certloom-designer-style-field="'.$key.'"')!==false,"text inspector exposes canonical $key"); }
dsn4(strpos($admin,'data-certloom-designer-style-field="font_family"')!==false&&strpos($admin,'<option value="serif">')!==false&&strpos($admin,'<option value="monospace">')!==false,'FONT-02A exposes only bounded controlled font-family options');
dsn4(strpos($admin,'data-certloom-designer-style="shape"')!==false&&substr_count($admin,'data-certloom-designer-style-field="fill"')===1,'shape styling keeps the canonical fill key in the Studio Properties inspector without the retired horizontal contextual toolbar');
dsn4(substr_count($admin,'data-certloom-designer-color-picker-for="fill"')===1,'shape fill exposes a native color selector alongside canonical hex input');
dsn4(substr_count($admin,'data-certloom-designer-color-picker-for="stroke"')===2,'shape and line stroke colors expose native color selectors alongside canonical hex inputs');
foreach(['color','header_fill','header_color','row_fill','alternate_row_fill','border_color'] as $key){ dsn4(strpos($admin,'data-certloom-designer-color-picker-for="'.$key.'"')!==false,"Studio exposes native color selector for $key"); }
dsn4(substr_count($admin,'data-certloom-designer-style-field="stroke"')===2&&substr_count($admin,'data-certloom-designer-style-field="stroke_width_mm"')===2,'shape and line keep their canonical stroke keys in the Studio Properties inspector without duplicate toolbar controls');
dsn4(strpos($admin,'data-certloom-designer-style-field="box_shadow"')===false&&strpos($admin,'data-certloom-designer-style-field="border_radius"')===false,'DSN-04 still refuses non-schema image effects after the v2 schema milestone');
dsn4(strpos($admin,'DSN-08 is a closed built-in registry')!==false,'typed registry is now implemented as the closed DSN-08 boundary');

dsn4(is_string($js)&&strpos($js,'function applyStyleField')!==false&&strpos($js,'function styleValueForElement')!==false,'Designer has an explicit canonical style mutation path');
dsn4(strpos($js,'function canonicalColor')!==false&&strpos($js,"/^#[0-9A-F]{6}(?:[0-9A-F]{2})?$/")!==false,'Designer color validation mirrors #RRGGBB/#RRGGBBAA server syntax');
dsn4(strpos($js,"querySelectorAll( '[data-certloom-designer-color-picker-for]' )")!==false&&strpos($js,"data-certloom-designer-color-picker-for")!==false,'Designer synchronizes all Studio color selectors through their canonical style keys');
dsn4(strpos($js,"value < 0 || value > 1")!==false,'opacity remains bounded to canonical zero-through-one range');
dsn4(strpos($js,"value < 1 || value > 300")!==false&&strpos($js,"value < 0.5 || value > 5")!==false&&strpos($js,"value < -20 || value > 50")!==false,'text numeric style bounds mirror server ranges');
dsn4(strpos($js,"value < 100 || value > 900 || 0 !== value % 100")!==false,'font weights remain exact 100-point variants');
dsn4(strpos($js,"[ 'left', 'center', 'right', 'start', 'end' ]")!==false&&strpos($js,"[ 'auto', 'ltr', 'rtl' ]")!==false,'alignment/direction enumerations mirror the canonical schema');
dsn4(strpos($js,"[ 'wrap', 'shrink', 'clip' ]")!==false,'text overflow policy remains canonical');
dsn4(strpos($js,"var minStroke = 'line' === element.type ? 0.001 : 0")!==false,'line and shape stroke widths preserve their distinct server lower bounds');
dsn4(strpos($js,'function fontKeyAllowed')!==false&&strpos($js,"if ( 'font_family' === key )")!==false&&strpos($js,'Choose a font from the controlled Certloom font list.')!==false,'font-family authoring is bounded to the controlled FONT-02A catalog');
dsn4(strpos($js,"content.setAttribute( 'dir', 'auto' )")!==false,'Designer auto direction delegates to browser bidi detection instead of forcing LTR');
dsn4(strpos($js,'content.style.fontFamily')!==false&&strpos($js,'content.style.fontSize')!==false&&strpos($js,'content.style.letterSpacing')!==false,'canvas reflects canonical typography fields');
dsn4(strpos($js,'node.style.opacity')!==false&&strpos($js,'node.style.background = element.fill')!==false,'canvas reflects opacity and shape color styling');
dsn4(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'DSN-04 styling introduces no browser-local persistence; DSN-07 recovery is server-side user meta');
dsn4(strpos($js,'fetch(')!==false&&strpos($admin,"admin_url( 'admin-ajax.php' )")!==false&&strpos($js,'XMLHttpRequest')===false&&strpos($js,'https://')===false&&substr_count($js,'http://')===1,'DSN-04 styling remains local; DSN-07 adds only same-origin WordPress recovery autosave');
dsn4(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'DSN-04 introduces no dynamic code execution');
dsn4(is_string($css)&&strpos($css,'.certloom-template-designer-style-editor')!==false&&strpos($css,'.certloom-template-designer-style-grid')!==false,'DSN-04 inspector has dedicated responsive styling');

$validator=new LayoutValidator(); $base=(new PresetCatalog())->get('classic-a4-landscape')['layout'];
$textIndex=null;$shapeIndex=null;$lineIndex=null; foreach($base['elements'] as $i=>$e){ if(null===$textIndex&&in_array($e['type'],array('text','merge'),true)){$textIndex=$i;} if(null===$shapeIndex&&'shape'===$e['type']){$shapeIndex=$i;} if(null===$lineIndex&&'line'===$e['type']){$lineIndex=$i;} }
if(null===$shapeIndex||null===$lineIndex){
    $uuid=function($n){return sprintf('10000000-0000-4000-8000-%012d',$n);};
    $base['elements'][]=array('id'=>$uuid(1),'type'=>'shape','x_mm'=>10,'y_mm'=>170,'width_mm'=>20,'height_mm'=>10,'z'=>98,'rotation_deg'=>0,'opacity'=>1,'shape'=>'rectangle','fill'=>'#FFFFFF00','stroke'=>'#000000','stroke_width_mm'=>0.3); $shapeIndex=count($base['elements'])-1;
    $base['elements'][]=array('id'=>$uuid(2),'type'=>'line','x_mm'=>40,'y_mm'=>170,'width_mm'=>20,'height_mm'=>1,'z'=>99,'rotation_deg'=>0,'opacity'=>1,'stroke'=>'#000000','stroke_width_mm'=>0.3); $lineIndex=count($base['elements'])-1;
}
$base['elements'][$textIndex]['style']=array('font_family'=>'sans-serif','font_size_pt'=>32.5,'font_weight'=>700,'italic'=>true,'color'=>'#12345678','align'=>'start','vertical_align'=>'top','direction'=>'rtl','line_height'=>1.55,'letter_spacing_pt'=>2.25,'overflow'=>'clip');
$base['elements'][$textIndex]['opacity']=0.65; $base['elements'][$shapeIndex]['fill']='#ABCDEF80'; $base['elements'][$shapeIndex]['stroke']='#102030'; $base['elements'][$shapeIndex]['stroke_width_mm']=0; $base['elements'][$shapeIndex]['opacity']=0.4; $base['elements'][$lineIndex]['stroke']='#FEDCBA'; $base['elements'][$lineIndex]['stroke_width_mm']=1.25;
try{$normalized=$validator->normalize($base);$valid=true;}catch(Throwable $e){$valid=false;echo "INFO  validator: {$e->getMessage()}\n";}
dsn4($valid,'full DSN-04 style range validates through authoritative LayoutValidator');
dsn4($valid&&$normalized['elements'][$textIndex]['style']['color']==='#12345678'&&$normalized['elements'][$textIndex]['style']['font_weight']===700,'text color alpha and font variant survive canonical normalization');
dsn4($valid&&abs($normalized['elements'][$shapeIndex]['stroke_width_mm']-0.0)<0.0001,'shape permits canonical zero-width stroke');
dsn4($valid&&abs($normalized['elements'][$lineIndex]['stroke_width_mm']-1.25)<0.0001,'line preserves positive canonical stroke width');
$bad=$base; $bad['elements'][$textIndex]['style']['color']='red'; try{$validator->normalize($bad);$badColor=false;}catch(Throwable $e){$badColor=true;} dsn4($badColor,'server rejects non-canonical named colors even if a browser could display them');
$bad=$base; $bad['elements'][$textIndex]['style']['font_size_pt']=301; try{$validator->normalize($bad);$badSize=false;}catch(Throwable $e){$badSize=true;} dsn4($badSize,'server rejects out-of-range typography even if client validation were bypassed');
$bad=$base; $bad['elements'][$lineIndex]['stroke_width_mm']=0; try{$validator->normalize($bad);$badLine=false;}catch(Throwable $e){$badLine=true;} dsn4($badLine,'server retains strict positive line-width boundary');
$types=LayoutSchema::element_types(LayoutSchema::VERSION_V1); dsn4($types===array('text','merge','image','logo','signature','shape','line','qr'),'DSN-04 changes no immutable layout element type');
$all=(new PresetCatalog())->all();$validPresets=0;foreach($all as $preset){try{$validator->normalize($preset['layout']);$validPresets++;}catch(Throwable $e){}} dsn4(count($all)===24&&$validPresets===24,'all 24 factory layouts remain valid after DSN-04');

echo "\n$pass DSN-04 checks, $fail failures\n"; exit($fail?1:0);
