<?php
/** DSX-01 Quick Designer presentation/authority contract. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Domain\Rendering\ElementRegistry;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $plugin=file_get_contents($root.'/certloom.php');
$pass=0;$fail=0; function dsx($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
dsx(is_string($admin)&&strpos($admin,'DSX-01 · Quick Designer')!==false,'Template admin identifies DSX-01 Quick Designer');
dsx(strpos($admin,'data-certloom-designer-mode-button="quick"')!==false&&strpos($admin,'data-certloom-designer-mode-button="pro"')!==false,'Quick and Pro presentation modes are explicit');
dsx(strpos($admin,'Same canonical layout · different editing depth')!==false,'mode switch states one canonical layout authority');
dsx(strpos($admin,'data-designer-default-mode="quick"')!==false,'Quick Designer is the default presentation');
dsx(strpos($admin,'certloom-designer-pro-only')!==false&&strpos($admin,'certloom-designer-quick-only')!==false,'progressive disclosure classes exist');
dsx(strpos($admin,'certloom-template-designer-stage')!==false&&strpos($admin,'certloom-template-designer-add-panel')!==false,'Quick Designer has Add plus canvas/properties stage');
foreach(array('Text','Image','Logo','Dynamic Field','QR','Shape','Line') as $label){ dsx(strpos($admin,"__( '$label', 'certloom' )")!==false||strpos($admin,"esc_html__( '$label', 'certloom' )")!==false,"Quick Add exposes $label"); }
dsx(strpos($admin,'Duplicate selected')!==false&&strpos($admin,'Delete selected')!==false,'direct duplicate/delete actions exist');
dsx(strpos($admin,'data-certloom-designer-preview-scroll')!==false&&strpos($admin,'data-certloom-designer-publication-scroll')!==false&&strpos($admin,'data-certloom-designer-quick-save')!==false,'compact top toolbar exposes Preview, Publish and Save');
dsx(strpos($admin,'Dynamic field')!==false&&strpos($admin,'Recipient Name')!==false&&strpos($admin,'Certificate Number')!==false&&strpos($admin,'Grade')!==false&&strpos($admin,'Custom Field…')!==false,'human-readable dynamic field chooser exists');
dsx(strpos($admin,'data-certloom-designer-custom-token')!==false,'custom merge-token escape hatch remains available');
dsx(strpos($admin,'Choose from Media Library')!==false,'image editing still uses WordPress Media Library');
dsx(strpos($admin,'data-certloom-designer-layers-toggle')!==false,'Layers are available on demand in Quick mode');
dsx(strpos($admin,'certloom-template-designer-geometry-grid certloom-designer-pro-only')!==false&&strpos($admin,"'x' => __( 'X (mm)'")!==false,'exact geometry remains available to Pro Designer');
dsx(strpos($admin,'Canonical DSN-08')!==false&&strpos($admin,'DSN-08 is a closed built-in registry')!==false,'DSN-08 typed registry remains explicit');
dsx(strpos($admin,'certloom_template_revision')!==false&&strpos($admin,'data-certloom-designer-json')!==false,'Quick Designer saves through immutable Template revision action');

dsx(is_string($js)&&strpos($js,'applyDesignerMode')!==false,'runtime switches presentation modes without replacing layout state');
dsx(strpos($js,"root.classList.toggle( 'is-quick-mode'")!==false&&strpos($js,"root.classList.toggle( 'is-pro-mode'")!==false,'mode switch is CSS/presentation state');
dsx(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'Quick/Pro mode adds no independent browser persistence authority');
dsx(strpos($js,"root.getAttribute( 'data-designer-default-mode' ) || 'pro'")!==false,'legacy Designer roots without DSX mode metadata remain Pro by default');
dsx(strpos($js,'duplicateSelected')!==false&&strpos($js,'secureUuidV4')!==false,'duplicate creates a fresh secure element identity');
dsx(strpos($js,'copy.id = id')!==false&&strpos($js,'clampDragged( copy )')!==false,'duplicate remains canonical and page-bounded');
dsx(strpos($js,'dynamicFieldSample')!==false&&strpos($js,"'recipient.name'")!==false,'Quick canvas uses human sample content for dynamic fields');
dsx(strpos($js,'syncQuickToken')!==false&&strpos($js,"'__custom__'")!==false,'Quick chooser and custom-field fallback stay synchronized');
dsx(strpos($js,'applyContentField( rawTokenInput )')!==false,'human field selection writes the existing canonical merge token property');
dsx(strpos($js,"scrollDesignerTarget( '.certloom-template-preview-card' )")!==false,'Preview control reuses existing canonical Preview surface');
dsx(strpos($js,"scrollDesignerTarget( '.certloom-template-publication-card' )")!==false,'Publish control delegates to existing publication review surface');
dsx(strpos($js,'JSON.stringify( layout )')!==false&&strpos($js,'layout.elements')!==false,'Quick Designer edits the same canonical layout document');
dsx(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Quick Designer adds no dynamic code execution');

dsx(is_string($css)&&strpos($css,'DSX-01: Quick Designer')!==false,'dedicated Quick Designer styling exists');
dsx(strpos($css,'grid-template-columns: 176px minmax(0,1fr)')!==false,'Quick Designer provides a compact Add rail beside the editing stage');
dsx(strpos($css,'background-image: none')!==false,'Quick canvas removes decorative checkerboard treatment');
dsx(strpos($css,'.is-quick-mode .certloom-designer-pro-only')!==false,'advanced controls are hidden by presentation only');
dsx(strpos($css,'.is-pro-mode .certloom-designer-quick-only')!==false,'Quick-only helpers disappear in Pro mode');
dsx(strpos($css,'min-height: 44px')!==false,'mobile Quick controls preserve accessible target sizing');
dsx(strpos($css,'prefers-reduced-motion: reduce')!==false&&strpos($css,'forced-colors: active')!==false,'Quick Designer covers reduced motion and forced colors');

$palette=ElementRegistry::palette(); $kinds=array_map(static function($row){return $row['kind'];},$palette);
dsx(in_array('text',$kinds,true)&&in_array('merge',$kinds,true)&&in_array('image',$kinds,true)&&in_array('logo',$kinds,true)&&in_array('qr',$kinds,true)&&in_array('rectangle',$kinds,true)&&in_array('line',$kinds,true),'Quick Add is backed only by existing typed registry primitives');
dsx(count(ElementRegistry::types_for_schema(3))>=count(ElementRegistry::types_for_schema(1)),'DSX does not remove established schema types');
dsx(!file_exists($root.'/resources/renderer/accepted.json'),'DSX-01 does not manufacture renderer authority');
dsx(strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false")!==false,'DSX-01 leaves normalized production authority closed');

echo "\n$pass DSX-01 checks, $fail failures\n"; exit($fail?1:0);
