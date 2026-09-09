<?php
/** DSX-01 legacy presentation capability / Designer v2 authority contract. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Domain\Rendering\ElementRegistry;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $plugin=file_get_contents($root.'/certloom.php');
$pass=0;$fail=0; function dsx($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
dsx(is_string($admin)&&strpos($admin,'certloom-template-designer-v2')!==false&&strpos($admin,'Certificate Designer')!==false&&strpos($admin,'Easy Designer')===false,'Template admin presents the unified Designer v2 surface');
dsx(strpos($admin,'data-certloom-designer-mode-button=')===false,'Designer v2 removes the customer-facing Quick/Pro mode switch');
dsx(strpos($admin,'Manual save remains the only authoritative revision write.')!==false,'Unified Designer v2 preserves one explicit revision authority');
dsx(strpos($admin,'data-designer-default-mode="pro"')!==false,'Designer v2 defaults to the unified precise editing surface');
dsx(strpos($admin,'certloom-designer-pro-only')!==false&&strpos($admin,'certloom-designer-quick-only')!==false,'progressive disclosure classes exist');
dsx(strpos($admin,'certloom-template-designer-stage')!==false&&strpos($admin,'certloom-template-designer-add-panel')!==false,'Designer v2 has Layers/Insert plus canvas/properties stage');
foreach(array('Text','Image','Logo','Dynamic Field','QR','Shape','Line') as $label){ dsx(strpos($admin,"__( '$label', 'certloom' )")!==false||strpos($admin,"esc_html__( '$label', 'certloom' )")!==false,"Quick Add exposes $label"); }
dsx(strpos($admin,'Duplicate selected')!==false&&strpos($admin,'Delete selected')!==false,'direct duplicate/delete actions exist');
dsx(strpos($admin,'data-certloom-designer-preview-scroll')!==false&&strpos($admin,'data-certloom-designer-quick-save')!==false&&strpos($admin,'Save design')!==false,'compact Designer v2 exposes Preview and explicit Save while publication stays in the design publication workflow');
dsx(strpos($admin,'Dynamic field')!==false&&strpos($admin,'Recipient Name')!==false&&strpos($admin,'Certificate Number')!==false&&strpos($admin,'Grade')!==false&&strpos($admin,'designer_catalog_field_options')!==false&&strpos($admin,'Technical custom field…')!==false&&strpos($admin,'Technical field token')!==false,'human-readable dynamic field chooser includes catalog-backed fields with an explicit Technical custom-field escape hatch');
dsx(strpos($admin,'data-certloom-designer-custom-token')!==false,'custom merge-token escape hatch remains available');
dsx(strpos($css,'.is-quick-mode .certloom-designer-token-advanced')!==false&&strpos($css,'.certloom-designer-token-advanced.is-custom')!==false&&strpos($css,'.is-pro-mode .certloom-designer-token-advanced')!==false,'Technical field token is progressively disclosed in Quick mode and available in Pro precision controls');
dsx(strpos($admin,'Choose from Media Library')!==false,'image editing still uses WordPress Media Library');
dsx(strpos($admin,'certloom-template-designer-layers-primary')!==false&&strpos($admin,'data-certloom-designer-layers-toggle')===false,'Layers are always visible in the Designer v2 left rail');
dsx(strpos($admin,'certloom-template-designer-geometry-grid certloom-designer-pro-only')!==false&&strpos($admin,"'x' => __( 'X (mm)'")!==false,'exact geometry remains available in the unified Designer v2 Properties inspector');
dsx(strpos($admin,'Controlled styles')!==false&&strpos($admin,'DSN-08 is a closed built-in registry')!==false,'customer styling label is plain-language while the collapsed technical boundary still records DSN-08 integrity');
dsx(strpos($admin,'certloom_template_revision')!==false&&strpos($admin,'data-certloom-designer-json')!==false,'Quick Designer saves through immutable Template revision action');

dsx(is_string($js)&&strpos($js,'applyDesignerMode')!==false,'legacy runtime mode compatibility remains available without exposing customer-facing mode controls');
dsx(strpos($js,"root.classList.toggle( 'is-quick-mode'")!==false&&strpos($js,"root.classList.toggle( 'is-pro-mode'")!==false,'legacy mode compatibility remains presentation-only state');
dsx(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'Designer presentation compatibility adds no independent browser persistence authority');
dsx(strpos($js,"root.getAttribute( 'data-designer-default-mode' ) || 'pro'")!==false,'legacy Designer roots without mode metadata remain precise-mode compatible by default');
dsx(strpos($js,'duplicateSelected')!==false&&strpos($js,'secureUuidV4')!==false,'duplicate creates a fresh secure element identity');
dsx(strpos($js,'copy.id = id')!==false&&strpos($js,'clampDragged( copy )')!==false,'duplicate remains canonical and page-bounded');
dsx(strpos($js,'dynamicFieldSample')!==false&&strpos($js,"'recipient.name'")!==false,'Quick canvas uses human sample content for dynamic fields');
dsx(strpos($js,'syncQuickToken')!==false&&strpos($js,"'__custom__'")!==false,'Quick chooser and custom-field fallback stay synchronized');
dsx(strpos($js,'applyContentField( rawTokenInput )')!==false,'human field selection writes the existing canonical merge token property');
dsx(strpos($js,"scrollDesignerTarget( '.certloom-template-preview-card' )")!==false,'Preview control reuses existing canonical Preview surface');
dsx(strpos($js,"scrollDesignerTarget( '.certloom-template-publication-card' )")!==false,'Publish control delegates to existing publication review surface');
dsx(strpos($js,'JSON.stringify( layout )')!==false&&strpos($js,'layout.elements')!==false,'Designer v2 edits the same canonical layout document');
dsx(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Designer v2 adds no dynamic code execution');

dsx(is_string($css)&&strpos($css,'Certloom 1.7 — Designer v2 shell foundation.')!==false,'dedicated Designer v2 shell styling exists');
dsx(strpos($css,'grid-template-columns: 220px minmax(0, 1fr)')!==false,'Designer v2 provides a dedicated Layers/Insert rail beside the editing stage');
dsx(strpos($css,'background-image: none')!==false,'Quick canvas removes decorative checkerboard treatment');
dsx(strpos($admin,'data-designer-default-mode="pro"')!==false&&strpos($admin,'data-certloom-designer-mode-button=')===false,'precision controls are the unified Designer v2 surface rather than a hidden customer mode');
dsx(strpos($css,'.is-pro-mode .certloom-designer-quick-only')!==false,'retired quick-only compatibility helpers remain hidden under the unified precise shell');
dsx(strpos($css,'min-height: 44px')!==false,'Designer controls preserve accessible mobile target sizing');
dsx(strpos($css,'prefers-reduced-motion: reduce')!==false&&strpos($css,'forced-colors: active')!==false,'Designer v2 retains reduced-motion and forced-colors coverage');

$palette=ElementRegistry::palette(); $kinds=array_map(static function($row){return $row['kind'];},$palette);
dsx(in_array('text',$kinds,true)&&in_array('merge',$kinds,true)&&in_array('image',$kinds,true)&&in_array('logo',$kinds,true)&&in_array('qr',$kinds,true)&&in_array('rectangle',$kinds,true)&&in_array('line',$kinds,true),'Designer v2 Insert remains backed only by existing typed registry primitives');
dsx(count(ElementRegistry::types_for_schema(3))>=count(ElementRegistry::types_for_schema(1)),'DSX does not remove established schema types');
dsx(!file_exists($root.'/resources/renderer/accepted.json'),'DSX-01 does not manufacture renderer authority');
dsx(strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false")!==false,'DSX-01 leaves normalized production authority closed');

echo "\n$pass DSX-01 checks, $fail failures\n"; exit($fail?1:0);
