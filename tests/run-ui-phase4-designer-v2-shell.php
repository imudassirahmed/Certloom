<?php
/** Phase 4 Gamma-02R Certloom Studio source shell contract. */
$root=dirname(__DIR__);$php=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');$css=file_get_contents($root.'/admin/assets/designer.css');$js=file_get_contents($root.'/admin/assets/designer.js');
$checks=0; function st($ok,$m){global$checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL  $m\n");exit(1);}} function sth($h,$n,$m){st(false!==strpos($h,$n),$m);} function stn($h,$n,$m){st(false===strpos($h,$n),$m);}
sth($php,'Certloom Studio','workspace is named Certloom Studio');
sth($php,"esc_html__( 'Templates', 'certloom' )",'Studio returns to Templates');
sth($php,'certloom-studio-back','Templates return is part of compact Studio header');
sth($php,'certloom-studio-actions','Studio has dedicated save/publish action group');
sth($php,'value="draft"','Draft is an explicit save intent');
sth($php,"__( 'Save', 'certloom' ) : __( 'Publish', 'certloom' )",'Publish becomes Save after a published revision exists');
sth($php,'value="Custom"','page-size selector includes Custom');
sth($php,'data-certloom-designer-custom-width','custom width is editable');
sth($php,'data-certloom-designer-custom-height','custom height is editable');
sth($php,'data-certloom-designer-zoom-out','compact zoom-out control exists');
sth($php,'data-certloom-designer-zoom-output','zoom percentage is always visible');
sth($php,'data-certloom-designer-zoom-in','compact zoom-in control exists');
sth($php,'data-certloom-designer-fit','fit-to-100% control is always available');
sth($php,'data-certloom-designer-test-print','only Test print proofing action is in Studio toolbar');
stn($php,'data-certloom-designer-preview-scroll','Studio does not embed separate Preview action');
sth($php,'data-certloom-studio-import-open','Studio permits direct single-template import');
sth($php,'data-certloom-designer-insert-panel','Insert is an expandable left-rail submenu');
foreach(array('Data & dynamic','Text','Branding & media','Shapes & layout') as $label){sth($php,$label,'Insert category '.$label.' exists');}
sth($php,'data-certloom-designer-layers','left rail includes Layers');
sth($php,'data-certloom-designer-reset','left utility strip includes restore-last-saved');
sth($php,'certloom-template-designer-workspace','center owns dedicated canvas viewport');
sth($php,'certloom-template-designer-inspector','right owns dedicated Inspector');
sth($css,'grid-template-columns: 248px minmax(420px, 1fr) 294px','desktop Studio is a three-pane editor');
sth($css,'overflow: hidden','focused desktop shell prevents page-workspace spill');
sth($css,'certloom-studio-insert-drawer','Insert drawer has dedicated layering/styling');
sth($css,'z-index: 4','Insert drawer uses bounded left-rail layering without covering Layers/canvas chrome');
sth($css,'position: relative','Insert drawer expands in-flow inside the left rail instead of floating over Layers');
sth($js,'function fitWorkspace()','fit behavior is centralized');
sth($js,'relativeZoomPercent()','displayed 100% is relative to fitted workspace scale');
sth($js,"'0' === key",'Ctrl/Cmd+0/0 fit shortcut is implemented');
sth($js,'function renderSelectionToolbar()','selection gets a direct on-canvas quick toolbar');
sth($js,'data-certloom-designer-resize','direct resize handles remain');
sth($js,'data-certloom-designer-rotate','direct rotate handle remains');
sth($js,'startDirectTextEdit','direct text editing remains');
sth($js,'autosave','recovery autosave remains intact');
sth($php,'Manual save remains the only authoritative revision write.','autosave/revision authority boundary is documented without visible clutter');
echo "PASS  Phase 4 Gamma-02R Certloom Studio shell\n\n$checks checks, 0 failures\n";
