<?php
/** Phase 4.3 unified contextual toolbar + structured Properties source contract. */
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$js = file_get_contents( $root . '/admin/assets/designer.js' );
$css = file_get_contents( $root . '/admin/assets/designer.css' );
$pass=0; $fail=0;
function p43($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}

p43(strpos($admin,'class="certloom-template-designer-context-dock" data-certloom-designer-context-dock')!==false,'context dock is unified and not quick-only');
p43(strpos($admin,'class="certloom-template-designer-quick-align" data-certloom-designer-quick-align')!==false,'Arrange context is available in unified Designer');
p43(strpos($admin,'class="certloom-template-designer-quick-text" data-certloom-designer-quick-text')!==false,'Text context is available in unified Designer');
p43(strpos($admin,'data-certloom-designer-context-edit-text')!==false,'Text context exposes direct Edit text');
p43(strpos($admin,'data-certloom-designer-context-token')!==false,'Merge context exposes bounded dynamic field selector');
p43(strpos($admin,'data-certloom-designer-context-group="asset"')!==false,'asset context group exists');
p43(strpos($admin,'data-certloom-designer-context-group="shape"')!==false,'shape context group exists');
p43(strpos($admin,'data-certloom-designer-context-group="line"')!==false,'line context group exists');
p43(strpos($admin,'data-certloom-designer-context-group="qr"')!==false,'QR context group exists');
p43(strpos($admin,'data-certloom-designer-context-group="barcode"')!==false,'barcode context group exists');
p43(
	(bool) preg_match('/data-certloom-designer-context-group="asset"[^>]*>.*?data-certloom-designer-content-field="fit"/s',$admin)
	&& (bool) preg_match('/data-certloom-designer-content="asset"[^>]*>.*?data-certloom-designer-content-field="fit"/s',$admin),
	'asset Fit mirrors the canonical content key in both context and Properties'
);
p43(substr_count($admin,'data-certloom-designer-content-field="error_correction"')===2,'QR error correction mirrors the canonical content key exactly twice');
p43(substr_count($admin,'data-certloom-designer-style-field="fill"')===2,'Shape fill mirrors the canonical style key exactly twice');
p43(substr_count($admin,'data-certloom-designer-style-field="stroke"')===4,'Shape/Line stroke mirrors canonical keys across context and Properties');
p43(substr_count($admin,'data-certloom-designer-style-field="stroke_width_mm"')===4,'Shape/Line stroke width mirrors canonical keys across context and Properties');
p43(strpos($js,"var contextGroups = root.querySelectorAll( '[data-certloom-designer-context-group]' )")!==false,'runtime discovers contextual groups');
p43(strpos($js,'function syncContextGroups()')!==false,'runtime owns explicit context synchronization');
p43(strpos($js,'if ( 1 !== selection.length || ! element ) { return; }')!==false,'ambiguous multi-selection suppresses type-specific context');
p43(strpos($js,'contextEditTextButton.disabled = ! element || locked')!==false&&strpos($js,'contextTokenSelect.disabled = ! element || locked')!==false,'locked selection disables contextual mutations');
p43(strpos($js,'startDirectTextEdit( element.id )')!==false&&strpos($js,'openDirectTextEditor( element.id )')===false,'Edit text uses the established direct-text authority');
p43(strpos($js,"querySelectorAll( '[data-certloom-designer-media], [data-certloom-designer-context-media]' )")!==false,'media authority is shared by Properties and context toolbar');
p43(strpos($js,'for ( var mediaButtonIndex = 0; mediaButtonIndex < mediaButtons.length; mediaButtonIndex++ )')!==false,'all media mirrors use one canonical handler');
p43(strpos($js,'function applyContentField')!==false&&strpos($js,'updateElement( element.id, proposed, false )')!==false,'content context reuses canonical mutation path');
p43(strpos($js,'function applyStyleField')!==false&&strpos($js,'styleValueForElement')!==false,'style context reuses canonical style mutation path');
p43(strpos($admin,'data-certloom-designer-property-section="geometry"')!==false&&strpos($admin,'Position & size')!==false,'Properties explicitly begins with Position & size');
p43(strpos($admin,'data-certloom-designer-property-section="content"')!==false&&strpos($admin,"esc_html__( 'Content', 'certloom' )")!==false,'Properties has explicit Content section');
p43(strpos($admin,'data-certloom-designer-property-section="appearance"')!==false,'Properties has explicit Appearance section');
p43(strpos($admin,'certloom-template-designer-geometry-grid certloom-designer-pro-only')===false,'exact geometry is no longer hidden behind retired mode presentation');
p43(strpos($css,'.certloom-template-designer-v2 .certloom-template-designer-context-controls')!==false,'context controls have dedicated Designer-v2 CSS');
p43(strpos($css,'.certloom-template-designer-v2 .certloom-template-designer-property-section')!==false,'structured Properties sections have dedicated CSS');
p43(strpos($css,'min-height: 44px')!==false&&strpos($css,'.certloom-template-designer-context-controls select')!==false,'context controls preserve mobile touch targets');
p43(strpos($css,'@media (forced-colors: active)')!==false&&strpos($css,'certloom-template-designer-property-section { border-color: CanvasText; }')!==false,'context/Properties preserve forced-colors borders');
p43(strpos($admin,'data-certloom-designer-context-group="table"')!==false&&strpos($admin,'data-certloom-designer-context-group="data_table"')!==false&&strpos($js,'commitTableElement')!==false,'Table/Data Table context appears only after its certified architecture and reuses the canonical Table mutation path');

echo "\n$pass Phase 4.3 contextual/Properties checks, $fail failures\n";
exit($fail?1:0);
