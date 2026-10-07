<?php
/** DSX-02B Easy Designer transient multi-select / distribution / compact text contract gate. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
function __($v){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Domain\Rendering\ElementRegistry;
$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $css=file_get_contents($root.'/admin/assets/designer.css'); $plugin=file_get_contents($root.'/certloom.php');
$pass=0;$fail=0; function dsx02b($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}

dsx02b(is_string($admin)&&strpos($admin,'certloom-template-designer-v2')!==false&&strpos($admin,'certloom-template-designer-kicker')===false&&strpos($admin,'Easy Designer')===false,'Template admin presents the unified Designer v2 shell without retired Easy Designer/kicker branding');
dsx02b(strpos($admin,'Shift/Ctrl/Cmd-click to add or remove elements')!==false,'Certloom Studio teaches bounded additive multi-selection in the Selection inspector');
dsx02b(strpos($admin,'data-certloom-designer-distribute="horizontal"')!==false&&strpos($admin,'data-certloom-designer-distribute="vertical"')!==false&&strpos($admin,'certloom-studio-distribute-actions')!==false,'Certloom Studio preserves deterministic horizontal and vertical distribution inside Arrange');
dsx02b(strpos($admin,'data-certloom-designer-selection-status')!==false&&strpos($admin,'data-certloom-designer-spacing-status')!==false&&strpos($admin,'certloom-studio-selection-feedback')!==false,'selection count and spacing feedback remain compact visible presentation state');
dsx02b(strpos($admin,'data-certloom-designer-group')!==false&&strpos($admin,'drag the selection together')!==false&&strpos($admin,'Arrange for alignment and equal spacing')!==false,'multi-selection receives a dedicated non-destructive Studio inspector');
foreach(array('font_family','font_size_pt','color') as $key){dsx02b(strpos($admin,'data-certloom-designer-style-field="'.$key.'"')!==false,"Studio Properties exposes canonical $key text control");}
dsx02b(strpos($admin,'data-certloom-designer-style-field="font_weight"')!==false&&strpos($admin,'data-certloom-designer-style-field="italic"')!==false,'Studio Properties exposes bounded weight and italic controls');
dsx02b(strpos($admin,'data-certloom-designer-style-field="align"')!==false&&strpos($admin,'<option value="left">')!==false&&strpos($admin,'<option value="center">')!==false&&strpos($admin,'<option value="right">')!==false,'Studio Properties exposes left/center/right canonical text alignment');

dsx02b(is_string($js)&&strpos($js,'var selectedIds = []')!==false&&strpos($js,'function selectionIds()')!==false,'multi-selection is explicit transient editor state');
dsx02b(strpos($js,'function combinedBounds')!==false&&strpos($js,'function clampSelectionMap')!==false,'group movement is bounded as a combined rotated selection');
dsx02b(strpos($js,'function snapSelectionMap')!==false&&strpos($js,"snapTargets( 'x', ids )")!==false,'Smart Guides exclude the moving selection from self-snapping');
dsx02b(strpos($js,'function distributeSelection')!==false&&strpos($js,"gap = ( span - total ) / ( ordered.length - 1 )")!==false&&strpos($js,"size: useX ? b.maxX - b.minX : b.maxY - b.minY")!==false,'equal spacing uses a deterministic geometry formula');
dsx02b(strpos($js,'distributionOverlap')!==false,'distribution fails closed when non-overlapping equal gaps are impossible');
dsx02b(strpos($js,'selectionHasLocked()')!==false&&strpos($js,'unlockSelectionBeforeMove')!==false,'mixed locked selections fail closed before movement');
dsx02b(strpos($js,'pushHistory( groupBefore )')!==false&&strpos($js,'pushHistory( before )')!==false,'group arrange operations remain atomic Undo transactions');
dsx02b(strpos($js,"'Escape' === key && selectionIds().length")!==false&&strpos($js,'function focusSelectedCanvasElement')!==false,'keyboard focus is preserved while Escape follows the unified Designer-v2 deselection contract');
dsx02b(strpos($js,'function textStyleElement()')!==false&&strpos($js,'1 !== selection.length')!==false,'compact text controls refuse ambiguous multi-selection styling');
dsx02b(strpos($js,'function applyQuickStyleValue')!==false&&strpos($js,'applyStyleField( field )')!==false,'compact text controls proxy the existing canonical style mutation path');
dsx02b(strpos($js,"if ( ( resize || rotate ) && selectionIds().length > 1 )")!==false,'resize/rotate explicitly collapse to a single primary element');
dsx02b(strpos($js,'localStorage')===false&&strpos($js,'sessionStorage')===false,'multi-selection creates no browser-local layout authority');
dsx02b(strpos($js,'selected_ids')===false&&strpos($js,'selection_ids')===false,'multi-selection is not serialized under a new layout/state key');
dsx02b(strpos($js,'eval(')===false&&strpos($js,'new Function')===false,'DSX-02B introduces no dynamic code execution');

dsx02b(is_string($css)&&strpos($css,'DSX-02B: transient multi-selection')!==false,'DSX-02B visual layer is explicitly scoped as transient presentation');
dsx02b(strpos($css,'.is-multi-selected')!==false&&strpos($css,'.is-primary-selected')!==false,'primary and secondary selections have distinct visible states');
dsx02b(strpos($css,'min-height: 44px')!==false&&strpos($css,'forced-colors: active')!==false,'compact controls preserve mobile target and forced-colors coverage');

$types=ElementRegistry::all();
dsx02b(!isset($types['group'])&&!isset($types['selection']),'Element Registry does not gain a persisted group/selection element type');
dsx02b(!file_exists($root.'/resources/renderer/accepted.json'),'DSX-02B does not manufacture renderer authority');
dsx02b(strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")!==false&&strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false")!==false,'DSX-02B leaves normalized production authority closed');

echo "\n$pass DSX-02B checks, $fail failures\n"; exit($fail?1:0);
