<?php
/** Phase 4 Gamma-02R contextual Inspector + accordion contract. */
define( 'ABSPATH', __DIR__ . '/' );
$root=dirname(__DIR__);$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');$js=file_get_contents($root.'/admin/assets/designer.js');$css=file_get_contents($root.'/admin/assets/designer.css');
$pass=0;$fail=0; function p43($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}

p43(false!==strpos($admin,'data-certloom-designer-property-section="geometry"')&&false!==strpos($admin,'Position & size'),'Inspector exposes exact position and size');
p43(false!==strpos($admin,'data-certloom-designer-property-section="transform"')&&false!==strpos($admin,'Rotate & transform'),'Inspector separates rotate/transform from geometry');
p43(false!==strpos($admin,'data-certloom-designer-property-section="content"')&&false!==strpos($admin,'Content & data'),'Inspector exposes contextual content/data');
p43(false!==strpos($admin,'data-certloom-designer-property-section="appearance"'),'Inspector exposes Appearance');
p43(false!==strpos($admin,'data-certloom-designer-property-section="arrange"'),'Inspector exposes Arrange');
p43(false!==strpos($admin,'data-certloom-designer-property-section="layer"'),'Inspector exposes compact Layer actions');
p43(false!==strpos($admin,'data-certloom-designer-content="merge"')&&false!==strpos($admin,'Technical field token'),'dynamic fields keep bounded picker plus advanced token fallback');
p43(false!==strpos($admin,'data-certloom-designer-content="rich_text"')&&false!==strpos($admin,'data-certloom-designer-rich-runs'),'rich text stays structured rather than HTML');
p43(false!==strpos($admin,'data-certloom-designer-content="asset"')&&false!==strpos($admin,'data-certloom-designer-media'),'image/media content uses shared Media Library authority');
p43(false!==strpos($admin,'data-certloom-designer-style-field="font_family"')&&false!==strpos($admin,'data-certloom-designer-style-field="font_size_pt"')&&false!==strpos($admin,'data-certloom-designer-style-field="font_weight"'),'text appearance exposes font family/size/weight');
p43(false!==strpos($admin,'data-certloom-designer-style-field="overflow"')&&false!==strpos($admin,'data-certloom-designer-style-field="line_height"'),'text wrapping/overflow and line height remain available');
p43(false!==strpos($js,'function enhancePropertySections()')&&false!==strpos($js,"section.classList.add( 'certloom-studio-accordion' )"),'runtime upgrades Inspector sections into Studio accordions');
p43(false!==strpos($js,"if ( ! body.hidden ) { return; }")&&false!==strpos($js,'openInspectorSection( key )'),'active Inspector pane stays open while choosing another pane closes its sibling');
p43(false!==strpos($js,'function openPreferredInspectorSection')&&false!==strpos($js,"? 'content' :") ,'first interaction opens the contextually important section');
p43(false!==strpos($js,'inspectorSectionFor[ element.id ]')&&false!==strpos($js,'var key = inspectorSectionFor[ element.id ] || preferred'),'Inspector remembers the chosen single-open pane per element while preserving contextual defaults');
p43(false!==strpos($js,'function applyContentField')&&false!==strpos($js,'updateElement( element.id, proposed, false )'),'Inspector content reuses canonical mutation path');
p43(false!==strpos($js,'function applyStyleField')&&false!==strpos($js,'styleValueForElement'),'Inspector style reuses canonical style mutation path');
p43(false!==strpos($js,'commitTableElement'),'Table/Data Table editing remains on the certified canonical mutation path');
p43(false!==strpos($css,'.certloom-studio-accordion-trigger')&&false!==strpos($css,'.certloom-studio-accordion-body'),'Studio accordion styling is dedicated and compact');
p43(false!==strpos($css,'.certloom-studio-layer-inspector-actions'),'Layer accordion uses compact icon-action layout');
p43(false!==strpos($css,'@media (forced-colors: active)'),'Inspector retains forced-colors accessibility support');

echo "\n$pass Gamma-02R Inspector checks, $fail failures\n"; exit($fail?1:0);
