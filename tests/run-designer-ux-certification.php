<?php
/** DSX-QA-01 Designer usability/accessibility certification source contract. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Rendering\LayoutValidator;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $plugin=file_get_contents($root.'/certloom.php');
$pass=0;$fail=0; function dqa($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}

dqa(is_string($admin)&&strpos($admin,'Technical integrity')!==false&&strpos($admin,'<details class="certloom-template-designer-boundary"')!==false,'technical registry/authority material is preserved but collapsed by default');
dqa(strpos($admin,'DSN-08 is a closed built-in registry')!==false,'closed typed registry boundary remains explicit inside technical disclosure');
dqa(strpos($admin,'data-certloom-designer-quick-align')!==false&&strpos($admin,'Quick alignment and distribution controls')!==false&&strpos($admin,'role="toolbar"')!==false,'Quick Arrange surface remains a dedicated accessible toolbar');
dqa(strpos($admin,'data-certloom-designer-quick-align role="toolbar"')!==false&&strpos($admin,'data-certloom-designer-quick-align role="toolbar" aria-label=')!==false&&strpos($admin,' hidden>')!==false,'Quick Arrange is initially hidden until a meaningful selection exists');
dqa(strpos($admin,'data-certloom-designer-distribute-group hidden')!==false,'distribution controls are progressively disclosed instead of occupying idle Quick Designer space');
dqa(strpos($admin,'Certificate Designer canvas workspace')!==false&&strpos($admin,'Certificate page canvas')!==false,'canvas workspace and page have explicit accessible names');
dqa(strpos($admin,'Click or drag onto canvas')!==false,'Add panel retains both click and drag instructions');
dqa(strpos($admin,'data-certloom-designer-quick-save')!==false&&strpos($admin,'data-certloom-designer-save')!==false,'Quick and end-of-form authoritative save affordances remain available');

dqa(is_string($js)&&strpos($js,"var quickArrangeBar = root.querySelector( '[data-certloom-designer-quick-align]' )")!==false,'runtime owns contextual Arrange disclosure');
dqa(strpos($js,"var distributionGroup = root.querySelector( '[data-certloom-designer-distribute-group]' )")!==false,'runtime owns contextual Distribution disclosure');
dqa(strpos($js,'quickArrangeBar.hidden = 0 === count')!==false&&strpos($js,'distributionGroup.hidden = count < 3')!==false,'selection count deterministically controls Arrange/Distribution visibility');
dqa(strpos($js,'1 element selected · Shift/Ctrl/Cmd-click to add more')!==false,'single-selection state teaches additive selection without permanent toolbar clutter');
dqa(strpos($js,'function fitWorkspace( comfortableEditing )')!==false&&strpos($js,'minimumPercent')!==false&&strpos($js,'workspace.clientWidth < 520 ? 40 : 25')!==false,'narrow Quick Designer receives a 40% comfortable editing floor');
dqa(strpos($js,"fitButton.addEventListener( 'click', function () { fitWorkspace( false ); }")!==false,'explicit Fit remains a true whole-page fit rather than forcing the comfortable-edit floor');
dqa(strpos($js,'workspace.scrollLeft = Math.max( 0, ( workspace.scrollWidth - workspace.clientWidth ) / 2 )')!==false,'comfortable mobile edit view centers its local horizontal workspace');
dqa(strpos($js,"window.setTimeout( function () { fitWorkspace( true ); }, 0 )")!==false,'initial Quick Designer render uses the comfortable editing fit');
dqa(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'usability polish creates no browser-local design authority');
dqa(strpos($js,'selection_ids')===false&&strpos($js,'selected_ids')===false,'selection convenience remains outside persisted canonical layout/state keys');
dqa(strpos($js,'https://')===false&&substr_count($js,'http://')===1,'Designer certification adds no remote content/network path');
dqa(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Designer certification adds no dynamic-code execution');

dqa(is_string($css)&&strpos($css,'DSX-QA-01: Designer usability/accessibility certification polish')!==false,'certification polish has an explicit scoped presentation boundary');
dqa(strpos($css,'.certloom-template-designer-boundary summary')!==false,'collapsed technical disclosure has a clear interactive summary style');
dqa(strpos($css,'.certloom-template-designer-distribute-group[hidden]')!==false&&strpos($css,'.certloom-template-designer-quick-align[hidden]')!==false,'progressive disclosure cannot be defeated by component display rules');
dqa(strpos($css,'overscroll-behavior: contain')!==false,'mobile editing keeps overflow local to the Designer workspace');
dqa(strpos($css,'grid-template-columns: repeat(2,minmax(0,1fr))')!==false&&strpos($css,'@media (max-width: 480px) {\n\t.certloom-template-designer.is-quick-mode .certloom-template-designer-palette { grid-template-columns: 1fr; }')===false,'phone Add palette stays compact in two columns instead of pushing the canvas far below the fold');
dqa(strpos($css,'forced-colors: active')!==false&&strpos($css,'prefers-reduced-motion: reduce')!==false,'existing forced-colors and reduced-motion contracts remain present');

$catalog=new PresetCatalog(); $validator=new LayoutValidator(); $layout=$catalog->get('classic-a4-landscape')['layout'];
try{$normalized=$validator->normalize($layout);$valid=true;}catch(Throwable $e){$valid=false;$normalized=array();}
dqa($valid&&!isset($normalized['comfortable_zoom'])&&!isset($normalized['arrange_visible']),'usability state is not serialized into certloom-layout');
dqa(!file_exists($root.'/resources/renderer/accepted.json'),'Designer UX certification does not manufacture renderer authority');
dqa(strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false")!==false,'normalized production authority remains closed');

echo "\n$pass DSX-QA-01 source checks, $fail failures\n"; exit($fail?1:0);
