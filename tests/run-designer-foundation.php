<?php
/** DSN-01 canonical-layout Designer architecture/contract gate. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function is_wp_error($v){return false;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\PresetCatalog;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css');
$pass=0;$fail=0;
function dsn($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
dsn(is_string($admin)&&strpos($admin,'data-certloom-designer')!==false,'Template administration contains the DSN-01 visual Designer boundary');
dsn(strpos($admin,"admin/assets/designer.js")!==false&&strpos($admin,"admin/assets/designer.css")!==false,'Designer assets are isolated and enqueued only by Template administration');
dsn(strpos($admin,'certloom_template_revision')!==false&&strpos($admin,'data-certloom-designer-json')!==false,'Designer saves through the established immutable Template revision action');
dsn(strpos($admin,'Advanced canonical JSON editor')!==false,'advanced JSON remains an explicit escape hatch over the same canonical layout');
dsn(strpos($admin,'Archived templates are read-only')!==false,'archived Templates remain read-only before Designer can render a save form');
dsn(strpos($admin,'DSX-01 · Quick Designer')!==false&&strpos($admin,'DSN-08 is a closed built-in registry')!==false&&strpos($admin,'Recovery checkpoint not applied.')!==false&&strpos($admin,'did not merge it into the authoritative current revision')!==false,'Quick Designer improves presentation while preserving DSN-08 registry and DSN-07 recovery boundaries');
dsn(strpos($admin,'Manual save remains the only authoritative revision write.')!==false,'DSN-01 geometry remains intact while DSN-08 keeps immutable revision authority manual and explicit');
dsn(is_string($js)&&strpos($js,"A4: { portrait: [ 210, 297 ], landscape: [ 297, 210 ] }")!==false,'Designer owns exact canonical A4 dimensions');
dsn(strpos($js,"Letter: { portrait: [ 215.9, 279.4 ], landscape: [ 279.4, 215.9 ] }")!==false,'Designer owns exact canonical Letter dimensions');
dsn(strpos($js,"mode: rotate ? 'rotate' : ( resize ? 'resize' : 'drag' )")!==false,'pointer interaction supports drag, resize and rotate');
dsn(strpos($js,'data-certloom-designer-zoom')!==false&&strpos($js,'fitWorkspace')!==false,'zoom and fit-to-workspace behavior are present');
dsn(strpos($js,'rotatedBounds')!==false&&strpos($js,'elementGeometryValid')!==false,'client interaction guards rotated elements against page-boundary escape');
dsn(strpos($js,'JSON.stringify( layout )')!==false,'visual save serializes the canonical layout document instead of a parallel Designer model');
dsn(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'DSN-01 does not introduce browser persistence ahead of autosave architecture');
dsn(strpos($js,'fetch(')!==false&&strpos($admin,"admin_url( 'admin-ajax.php' )")!==false&&strpos($js,'XMLHttpRequest')===false&&strpos($js,'https://')===false&&substr_count($js,'http://')===1&&strpos($js,'http://www.w3.org/2000/svg')!==false,'Designer network use is limited to authenticated same-origin WordPress autosave; no remote dependency is introduced');
dsn(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Designer JavaScript introduces no dynamic code execution');
dsn(is_string($css)&&strpos($css,'.certloom-template-designer-page')!==false&&strpos($css,'prefers-reduced-motion')!==false,'Designer CSS includes page isolation and reduced-motion treatment');
$catalog=new PresetCatalog();$validator=new LayoutValidator();$all=$catalog->all();$valid=0;foreach($all as $preset){try{$validator->normalize($preset['layout']);$valid++;}catch(Throwable $e){}}
dsn(count($all)===24&&$valid===24,'all 24 factory layouts remain valid DSN-01 canonical canvas inputs');
// Verify the exact four page contracts independently through LayoutValidator.
$base=(new PresetCatalog())->get('classic-a4-landscape')['layout']; $variants=[['A4','landscape',297,210],['A4','portrait',210,297],['Letter','landscape',279.4,215.9],['Letter','portrait',215.9,279.4]];$ok=true;foreach($variants as $v){$l=$base;$l['page']=['size'=>$v[0],'orientation'=>$v[1],'width_mm'=>$v[2],'height_mm'=>$v[3]];$l['elements']=[];try{$n=$validator->normalize($l);$ok=$ok&&abs((float)$n['page']['width_mm']-(float)$v[2])<0.001&&abs((float)$n['page']['height_mm']-(float)$v[3])<0.001;}catch(Throwable $e){$ok=false;}}
dsn($ok,'A4/Letter portrait/landscape page choices all validate through the server canonical contract');
echo "\n$pass DSN-01 checks, $fail failures\n"; exit($fail?1:0);
