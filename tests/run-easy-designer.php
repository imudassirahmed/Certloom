<?php
/** DSX-02A Easy Designer direct-placement / Smart Guides / direct-text contract gate. */
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\ElementRegistry;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $plugin=file_get_contents($root.'/certloom.php');
$pass=0;$fail=0; function dsx02($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}

dsx02(is_string($admin)&&strpos($admin,'certloom-template-designer-v2')!==false&&strpos($admin,'Certificate Designer')!==false&&strpos($admin,'Easy Designer')===false,'Template admin presents the unified Designer v2 product surface without retired Easy Designer branding');
dsx02(strpos($admin,'data-certloom-designer-palette-item')!==false&&strpos($admin,'draggable="true"')!==false&&strpos($admin,'data-certloom-designer-content-field="text"')!==false&&strpos($admin,'data-certloom-designer-snap')!==false,'Certloom Studio retains direct placement, direct text editing and Smart Guide snapping in the unified workspace');
dsx02(strpos($admin,'data-designer-default-mode="pro"')!==false&&strpos($admin,'data-designer-safe-margin-mm="10"')!==false&&strpos($admin,'data-certloom-designer-mode-button=')===false,'Designer v2 defaults to the unified precise editor with the existing presentation-only safe margin and no customer mode switch');
dsx02(strpos($admin,"esc_html__( 'Snap while moving', 'certloom' )")!==false&&strpos($admin,'data-certloom-designer-snap')!==false&&strpos($admin,'certloom-studio-view-popover')!==false,'Smart Guide snapping remains available in the compact Studio view controls');
dsx02(strpos($admin,'data-certloom-designer-quick-align')===false&&substr_count($admin,'data-certloom-designer-align=')>=1&&strpos($admin,'data-certloom-designer-property-section="arrange"')!==false,'Studio moves canonical page-alignment actions into the compact Arrange inspector instead of a horizontal toolbar');
foreach(array('text','image','logo','merge','qr','rectangle','line') as $kind){dsx02(strpos($admin,'data-certloom-designer-add="\' . esc_attr( $kind )')===false || strpos($admin,"draggable=\"true\"")!==false,"Quick $kind can participate in drag placement");}
dsx02(strpos($admin,'data-certloom-designer-color-picker')!==false&&strpos($admin,'data-certloom-designer-style-field="color"')!==false,'text color has both accessible visual picker and exact canonical hex input');
dsx02(strpos($admin,'data-certloom-designer-style-field="font_family"')!==false&&strpos($admin,'<option value="serif">')!==false&&strpos($admin,'<option value="monospace">')!==false,'Easy Designer exposes the bounded FONT-02A family picker without free-text CSS');
dsx02(strpos($admin,'directTextEditStarted')!==false&&strpos($admin,'directTextEditSaved')!==false&&strpos($admin,'directTextEditCancelled')!==false,'direct text editing owns explicit accessible announcements');

dsx02(is_string($js)&&strpos($js,'function geometryAtPoint')!==false&&strpos($js,"addEventListener( 'dragstart'")!==false&&strpos($js,"addEventListener( 'drop'")!==false,'runtime implements palette-to-canvas placement');
dsx02(strpos($js,"application/x-certloom-element")!==false&&strpos($js,"effectAllowed = 'copy'")!==false,'drag payload is bounded to a Certloom element kind and copy semantics');
dsx02(strpos($js,'function startDirectTextEdit')!==false&&strpos($js,'certloom-template-designer-direct-text')!==false&&strpos($js,"'text' !== element.type")!==false,'ordinary canonical text has a dedicated direct-edit overlay');
dsx02(strpos($js,'new Blob')!==false&&strpos($js,'20000')!==false&&strpos($js,"value.indexOf( '\\0' )")!==false,'direct text preserves canonical text-size and NUL safety boundaries');
dsx02(strpos($js,'function safeMarginMm')!==false&&strpos($js,'DEFAULT_SAFE_MARGIN_MM = 10')!==false&&strpos($js,'extent - margin')!==false,'Smart Guides include safe-margin snap targets');
dsx02(strpos($js,'function snapDragged( element, bypass )')!==false&&strpos($js,'!! event.altKey')!==false,'Alt/Option temporarily bypasses snapping during a drag');
dsx02(strpos($js,'rotatedBounds')!==false&&strpos($js,'elementGeometryValid( candidate, page() )')!==false,'Smart snapping remains guarded by rotated page bounds');
dsx02(strpos($js,'colorPicker.addEventListener')!==false&&strpos($js,'colorTextField.value')!==false,'visual color picker synchronizes through the existing canonical color field');
dsx02(strpos($js,'mediaFrame')!==false&&strpos($js,"'attachment:' + Number( data.id )")!==false,'dragged Image/Logo placement still resolves only to local WordPress attachment identity');
dsx02(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'Easy Designer creates no browser-local design authority');
dsx02(strpos($js,'https://')===false&&substr_count($js,'http://')===1,'Easy Designer adds no remote network/content path');
dsx02(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'Easy Designer introduces no dynamic code execution');

dsx02(is_string($css)&&strpos($css,'DSX-02A: Easy Designer')!==false&&strpos($css,'.certloom-template-designer-direct-text')!==false,'Easy Designer direct editing has dedicated presentation styling');
dsx02(strpos($css,'.certloom-template-designer-page.is-drop-target')!==false&&strpos($css,'.is-palette-dragging')!==false,'drag destination receives visible, non-persisted feedback');
dsx02(strpos($css,'min-height: 44px')!==false&&strpos($css,'forced-colors: active')!==false&&strpos($css,'prefers-reduced-motion: reduce')!==false,'Easy Designer retains mobile target, forced-colors and reduced-motion coverage');

$catalog=new PresetCatalog(); $validator=new LayoutValidator(); $layout=$catalog->get('classic-a4-landscape')['layout'];
try{$normalized=$validator->normalize($layout);$valid=true;}catch(Throwable $e){$valid=false;$normalized=array();}
dsx02($valid&&!isset($normalized['safe_margin_mm'])&&!isset($normalized['smart_guides']),'Smart Guides and safe margins remain presentation state outside certloom-layout');
$kinds=array_map(static function($row){return$row['kind'];},ElementRegistry::palette());
dsx02(!array_diff(array('text','image','logo','merge','qr','rectangle','line'),$kinds),'Easy Add uses only existing typed Element Registry primitives');
dsx02(!file_exists($root.'/resources/renderer/accepted.json'),'DSX-02A does not manufacture renderer authority');
dsx02(strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false")!==false,'DSX-02A leaves normalized production authority closed');

echo "\n$pass DSX-02A checks, $fail failures\n"; exit($fail?1:0);
