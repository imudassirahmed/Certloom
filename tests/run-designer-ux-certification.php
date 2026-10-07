<?php
/** DSX-QA-01 Designer usability/accessibility certification source contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Rendering\LayoutValidator;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $plugin=file_get_contents($root.'/certloom.php');
$pass=0;$fail=0; function dqa($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}

dqa(is_string($admin)&&strpos($admin,'Technical integrity')!==false&&strpos($admin,'<details class="certloom-template-designer-boundary"')!==false,'technical registry/authority material is preserved but collapsed by default');
dqa(strpos($admin,'DSN-08 is a closed built-in registry')!==false,'closed typed registry boundary remains explicit inside technical disclosure');
dqa(strpos($admin,'data-certloom-designer-property-section="arrange"')!==false&&strpos($admin,'certloom-studio-arrange-grid')!==false&&strpos($admin,'certloom-studio-distribute-actions')!==false,'Arrange is owned by the contextual Studio inspector rather than the retired horizontal dock');
dqa(strpos($admin,'data-certloom-designer-align=')!==false&&strpos($admin,'data-certloom-designer-distribute="horizontal"')!==false&&strpos($admin,'data-certloom-designer-distribute="vertical"')!==false,'Inspector exposes alignment and equal-spacing controls for multi-selection');
dqa(strpos($admin,'data-certloom-designer-selection-status')!==false&&strpos($admin,'data-certloom-designer-spacing-status')!==false,'selection and spacing feedback remain available in the Inspector');
dqa(strpos($admin,'Certificate Designer canvas workspace')!==false&&strpos($admin,'Certificate page canvas')!==false,'canvas workspace and page have explicit accessible names');
dqa(strpos($admin,'data-certloom-designer-palette-item')!==false&&strpos($admin,'draggable="true"')!==false&&strpos($admin,"'Add %s'")!==false,'Insert drawer retains both click and drag affordances with accessible titles');
dqa(strpos($admin,'certloom-studio-draft')!==false&&strpos($admin,'certloom-studio-primary')!==false&&strpos($admin,'data-certloom-designer-save')!==false&&strpos($admin,'data-certloom-designer-primary-save')!==false,'authoritative Draft and Publish/Save Studio actions remain available');

dqa(is_string($js)&&strpos($js,"var alignButtons = root.querySelectorAll( '[data-certloom-designer-align]' )")!==false,'runtime owns Inspector alignment controls');
dqa(strpos($js,"var distributeButtons = root.querySelectorAll( '[data-certloom-designer-distribute]' )")!==false,'runtime owns Inspector equal-spacing controls');
dqa(strpos($js,'alignButtons[ i ].disabled = ! selection.length || groupLocked')!==false&&strpos($js,'distributeButtons[ distributeIndex ].disabled = selection.length < 3 || groupLocked')!==false,'selection state deterministically enables alignment and distribution actions');
dqa(strpos($js,'1 element selected · Shift/Ctrl/Cmd-click to add more')!==false,'single-selection state teaches additive selection without permanent toolbar clutter');
dqa(strpos($js,'function fitWorkspace()')!==false&&strpos($js,'availableW / ( p.width_mm * BASE_PX_PER_MM )')!==false&&strpos($js,'availableH / ( p.height_mm * BASE_PX_PER_MM )')!==false,'Studio 100% is a whole-page auto-fit constrained by the available canvas viewport');
dqa(strpos($js,"fitButton.addEventListener( 'click', fitWorkspace )")!==false,'explicit Fit returns directly to the 100% whole-page fit baseline');
dqa(strpos($js,'workspace.scrollLeft = 0; workspace.scrollTop = 0;')!==false,'fitted Studio view resets local canvas scrolling after centering the page');
dqa(strpos($js,"window.setTimeout( function () { fitWorkspace(); }, 0 )")!==false,'initial Studio render uses the fitted 100% baseline');
dqa(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'usability polish creates no browser-local design authority');
dqa(strpos($js,'selection_ids')===false&&strpos($js,'selected_ids')===false,'selection convenience remains outside persisted canonical layout/state keys');
dqa(strpos($js,'https://')===false&&substr_count($js,'http://')===1,'Designer certification adds no remote content/network path');
dqa(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Designer certification adds no dynamic-code execution');

dqa(is_string($css)&&strpos($css,'DSX-QA-01: Designer usability/accessibility certification polish')!==false,'certification polish has an explicit scoped presentation boundary');
dqa(strpos($css,'.certloom-template-designer-boundary summary')!==false,'collapsed technical disclosure has a clear interactive summary style');
dqa(strpos($css,'.certloom-studio-distribute-actions')!==false&&strpos($css,'.certloom-studio-arrange-grid')!==false,'Inspector Arrange and distribution groups have scoped compact presentation');
dqa(strpos($css,'overscroll-behavior: contain')!==false,'Studio keeps overflow local to its internal editing regions');
dqa(strpos($css,'.certloom-studio-insert-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));')!==false,'Insert drawer stays compact in a two-column element grid on desktop');
dqa(strpos($css,'forced-colors: active')!==false&&strpos($css,'prefers-reduced-motion: reduce')!==false,'existing forced-colors and reduced-motion contracts remain present');

$catalog=new PresetCatalog(); $validator=new LayoutValidator(); $layout=$catalog->get('classic-a4-landscape')['layout'];
try{$normalized=$validator->normalize($layout);$valid=true;}catch(Throwable $e){$valid=false;$normalized=array();}
dqa($valid&&!isset($normalized['comfortable_zoom'])&&!isset($normalized['arrange_visible']),'usability state is not serialized into certloom-layout');
dqa(!file_exists($root.'/resources/renderer/accepted.json'),'Designer UX certification does not manufacture renderer authority');
dqa(strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false")!==false,'normalized production authority remains closed');

echo "\n$pass DSX-QA-01 source checks, $fail failures\n"; exit($fail?1:0);
