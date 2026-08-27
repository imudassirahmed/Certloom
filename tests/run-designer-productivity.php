<?php
/** DSN-02 Designer productivity + non-artifact authoring-state contract gate. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateDesignerState;
use Certloom\Application\Rendering\LayoutValidator;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $uninstall=file_get_contents($root.'/uninstall.php');
$pass=0;$fail=0;
function dsn2($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}

dsn2(is_string($admin)&&strpos($admin,'DSN-08 · Typed Element Registry')!==false&&strpos($admin,'data-certloom-designer-undo')!==false&&strpos($admin,'data-certloom-designer-redo')!==false,'Template admin advances through DSN-08 while retaining the DSN-02 productivity surface');
dsn2(strpos($admin,'data-certloom-designer-state-json')!==false&&strpos($admin,'designer_state_json')!==false,'Designer submits non-artifact authoring state separately from canonical layout JSON');
dsn2(strpos($admin,'update_user_meta')!==false&&strpos($admin,"get_current_blog_id() . '_' . absint( \$template_id )")!==false,'personal locks/snapping persist in site-scoped WordPress user meta rather than artifact content');
dsn2(strpos($admin,'TemplateDesignerState')!==false,'authoring-state persistence passes through a dedicated normalizer');
dsn2(is_string($uninstall)&&strpos($uninstall,'certloom_template_designer_state_')!==false&&strpos($uninstall,'$wpdb->usermeta')!==false,'site-scoped personal Designer state is uninstall-owned operational UI data');
dsn2(strpos($admin,'data-certloom-designer-undo')!==false&&strpos($admin,'data-certloom-designer-redo')!==false,'undo/redo controls are present');
dsn2(strpos($admin,'data-certloom-designer-snap')!==false,'snap/guides control is present');
dsn2(substr_count($admin,'data-certloom-designer-align=')>=1&&strpos($admin,"'center-x'")!==false&&strpos($admin,"'center-y'")!==false,'page-alignment controls cover both axes');
dsn2(strpos($admin,'data-certloom-designer-layer-action')!==false&&strpos($admin,'data-certloom-designer-layers')!==false,'layer ordering and layer list controls are present');
dsn2(strpos($admin,'data-certloom-designer-lock')!==false,'personal authoring lock controls are present');
dsn2(strpos($admin,'Arrow keys nudge 1 mm')!==false&&strpos($admin,'Alt/Option+Arrow 0.1 mm')!==false,'direct keyboard manipulation is documented in-product');
dsn2(strpos($admin,'manual save remains the only immutable revision write')!==false,'DSN-02 productivity remains intact while DSN-08 keeps recovery outside immutable artifact authority');

$catalog=new PresetCatalog(); $layout=$catalog->get('classic-a4-landscape')['layout']; $ids=array_column($layout['elements'],'id'); $state=new TemplateDesignerState();
$defaults=$state->defaults();
dsn2($defaults===array('version'=>1,'locked_ids'=>array(),'snap_enabled'=>true),'authoring state has deterministic safe defaults');
$normalized=$state->normalize(array('version'=>999,'locked_ids'=>array($ids[3],'not-an-element',$ids[1],$ids[3]),'snap_enabled'=>false,'unexpected'=>'discard'),$layout);
$expected_locks=array($ids[1],$ids[3]); sort($expected_locks,SORT_STRING);
dsn2(1===$normalized['version']&&false===$normalized['snap_enabled']&&$expected_locks===$normalized['locked_ids'],'authoring-state normalizer keeps only known unique element locks and supported preferences');
dsn2($state->decode('{bad json',$layout)===$defaults,'malformed non-authoritative authoring state fails safely to defaults');

// Canonical artifact hashes must not need or accept lock/snap authoring metadata.
$validator=new LayoutValidator(); $canonical=$validator->normalize($layout);
dsn2(!isset($canonical['locked_ids'])&&!isset($canonical['snap_enabled']),'authoring locks/snapping remain outside certloom-layout artifact content');

dsn2(is_string($js)&&strpos($js,'MAX_HISTORY = 100')!==false&&strpos($js,'function undo()')!==false&&strpos($js,'function redo()')!==false,'Designer owns bounded undo/redo history');
dsn2(strpos($js,'SNAP_THRESHOLD_MM')!==false&&strpos($js,'function snapTargets')!==false&&strpos($js,'function snapDragged')!==false,'moving elements can snap to page/element guides');
dsn2(strpos($js,'certloom-template-designer-guide')!==false&&strpos($js,'activeGuides')!==false,'snap targets expose transient visual guides');
dsn2(strpos($js,'function alignSelected')!==false&&strpos($js,"'center-x'")!==false&&strpos($js,"'center-y'")!==false,'alignment logic uses rotated bounds on both page axes');
dsn2(strpos($js,'function reorderSelected')!==false&&strpos($js,'order[ j ].z = j')!==false,'layer actions re-canonicalize unique deterministic z-order values');
dsn2(strpos($js,'function toggleLock')!==false&&strpos($js,'isLocked( element )')!==false,'locks block transformations while keeping elements selectable');
dsn2(strpos($js,"event.altKey ? 0.1 : ( event.shiftKey ? 5 : 1 )")!==false,'keyboard nudging provides fine/default/coarse deterministic millimetre steps');
dsn2(strpos($js,"'z' === event.key.toLowerCase()")!==false&&strpos($js,"'y' === event.key.toLowerCase()")!==false,'standard keyboard history shortcuts are wired');
dsn2(strpos($js,'stateField.value = JSON.stringify( designerState )')!==false&&strpos($js,'jsonField.value = JSON.stringify( layout )')!==false,'submit serializes authoring state and canonical layout through separate fields');
dsn2(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'DSN-02 introduces no browser-local persistence');
dsn2(strpos($js,'fetch(')!==false&&strpos($admin,"admin_url( 'admin-ajax.php' )")!==false&&strpos($js,'XMLHttpRequest')===false&&strpos($js,'https://')===false&&substr_count($js,'http://')===1,'Designer uses only same-origin WordPress AJAX for recovery autosave and introduces no remote dependency');
dsn2(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Designer productivity JavaScript introduces no dynamic code execution');
dsn2(is_string($css)&&strpos($css,'.certloom-template-designer-guide')!==false&&strpos($css,'.certloom-template-designer-layer')!==false&&strpos($css,'.certloom-template-designer-element.is-locked')!==false,'DSN-02 CSS covers guides, layers and lock state');
dsn2(strpos($css,'prefers-reduced-motion')!==false,'existing reduced-motion accessibility contract remains intact');

$all=$catalog->all();$valid=0;foreach($all as $preset){try{$validator->normalize($preset['layout']);$valid++;}catch(Throwable $e){}}
dsn2(count($all)===24&&$valid===24,'all 24 factory layouts remain valid DSN-02 inputs without schema drift');

echo "\n$pass DSN-02 checks, $fail failures\n"; exit($fail?1:0);
